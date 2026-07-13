<?php
// users.php (formerly drivers.php)

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


// Ensure optional user profile picture column exists and handle uploads
$profile_picture_column_exists = false;
$check_profile_picture_column = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
if ($check_profile_picture_column && $check_profile_picture_column->num_rows > 0) {
    $profile_picture_column_exists = true;
} else {
    $add_profile_picture_column = $conn->query("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL AFTER contact_number");
    if ($add_profile_picture_column) {
        $profile_picture_column_exists = true;
    }
}

function handleProfilePictureUpload($existing_path = null) {
    if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] === UPLOAD_ERR_NO_FILE) {
        return $existing_path;
    }
    if ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Profile picture upload failed');
    }
    if ($_FILES['profile_picture']['size'] > 5 * 1024 * 1024) {
        throw new Exception('Profile picture must not exceed 5MB');
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $original_name = $_FILES['profile_picture']['name'];
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        throw new Exception('Profile picture must be JPG, JPEG, PNG, GIF, or WEBP');
    }

    $tmp_name = $_FILES['profile_picture']['tmp_name'];
    $image_info = @getimagesize($tmp_name);
    if ($image_info === false) {
        throw new Exception('Uploaded profile picture is not a valid image');
    }

    $upload_dir = __DIR__ . '/../uploads/profile_pictures/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $file_name = 'profile_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target_path = $upload_dir . $file_name;
    if (!move_uploaded_file($tmp_name, $target_path)) {
        throw new Exception('Failed to save profile picture');
    }

    return 'uploads/profile_pictures/' . $file_name;
}

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

// Check if branch_id column exists in drivers table
$drivers_branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
}

