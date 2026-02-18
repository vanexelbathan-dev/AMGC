<?php
// drivers.php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Check if branch_id column exists in drivers table
$drivers_branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
}

// Determine branch filter condition
$branch_condition = "";
if ($drivers_branch_column_exists && !$view_all_branches) {
    $branch_condition = "AND d.branch_id = $branch_id";
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // ADD DRIVER WITH USER ACCOUNT
        if ($_POST['action'] === 'add_driver') {
            // Driver information
            $driver_name = $_POST['driver_name'];
            $license_number = $_POST['license_number'];
            $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
            $contact_number = !empty($_POST['contact_number']) ? $_POST['contact_number'] : null;
            $vehicle_type = !empty($_POST['vehicle_type']) ? $_POST['vehicle_type'] : null;
            $vehicle_plate_number = !empty($_POST['vehicle_plate_number']) ? $_POST['vehicle_plate_number'] : null;
            $status = $_POST['status'] ?? 'active';
            
            // User account information
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $first_name = $_POST['first_name'] ?? '';
            $last_name = $_POST['last_name'] ?? '';
            
            // Validate required fields
            if (empty($driver_name)) {
                throw new Exception('Driver Name is required');
            }
            
            if (empty($license_number)) {
                throw new Exception('License Number is required');
            }
            
            if (empty($email)) {
                throw new Exception('Email is required');
            }
            
            if (empty($password)) {
                throw new Exception('Password is required');
            }
            
            if (strlen($password) < 6) {
                throw new Exception('Password must be at least 6 characters long');
            }
            
            if (empty($first_name)) {
                throw new Exception('First Name is required');
            }
            
            if (empty($last_name)) {
                throw new Exception('Last Name is required');
            }
            
            // Check if email already exists
            $check_email_query = "SELECT user_id FROM users WHERE email = ?";
            $check_email_stmt = $conn->prepare($check_email_query);
            $check_email_stmt->bind_param("s", $email);
            $check_email_stmt->execute();
            $check_email_result = $check_email_stmt->get_result();
            
            if ($check_email_result->num_rows > 0) {
                throw new Exception('Email already exists in the system');
            }
            
            // Check if license number already exists
            $check_license_query = "SELECT driver_id FROM drivers WHERE license_number = ?";
            $check_license_stmt = $conn->prepare($check_license_query);
            $check_license_stmt->bind_param("s", $license_number);
            $check_license_stmt->execute();
            $check_license_result = $check_license_stmt->get_result();
            
            if ($check_license_result->num_rows > 0) {
                throw new Exception('License number already exists');
            }
            
            // Insert driver
            if ($drivers_branch_column_exists) {
                $insert_driver_query = "INSERT INTO drivers (driver_name, license_number, license_expiry, contact_number, vehicle_type, vehicle_plate_number, status, branch_id, created_at, updated_at) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_driver_stmt = $conn->prepare($insert_driver_query);
                $insert_driver_stmt->bind_param("sssssssi", $driver_name, $license_number, $license_expiry, $contact_number, $vehicle_type, $vehicle_plate_number, $status, $branch_id);
            } else {
                $insert_driver_query = "INSERT INTO drivers (driver_name, license_number, license_expiry, contact_number, vehicle_type, vehicle_plate_number, status, created_at, updated_at) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_driver_stmt = $conn->prepare($insert_driver_query);
                $insert_driver_stmt->bind_param("sssssss", $driver_name, $license_number, $license_expiry, $contact_number, $vehicle_type, $vehicle_plate_number, $status);
            }
            
            if (!$insert_driver_stmt->execute()) {
                throw new Exception('Failed to add driver: ' . $insert_driver_stmt->error);
            }
            
            $driver_id = $conn->insert_id;
            
            // Hash the password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $user_role_db = 'delivery'; // Default role for drivers
            
            // Insert user account
            $insert_user_query = "INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, driver_id, status, created_at, updated_at) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())";
            $insert_user_stmt = $conn->prepare($insert_user_query);
            $insert_user_stmt->bind_param("sssssii", $email, $password_hash, $first_name, $last_name, $user_role_db, $branch_id, $driver_id);
            
            if (!$insert_user_stmt->execute()) {
                // If user creation fails, rollback driver insertion
                throw new Exception('Failed to create user account: ' . $insert_user_stmt->error);
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Driver and user account created successfully',
                'driver_id' => $driver_id
            ]);
            exit;
        }
        
        // UPDATE DRIVER AND USER ACCOUNT
        elseif ($_POST['action'] === 'update_driver') {
            $driver_id = (int)$_POST['driver_id'];
            
            // Driver information
            $driver_name = $_POST['driver_name'];
            $license_number = $_POST['license_number'];
            $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
            $contact_number = !empty($_POST['contact_number']) ? $_POST['contact_number'] : null;
            $vehicle_type = !empty($_POST['vehicle_type']) ? $_POST['vehicle_type'] : null;
            $vehicle_plate_number = !empty($_POST['vehicle_plate_number']) ? $_POST['vehicle_plate_number'] : null;
            $status = $_POST['status'] ?? 'active';
            
            // User account information
            $email = $_POST['email'] ?? '';
            $password = !empty($_POST['password']) ? $_POST['password'] : null;
            $first_name = $_POST['first_name'] ?? '';
            $last_name = $_POST['last_name'] ?? '';
            
            // Validate required fields
            if (empty($driver_name)) {
                throw new Exception('Driver Name is required');
            }
            
            if (empty($license_number)) {
                throw new Exception('License Number is required');
            }
            
            if (empty($email)) {
                throw new Exception('Email is required');
            }
            
            if (empty($first_name)) {
                throw new Exception('First Name is required');
            }
            
            if (empty($last_name)) {
                throw new Exception('Last Name is required');
            }
            
            if ($password && strlen($password) < 6) {
                throw new Exception('Password must be at least 6 characters long');
            }
            
            // Verify driver belongs to user's branch
            if ($drivers_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT driver_id FROM drivers WHERE driver_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $driver_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Driver not found or access denied');
                }
            }
            
            // Check if license number already exists for another driver
            $check_license_query = "SELECT driver_id FROM drivers WHERE license_number = ? AND driver_id != ?";
            $check_license_stmt = $conn->prepare($check_license_query);
            $check_license_stmt->bind_param("si", $license_number, $driver_id);
            $check_license_stmt->execute();
            $check_license_result = $check_license_stmt->get_result();
            
            if ($check_license_result->num_rows > 0) {
                throw new Exception('License number already exists for another driver');
            }
            
            // Check if email already exists for another user
            $check_email_query = "SELECT user_id FROM users WHERE email = ? AND driver_id != ?";
            $check_email_stmt = $conn->prepare($check_email_query);
            $check_email_stmt->bind_param("si", $email, $driver_id);
            $check_email_stmt->execute();
            $check_email_result = $check_email_stmt->get_result();
            
            if ($check_email_result->num_rows > 0) {
                throw new Exception('Email already exists for another user');
            }
            
            // Update driver
            $update_driver_query = "UPDATE drivers 
                                   SET driver_name = ?, license_number = ?, license_expiry = ?, contact_number = ?, vehicle_type = ?, vehicle_plate_number = ?, status = ?, updated_at = NOW() 
                                   WHERE driver_id = ?";
            $update_driver_stmt = $conn->prepare($update_driver_query);
            $update_driver_stmt->bind_param("sssssssi", $driver_name, $license_number, $license_expiry, $contact_number, $vehicle_type, $vehicle_plate_number, $status, $driver_id);
            
            if (!$update_driver_stmt->execute()) {
                throw new Exception('Failed to update driver: ' . $update_driver_stmt->error);
            }
            
            // Check if user exists for this driver
            $check_user_query = "SELECT user_id FROM users WHERE driver_id = ?";
            $check_user_stmt = $conn->prepare($check_user_query);
            $check_user_stmt->bind_param("i", $driver_id);
            $check_user_stmt->execute();
            $check_user_result = $check_user_stmt->get_result();
            
            if ($check_user_result->num_rows > 0) {
                // Update existing user
                $user = $check_user_result->fetch_assoc();
                
                if ($password) {
                    // Update with new password
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $update_user_query = "UPDATE users 
                                         SET email = ?, first_name = ?, last_name = ?, password_hash = ?, updated_at = NOW() 
                                         WHERE user_id = ?";
                    $update_user_stmt = $conn->prepare($update_user_query);
                    $update_user_stmt->bind_param("ssssi", $email, $first_name, $last_name, $password_hash, $user['user_id']);
                } else {
                    // Update without password
                    $update_user_query = "UPDATE users 
                                         SET email = ?, first_name = ?, last_name = ?, updated_at = NOW() 
                                         WHERE user_id = ?";
                    $update_user_stmt = $conn->prepare($update_user_query);
                    $update_user_stmt->bind_param("sssi", $email, $first_name, $last_name, $user['user_id']);
                }
                
                if (!$update_user_stmt->execute()) {
                    throw new Exception('Failed to update user account: ' . $update_user_stmt->error);
                }
            } else {
                // Create new user account if it doesn't exist
                // Generate a random password if not provided
                if (!$password) {
                    $temp_password = bin2hex(random_bytes(4)); // 8 character random password
                    $password = $temp_password;
                }
                
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $user_role_db = 'delivery';
                
                $insert_user_query = "INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, driver_id, status, created_at, updated_at) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())";
                $insert_user_stmt = $conn->prepare($insert_user_query);
                $insert_user_stmt->bind_param("sssssii", $email, $password_hash, $first_name, $last_name, $user_role_db, $branch_id, $driver_id);
                
                if (!$insert_user_stmt->execute()) {
                    throw new Exception('Failed to create user account: ' . $insert_user_stmt->error);
                }
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Driver and user account updated successfully'
            ]);
            exit;
        }
        
        // DELETE DRIVER
        elseif ($_POST['action'] === 'delete_driver') {
            $driver_id = (int)$_POST['driver_id'];
            
            // Verify driver belongs to user's branch
            if ($drivers_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT driver_id FROM drivers WHERE driver_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $driver_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Driver not found or access denied');
                }
            }
            
            // First, update the associated user account
            $update_user_query = "UPDATE users SET driver_id = NULL, status = 'inactive' WHERE driver_id = ?";
            $update_user_stmt = $conn->prepare($update_user_query);
            $update_user_stmt->bind_param("i", $driver_id);
            $update_user_stmt->execute();
            
            // Check if driver is used in any pick lists or trip tickets
            $check_picklist_query = "SELECT COUNT(*) as count FROM pick_lists WHERE driver_id = ?";
            $check_picklist_stmt = $conn->prepare($check_picklist_query);
            $check_picklist_stmt->bind_param("i", $driver_id);
            $check_picklist_stmt->execute();
            $picklist_count = $check_picklist_stmt->get_result()->fetch_assoc()['count'];
            
            $check_trip_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE driver_id = ?";
            $check_trip_stmt = $conn->prepare($check_trip_query);
            $check_trip_stmt->bind_param("i", $driver_id);
            $check_trip_stmt->execute();
            $trip_count = $check_trip_stmt->get_result()->fetch_assoc()['count'];
            
            if ($picklist_count > 0 || $trip_count > 0) {
                // Soft delete - just update status to inactive
                $update_driver_query = "UPDATE drivers SET status = 'inactive', updated_at = NOW() WHERE driver_id = ?";
                $update_driver_stmt = $conn->prepare($update_driver_query);
                $update_driver_stmt->bind_param("i", $driver_id);
                $update_driver_stmt->execute();
                
                $message = 'Driver and associated user marked as inactive (used in existing transactions)';
            } else {
                // Hard delete if not used
                $delete_driver_query = "DELETE FROM drivers WHERE driver_id = ?";
                $delete_driver_stmt = $conn->prepare($delete_driver_query);
                $delete_driver_stmt->bind_param("i", $driver_id);
                
                if (!$delete_driver_stmt->execute()) {
                    throw new Exception('Failed to delete driver');
                }
                
                $message = 'Driver and associated user deleted successfully';
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            exit;
        }
        
        // GET DRIVER DETAILS
        elseif ($_POST['action'] === 'get_driver') {
            $driver_id = (int)$_POST['driver_id'];
            
            // Build query with user info
            $query = "
                SELECT 
                    d.*,
                    u.user_id,
                    u.email,
                    u.first_name,
                    u.last_name,
                    u.role as user_role,
                    u.status as user_status
                FROM drivers d
                LEFT JOIN users u ON d.driver_id = u.driver_id
                WHERE d.driver_id = ?
            ";
            
            if ($drivers_branch_column_exists && !$view_all_branches) {
                $query .= " AND d.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $driver_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $driver_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $driver = $result->fetch_assoc();
            
            if ($driver) {
                echo json_encode([
                    'success' => true,
                    'driver' => $driver
                ]);
            } else {
                throw new Exception('Driver not found or access denied');
            }
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
}

// FETCH DRIVERS FROM DATABASE WITH USER INFO
$drivers_query = "
    SELECT 
        d.driver_id,
        d.driver_name,
        d.license_number,
        d.license_expiry,
        d.contact_number,
        d.vehicle_type,
        d.vehicle_plate_number,
        d.status as driver_status,
        d.branch_id,
        d.created_at,
        d.updated_at,
        u.user_id,
        u.email,
        u.first_name,
        u.last_name,
        u.status as user_status,
        CONCAT(u.first_name, ' ', u.last_name) as user_full_name
    FROM drivers d
    LEFT JOIN users u ON d.driver_id = u.driver_id
    WHERE 1=1
    $branch_condition
    ORDER BY d.driver_name ASC
";

$drivers_result = $conn->query($drivers_query);
if (!$drivers_result) {
    die("Query failed: " . $conn->error);
}
$drivers = $drivers_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS
$total_drivers = count($drivers);
$active_drivers = count(array_filter($drivers, fn($d) => $d['driver_status'] === 'active'));
$inactive_drivers = count(array_filter($drivers, fn($d) => $d['driver_status'] === 'inactive'));
$on_leave_drivers = count(array_filter($drivers, fn($d) => $d['driver_status'] === 'on-leave'));

// STAT CARD VALUES
$statTotalDrivers = $total_drivers;
$statActiveDrivers = $active_drivers;
$statInactiveDrivers = $inactive_drivers;
$statOnLeaveDrivers = $on_leave_drivers;

// Helper functions
function getDriverStatusClass($status) {
    return match($status) {
        'active' => 'bg-success',
        'inactive' => 'bg-secondary',
        'on-leave' => 'bg-warning text-dark',
        default => 'bg-secondary'
    };
}

function getDriverStatusText($status) {
    return match($status) {
        'active' => 'Active',
        'inactive' => 'Inactive',
        'on-leave' => 'On Leave',
        default => ucfirst($status)
    };
}

function getUserStatusClass($status) {
    return $status === 'active' ? 'bg-success' : 'bg-secondary';
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}

function formatDateTime($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y h:i A');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Management - Branch Admin</title>
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
        
        /* Alert for missing branch column */
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
        
        /* Table styles */
        .driver-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .driver-table thead th {
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
        
        .driver-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .driver-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Column widths */
        .col-name { width: 15%; }
        .col-license { width: 12%; }
        .col-contact { width: 12%; }
        .col-vehicle { width: 15%; }
        .col-status { width: 10%; }
        <?php if ($drivers_branch_column_exists && $view_all_branches): ?>
        .col-branch { width: 8%; }
        <?php endif; ?>
        .col-user { width: 15%; }
        .col-actions { width: 13%; text-align: center; }
        
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
        
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .status-on-leave { background-color: #fff3cd; color: #856404; }
        
        .user-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }
        
        .user-badge i {
            margin-right: 3px;
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
        
        .action-buttons {
            display: flex;
            gap: 5px;
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
        
        .license-expiry-warning {
            font-size: 11px;
            color: #dc3545;
            margin-top: 2px;
        }
        
        .license-expiry-ok {
            font-size: 11px;
            color: #28a745;
        }
        
        /* Modal styling */
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
        
        .password-note {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .password-note i {
            color: #856404;
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
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="current_inventory.php">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php">
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="drivers.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">Drivers</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
            
            <!-- User Profile Section -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
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
            <!-- DRIVER MANAGEMENT CONTENT -->
            <div id="dashboardContent" class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2><i class="bi bi-truck me-2"></i>Driver Management</h2>
                        <p id="dashboardSubtitle">
                            Manage drivers and their user accounts
                            <?php if ($drivers_branch_column_exists && !$view_all_branches): ?>
                                for your branch
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Branch Info Alerts -->
                <?php if (!$drivers_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for drivers not yet set up.</strong> Please run this SQL in phpMyAdmin:
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

                <!-- No Drivers Warning -->
                <?php if (empty($drivers) && $drivers_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No drivers found for your branch. Click "Add Driver" to create one.
                    </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-people stat-icon"></i>
                            <div class="stat-value"><?= $statTotalDrivers ?></div>
                            <div class="stat-label">Total Drivers</div>
                            <?php if ($drivers_branch_column_exists && !$view_all_branches): ?>
                                <small class="d-block text-white-50">Your Branch</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value"><?= $statActiveDrivers ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value"><?= $statOnLeaveDrivers ?></div>
                            <div class="stat-label">On Leave</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card rejected">
                            <i class="bi bi-x-circle stat-icon"></i>
                            <div class="stat-value"><?= $statInactiveDrivers ?></div>
                            <div class="stat-label">Inactive</div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION -->
                <div class="filter-section">
                    <div class="filter-controls">
                        <div class="filter-dropdowns">
                            <!-- Status Filter -->
                            <div class="filter-dropdown">
                                <select class="form-select" id="statusFilter" onchange="filterDrivers()">
                                    <option value="all">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="on-leave">On Leave</option>
                                </select>
                            </div>
                            
                            <!-- Search Box -->
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="searchInput" placeholder="Search by name, license, email..." onkeyup="filterDrivers()">
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button class="btn btn-outline-success" onclick="exportToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export
                        </button>
                        <button class="btn btn-primary" onclick="showAddDriverModal()">
                            <i class="bi bi-plus-circle me-1"></i> Add Driver
                        </button>
                    </div>
                </div>

                <!-- DRIVERS TABLE -->
                <div class="table-responsive">
                    <table class="table driver-table" id="driversTable">
                        <thead>
                            <tr>
                                <th class="col-name">DRIVER NAME</th>
                                <th class="col-license">LICENSE #</th>
                                <th class="col-license">EXPIRY</th>
                                <th class="col-contact">CONTACT</th>
                                <th class="col-vehicle">VEHICLE</th>
                                <th class="col-status">STATUS</th>
                                <?php if ($drivers_branch_column_exists && $view_all_branches): ?>
                                    <th class="col-branch">BRANCH</th>
                                <?php endif; ?>
                                <th class="col-user">USER ACCOUNT</th>
                                <th class="col-actions">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="driversTableBody">
                            <?php if (empty($drivers)): ?>
                            <tr>
                                <td colspan="<?= ($drivers_branch_column_exists && $view_all_branches) ? '9' : '8' ?>" class="empty-state-table">
                                    <i class="bi bi-truck"></i>
                                    <h5>No Drivers Found</h5>
                                    <p class="text-muted">Click "Add Driver" to create a new driver with user account.</p>
                                    <button class="btn btn-primary mt-2" onclick="showAddDriverModal()">
                                        <i class="bi bi-plus-circle me-1"></i> Add Driver
                                    </button>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($drivers as $driver): 
                                    // Check license expiry
                                    $license_expiry = $driver['license_expiry'] ? new DateTime($driver['license_expiry']) : null;
                                    $today = new DateTime();
                                    $days_to_expiry = $license_expiry ? $today->diff($license_expiry)->days : null;
                                    $expiry_warning = $license_expiry && $license_expiry < $today ? 'expired' : ($days_to_expiry && $days_to_expiry <= 30 ? 'warning' : 'ok');
                                ?>
                                <tr class="driver-row" 
                                    data-id="<?= $driver['driver_id'] ?>"
                                    data-name="<?= htmlspecialchars($driver['driver_name']) ?>"
                                    data-license="<?= htmlspecialchars($driver['license_number']) ?>"
                                    data-status="<?= $driver['driver_status'] ?>">
                                    <td class="col-name">
                                        <strong><?= htmlspecialchars($driver['driver_name']) ?></strong>
                                    </td>
                                    <td class="col-license"><?= htmlspecialchars($driver['license_number']) ?></td>
                                    <td class="col-license">
                                        <?= formatDate($driver['license_expiry']) ?>
                                        <?php if ($expiry_warning == 'expired'): ?>
                                            <span class="license-expiry-warning"><i class="bi bi-exclamation-triangle"></i> Expired</span>
                                        <?php elseif ($expiry_warning == 'warning'): ?>
                                            <span class="license-expiry-warning"><i class="bi bi-clock"></i> <?= $days_to_expiry ?> days</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-contact"><?= htmlspecialchars($driver['contact_number'] ?? 'N/A') ?></td>
                                    <td class="col-vehicle">
                                        <?= htmlspecialchars($driver['vehicle_type'] ?? 'N/A') ?>
                                        <small class="d-block text-muted"><?= htmlspecialchars($driver['vehicle_plate_number'] ?? '') ?></small>
                                    </td>
                                    <td class="col-status">
                                        <span class="status-badge <?= getDriverStatusClass($driver['driver_status']) ?>">
                                            <?= getDriverStatusText($driver['driver_status']) ?>
                                        </span>
                                    </td>
                                    <?php if ($drivers_branch_column_exists && $view_all_branches): ?>
                                        <td class="col-branch">
                                            <span class="badge bg-info">Branch <?= $driver['branch_id'] ?? 'N/A' ?></span>
                                        </td>
                                    <?php endif; ?>
                                    <td class="col-user">
                                        <?php if ($driver['user_id']): ?>
                                            <span class="user-badge">
                                                <i class="bi bi-person-check"></i> 
                                                <?= htmlspecialchars($driver['email']) ?>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($driver['first_name'] . ' ' . $driver['last_name']) ?></small>
                                                <br>
                                                <span class="badge <?= getUserStatusClass($driver['user_status']) ?>"><?= $driver['user_status'] ?></span>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">No account</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-actions">
                                        <div class="action-buttons">
                                            <button class="table-btn btn-view" onclick="viewDriver(<?= $driver['driver_id'] ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="table-btn btn-edit" onclick="editDriver(<?= $driver['driver_id'] ?>)" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="table-btn btn-delete" onclick="deleteDriver(<?= $driver['driver_id'] ?>)" title="Delete">
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

    <!-- ADD/EDIT DRIVER MODAL (Combined Form) -->
    <div class="modal fade" id="driverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="driverModalTitle"><i class="bi bi-plus-circle me-2"></i>Add New Driver</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="driverForm" onsubmit="return false;">
                        <input type="hidden" id="driverId" name="driver_id">
                        
                        <?php if ($drivers_branch_column_exists && !$view_all_branches): ?>
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle"></i>
                                Adding driver to your branch (Branch ID: <?= $branch_id ?>)
                            </div>
                        <?php endif; ?>
                        
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
                                    <label for="driverStatus" class="form-label">Driver Status</label>
                                    <select class="form-select" id="driverStatus" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="on-leave">On Leave</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- User Account Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-person-circle me-2"></i>User Account (for Login)
                            </div>
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle"></i>
                                This user account will be used by the driver to log in to the system. Password will be securely hashed.
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="firstName" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="firstName" name="first_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="lastName" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="lastName" name="last_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                                <div class="col-md-6" id="passwordField">
                                    <label for="password" class="form-label">Password *</label>
                                    <input type="password" class="form-control" id="password" name="password" minlength="6">
                                    <div class="password-note">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        Password will be securely hashed. Minimum 6 characters.
                                    </div>
                                </div>
                                <div class="col-12" id="passwordEditNote" style="display: none;">
                                    <div class="password-note">
                                        <i class="bi bi-info-circle"></i>
                                        Leave password blank to keep current password when editing.
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

    <!-- VIEW DRIVER MODAL -->
    <div class="modal fade" id="viewDriverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Driver Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row" id="viewDriverContent">
                        <!-- Content populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" onclick="editFromView()">Edit Driver</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteDriverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this driver?</p>
                    <p class="fw-bold" id="deleteDriverName"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        The associated user account will also be deactivated or deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteDriver()">Delete Driver</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentDriverId = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const driversBranchColumnExists = <?php echo $drivers_branch_column_exists ? 'true' : 'false'; ?>;
    
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

    // ========== DOM READY ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Driver Management with User Accounts - Combined Form");
        console.log("Branch ID:", branchId);
        console.log("View All Branches:", viewAllBranches);
        
        initializeSidebar();
        
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
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

        // Fix modal backdrop issue
        const modals = ['driverModal', 'viewDriverModal', 'deleteDriverModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('hidden.bs.modal', function () {
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                    document.body.style.removeProperty('overflow');
                });
            }
        });
    });

    // ========== FILTER FUNCTIONS ==========
    function filterDrivers() {
        const statusFilter = document.getElementById('statusFilter').value;
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        
        const rows = document.querySelectorAll('.driver-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            let showRow = true;
            
            // Status filter
            if (statusFilter !== 'all') {
                const rowStatus = row.dataset.status;
                if (rowStatus !== statusFilter) showRow = false;
            }
            
            // Search filter
            if (showRow && searchTerm !== '') {
                const name = row.dataset.name?.toLowerCase() || '';
                const license = row.dataset.license?.toLowerCase() || '';
                const rowText = row.innerText.toLowerCase();
                
                if (!name.includes(searchTerm) && !license.includes(searchTerm) && !rowText.includes(searchTerm)) {
                    showRow = false;
                }
            }
            
            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        });
    }

    // ========== MODAL FUNCTIONS ==========
    function showAddDriverModal() {
        document.getElementById('driverModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add New Driver';
        document.getElementById('driverForm').reset();
        document.getElementById('driverId').value = '';
        document.getElementById('driverStatus').value = 'active';
        
        // Show password field for new driver
        document.getElementById('passwordField').style.display = 'block';
        document.getElementById('password').required = true;
        document.getElementById('passwordEditNote').style.display = 'none';
        
        // Set default license expiry to 1 year from now
        const today = new Date();
        const oneYearFromNow = new Date(today);
        oneYearFromNow.setFullYear(today.getFullYear() + 1);
        document.getElementById('licenseExpiry').value = oneYearFromNow.toISOString().split('T')[0];
        
        new bootstrap.Modal(document.getElementById('driverModal')).show();
    }

    function viewDriver(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_driver');
        formData.append('driver_id', id);
        
        fetch('drivers.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const driver = data.driver;
                
                const expiryDate = driver.license_expiry ? new Date(driver.license_expiry).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Not set';
                const createdDate = driver.created_at ? new Date(driver.created_at).toLocaleString() : 'N/A';
                
                let branchHtml = '';
                if (driversBranchColumnExists && driver.branch_id) {
                    branchHtml = `
                        <tr>
                            <td class="detail-label">Branch:</td>
                            <td><span class="badge bg-info">Branch ${driver.branch_id}</span></td>
                        </tr>
                    `;
                }
                
                let userHtml = '';
                if (driver.user_id) {
                    userHtml = `
                        <tr>
                            <td class="detail-label">User Account:</td>
                            <td>
                                <span class="user-badge">
                                    <i class="bi bi-person-check"></i> ${driver.email}
                                </span>
                                <br>
                                <small>${driver.first_name} ${driver.last_name}</small>
                                <br>
                                <span class="badge ${driver.user_status === 'active' ? 'bg-success' : 'bg-secondary'}">${driver.user_status}</span>
                                <br>
                                <small class="text-muted">Role: ${driver.user_role}</small>
                            </td>
                        </tr>
                    `;
                } else {
                    userHtml = `
                        <tr>
                            <td class="detail-label">User Account:</td>
                            <td><span class="text-muted fst-italic">No user account</span></td>
                        </tr>
                    `;
                }
                
                const content = document.getElementById('viewDriverContent');
                content.innerHTML = `
                    <div class="col-md-6">
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-truck me-2"></i>Driver Information
                            </div>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td width="40%" class="detail-label">Driver Name:</td>
                                    <td class="fw-bold">${driver.driver_name}</td>
                                </tr>
                                <tr>
                                    <td class="detail-label">License Number:</td>
                                    <td>${driver.license_number}</td>
                                </tr>
                                <tr>
                                    <td class="detail-label">License Expiry:</td>
                                    <td>${expiryDate}</td>
                                </tr>
                                <tr>
                                    <td class="detail-label">Contact Number:</td>
                                    <td>${driver.contact_number || 'Not provided'}</td>
                                </tr>
                                <tr>
                                    <td class="detail-label">Vehicle Type:</td>
                                    <td>${driver.vehicle_type || 'Not specified'}</td>
                                </tr>
                                <tr>
                                    <td class="detail-label">Plate Number:</td>
                                    <td>${driver.vehicle_plate_number || 'Not specified'}</td>
                                </tr>
                                <tr>
                                    <td class="detail-label">Driver Status:</td>
                                    <td>
                                        <span class="status-badge ${getStatusClass(driver.status)}">
                                            ${getStatusText(driver.status)}
                                        </span>
                                    </td>
                                </tr>
                                ${branchHtml}
                            </table>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-person-circle me-2"></i>User Account
                            </div>
                            <table class="table table-sm table-borderless">
                                ${userHtml}
                                <tr>
                                    <td class="detail-label">Created:</td>
                                    <td>${createdDate}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                `;
                
                currentDriverId = id;
                new bootstrap.Modal(document.getElementById('viewDriverModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while fetching driver details', 'error');
        });
    }

    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewDriverModal')).hide();
        setTimeout(() => {
            editDriver(currentDriverId);
        }, 300);
    }

    function editDriver(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_driver');
        formData.append('driver_id', id);
        
        fetch('drivers.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const driver = data.driver;
                
                document.getElementById('driverModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Driver';
                document.getElementById('driverId').value = driver.driver_id;
                
                // Driver fields
                document.getElementById('driverName').value = driver.driver_name || '';
                document.getElementById('licenseNumber').value = driver.license_number || '';
                document.getElementById('licenseExpiry').value = driver.license_expiry || '';
                document.getElementById('contactNumber').value = driver.contact_number || '';
                document.getElementById('vehicleType').value = driver.vehicle_type || '';
                document.getElementById('vehiclePlate').value = driver.vehicle_plate_number || '';
                document.getElementById('driverStatus').value = driver.status || 'active';
                
                // User account fields
                document.getElementById('firstName').value = driver.first_name || '';
                document.getElementById('lastName').value = driver.last_name || '';
                document.getElementById('email').value = driver.email || '';
                
                // Hide password field for edit (optional)
                document.getElementById('passwordField').style.display = 'none';
                document.getElementById('password').required = false;
                document.getElementById('passwordEditNote').style.display = 'block';
                
                currentDriverId = id;
                new bootstrap.Modal(document.getElementById('driverModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while fetching driver details', 'error');
        });
    }

    function saveDriver() {
        // Validate required fields
        const driverName = document.getElementById('driverName').value.trim();
        const licenseNumber = document.getElementById('licenseNumber').value.trim();
        const firstName = document.getElementById('firstName').value.trim();
        const lastName = document.getElementById('lastName').value.trim();
        const email = document.getElementById('email').value.trim();
        const driverId = document.getElementById('driverId').value;
        
        if (!driverName) {
            Swal.fire('Warning', 'Driver Name is required', 'warning');
            return;
        }
        
        if (!licenseNumber) {
            Swal.fire('Warning', 'License Number is required', 'warning');
            return;
        }
        
        if (!firstName) {
            Swal.fire('Warning', 'First Name is required for user account', 'warning');
            return;
        }
        
        if (!lastName) {
            Swal.fire('Warning', 'Last Name is required for user account', 'warning');
            return;
        }
        
        if (!email) {
            Swal.fire('Warning', 'Email is required for user account', 'warning');
            return;
        }
        
        // Validate email format
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            Swal.fire('Warning', 'Please enter a valid email address', 'warning');
            return;
        }
        
        // Check password for new driver
        if (!driverId) {
            const password = document.getElementById('password').value.trim();
            if (!password) {
                Swal.fire('Warning', 'Password is required for new user account', 'warning');
                return;
            }
            
            if (password.length < 6) {
                Swal.fire('Warning', 'Password must be at least 6 characters long', 'warning');
                return;
            }
        }
        
        showLoading();
        
        const formData = new FormData(document.getElementById('driverForm'));
        
        if (driverId) {
            formData.append('action', 'update_driver');
        } else {
            formData.append('action', 'add_driver');
        }
        
        fetch('drivers.php', {
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
                    const modal = bootstrap.Modal.getInstance(document.getElementById('driverModal'));
                    if (modal) {
                        modal.hide();
                    }
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while saving driver', 'error');
        });
    }

    function deleteDriver(id) {
        const row = document.querySelector(`.driver-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deleteDriverName').textContent = row.dataset.name;
        currentDriverId = id;
        new bootstrap.Modal(document.getElementById('deleteDriverModal')).show();
    }

    function confirmDeleteDriver() {
        if (!currentDriverId) {
            Swal.fire('Error', 'No driver selected', 'error');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_driver');
        formData.append('driver_id', currentDriverId);
        
        fetch('drivers.php', {
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
                    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteDriverModal'));
                    if (modal) {
                        modal.hide();
                    }
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while deleting driver', 'error');
        });
    }

    // ========== EXCEL EXPORT ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.driver-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No drivers to export', 'warning');
            return;
        }
        
        const excelData = [];
        const headers = [
            'Driver Name',
            'License Number',
            'License Expiry',
            'Contact Number',
            'Vehicle Type',
            'Plate Number',
            'Driver Status',
            ...(driversBranchColumnExists && viewAllBranches ? ['Branch'] : []),
            'Email',
            'User First Name',
            'User Last Name',
            'User Status'
        ];
        excelData.push(headers);

        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let cellIndex = 0;
            
            const name = cells[cellIndex++]?.innerText || '';
            const license = cells[cellIndex++]?.innerText || '';
            const expiry = cells[cellIndex++]?.innerText || '';
            const contact = cells[cellIndex++]?.innerText || '';
            const vehicle = cells[cellIndex++]?.innerText || '';
            const status = cells[cellIndex++]?.innerText || '';
            
            let branch = '';
            if (driversBranchColumnExists && viewAllBranches) {
                branch = cells[cellIndex++]?.innerText || '';
            }
            
            const userCell = cells[cellIndex++]?.innerHTML || '';
            
            // Parse user info (simplified)
            let email = '', userFirstName = '', userLastName = '', userStatus = '';
            if (userCell.includes('person-check')) {
                const emailMatch = userCell.match(/>([^<]+)</);
                if (emailMatch) email = emailMatch[1].trim();
                
                const nameMatch = userCell.match(/<small[^>]*>([^<]+)</);
                if (nameMatch) {
                    const fullName = nameMatch[1].trim().split(' ');
                    userFirstName = fullName[0] || '';
                    userLastName = fullName.slice(1).join(' ') || '';
                }
                
                const statusMatch = userCell.match(/badge[^>]*>([^<]+)</);
                if (statusMatch) userStatus = statusMatch[1].trim();
            }
            
            const rowData = [
                name,
                license,
                expiry,
                contact,
                vehicle.split('\n')[0],
                vehicle.includes('\n') ? vehicle.split('\n')[1] : '',
                status,
                ...(driversBranchColumnExists && viewAllBranches ? [branch] : []),
                email,
                userFirstName,
                userLastName,
                userStatus
            ];
            
            excelData.push(rowData);
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        const colWidths = [
            { wch: 20 }, { wch: 15 }, { wch: 15 }, { wch: 15 }, 
            { wch: 15 }, { wch: 12 }, { wch: 10 },
            ...(driversBranchColumnExists && viewAllBranches ? [{ wch: 12 }] : []),
            { wch: 25 }, { wch: 15 }, { wch: 15 }, { wch: 12 }
        ];
        ws['!cols'] = colWidths;

        XLSX.utils.book_append_sheet(wb, ws, 'Drivers');

        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Drivers_${dateStr}`;
        if (driversBranchColumnExists && !viewAllBranches) {
            filename += `_Branch_${branchId}`;
        }
        filename += '.xlsx';

        XLSX.writeFile(wb, filename);
        
        Swal.fire({
            icon: 'success',
            title: 'Export Complete',
            text: 'Drivers exported successfully!',
            timer: 2000,
            showConfirmButton: false
        });
    }

    // ========== COPY SQL FUNCTION ==========
    function copySQL(table) {
        let sql = '';
        if (table === 'drivers') {
            sql = "ALTER TABLE drivers ADD COLUMN branch_id INT NULL;\nALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        }
        
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Copied!',
                text: 'SQL copied to clipboard',
                timer: 1500,
                showConfirmButton: false
            });
        });
    }

    // ========== HELPER FUNCTIONS ==========
    function getStatusClass(status) {
        const classes = {
            'active': 'bg-success text-white',
            'inactive': 'bg-secondary text-white',
            'on-leave': 'bg-warning text-dark'
        };
        return classes[status] || 'bg-secondary text-white';
    }

    function getStatusText(status) {
        const texts = {
            'active': 'Active',
            'inactive': 'Inactive',
            'on-leave': 'On Leave'
        };
        return texts[status] || status;
    }

    // ========== LOGOUT ==========
    function logout() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will be logged out of the system',
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
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showAddDriverModal();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
        }
    });
    </script>
</body>
</html>