// Determine branch filter condition
$branch_condition = "";
if (!$view_all_branches) {
    $branch_condition = "AND u.branch_id = $branch_id";
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
            $status = $_POST['status'] ?? 'active';
            
            // User account information
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            
            // Validate required fields
            if (empty($driver_name)) throw new Exception('Driver Name is required');
            if (empty($license_number)) throw new Exception('License Number is required');
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
            $insert_driver = $conn->prepare("INSERT INTO drivers (driver_name, license_number, license_expiry, contact_number, status, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $insert_driver->bind_param("sssssi", $driver_name, $license_number, $license_expiry, $contact_number, $status, $branch_id);
            
            if (!$insert_driver->execute()) {
                throw new Exception('Failed to add driver: ' . $insert_driver->error);
            }
            
            $driver_id = $conn->insert_id;
            
            // Hash the password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $profile_picture = handleProfilePictureUpload();
            
            // Insert user account with optional profile picture
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, driver_id, contact_number, profile_picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'delivery', ?, ?, ?, ?, 'active', NOW(), NOW())");
            $insert_user->bind_param("ssssiiss", $email, $password_hash, $first_name, $last_name, $branch_id, $driver_id, $contact_number, $profile_picture);
            
            if (!$insert_user->execute()) {
                throw new Exception('Failed to create user account: ' . $insert_user->error);
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Driver and user account created successfully'];
        }
        
        // ADD WAREHOUSE STAFF
        elseif ($_POST['action'] === 'add_warehouse') {
            // Get branch_id from session
            $branch_id = (int)$_SESSION['branch_id'];
            
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            
            // Validate
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            
            // Check email
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $profile_picture = handleProfilePictureUpload();
            
            // Insert warehouse staff with optional profile picture
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, category, contact_number, profile_picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'warehouse', ?, ?, ?, ?, ?, NOW(), NOW())");
            $insert_user->bind_param("ssssissss", $email, $password_hash, $first_name, $last_name, $branch_id, $category, $contact_number, $profile_picture, $status);
            
            if (!$insert_user->execute()) {
                throw new Exception('Failed to create warehouse staff: ' . $insert_user->error);
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Warehouse staff created successfully'];
        }
        
        // ADD SALES AGENT
        elseif ($_POST['action'] === 'add_sales') {
            // Get branch_id from session
            $branch_id = (int)$_SESSION['branch_id'];
            
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            
            // Validate
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            
            // Check email
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $profile_picture = handleProfilePictureUpload();
            
            // Insert sales agent with optional profile picture
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, contact_number, profile_picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'sales', ?, ?, ?, ?, NOW(), NOW())");
            $insert_user->bind_param("ssssisss", $email, $password_hash, $first_name, $last_name, $branch_id, $contact_number, $profile_picture, $status);
            
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
            
            // Validate
            if (empty($email)) throw new Exception('Email is required');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email format');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            if ($password && strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            
            // Check if email is already used by another user
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $check_email->bind_param("si", $email, $user_id);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) {
                throw new Exception('Email is already in use by another user');
            }
            
            $get_current_picture = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
            $get_current_picture->bind_param("i", $user_id);
            $get_current_picture->execute();
            $current_picture_data = $get_current_picture->get_result()->fetch_assoc();
            $profile_picture = handleProfilePictureUpload($current_picture_data['profile_picture'] ?? null);

            // Update driver if applicable
            if ($user_role_type === 'driver') {
                $driver_id = (int)$_POST['driver_id'];
                $driver_name = trim($_POST['driver_name']);
                $license_number = trim($_POST['license_number']);
                $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
                $vehicle_type = !empty($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : null;
                $vehicle_plate_number = !empty($_POST['vehicle_plate_number']) ? trim($_POST['vehicle_plate_number']) : null;
                $driver_status = $_POST['driver_status'] ?? $status;
                
                $update_driver = $conn->prepare("UPDATE drivers SET driver_name = ?, license_number = ?, license_expiry = ?, contact_number = ?, vehicle_type = ?, vehicle_plate_number = ?, status = ?, updated_at = NOW() WHERE driver_id = ?");
                $update_driver->bind_param("sssssssi", $driver_name, $license_number, $license_expiry, $contact_number, $vehicle_type, $vehicle_plate_number, $driver_status, $driver_id);
                
                if (!$update_driver->execute()) {
                    throw new Exception('Failed to update driver: ' . $update_driver->error);
                }
            }
            
            // Update user with email
            if ($password) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                if ($user_role_type === 'warehouse') {
                    $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, category = ?, profile_picture = ?, password_hash = ?, status = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("ssssssssi", $email, $first_name, $last_name, $contact_number, $category, $profile_picture, $password_hash, $status, $user_id);
                } else {
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, profile_picture = ?, password_hash = ?, status = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("sssssssi", $email, $first_name, $last_name, $contact_number, $profile_picture, $password_hash, $status, $user_id);
                }
            } else {
                if ($user_role_type === 'warehouse') {
                    $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, category = ?, profile_picture = ?, status = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("sssssssi", $email, $first_name, $last_name, $contact_number, $category, $profile_picture, $status, $user_id);
                } else {
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, profile_picture = ?, status = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("ssssssi", $email, $first_name, $last_name, $contact_number, $profile_picture, $status, $user_id);
                }
            }
            
            if (!$update_user->execute()) {
                throw new Exception('Failed to update user: ' . $update_user->error);
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'User updated successfully'];
        }
        
        // DELETE USER (Hard Delete)
        elseif ($_POST['action'] === 'delete_user') {
            $user_id = (int)$_POST['user_id'];
            
            // Get user details first to check if they have a driver_id
            $get_user = $conn->prepare("SELECT driver_id FROM users WHERE user_id = ?");
            $get_user->bind_param("i", $user_id);
            $get_user->execute();
            $user_data = $get_user->get_result()->fetch_assoc();
            $driver_id = $user_data['driver_id'] ?? null;
            
            // Delete from drivers table if they have a driver_id
            if ($driver_id) {
                $delete_driver = $conn->prepare("DELETE FROM drivers WHERE driver_id = ?");
                $delete_driver->bind_param("i", $driver_id);
                
                if (!$delete_driver->execute()) {
                    throw new Exception('Failed to delete driver record: ' . $delete_driver->error);
                }
            }
            
            // Delete the user from database
            $delete_user = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $delete_user->bind_param("i", $user_id);
            
            if (!$delete_user->execute()) {
                throw new Exception('Failed to delete user: ' . $delete_user->error);
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'User and associated records permanently deleted from database'];
        }
        
        // GET USER DETAILS
        elseif ($_POST['action'] === 'get_user') {
            $user_id = (int)$_POST['user_id'];
            
            $query = "SELECT u.*, d.driver_id, d.driver_name, d.license_number, d.license_expiry, d.status as driver_status 
                     FROM users u 
                     LEFT JOIN drivers d ON u.driver_id = d.driver_id 
                     WHERE u.user_id = ?";
            
            if (!$view_all_branches) {
                $query .= " AND u.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $user_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $user_id);
            }
            
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

// FETCH ALL USERS - This part only runs for normal page load, not for AJAX requests
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
        u.profile_picture,
        u.category,
        d.driver_id,
        d.driver_name,
        d.license_number,
        d.license_expiry,
        d.status as driver_status,
        b.branch_name
    FROM users u
    LEFT JOIN drivers d ON u.driver_id = d.driver_id
    LEFT JOIN branches b ON u.branch_id = b.branch_id
    WHERE u.role IN ('delivery', 'warehouse', 'sales') AND u.status = 'active'
    $branch_condition
    ORDER BY 
        CASE 
            WHEN u.role = 'delivery' THEN 1
            WHEN u.role = 'warehouse' THEN 2
            WHEN u.role = 'sales' THEN 3
        END,
        u.first_name ASC
";

$users_result = $conn->query($users_query);
if (!$users_result) {
    die("Query failed: " . $conn->error);
}
$users = $users_result->fetch_all(MYSQLI_ASSOC);

// Group users by role
$drivers = array_filter($users, function($u) { return $u['role'] === 'delivery'; });
$warehouse_staff = array_filter($users, function($u) { return $u['role'] === 'warehouse'; });
$sales_agents = array_filter($users, function($u) { return $u['role'] === 'sales'; });

// Statistics
$total_users = count($users);
$total_drivers = count($drivers);
$total_warehouse = count($warehouse_staff);
$total_sales = count($sales_agents);
$active_users = count(array_filter($users, function($u) { return $u['user_status'] === 'active'; }));

// Helper functions
function getUserRoleBadge($role) {
    switch($role) {
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
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Branch Admin</title>
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
        
        /* Table styles */
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
        .col-name { width: 24%; }
        .col-role { width: 13%; }
        .col-details { width: 25%; }
        .col-contact { width: 15%; }
        .col-status { width: 12%; }
        .col-branch { width: 11%; }
        
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
        
        /* Add User Buttons - Outside Filter */
        .add-user-buttons-wrapper {
            margin-bottom: 1.25rem;
            text-align: right;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .btn-add-driver,
        .btn-add-warehouse,
        .btn-add-sales,
        .btn-outline-success {
            background: linear-gradient(135deg, #047857, #059669) !important;
            color: white !important;
            border: none !important;
            border-radius: 10px;
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(4, 120, 87, 0.22);
            cursor: pointer;
        }
        
        .btn-add-driver:hover,
        .btn-add-warehouse:hover,
        .btn-add-sales:hover,
        .btn-outline-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(4, 120, 87, 0.32);
            background: linear-gradient(135deg, #059669, #44D34E) !important;
            color: white !important;
        }
        
        .btn-outline-success i,
        .btn-add-driver i,
        .btn-add-warehouse i,
        .btn-add-sales i {
            color: white !important;
        }


        /* ===== USERS SECTION ===== */
        .management-tabs {
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1.25rem;
            gap: 0.5rem;
        }
        .management-tabs .nav-link {
            border: none !important;
            border-radius: 12px 12px 0 0 !important;
            color: #052A47 !important;
            font-weight: 700 !important;
            padding: 0.8rem 1.1rem !important;
            background: #f8fafc !important;
        }
        .management-tabs .nav-link.active {
            background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
            color: #ffffff !important;
            box-shadow: 0 8px 18px rgba(4, 120, 87, 0.18) !important;
        }
        @media (max-width: 576px) {
            .management-tabs .nav-link {
                width: 100%;
                border-radius: 12px !important;
                text-align: center;
            }
        }
        
        @media (max-width: 768px) {
            .add-user-buttons-wrapper {
                justify-content: center;
                margin-bottom: 1rem;
                gap: 0.5rem;
            }
            
            .btn-add-driver,
            .btn-add-warehouse,
            .btn-add-sales,
            .btn-outline-success {
                padding: 0.5rem 0.8rem;
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 576px) {
            .add-user-buttons-wrapper {
                flex-wrap: wrap;
            }
            
            .btn-add-driver,
            .btn-add-warehouse,
            .btn-add-sales,
            .btn-outline-success {
                flex: 1;
                min-width: calc(50% - 0.5rem);
                text-align: center;
            }
        }
        
        /* ===== STAT CARDS - MATCHING BOTTOM LAYOUT ===== */
.stat-card-row {
    margin-bottom: 1.5rem;
}

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

/* Gradient backgrounds for each type */
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

.stat-card.delivery {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

/* Force text colors to white */
.stat-card .stat-value,
.stat-card .stat-label,
.stat-card .stat-content,
.stat-card small,
.stat-card small i,
.stat-card .badge {
    color: white !important;
}

/* Remove any white background from children */
.stat-card .stat-content,
.stat-card .stat-icon {
    background: transparent !important;
}

/* Hover effect */
.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}

/* ===== MOBILE: SQUARE CARDS WITH CENTERED ICON ===== */
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

/* ===== DESKTOP: HORIZONTAL LAYOUT ===== */
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

/* ===== TABLET (768px - 991px) ===== */
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

/* ===== EXTRA SMALL MOBILE (below 400px) ===== */
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

/* ===== LANDSCAPE MODE ===== */
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
/* ===== FILTER SECTION - COLLAPSIBLE DESIGN ===== */
.form-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.filter-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1.25rem;
    border-bottom: 1px solid #e9ecef;
    cursor: pointer;
}

.filter-header h5 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: #333;
}

.filter-header h5 i {
    margin-right: 0.5rem;
    color: #047857;
}

.filter-toggle-btn {
    background: none;
    border: none;
    color: #6c757d;
    font-size: 1.2rem;
    padding: 0;
    cursor: pointer;
    transition: transform 0.3s ease;
}

.filter-toggle-btn:focus {
    outline: none;
}

.filter-content {
    max-height: 500px;
    overflow: hidden;
    transition: max-height 0.3s ease, padding 0.3s ease;
    padding: 1rem 1.25rem;
}

.filter-content.collapsed {
    max-height: 0;
    padding: 0 1.25rem;
}

/* Remove large white space - compact spacing */
.filter-content .row {
    margin-left: 0;
    margin-right: 0;
}

.filter-content .col-12 {
    padding-left: 0.5rem;
    padding-right: 0.5rem;
}

.filter-content .form-label {
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: #495057;
    display: block;
}

.filter-content .form-label i {
    margin-right: 0.25rem;
    font-size: 0.7rem;
}

.filter-content .form-select,
.filter-content .form-control {
    font-size: 0.85rem;
    border-radius: 8px;
    border: 1px solid #dee2e6;
    padding: 0.4rem 0.75rem;
    height: auto;
}

.filter-content .form-select:focus,
.filter-content .form-control:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
}

/* Search box styling */
.search-box {
    position: relative;
}

.search-box input {
    padding-left: 2rem;
    padding-right: 0.75rem;
}

.search-box i {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 0.9rem;
    z-index: 1;
}

/* ===== USERS TABLE - MOBILE CARD VIEW (MALINIS) ===== */
@media (max-width: 768px) {
    /* Itago ang table header */
    .user-table thead {
        display: none;
    }
    
    /* Gawing block ang bawat row */
    .user-table tbody,
    .user-table tr,
    .user-table td {
        display: block;
        width: 100%;
    }
    
    /* Card style para sa bawat user */
    .user-table tbody tr {
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        margin-bottom: 1rem;
        padding: 1rem;
        position: relative;
        border: none;
        cursor: pointer;
    }
    
    /* Alisin lahat ng borders */
    .user-table tbody td {
        padding: 0;
        border: none !important;
        background: transparent;
    }
    
    /* NAME cell - nasa kaliwa */
    .user-table tbody td:first-child {
        text-align: left !important;
        padding-right: 80px;
    }
    
    .user-table tbody td:first-child strong {
        font-size: 1rem;
        color: #212529;
        font-weight: 600;
        display: block;
    }
    
    .user-table tbody td:first-child small {
        font-size: 0.7rem;
        color: #6c757d;
        display: block;
        margin-top: 0.25rem;
    }
    
    /* ROLE badge - nasa ilalim ng email */
    .user-table tbody td:nth-child(2) {
        margin-top: 0.5rem;
        margin-bottom: 0;
        text-align: left !important;
        border: none !important;
    }
    
    .user-table tbody td:nth-child(2) span {
        display: inline-block;
        padding: 4px 16px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        border: none;
    }
    
    /* Role badge colors */
    .user-table tbody tr.role-delivery td:nth-child(2) span {
        background: #e3f2fd;
        color: #0d6efd;
    }
    
    .user-table tbody tr.role-warehouse td:nth-child(2) span {
        background: #e8f5e9;
        color: #198754;
    }
    
    .user-table tbody tr.role-sales td:nth-child(2) span {
        background: #fff3e0;
        color: #f59e0b;
    }
    
    /* Itago ang DETAILS column */
    .user-table tbody td:nth-child(3) {
        display: none !important;
    }
    
    /* STATUS badge - SA RIGHT TOP SIDE */
    .user-table tbody td:nth-child(4) {
        position: absolute !important;
        top: 1rem !important;
        right: 1rem !important;
        left: auto !important;
        padding: 0 !important;
        margin: 0 !important;
        width: auto !important;
        display: block !important;
        text-align: right !important;
        border: none !important;
    }
    
    .user-table tbody td:nth-child(4) .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 500;
        min-width: auto;
        border: none;
    }
    
    .status-active {
        background: #e8f5e9;
        color: #198754;
    }
    
    .status-inactive {
        background: #ffebee;
        color: #dc3545;
    }
    
    /* Itago ang BRANCH column sa mobile */
    .user-table tbody td:nth-child(5) {
        display: none !important;
    }
    
    /* Itago ang buong action buttons cell */
    .user-table tbody td:last-child {
        display: none !important;
    }
    
    /* "tap to view" sa kanang ibaba - gaya ng supplier table */
    .user-table tbody tr::after {
    content: "tap to view" !important;
    position: absolute !important;
    bottom: 12px !important;
    right: 12px !important;
    font-size: 0.65rem !important;
    color: #9ca3af !important;
    background: transparent !important;
    padding: 2px 8px !important;
    border-radius: 20px !important;
    pointer-events: auto !important;
    cursor: pointer !important;
    z-index: 5 !important;
}
.user-table tbody tr::after:hover {
    color: #0d6efd !important;
    text-decoration: underline !important;
}
}

/* ===== DESKTOP - normal table view ===== */
@media (min-width: 769px) {
    .user-table .btn-edit,
    .user-table .btn-delete {
        display: none !important;
    }
    
    .action-buttons {
        display: flex;
        justify-content: center;
    }
    
    /* Sa desktop, itago ang tap to view text */
    .user-table tbody tr::after {
        display: none !important;
    }
    
    /* Ipakita ang action buttons cell sa desktop */
    .user-table tbody td:last-child {
        display: table-cell !important;
    }
}

/* ===== EXTRA SMALL MOBILE (below 400px) ===== */
@media (max-width: 399px) {
    .user-table tbody tr {
        padding: 0.75rem;
    }
    
    .user-table tbody td:first-child strong {
        font-size: 0.9rem;
    }
    
    .user-table tbody td:first-child small {
        font-size: 0.65rem;
    }
    
    .user-table tbody td:nth-child(2) span,
    .user-table tbody td:nth-child(4) .status-badge {
        padding: 3px 12px;
        font-size: 0.7rem;
    }
    
    .user-table tbody tr::after {
        font-size: 0.6rem !important;
        bottom: 8px !important;
        right: 8px !important;
    }
}

/* ===== LANDSCAPE MODE ===== */
@media (max-height: 500px) and (orientation: landscape) {
    .user-table tbody tr {
        padding: 0.5rem;
        margin-bottom: 0.5rem;
    }
    
    .user-table tbody td:first-child strong {
        font-size: 0.8rem;
    }
    
    .user-table tbody td:first-child small {
        font-size: 0.6rem;
    }
    
    .user-table tbody tr::after {
        font-size: 0.55rem !important;
        bottom: 6px !important;
        right: 6px !important;
    }
}

/* ===== EMPTY STATE MOBILE STYLES ===== */
@media (max-width: 768px) {
    .user-table tbody td.empty-state-table {
        display: block;
        text-align: center;
        padding: 2rem 1rem;
    }
    
    .user-table tbody td.empty-state-table i {
        font-size: 3rem;
        margin-bottom: 1rem;
    }
    
    .user-table tbody td.empty-state-table h5 {
        font-size: 1rem;
        margin-bottom: 0.5rem;
    }
    
    .user-table tbody td.empty-state-table p {
        font-size: 0.8rem;
    }
}
/* ===== MODAL STYLES - CONSISTENT WITH OTHER PAGES ===== */

/* Modal Container */
.modal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Modal Header - Green Gradient (same as other pages) */
.modal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.5rem !important;
    flex-shrink: 0 !important;
}

.modal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

.modal .modal-header .modal-title i {
    font-size: 1.2rem !important;
}

.modal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
    background: transparent !important;
    transition: all 0.2s ease !important;
}

.modal .modal-header .btn-close:hover {
    opacity: 1 !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
.modal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 !important;
    background: #f8fafc !important;
}

/* Modal Body Scrollbar */
.modal .modal-body::-webkit-scrollbar {
    width: 6px;
}

.modal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.modal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

.modal .modal-body::-webkit-scrollbar-thumb:hover {
    background: #047857;
}

/* Modal Footer */
.modal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
}

.modal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

.modal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

.modal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

.modal .modal-footer .btn-primary {
    background: linear-gradient(135deg, #047857, #44D34E) !important;
    border: none !important;
    color: white !important;
}

.modal .modal-footer .btn-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(68, 211, 78, 0.3) !important;
}

.modal .modal-footer .btn-success {
    background: linear-gradient(135deg, #059669, #10b981) !important;
    border: none !important;
    color: white !important;
}

.modal .modal-footer .btn-success:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

/* ===== FORM SECTIONS INSIDE MODAL ===== */
.modal .form-section {
    background: white !important;
    border-radius: 12px !important;
    padding: 1.25rem !important;
    margin-bottom: 1.25rem !important;
    border: 1px solid #e9ecef !important;
    transition: all 0.2s ease !important;
}

.modal .form-section:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
}

.modal .form-section-title {
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    margin-bottom: 1rem !important;
    padding-bottom: 0.5rem !important;
    border-bottom: 2px solid #44D34E !important;
    color: #047857 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

.modal .form-section-title i {
    font-size: 1rem !important;
    color: #44D34E !important;
}

/* Form Labels */
.modal .form-label {
    font-size: 0.75rem !important;
    font-weight: 600 !important;
    color: #374151 !important;
    margin-bottom: 0.35rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.35rem !important;
}

.modal .form-label i {
    color: #047857 !important;
    font-size: 0.8rem !important;
}

.modal .form-label .text-danger {
    color: #dc3545 !important;
    font-size: 0.7rem !important;
}

/* Form Controls */
.modal .form-control,
.modal .form-select {
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    padding: 0.6rem 0.85rem !important;
    font-size: 0.85rem !important;
    transition: all 0.2s ease !important;
    background-color: #ffffff !important;
    width: 100% !important;
}

.modal .form-control:focus,
.modal .form-select:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15) !important;
    outline: none !important;
}

.modal .form-control:read-only,
.modal .form-control[readonly] {
    background-color: #f1f5f9 !important;
    cursor: not-allowed !important;
    color: #475569 !important;
}

/* Password Note */
.modal .password-note {
    background-color: #fef3c7 !important;
    border-left: 4px solid #f59e0b !important;
    padding: 0.5rem 0.75rem !important;
    border-radius: 8px !important;
    font-size: 0.7rem !important;
    margin-top: 0.5rem !important;
    color: #92400e !important;
}

.modal .password-note i {
    color: #f59e0b !important;
    margin-right: 0.25rem !important;
}

/* ===== RESPONSIVE MODAL STYLES ===== */
@media (max-width: 768px) {
    .modal .modal-dialog {
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
    }
    
    .modal .modal-header {
        padding: 0.875rem 1rem !important;
    }
    
    .modal .modal-header .modal-title {
        font-size: 1rem !important;
    }
    
    .modal .modal-body {
        padding: 1rem !important;
    }
    
    .modal .modal-footer {
        padding: 0.75rem 1rem !important;
    }
    
    .modal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.8rem !important;
    }
    
    .modal .form-section {
        padding: 1rem !important;
        margin-bottom: 1rem !important;
    }
    
    .modal .form-section-title {
        font-size: 0.85rem !important;
        margin-bottom: 0.75rem !important;
    }
    
    .modal .form-label {
        font-size: 0.7rem !important;
    }
    
    .modal .form-control,
    .modal .form-select {
        padding: 0.5rem 0.7rem !important;
        font-size: 0.8rem !important;
    }
}

@media (max-width: 576px) {
    .modal .modal-header {
        padding: 0.75rem !important;
    }
    
    .modal .modal-body {
        padding: 0.75rem !important;
    }
    
    .modal .modal-footer {
        padding: 0.6rem 0.75rem !important;
        gap: 0.5rem !important;
    }
    
    .modal .modal-footer .btn {
        padding: 0.4rem 0.4rem !important;
        font-size: 0.75rem !important;
    }
    
    .modal .form-section {
        padding: 0.75rem !important;
    }
    
    .modal .form-section-title {
        font-size: 0.8rem !important;
    }
    
    .modal .form-label {
        font-size: 0.65rem !important;
    }
    
    .modal .form-control,
    .modal .form-select {
        padding: 0.45rem 0.6rem !important;
        font-size: 0.75rem !important;
    }
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    .modal .modal-body {
        max-height: calc(100vh - 120px) !important;
    }
    
    .modal .form-section {
        padding: 0.75rem !important;
        margin-bottom: 0.75rem !important;
    }
    
    .modal .form-section-title {
        font-size: 0.8rem !important;
        margin-bottom: 0.5rem !important;
    }
}
    
/* ===== USERS SECTION ===== */
.management-tabs {
    border-bottom: 2px solid #e5e7eb !important;
    margin-top: 0.5rem !important;
}

.management-tabs .nav-link {
    color: #052A47 !important;
    font-weight: 700 !important;
    border: none !important;
    border-bottom: 3px solid transparent !important;
    border-radius: 10px 10px 0 0 !important;
    padding: 0.75rem 1.2rem !important;
}

.management-tabs .nav-link.active {
    color: #047857 !important;
    background: #ecfdf5 !important;
    border-bottom-color: #44D34E !important;
}

.management-pane.d-none {
    display: none !important;
}

    
        .profile-picture-preview {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e9ecef;
            background: #f8f9fa;
        }

        /* Optional Profile Picture Upload UI - circle, top-left, with live preview */
        .row > [class*="col-"]:has(.profile-upload-wrapper) {
            order: -999;
            flex: 0 0 100% !important;
            max-width: 100% !important;
            width: 100% !important;
        }
        .profile-upload-wrapper {
            width: 100%;
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            margin-bottom: 10px;
        }
        .profile-upload-box {
            width: 112px;
            height: 112px;
            border-radius: 50%;
            border: 3px solid #d1fae5;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            padding: 0;
            transition: all 0.2s ease;
            cursor: pointer !important;
            margin: 0;
            box-shadow: 0 4px 12px rgba(4, 120, 87, 0.12);
        }
        .profile-upload-box:hover {
            border-color: #44D34E;
            box-shadow: 0 8px 18px rgba(4, 120, 87, 0.20);
            transform: translateY(-1px) scale(1.02);
        }
        .profile-upload-box * {
            cursor: pointer !important;
        }
        .profile-picture-input {
            display: none !important;
        }
        .profile-upload-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            background: #f8f9fa;
        }
        .profile-upload-overlay {
            position: absolute;
            inset: auto 0 0 0;
            height: 40%;
            background: rgba(5, 42, 71, 0.72);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .profile-upload-box:hover .profile-upload-overlay {
            opacity: 1;
        }
        .profile-upload-caption {
            margin-left: 14px;
            padding-top: 20px;
            min-width: 0;
        }
        .profile-upload-text {
            color: #052A47;
            font-weight: 700;
            font-size: 0.9rem;
            line-height: 1.2;
            display: block;
        }
        .profile-upload-hint {
            color: #6b7280;
            font-size: 0.76rem;
            line-height: 1.2;
            display: block;
            margin-top: 3px;
        }
        .profile-upload-filename {
            max-width: 240px;
            color: #047857;
            background: #d1fae5;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 7px;
            display: inline-block;
        }
        @media (max-width: 576px) {
            .profile-upload-box {
                width: 96px;
                height: 96px;
            }
            .profile-upload-caption {
                padding-top: 12px;
                margin-left: 12px;
            }
            .profile-upload-filename {
                max-width: 170px;
            }
        }

        .profile-picture-placeholder {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #047857, #44D34E);
            color: #fff;
            font-size: 2rem;
            border: 3px solid #e9ecef;
        }
        .profile-upload-box::before {
            content: "\F4D7";
            font-family: "bootstrap-icons";
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.2rem;
            color: #94a3b8;
            background: #e5e7eb;
            z-index: 0;
        }
        .profile-upload-preview {
            position: relative;
            z-index: 1;
        }
        .profile-upload-preview[src=""],
        .profile-upload-preview:not([src]) {
            opacity: 0;
        }
        .profile-upload-overlay {
            z-index: 2;
        }
        .user-details-profile-top-left {
            order: -999;
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            gap: 18px;
            margin-bottom: 18px;
            text-align: left !important;
        }
        .user-details-profile-top-left .profile-picture-preview,
        .user-details-profile-top-left .profile-picture-placeholder {
            width: 150px;
            height: 150px;
            flex: 0 0 150px;
            font-size: 4rem;
            border: 4px solid #d1fae5;
            box-shadow: 0 8px 20px rgba(4, 120, 87, 0.16);
        }
        .user-details-profile-meta {
            padding-top: 18px;
        }
        @media (max-width: 576px) {
            .user-details-profile-top-left .profile-picture-preview,
            .user-details-profile-top-left .profile-picture-placeholder {
                width: 118px;
                height: 118px;
                flex-basis: 118px;
                font-size: 3rem;
            }
            .user-details-profile-meta { padding-top: 8px; }
        }


        /* Match Global/drivers.php add-user modal header colors */
        #driverModal .modal-header { background-color: #0d6efd !important; color: #ffffff !important; }
        #warehouseModal .modal-header { background-color: #198754 !important; color: #ffffff !important; }
        #salesModal .modal-header { background-color: #ffc107 !important; color: #212529 !important; }
        #driverModal .modal-title, #warehouseModal .modal-title { color: #ffffff !important; }
        #salesModal .modal-title { color: #212529 !important; }
        #driverModal .btn-close, #warehouseModal .btn-close { filter: brightness(0) invert(1) !important; }
        #salesModal .btn-close { filter: none !important; }
        
/* Action Bar with Search */
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
    justify-content: flex-end;
}

.search-bar-wrapper {
    width: 360px;  /* Changed from 280px to 340px */
    flex-shrink: 0;
}

.search-bar-wrapper .input-group {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.search-bar-wrapper .input-group-text {
    background: #f8fafc;
    border: none;
    color: #94a3b8;
    padding: 0.4rem 0.6rem;
    font-size: 0.8rem;
}

.search-bar-wrapper .form-control {
    border: none;
    padding: 0.4rem 0.6rem;
    font-size: 0.8rem;
    height: 34px;
    background: #f8fafc;
}

.search-bar-wrapper .form-control:focus {
    border: none;
    outline: none;
    box-shadow: none;
    background: white;
}

.search-bar-wrapper .form-control::placeholder {
    color: #94a3b8;
    font-size: 0.75rem;
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
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="branchdashboard.php">
                        <i class="bi bi-speedometer2"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>

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
                                <a class="nav-link active" href="drivers.php">
                                    <i class="bi bi-people-fill"></i>
                                    <span class="nav-text">Users</span>
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
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2><i class="bi bi-people me-2"></i>User Management</h2>
                        <p id="dashboardSubtitle">
                            Manage all users in your branch - Drivers, Warehouse Staff, and Sales Agents
                            <?php if (!$view_all_branches): ?>
                                for your branch
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

               <!-- Stats Cards -->
<div class="row stat-card-row g-1 g-sm-2 mb-4">
    <!-- Stat 1: Total Users -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-people stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $total_users ?></div>
                <div class="stat-label">Total Users</div>
            </div>
        </div>
    </div>
    
    <!-- Stat 2: Drivers -->
    <div class="col">
        <div class="stat-card delivery">
            <i class="bi bi-truck stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $total_drivers ?></div>
                <div class="stat-label">Drivers</div>
            </div>
        </div>
    </div>
    
    <!-- Stat 3: Warehouse -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-building stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $total_warehouse ?></div>
                <div class="stat-label">Warehouse</div>
            </div>
        </div>
    </div>
    
    <!-- Stat 4: Sales Agents -->
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-graph-up stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $total_sales ?></div>
                <div class="stat-label">Sales Agents</div>
            </div>
        </div>
    </div>
</div>
               <!-- FILTER SECTION - COLLAPSIBLE DESIGN (Entire header clickable) -->
<div class="form-card mb-4" id="filterCard">
    <div class="filter-header" id="filterHeader" style="cursor: pointer;">
        <h5><i class="bi bi-funnel"></i> Filter Users</h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false"><i class="bi bi-chevron-down" id="filterIcon"></i></button>
    </div>
    <div class="filter-content collapsed" id="filterContent">
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label"><i class="bi bi-person-badge"></i> User Type</label>
                <select class="form-select" id="userTypeFilter" onchange="filterUsers()">
                    <option value="all">All Users</option>
                    <option value="delivery">Drivers Only</option>
                    <option value="warehouse">Warehouse Only</option>
                    <option value="sales">Sales Agents Only</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label"><i class="bi bi-flag"></i> Status</label>
                <select class="form-select" id="statusFilter" onchange="filterUsers()">
                    <option value="all">All Status</option>
                    <option value="active">Active Only</option>
                    <option value="inactive">Inactive Only</option>
                </select>
            </div>
            <?php if ($view_all_branches): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label"><i class="bi bi-building"></i> Branch</label>
                <select class="form-select" id="branchFilter" onchange="filterUsers()">
                    <option value="all">All Branches</option>
                    <?php
                    $branches_for_filter = "SELECT branch_id, branch_name FROM branches ORDER BY branch_name";
                    $branches_filter_result = $conn->query($branches_for_filter);
                    while ($branch = $branches_filter_result->fetch_assoc()):
                    ?>
                    <option value="<?= $branch['branch_id'] ?>"><?= htmlspecialchars($branch['branch_name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
                <div class="action-bar">
    <div class="search-bar-wrapper">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" id="searchInput" placeholder="Search by name, email, role..." onkeyup="filterUsers()">
        </div>
    </div>
    <div class="action-buttons-group">
        <button class="btn-outline-success" onclick="exportToExcel()"><i class="bi bi-file-earmark-excel me-1"></i> Export</button>
        <button class="btn-add-driver" onclick="showAddDriverModal()"><i class="bi bi-truck me-1"></i> Add Driver</button>
        <button class="btn-add-warehouse" onclick="showAddWarehouseModal()"><i class="bi bi-building me-1"></i> Add Warehouse</button>
        <button class="btn-add-sales" onclick="showAddSalesModal()"><i class="bi bi-graph-up me-1"></i> Add Sales</button>
    </div>
</div>

                <div id="usersPane" class="management-pane">
                    <div class="table-responsive">
                        <table class="table user-table" id="usersTable">
                            <thead>
                                <tr>
                                    <th class="col-name">NAME</th>
                                    <th class="col-role">ROLE</th>
                                    <th class="col-details">DETAILS</th>
                                    <th class="col-contact">CONTACT</th>
                                    <th class="col-status">STATUS</th>
                                    <?php if ($view_all_branches): ?>
                                        <th class="col-branch">BRANCH</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="<?= $view_all_branches ? '6' : '5' ?>" class="empty-state-table">
                                        <i class="bi bi-people"></i>
                                        <h5>No Users Found</h5>
                                        <p class="text-muted">Click one of the Add buttons above to create users.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                    <tr class="user-row role-<?= $user['role'] ?>" 
                                        onclick="viewUser(<?= $user['user_id'] ?>)"
                                        style="cursor: pointer;"
                                        data-id="<?= $user['user_id'] ?>"
                                        data-role="<?= $user['role'] ?>"
                                        data-status="<?= $user['user_status'] ?>"
                                        data-branch="<?= htmlspecialchars($user['branch_id'] ?? '') ?>">
                                        <td class="col-name">
                                            <strong><?= htmlspecialchars($user['full_name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                                        </td>
                                        <td class="col-role"><?= getUserRoleText($user['role']) ?></td>
                                        <td class="col-details">
                                            <div class="details-text">
                                            <?php if ($user['role'] === 'delivery'): ?>
                                                <i class="bi bi-card-text"></i> License: <?= htmlspecialchars($user['license_number'] ?? 'N/A') ?><br>
                                                <i class="bi bi-calendar"></i> Exp: <?= formatDate($user['license_expiry']) ?>
                                            <?php elseif ($user['role'] === 'warehouse'): ?>
                                                <i class="bi bi-briefcase"></i> Category: <?= htmlspecialchars($user['category'] ?? 'General') ?>
                                            <?php elseif ($user['role'] === 'sales'): ?>
                                                <i class="bi bi-briefcase"></i> Position: Sales Agent
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="col-contact">
                                            <i class="bi bi-telephone"></i>
                                            <?= htmlspecialchars($user['contact_number'] ?? 'N/A') ?>
                                        </td>
                                        <td class="col-status">
                                            <span class="status-badge <?= getUserStatusClass($user['user_status']) ?>">
                                                <?= ucfirst($user['user_status']) ?>
                                            </span>
                                        </td>
                                        <?php if ($view_all_branches): ?>
                                            <td class="col-branch">
                                                <span class="badge bg-info"><?= htmlspecialchars($user['branch_name'] ?? 'Branch '.$user['branch_id']) ?></span>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

    <!-- ADD/EDIT DRIVER MODAL -->
    <div class="modal fade" id="driverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #0d6efd; color: white;">
                    <h5 class="modal-title"><i class="bi bi-truck me-2"></i><span id="driverModalTitle">Add New Driver</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="driverForm" enctype="multipart/form-data" onsubmit="return false;">
                        <input type="hidden" id="driverId" name="driver_id">
                        <input type="hidden" id="driverUserId" name="user_id">
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-truck me-2"></i>Driver Information</div>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">Driver Name *</label><input type="text" class="form-control" id="driverName" name="driver_name" required></div>
                                <div class="col-md-6"><label class="form-label">License # *</label><input type="text" class="form-control" id="licenseNumber" name="license_number" required></div>
                                <div class="col-md-6"><label class="form-label">License Expiry</label><input type="date" class="form-control" id="licenseExpiry" name="license_expiry"></div>
                                <div class="col-md-6"><label class="form-label">Contact</label><input type="text" class="form-control" id="contactNumber" name="contact_number" placeholder="09-1234-5678"></div>
                                <div class="col-md-6"><label class="form-label">Vehicle Type</label><select class="form-select" id="vehicleType" name="vehicle_type"><option value="">Select</option><option value="Van">Van</option><option value="Truck">Truck</option><option value="Motorcycle">Motorcycle</option><option value="Car">Car</option></select></div>
                                <div class="col-md-6"><label class="form-label">Plate #</label><input type="text" class="form-control" id="vehiclePlate" name="vehicle_plate_number"></div>
                                <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" id="driverStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                            </div>
                        </div>
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-person-circle me-2"></i>User Account</div>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">First Name *</label><input type="text" class="form-control" id="driverFirstName" name="first_name" required></div>
                                <div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" class="form-control" id="driverLastName" name="last_name" required></div>
                                <div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" id="driverEmail" name="email" required></div>
                                <div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" id="driverPassword" name="password" minlength="6"><div class="password-note" id="driverPasswordNote"><i class="bi bi-info-circle"></i> Required for new users.</div></div>
                                <div class="col-md-6"><label class="form-label">Profile Picture <small class="text-muted">(optional)</small></label><div class="profile-upload-wrapper"><label class="profile-upload-box">
                                            <input type="file" class="profile-picture-input" name="profile_picture" accept="image/*">
                                            <img class="profile-upload-preview" src="" alt="Profile preview">
                                            <span class="profile-upload-overlay"><i class="bi bi-camera-fill"></i></span>
                                        </label>
                                        <div class="profile-upload-caption">
                                            <span class="profile-upload-text">Profile Picture</span>
                                            <span class="profile-upload-hint">Optional • JPG, PNG, GIF, WEBP up to 5MB</span>
                                            <span class="profile-upload-filename">No file selected</span>
                                        </div>
                                    </div></div>
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

    <!-- ADD/EDIT WAREHOUSE MODAL -->
    <div class="modal fade" id="warehouseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #198754; color: white;">
                    <h5 class="modal-title"><i class="bi bi-building me-2"></i><span id="warehouseModalTitle">Add Warehouse Staff</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="warehouseForm" enctype="multipart/form-data" onsubmit="return false;">
                        <input type="hidden" id="warehouseId" name="user_id">
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-person-badge me-2"></i>Staff Information</div>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">First Name *</label><input type="text" class="form-control" id="warehouseFirstName" name="first_name" required></div>
                                <div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" class="form-control" id="warehouseLastName" name="last_name" required></div>
                                <div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" id="warehouseEmail" name="email" required></div>
                                <div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" id="warehousePassword" name="password" minlength="6"><div class="password-note" id="warehousePasswordNote"><i class="bi bi-info-circle"></i> Required for new users.</div></div>
                                <div class="col-md-6"><label class="form-label">Profile Picture <small class="text-muted">(optional)</small></label><div class="profile-upload-wrapper"><label class="profile-upload-box">
                                            <input type="file" class="profile-picture-input" name="profile_picture" accept="image/*">
                                            <img class="profile-upload-preview" src="" alt="Profile preview">
                                            <span class="profile-upload-overlay"><i class="bi bi-camera-fill"></i></span>
                                        </label>
                                        <div class="profile-upload-caption">
                                            <span class="profile-upload-text">Profile Picture</span>
                                            <span class="profile-upload-hint">Optional • JPG, PNG, GIF, WEBP up to 5MB</span>
                                            <span class="profile-upload-filename">No file selected</span>
                                        </div>
                                    </div></div>
                                <div class="col-md-6"><label class="form-label">Category</label><select class="form-select" id="warehouseCategory" name="category"><option value="">Select Category</option><option value="Oil">Oil</option><option value="Cement">Cement</option><option value="General">General</option><option value="Lubricants">Lubricants</option><option value="Chemicals">Chemicals</option><option value="Tools">Tools</option><option value="Equipment">Equipment</option></select></div>
                                <div class="col-md-6"><label class="form-label">Contact</label><input type="text" class="form-control" id="warehouseContact" name="contact_number" placeholder="09-1234-5678"></div>
                                <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" id="warehouseStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
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

    <!-- ADD/EDIT SALES MODAL -->
    <div class="modal fade" id="salesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #ffc107; color: #212529;">
                    <h5 class="modal-title"><i class="bi bi-graph-up me-2"></i><span id="salesModalTitle">Add Sales Agent</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="salesForm" enctype="multipart/form-data" onsubmit="return false;">
                        <input type="hidden" id="salesId" name="user_id">
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-person-workspace me-2"></i>Agent Information</div>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">First Name *</label><input type="text" class="form-control" id="salesFirstName" name="first_name" required></div>
                                <div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" class="form-control" id="salesLastName" name="last_name" required></div>
                                <div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" id="salesEmail" name="email" required></div>
                                <div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" id="salesPassword" name="password" minlength="6"><div class="password-note" id="salesPasswordNote"><i class="bi bi-info-circle"></i> Required for new users.</div></div>
                                <div class="col-md-6"><label class="form-label">Profile Picture <small class="text-muted">(optional)</small></label><div class="profile-upload-wrapper"><label class="profile-upload-box">
                                            <input type="file" class="profile-picture-input" name="profile_picture" accept="image/*">
                                            <img class="profile-upload-preview" src="" alt="Profile preview">
                                            <span class="profile-upload-overlay"><i class="bi bi-camera-fill"></i></span>
                                        </label>
                                        <div class="profile-upload-caption">
                                            <span class="profile-upload-text">Profile Picture</span>
                                            <span class="profile-upload-hint">Optional • JPG, PNG, GIF, WEBP up to 5MB</span>
                                            <span class="profile-upload-filename">No file selected</span>
                                        </div>
                                    </div></div>
                                <div class="col-md-6"><label class="form-label">Contact</label><input type="text" class="form-control" id="salesContact" name="contact_number" placeholder="09-1234-5678"></div>
                                <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" id="salesStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
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

    <!-- VIEW USER MODAL -->
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

    <!-- DELETE CONFIRMATION MODAL -->
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
    let currentUserId = null;
    let currentUserRole = null;
    let currentDriverId = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    let globalScrollTimeout; // Single scroll timeout variable

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
        const modals = ['driverModal', 'warehouseModal', 'salesModal', 'viewUserModal', 'deleteUserModal'];
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
        
        // Call setActiveMobileNav
        setActiveMobileNav();
    });

    // ========== FILTER FUNCTIONS ==========
    function filterUsers() {
        const userType = document.getElementById('userTypeFilter')?.value || 'all';
        const status = document.getElementById('statusFilter')?.value || 'all';
        const branch = document.getElementById('branchFilter')?.value || 'all';
        const search = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
        
        document.querySelectorAll('.user-row').forEach(row => {
            let show = true;
            
            if (userType !== 'all' && row.dataset.role !== userType) show = false;
            if (show && status !== 'all' && row.dataset.status !== status) show = false;
            if (show && branch !== 'all' && String(row.dataset.branch || '') !== String(branch)) show = false;
            
            if (show && search) {
                const text = row.innerText.toLowerCase();
                show = text.includes(search);
            }
            
            row.style.display = show ? '' : 'none';
        });
    }

    // ========== MODAL FUNCTIONS ==========
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
        resetProfileUpload('driverForm');
        
        new bootstrap.Modal(document.getElementById('driverModal')).show();
    }


    function showAddWarehouseModal() {
        document.getElementById('warehouseForm').reset();
        document.getElementById('warehouseId').value = '';
        document.getElementById('warehouseModalTitle').textContent = 'Add Warehouse Staff';
        document.getElementById('warehousePassword').required = true;
        document.getElementById('warehousePasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Required for new users.';
        resetProfileUpload('warehouseForm');
        
        new bootstrap.Modal(document.getElementById('warehouseModal')).show();
    }

    function showAddSalesModal() {
        document.getElementById('salesForm').reset();
        document.getElementById('salesId').value = '';
        document.getElementById('salesModalTitle').textContent = 'Add Sales Agent';
        document.getElementById('salesPassword').required = true;
        document.getElementById('salesPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Required for new users.';
        resetProfileUpload('salesForm');
        
        new bootstrap.Modal(document.getElementById('salesModal')).show();
    }



    function getProfilePictureHtml(u) {
        const fullName = `${u.first_name || ''} ${u.last_name || ''}`.trim();
        const roleText = u.role === 'delivery' ? 'Driver' : (u.role === 'branch_admin' ? 'Branch Admin' : (u.role === 'warehouse' ? 'Warehouse Staff' : 'Sales Agent'));
        const src = getProfilePictureSrc(u.profile_picture);
        const profileVisual = u.profile_picture
            ? `<img src="${src}" alt="Profile Picture" class="profile-picture-preview">`
            : `<div class="profile-picture-placeholder"><i class="bi bi-person-fill"></i></div>`;
        return `<div class="col-12 user-details-profile-top-left">${profileVisual}<div class="user-details-profile-meta"><div class="fw-bold fs-5">${fullName || 'User'}</div><small class="text-muted">${u.profile_picture ? 'Profile Picture' : 'Default profile icon'}</small><div class="mt-2"><span class="badge bg-success">${roleText}</span></div></div></div>`;
    }

    const DEFAULT_PROFILE_ICON = 'data:image/svg+xml;utf8,' + encodeURIComponent(`
        <svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160">
            <rect width="160" height="160" rx="80" fill="#e5e7eb"/>
            <circle cx="80" cy="58" r="30" fill="#94a3b8"/>
            <path d="M28 140c8-33 29-51 52-51s44 18 52 51" fill="#94a3b8"/>
        </svg>
    `);

    function getProfilePictureSrc(profilePicture) {
        if (!profilePicture || String(profilePicture).trim() === '') return DEFAULT_PROFILE_ICON;
        const src = String(profilePicture).trim();
        if (src.startsWith('http') || src.startsWith('data:') || src.startsWith('../')) return src;
        return '../' + src;
    }

    function setProfileUploadPreview(formId, profilePicture = '', labelText = '') {
        const form = document.getElementById(formId);
        if (!form) return;
        const input = form.querySelector('input[name="profile_picture"]');
        const preview = form.querySelector('.profile-upload-preview');
        const fileName = form.querySelector('.profile-upload-filename');
        if (input) input.value = '';
        if (preview) preview.src = getProfilePictureSrc(profilePicture);
        if (fileName) fileName.textContent = labelText || (profilePicture ? 'Current profile picture' : 'No file selected');
    }

    function resetProfileUpload(formId) {
        setProfileUploadPreview(formId, '', 'No file selected');
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
                
                if (u.role === 'delivery') {
                    html = `
                        <div class="col-md-6">
                            <div class="form-section">
                                <h6 class="fw-bold mb-3">Driver Information</h6>
                                <table class="table table-sm">
                                     <tr><td>Driver Name: <td class="fw-bold">${u.driver_name || u.full_name}</td></tr>
                                     <tr><td>License: <td>${u.license_number || 'N/A'}</td></tr>
                                     <tr><td>Expiry: <td>${u.license_expiry ? new Date(u.license_expiry).toLocaleDateString() : 'Not set'}</td></tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-section">
                                <h6 class="fw-bold mb-3">User Account</h6>
                                <table class="table table-sm">
                                     <tr><td>Name: <td>${u.first_name} ${u.last_name}</td></tr>
                                     <tr><td>Email: <td>${u.email}</td></tr>
                                     <tr><td>Contact: <td>${u.contact_number || 'Not provided'}</td></tr>
                                     <tr><td>Status: <td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr>
                                     <tr><td>Created: <td>${created}</td></tr>
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
                                     <tr><td>Name: <td class="fw-bold">${u.first_name} ${u.last_name}</td></tr>
                                     <tr><td>Email: <td>${u.email}</td></tr>
                                     <tr><td>Category: <td>${u.category || 'General'}</td></tr>
                                     <tr><td>Contact: <td>${u.contact_number || 'Not provided'}</td></tr>
                                     <tr><td>Status: <td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr>
                                     <tr><td>Created: <td>${created}</td></tr>
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
                                     <tr><td>Name: <td class="fw-bold">${u.first_name} ${u.last_name}</td></tr>
                                     <tr><td>Email: <td>${u.email}</td></tr>
                                     <tr><td>Contact: <td>${u.contact_number || 'Not provided'}</td></tr>
                                     <tr><td>Status: <td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr>
                                     <tr><td>Created: <td>${created}</td></tr>
                                </table>
                            </div>
                        </div>
                    `;
                }
                
                document.getElementById('viewUserContent').innerHTML = getProfilePictureHtml(u) + html;
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
                
                if (u.role === 'delivery') {
                    document.getElementById('driverModalTitle').textContent = 'Edit Driver';
                    document.getElementById('driverId').value = u.driver_id || '';
                    document.getElementById('driverUserId').value = u.user_id;
                    document.getElementById('driverName').value = u.driver_name || '';
                    document.getElementById('licenseNumber').value = u.license_number || '';
                    document.getElementById('licenseExpiry').value = u.license_expiry || '';
                    document.getElementById('contactNumber').value = u.contact_number || '';
                    document.getElementById('driverStatus').value = u.status || 'active';
                    document.getElementById('driverFirstName').value = u.first_name || '';
                    document.getElementById('driverLastName').value = u.last_name || '';
                    document.getElementById('driverEmail').value = u.email || '';
                    
                    document.getElementById('driverPassword').required = false;
                    document.getElementById('driverPassword').value = '';
                    document.getElementById('driverPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Leave blank to keep current password.';
                    setProfileUploadPreview('driverForm', u.profile_picture, u.profile_picture ? 'Current profile picture' : 'Default profile icon');
                    
                    new bootstrap.Modal(document.getElementById('driverModal')).show();
                    
                } else if (u.role === 'warehouse') {
                    document.getElementById('warehouseModalTitle').textContent = 'Edit Warehouse Staff';
                    document.getElementById('warehouseId').value = u.user_id;
                    document.getElementById('warehouseFirstName').value = u.first_name || '';
                    document.getElementById('warehouseLastName').value = u.last_name || '';
                    document.getElementById('warehouseEmail').value = u.email || '';
                    document.getElementById('warehouseCategory').value = u.category || '';
                    document.getElementById('warehouseContact').value = u.contact_number || '';
                    document.getElementById('warehouseStatus').value = u.status || 'active';
                    
                    document.getElementById('warehousePassword').required = false;
                    document.getElementById('warehousePassword').value = '';
                    document.getElementById('warehousePasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Leave blank to keep current password.';
                    setProfileUploadPreview('warehouseForm', u.profile_picture, u.profile_picture ? 'Current profile picture' : 'Default profile icon');
                    
                    new bootstrap.Modal(document.getElementById('warehouseModal')).show();
                    
                } else if (u.role === 'sales') {
                    document.getElementById('salesModalTitle').textContent = 'Edit Sales Agent';
                    document.getElementById('salesId').value = u.user_id;
                    document.getElementById('salesFirstName').value = u.first_name || '';
                    document.getElementById('salesLastName').value = u.last_name || '';
                    document.getElementById('salesEmail').value = u.email || '';
                    document.getElementById('salesContact').value = u.contact_number || '';
                    document.getElementById('salesStatus').value = u.status || 'active';
                    
                    document.getElementById('salesPassword').required = false;
                    document.getElementById('salesPassword').value = '';
                    document.getElementById('salesPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Leave blank to keep current password.';
                    setProfileUploadPreview('salesForm', u.profile_picture, u.profile_picture ? 'Current profile picture' : 'Default profile icon');
                    
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

    function saveDriver() {
        const driverId = document.getElementById('driverId').value;
        const driverName = document.getElementById('driverName').value.trim();
        const licenseNumber = document.getElementById('licenseNumber').value.trim();
        const firstName = document.getElementById('driverFirstName').value.trim();
        const lastName = document.getElementById('driverLastName').value.trim();
        const email = document.getElementById('driverEmail').value.trim();
        const password = document.getElementById('driverPassword').value;
        
        if (!driverName) return Swal.fire('Warning', 'Driver Name is required', 'warning');
        if (!licenseNumber) return Swal.fire('Warning', 'License Number is required', 'warning');
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
        
        if (!firstName) return Swal.fire('Warning', 'First Name is required', 'warning');
        if (!lastName) return Swal.fire('Warning', 'Last Name is required', 'warning');
        if (!email) return Swal.fire('Warning', 'Email is required', 'warning');
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
        
        if (!firstName) return Swal.fire('Warning', 'First Name is required', 'warning');
        if (!lastName) return Swal.fire('Warning', 'Last Name is required', 'warning');
        if (!email) return Swal.fire('Warning', 'Email is required', 'warning');
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

    // ========== EXCEL EXPORT ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.user-row:not([style*="display: none"])');
        if (!rows.length) {
            return Swal.fire('Warning', 'No users to export', 'warning');
        }
        
        const data = [['Full Name', 'Email', 'Role', 'Details', 'Contact', 'Status']];
        if (viewAllBranches) data[0].push('Branch');
        
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
                cells[i++]?.innerText.trim() || '',
                cells[i++]?.innerText.split('\n')[0] || ''
            ];
            if (viewAllBranches) rowData.push(cells[i++]?.innerText || '');
            data.push(rowData);
        });
        
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), 'Users');
        XLSX.writeFile(wb, `Users_${new Date().toISOString().slice(0,10).replace(/-/g, '')}${!viewAllBranches ? '_Branch_'+branchId : ''}.xlsx`);
        
        Swal.fire({ icon: 'success', title: 'Export Complete', timer: 1500, showConfirmButton: false });
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

   function showProfileModal() { 
    cleanupModalBackdrops();
    new bootstrap.Modal(document.getElementById('profileModal')).show(); 
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
    

    // ========== KEYBOARD SHORTCUTS ==========
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === '1') {
            e.preventDefault();
            showAddDriverModal();
        } else if (e.ctrlKey && e.key === '2') {
            e.preventDefault();
            showAddWarehouseModal();
        } else if (e.ctrlKey && e.key === '3') {
            e.preventDefault();
            showAddSalesModal();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
        }
    });

    
// ========== MOBILE BOTTOM NAVBAR FIX ==========
window.closeAllMobileDropdowns = function() {
    document.querySelectorAll('.more-dropdown').forEach(el=>{
        el.classList.remove('show');
    });

    document.querySelectorAll('.more-btn').forEach(btn=>{
        btn.classList.remove('active','has-active');
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

    window.closeAllMobileDropdowns();

    if(!isOpen){
        dropdown.classList.add('show');
        btn.classList.add('active');
        btn.setAttribute('aria-expanded','true');
    }

    return false;
};

window.toggleDropdown=function(event,dropdownId){
    return window.toggleMobileDropdown(event,dropdownId);
};

window.showProfileModal=function(event){
    if(event){
        event.preventDefault();
        event.stopPropagation();
    }

    if(typeof cleanupModalBackdrops==='function'){
        cleanupModalBackdrops();
    }

    window.closeAllMobileDropdowns();

    const modal=document.getElementById('profileModal');
    if(modal){
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    return false;
};

document.addEventListener('click',function(e){
    if(!e.target.closest('.mobile-nav')){
        window.closeAllMobileDropdowns();
    }
});

document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
        window.closeAllMobileDropdowns();
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

    // ========== MOBILE NAV ACTIVE STATE (UPDATED) ==========
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
    // Filter toggle functionality - ENTIRE HEADER CLICKABLE
document.addEventListener('DOMContentLoaded', function() {
    const filterHeader = document.getElementById('filterHeader');
    const filterContent = document.getElementById('filterContent');
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterIcon = document.getElementById('filterIcon');
    
    if (filterHeader && filterContent) {
        // Set initial state - collapsed
        filterContent.classList.add('collapsed');
        if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
        
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
        } else {
            // Expand
            filterContent.classList.remove('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'true');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-down');
                filterIcon.classList.add('bi-chevron-up');
            }
        }
    }
});
// Add tap to view functionality for user rows
// Action column has been removed, so the whole user row opens the details modal.
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.user-table tbody tr.user-row').forEach(function(row) {
        row.style.cursor = 'pointer';
    });
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
</script>

<script>
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('profile-picture-input')) return;

    const uploadWrapper = e.target.closest('.profile-upload-wrapper');
    const uploadBox = e.target.closest('.profile-upload-box');
    const fileName = uploadWrapper ? uploadWrapper.querySelector('.profile-upload-filename') : null;
    const preview = uploadBox ? uploadBox.querySelector('.profile-upload-preview') : null;
    const file = e.target.files && e.target.files.length ? e.target.files[0] : null;

    if (fileName) {
        fileName.textContent = file ? file.name : 'No file selected';
    }

    if (preview && file) {
        const reader = new FileReader();
        reader.onload = function(event) {
            preview.src = event.target.result;
        };
        reader.readAsDataURL(file);
    }
});

document.addEventListener('reset', function(e) {
    setTimeout(function() {
        e.target.querySelectorAll('.profile-upload-filename').forEach(function(el) {
            el.textContent = 'No file selected';
        });
        e.target.querySelectorAll('.profile-upload-preview').forEach(function(img) {
            img.src = (typeof DEFAULT_PROFILE_ICON !== 'undefined' ? DEFAULT_PROFILE_ICON : '');
        });
    }, 0);
});

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
</script>

</body>
</html>