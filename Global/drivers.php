<?php
// Global drivers.php - User Management for Global Admin

// Turn off error display completely for AJAX requests
if (isset($_POST['action'])) {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
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
$view_all_branches = true;

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

// Check if business_unit column exists in branches table
$branches_business_unit_column_exists = false;
$check_branch_bu_column = $conn->query("SHOW COLUMNS FROM branches LIKE 'business_unit'");
if ($check_branch_bu_column && $check_branch_bu_column->num_rows > 0) {
    $branches_business_unit_column_exists = true;
}

// Get all active branches for dropdowns
$branches_sql = "SELECT branch_id, branch_name, branch_code" . ($branches_business_unit_column_exists ? ", business_unit" : "") . " FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = $conn->query($branches_sql);
$branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $branches[] = $row;
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $conn->begin_transaction();
        
        if ($_POST['action'] === 'add_driver') {
            $driver_name = trim($_POST['driver_name'] ?? '');
            $license_number = trim($_POST['license_number'] ?? '');
            $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $vehicle_type = !empty($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : null;
            $vehicle_plate_number = !empty($_POST['vehicle_plate_number']) ? trim($_POST['vehicle_plate_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            
            if (empty($driver_name)) throw new Exception('Driver Name is required');
            if (empty($license_number)) throw new Exception('License Number is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters long');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) throw new Exception('Email already exists');
            
            $check_license = $conn->prepare("SELECT driver_id FROM drivers WHERE license_number = ?");
            $check_license->bind_param("s", $license_number);
            $check_license->execute();
            if ($check_license->get_result()->num_rows > 0) throw new Exception('License number already exists');
            
            $insert_driver = $conn->prepare("INSERT INTO drivers (driver_name, license_number, license_expiry, contact_number, vehicle_type, vehicle_plate_number, status, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $insert_driver->bind_param("sssssssi", $driver_name, $license_number, $license_expiry, $contact_number, $vehicle_type, $vehicle_plate_number, $status, $branch_id);
            if (!$insert_driver->execute()) throw new Exception('Failed to add driver');
            $driver_id = $conn->insert_id;
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $profile_picture = handleProfilePictureUpload();
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, driver_id, contact_number, profile_picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'delivery', ?, ?, ?, ?, ?, NOW(), NOW())");
            $insert_user->bind_param("ssssiisss", $email, $password_hash, $first_name, $last_name, $branch_id, $driver_id, $contact_number, $profile_picture, $status);
            if (!$insert_user->execute()) throw new Exception('Failed to create user account');
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Driver and user account created successfully'];
        }
        elseif ($_POST['action'] === 'add_branch_admin') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            $business_unit = trim($_POST['business_unit'] ?? '');
            $business_unit_custom = trim($_POST['business_unit_custom'] ?? '');
            if (strtolower($business_unit) === 'etc') {
                $business_unit = $business_unit_custom;
            }
            
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) throw new Exception('Email already exists');
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $profile_picture = handleProfilePictureUpload();
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, contact_number, profile_picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'branch_admin', ?, ?, ?, ?, NOW(), NOW())");
            $insert_user->bind_param("ssssisss", $email, $password_hash, $first_name, $last_name, $branch_id, $contact_number, $profile_picture, $status);
            if (!$insert_user->execute()) throw new Exception('Failed to create branch admin');
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Branch admin created successfully'];
        }
        elseif ($_POST['action'] === 'add_branch') {
            $branch_name = trim($_POST['branch_name'] ?? '');
            $branch_code = trim($_POST['branch_code'] ?? '');
            $status = $_POST['status'] ?? 'active';
            $business_unit = trim($_POST['business_unit'] ?? '');
            $business_unit_custom = trim($_POST['business_unit_custom'] ?? '');

            if (empty($branch_name)) throw new Exception('Branch Name is required');
            if (empty($branch_code)) throw new Exception('Branch Code is required');
            if (strtolower($business_unit) === 'etc') {
                $business_unit = $business_unit_custom;
            }
            $business_unit = trim($business_unit);
            if (empty($business_unit)) throw new Exception('Business Unit is required');

            $check_branch_name = $conn->prepare("SELECT branch_id FROM branches WHERE branch_name = ?");
            $check_branch_name->bind_param("s", $branch_name);
            $check_branch_name->execute();
            if ($check_branch_name->get_result()->num_rows > 0) throw new Exception('Branch name already exists');

            $check_branch_code = $conn->prepare("SELECT branch_id FROM branches WHERE branch_code = ?");
            $check_branch_code->bind_param("s", $branch_code);
            $check_branch_code->execute();
            if ($check_branch_code->get_result()->num_rows > 0) throw new Exception('Branch code already exists');

            if (!$branches_business_unit_column_exists) {
                throw new Exception("The branches table does not have a business_unit column yet. Please run: ALTER TABLE branches ADD COLUMN business_unit VARCHAR(100) NOT NULL AFTER branch_code;");
            }

            $insert_branch = $conn->prepare("INSERT INTO branches (branch_name, branch_code, business_unit, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
            $insert_branch->bind_param("ssss", $branch_name, $branch_code, $business_unit, $status);
            if (!$insert_branch->execute()) throw new Exception('Failed to add branch');

            $conn->commit();
            $response = ['success' => true, 'message' => 'Branch created successfully'];
        }
        elseif ($_POST['action'] === 'add_warehouse') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) throw new Exception('Email already exists');
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $profile_picture = handleProfilePictureUpload();
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, category, contact_number, profile_picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'warehouse', ?, ?, ?, ?, ?, NOW(), NOW())");
            $insert_user->bind_param("ssssissss", $email, $password_hash, $first_name, $last_name, $branch_id, $category, $contact_number, $profile_picture, $status);
            if (!$insert_user->execute()) throw new Exception('Failed to create warehouse staff');
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Warehouse staff created successfully'];
        }
        elseif ($_POST['action'] === 'add_sales') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) throw new Exception('Email already exists');
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $profile_picture = handleProfilePictureUpload();
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, contact_number, profile_picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'sales', ?, ?, ?, ?, NOW(), NOW())");
            $insert_user->bind_param("ssssisss", $email, $password_hash, $first_name, $last_name, $branch_id, $contact_number, $profile_picture, $status);
            if (!$insert_user->execute()) throw new Exception('Failed to create sales agent');
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Sales agent created successfully'];
        }
        elseif ($_POST['action'] === 'update_user') {
            $user_id = (int)$_POST['user_id'];
            $user_role_type = $_POST['user_role_type'];
            $email = trim($_POST['email'] ?? '');
            $password = !empty($_POST['password']) ? $_POST['password'] : null;
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            $business_unit = trim($_POST['business_unit'] ?? '');
            $business_unit_custom = trim($_POST['business_unit_custom'] ?? '');
            if (strtolower($business_unit) === 'etc') {
                $business_unit = $business_unit_custom;
            }
            $business_unit = trim($business_unit);
            
            if (empty($email)) throw new Exception('Email is required');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email format');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            if ($password && strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $check_email->bind_param("si", $email, $user_id);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) throw new Exception('Email is already in use');
            
            $get_current_picture = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
            $get_current_picture->bind_param("i", $user_id);
            $get_current_picture->execute();
            $current_picture_data = $get_current_picture->get_result()->fetch_assoc();
            $profile_picture = handleProfilePictureUpload($current_picture_data['profile_picture'] ?? null);

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
                if (!$update_driver->execute()) throw new Exception('Failed to update driver');
            }
            
            if ($password) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                if ($user_role_type === 'warehouse') {
                    $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, category = ?, profile_picture = ?, password_hash = ?, status = ?, branch_id = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("ssssssssii", $email, $first_name, $last_name, $contact_number, $category, $profile_picture, $password_hash, $status, $branch_id, $user_id);
                } else {
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, profile_picture = ?, password_hash = ?, status = ?, branch_id = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("sssssssii", $email, $first_name, $last_name, $contact_number, $profile_picture, $password_hash, $status, $branch_id, $user_id);
                }
            } else {
                if ($user_role_type === 'warehouse') {
                    $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, category = ?, profile_picture = ?, status = ?, branch_id = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("sssssssii", $email, $first_name, $last_name, $contact_number, $category, $profile_picture, $status, $branch_id, $user_id);
                } else {
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, profile_picture = ?, status = ?, branch_id = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("ssssssii", $email, $first_name, $last_name, $contact_number, $profile_picture, $status, $branch_id, $user_id);
                }
            }
            if (!$update_user->execute()) throw new Exception('Failed to update user');

            if ($user_role_type === 'branch_admin' && $branch_id > 0 && $branches_business_unit_column_exists) {
                if (empty($business_unit)) throw new Exception('Business Unit is required');
                $update_branch_bu = $conn->prepare("UPDATE branches SET business_unit = ?, updated_at = NOW() WHERE branch_id = ?");
                $update_branch_bu->bind_param("si", $business_unit, $branch_id);
                if (!$update_branch_bu->execute()) throw new Exception('Failed to update business unit');
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'User updated successfully'];
        }
        elseif ($_POST['action'] === 'delete_user') {
            $user_id = (int)$_POST['user_id'];
            $get_user = $conn->prepare("SELECT driver_id FROM users WHERE user_id = ?");
            $get_user->bind_param("i", $user_id);
            $get_user->execute();
            $user_data = $get_user->get_result()->fetch_assoc();
            $driver_id = $user_data['driver_id'] ?? null;
            
            $update_user = $conn->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE user_id = ?");
            $update_user->bind_param("i", $user_id);
            if (!$update_user->execute()) throw new Exception('Failed to deactivate user');
            
            if ($driver_id) {
                $update_driver = $conn->prepare("UPDATE drivers SET status = 'inactive', updated_at = NOW() WHERE driver_id = ?");
                $update_driver->bind_param("i", $driver_id);
                $update_driver->execute();
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'User deactivated successfully'];
        }
        elseif ($_POST['action'] === 'get_user') {
            $user_id = (int)$_POST['user_id'];
            $query = "SELECT u.*, d.driver_id, d.driver_name, d.license_number, d.license_expiry, d.vehicle_type, d.vehicle_plate_number, d.status as driver_status, b.branch_name, b.business_unit 
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
        
        http_response_code(200);
        $json_response = json_encode($response, JSON_UNESCAPED_SLASHES);
        ob_end_clean();
        echo $json_response;
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("User Management Error: " . $e->getMessage());
        http_response_code(400);
        $json_response = json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        ob_end_clean();
        echo $json_response;
        exit;
    }
}

// FETCH ALL USERS
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
$users = $users_result->fetch_all(MYSQLI_ASSOC);

$branch_admins = array_filter($users, function($u) { return $u['role'] === 'branch_admin'; });
$drivers = array_filter($users, function($u) { return $u['role'] === 'delivery'; });
$warehouse_staff = array_filter($users, function($u) { return $u['role'] === 'warehouse'; });
$sales_agents = array_filter($users, function($u) { return $u['role'] === 'sales'; });

$total_branch_admins = count($branch_admins);
$total_drivers = count($drivers);
$total_warehouse = count($warehouse_staff);
$total_sales = count($sales_agents);

function getUserRoleText($role) {
    switch($role) {
        case 'branch_admin': return 'Branch Admin';
        case 'delivery': return 'Driver';
        case 'warehouse': return 'Warehouse';
        case 'sales': return 'Sales Agent';
        default: return ucfirst($role);
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

$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    foreach ($name_parts as $part) {
        if (!empty($part)) $user_initials .= strtoupper(substr($part, 0, 1));
    }
}
if (empty($user_initials)) $user_initials = 'GA';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Global - User Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/global.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ===== STAT CARD STYLES ===== */
        .stat-card-row {
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: transform 0.2s ease;
            flex: 1;
            min-width: 0;
        }
        
        .stat-card i { font-size: 2rem; }
        .stat-card.bg-danger { background: #dc3545 !important; color: white; }
        .stat-card.bg-primary { background: #0d6efd !important; color: white; }
        .stat-card.bg-success { background: #198754 !important; color: white; }
        .stat-card.bg-warning { background: #ffc107 !important; color: white; }
        .stat-card.bg-warning .stat-value,
        .stat-card.bg-warning .stat-label { color: white; }
        
        .stat-card .stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
        .stat-card .stat-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9; }
        
        /* MOBILE STAT CARDS - 4 in a row, no scroll */
        @media (max-width: 768px) {
            .stat-card-row {
                gap: 8px;
                flex-wrap: nowrap;
            }
            .stat-card {
                flex: 1;
                min-width: 0;
                padding: 0.6rem 0.4rem;
                gap: 6px;
                justify-content: center;
                text-align: center;
            }
            .stat-card i {
                font-size: 1.2rem;
                margin-bottom: 2px;
            }
            .stat-card .stat-value {
                font-size: 1rem;
            }
            .stat-card .stat-label {
                font-size: 0.55rem;
                white-space: nowrap;
            }
            .stat-content {
                display: flex;
                flex-direction: column;
                align-items: center;
            }
        }
        
        /* ===== FILTER SECTION ===== */
        .filter-container {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 20px;
        }
        
        .search-input {
            width: 100%;
            padding: 8px 12px 8px 32px;
            border: 1px solid #ced4da;
            border-radius: 8px;
            height: 40px;
            font-size: 13px;
        }
        
        .branch-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 8px;
            height: 40px;
            font-size: 13px;
        }
        
        .buttons-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        
        .action-btn {
            flex: 1;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s ease;
            height: 40px;
        }
        
        .export-btn { background: white; border: 1px solid #198754; color: #198754; }
        .export-btn:hover { background: #198754; color: white; }
        .admin-btn { background: #dc3545; color: white; }
        .driver-btn { background: #0d6efd; color: white; }
        .warehouse-btn { background: #198754; color: white; }
        .sales-btn { background: #ffc107; color: white; }
        .branch-btn { background: #6f42c1; color: white; }
        
        @media (max-width: 768px) {
            .filter-container { padding: 10px; }
            .search-input, .branch-select { height: 34px; font-size: 12px; }
            .action-btn { height: 34px; font-size: 11px; padding: 6px 8px; }
            .action-btn i { font-size: 12px; }
            .buttons-container { gap: 6px; }
        }
        
        @media (max-width: 480px) {
            .action-btn span { display: none; }
            .action-btn { font-size: 0; padding: 6px 0; min-width: 36px; }
            .action-btn i { font-size: 14px; margin: 0; }
        }
          
        .table-header h5 { margin: 0; font-weight: 600; color: #1e293b; }
        .table-container { overflow-x: auto; }
        
        .custom-table { width: 100%; margin-bottom: 0; }
        .custom-table th {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            padding: 0.75rem;
            border-bottom: 2px solid #e9ecef;
            text-align: left;
        }
        .custom-table td { 
            padding: 0.75rem; 
            vertical-align: middle; 
            border-bottom: 1px solid #f1f5f9;
            text-align: left;
        }
        
        /* Row clickable styling */
        .user-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .user-row:hover {
            background-color: #f8fafc;
        }
        
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
        
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: none;
            background: #f8f9fa;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .btn-edit { background: #e7f5e9; color: #198754; }
        .btn-delete { background: #fee2e2; color: #dc3545; }
        .btn-edit:hover { background: #198754; color: white; }
        .btn-delete:hover { background: #dc3545; color: white; }
        
        /* Prevent action buttons from triggering row click */
        .btn-action {
            position: relative;
            z-index: 2;
        }
        
        /* ===== MOBILE VIEW - Card style ===== */
        @media (max-width: 768px) {
            .custom-table thead { display: none; }
            .custom-table, .custom-table tbody, .custom-table tr, .custom-table td {
                display: block;
                width: 100%;
            }
            .custom-table tbody tr {
                background: white;
                border-radius: 12px;
                margin-bottom: 10px;
                padding: 12px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.06);
                border: 1px solid #e9ecef;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            .custom-table tbody tr td { display: none; }
            
            .custom-table tbody tr .mobile-card-left {
                flex: 1;
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .mobile-branch {
                font-size: 0.7rem;
                color: #6c757d;
            }
            .mobile-name {
                font-size: 0.95rem;
                font-weight: 600;
                color: #047857;
            }
            .mobile-role {
                font-size: 0.7rem;
            }
            .mobile-status {
                margin-top: 2px;
            }
            .mobile-status .status-badge {
                font-size: 0.6rem;
                padding: 2px 6px;
                min-width: 55px;
            }
            
            .mobile-action {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                margin-left: 12px;
            }
            .mobile-action .btn-action {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 480px) {
            .mobile-name { font-size: 0.85rem; }
            .mobile-branch { font-size: 0.65rem; }
            .mobile-action .btn-action { width: 28px; height: 28px; font-size: 0.8rem; }
        }
        
        /* ===== DESKTOP VIEW - Center table headers and cells ===== */
        @media (min-width: 769px) {
            .custom-table th,
            .custom-table td {
                text-align: center;
                vertical-align: middle;
            }
            
            .custom-table td:first-child,
            .custom-table th:first-child {
                text-align: left;
            }
            
            .custom-table td:nth-child(2),
            .custom-table th:nth-child(2) {
                text-align: left;
            }
        }
        
        /* ===== MODAL STYLES ===== */
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
        .password-note {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 8px;
            font-size: 11px;
            margin-top: 5px;
        }
        
        /* ===== PROFILE MODAL ===== */
        .user-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto;
            border: 4px solid #d1fae5;
        }
        #profileModal .modal-header { background: linear-gradient(135deg, #047857, #44D34E); color: white; border-bottom: none; padding: 1.5rem; }
        #profileModal .modal-header .modal-title { color: white; }
        #profileModal .modal-header .btn-close { filter: brightness(0) invert(1); }
        #profileModal .modal-body { padding: 2rem; background: linear-gradient(135deg, #f9fefc 0%, #f0fdf4 100%); }
        #profileModal .branch-info { background: #d1fae5; color: #047857; padding: 0.5rem 1rem; border-radius: 50px; display: inline-block; }
        
        .mobile-nav .nav-link.logout-btn { color: #dc3545; }
        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            margin-bottom: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .page-title h2 { margin: 0; font-size: 20px; font-weight: 600; color: #1e293b; }
        .page-title p { margin: 3px 0 0 0; color: #666; font-size: 12px; }
        
        /* Remove badges from details column */
        .details-text {
            font-size: 0.75rem;
            line-height: 1.4;
        }
        .details-text i {
            font-size: 0.7rem;
            margin-right: 2px;
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

    

        /* ===== GLOBAL BUTTONS + STAT CARDS MATCH BRANCHADMIN GREEN PALETTE ===== */
        .stat-card,
        .stat-card.bg-danger,
        .stat-card.bg-primary,
        .stat-card.bg-success,
        .stat-card.bg-warning,
        .stat-card.total,
        .stat-card.pending,
        .stat-card.complete,
        .stat-card.delivery {
            background: linear-gradient(135deg, #047857, #059669) !important;
            color: #ffffff !important;
            border: none !important;
            box-shadow: 0 4px 10px rgba(4, 120, 87, 0.18) !important;
        }
        .stat-card:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 18px rgba(4, 120, 87, 0.28) !important;
        }
        .stat-card .stat-value,
        .stat-card .stat-label,
        .stat-card .stat-content,
        .stat-card i {
            color: #ffffff !important;
        }
        .action-btn,
        .export-btn,
        .admin-btn,
        .driver-btn,
        .warehouse-btn,
        .sales-btn,
        .branch-btn,
        .btn-global-green {
            background: linear-gradient(135deg, #047857, #059669) !important;
            color: #ffffff !important;
            border: none !important;
            box-shadow: 0 4px 10px rgba(4, 120, 87, 0.22) !important;
            cursor: pointer !important;
        }
        .action-btn:hover,
        .export-btn:hover,
        .admin-btn:hover,
        .driver-btn:hover,
        .warehouse-btn:hover,
        .sales-btn:hover,
        .branch-btn:hover,
        .btn-global-green:hover {
            background: linear-gradient(135deg, #059669, #44D34E) !important;
            color: #ffffff !important;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(4, 120, 87, 0.32) !important;
        }
        .custom-table tbody tr.user-row { cursor: pointer; }


/* ===== GLOBAL DRIVERS MODAL SIZE FIX - SCROLL INSIDE MODAL ONLY ===== */
body.modal-open {
    overflow: hidden !important;
    padding-right: 0 !important;
}

.modal {
    overflow-y: hidden !important;
}

.modal-dialog {
    max-width: 760px !important;
    margin: 1rem auto !important;
}

.modal-dialog.modal-lg {
    max-width: 820px !important;
}

.modal-dialog.modal-sm,
#deleteUserModal .modal-dialog {
    max-width: 520px !important;
}

.modal-dialog-centered {
    min-height: calc(100% - 2rem) !important;
}

.modal-content {
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
    border-radius: 14px !important;
    overflow: hidden !important;
}

.modal-header,
.modal-footer {
    flex-shrink: 0 !important;
}

.modal-body {
    overflow-y: auto !important;
    max-height: calc(90vh - 135px) !important;
    padding-right: 1rem !important;
}

.modal-body::-webkit-scrollbar {
    width: 6px;
}

.modal-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.modal-body::-webkit-scrollbar-track {
    background: #f1f5f9;
}

#profileModal .modal-body,
#deleteUserModal .modal-body {
    max-height: calc(90vh - 125px) !important;
}

@media (max-width: 768px) {
    .modal-dialog,
    .modal-dialog.modal-lg {
        max-width: calc(100% - 1rem) !important;
        margin: 0.5rem auto !important;
    }

    .modal-content {
        max-height: 92vh !important;
    }

    .modal-body {
        max-height: calc(92vh - 130px) !important;
    }
}

</style>
</head>
<body>
    <div id="appPage">
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
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="sales_reports.php"><i class="bi bi-graph-up"></i><span class="nav-text">Sales Reports</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="branch_records.php"><i class="bi bi-file-text"></i><span class="nav-text">Branch Records</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="all_items.php"><i class="bi bi-box"></i><span class="nav-text">All Items</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="location_verification.php"><i class="bi bi-geo-alt-fill"></i><span class="nav-text">Location Verification</span></a></li>
                    <li class="nav-item"><a class="nav-link active" href="drivers.php"><i class="bi bi-people"></i><span class="nav-text">User Management</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span class="nav-text">Trip Tickets</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="driver_tracking.php"><i class="bi bi-geo-alt"></i><span class="nav-text">Driver Tracking</span></a></li>
                </ul>
            </div>
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
                    <div class="user-details-sidebar"><span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span></div>
                </div>
                <button class="logout-btn-sidebar" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span class="logout-text">Logout</span></button>
            </div>
        </div>

        <div class="main-content" id="mainContent">
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
                    <div class="page-title">
                        <h2><i class="bi bi-people me-2"></i>User Management</h2>
                        <p>Manage all users across all branches - Branch Admins, Drivers, Warehouse Staff, and Sales Agents</p>
                    </div>
                </div>

                <!-- Stats Cards - 4 in a row, no scroll on mobile -->
                <div class="stat-card-row">
                    <div class="stat-card bg-danger"><i class="bi bi-person-badge"></i><div class="stat-content"><div class="stat-value"><?= $total_branch_admins ?></div><div class="stat-label">Branch Admins</div></div></div>
                    <div class="stat-card bg-primary"><i class="bi bi-truck"></i><div class="stat-content"><div class="stat-value"><?= $total_drivers ?></div><div class="stat-label">Drivers</div></div></div>
                    <div class="stat-card bg-success"><i class="bi bi-building"></i><div class="stat-content"><div class="stat-value"><?= $total_warehouse ?></div><div class="stat-label">Warehouse</div></div></div>
                    <div class="stat-card bg-warning"><i class="bi bi-graph-up"></i><div class="stat-content"><div class="stat-value"><?= $total_sales ?></div><div class="stat-label">Sales Agents</div></div></div>
                </div>

                <!-- FILTER SECTION -->
                <div class="filter-container">
                    <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                        <div style="flex: 2; min-width: 150px;">
                            <div style="position: relative;">
                                <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d;"></i>
                                <input type="text" id="searchInput" class="search-input" placeholder="Search by name, email..." style="padding-left: 32px;" onkeyup="filterUsers()">
                            </div>
                        </div>
                        <div style="flex: 1; min-width: 140px;">
                            <select id="branchFilter" class="branch-select" onchange="filterUsers()">
                                <option value="all">All Branches</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['branch_id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="buttons-container">
                        <button onclick="exportToExcel()" class="action-btn export-btn"><i class="bi bi-file-earmark-excel"></i><span>Export</span></button>
                        <button onclick="showAddBranchModal()" class="action-btn branch-btn"><i class="bi bi-diagram-3"></i><span>Branch</span></button>
                        <button onclick="showAddBranchAdminModal()" class="action-btn admin-btn"><i class="bi bi-person-badge"></i><span>Branch Admin</span></button>
                        <button onclick="showAddDriverModal()" class="action-btn driver-btn"><i class="bi bi-truck"></i><span>Driver</span></button>
                        <button onclick="showAddWarehouseModal()" class="action-btn warehouse-btn"><i class="bi bi-building"></i><span>Warehouse</span></button>
                        <button onclick="showAddSalesModal()" class="action-btn sales-btn"><i class="bi bi-graph-up"></i><span>Sales</span></button>
                    </div>
                </div>

                <!-- USERS TABLE - DESKTOP VIEW -->
                <div class="data-table">
                    <div class="table-header"><h5><i class="bi bi-people"></i> All Users</h5></div>
                    <div class="table-container">
                        <table class="table custom-table" id="usersTable">
                            <thead>
                                <tr>
                                    <th>BRANCH</th>
                                    <th>NAME</th>
                                    <th>ROLE</th>
                                    <th>DETAILS</th>
                                    <th>CONTACT</th>
                                    <th>STATUS</th>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="6" class="text-center py-4">No users found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                    <tr class="user-row" 
                                        data-id="<?= $user['user_id'] ?>"
                                        data-branch="<?= $user['branch_id'] ?>"
                                        data-branch-name="<?= htmlspecialchars($user['branch_name'] ?? 'Branch '.$user['branch_id']) ?>"
                                        data-name="<?= htmlspecialchars($user['full_name']) ?>"
                                        data-email="<?= htmlspecialchars($user['email']) ?>"
                                        data-role="<?= $user['role'] ?>"
                                        data-role-text="<?= getUserRoleText($user['role']) ?>"
                                        data-status="<?= $user['user_status'] ?>"
                                        data-status-text="<?= ucfirst($user['user_status']) ?>"
                                        data-status-class="<?= $user['user_status'] === 'active' ? 'status-active' : 'status-inactive' ?>"
                                        onclick="viewUserFromRow(event, <?= $user['user_id'] ?>)">
                                        <td><span><?= htmlspecialchars($user['branch_name'] ?? 'Branch '.$user['branch_id']) ?></span></td>
                                        <td><strong><?= htmlspecialchars($user['full_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($user['email']) ?></small></td>
                                        <td>
                                            <span class="text-dark">
                                                <?= getUserRoleText($user['role']) ?>
                                            </span>
                                        </td>
                                        <td class="details-text">
                                            <?php if ($user['role'] === 'branch_admin'): ?>
                                                <i class="bi bi-briefcase"></i> Position: Branch Admin
                                            <?php elseif ($user['role'] === 'delivery'): ?>
                                                <i class="bi bi-card-text"></i> License: <?= htmlspecialchars($user['license_number'] ?? 'N/A') ?><br>
                                                <i class="bi bi-calendar"></i> Exp: <?= formatDate($user['license_expiry']) ?><br>
                                                <i class="bi bi-truck"></i> <?= htmlspecialchars($user['vehicle_type'] ?? 'N/A') ?> - <?= htmlspecialchars($user['vehicle_plate_number'] ?? 'N/A') ?>
                                            <?php elseif ($user['role'] === 'warehouse'): ?>
                                                <i class="bi bi-briefcase"></i> Category: <?= htmlspecialchars($user['category'] ?? 'General') ?>
                                            <?php elseif ($user['role'] === 'sales'): ?>
                                                <i class="bi bi-briefcase"></i> Position: Sales Agent
                                            <?php endif; ?>
                                        </td>
                                        <td><i class="bi bi-telephone"></i> <?= formatPhoneNumber($user['contact_number']) ?></td>
                                        <td><span class="status-badge <?= $user['user_status'] === 'active' ? 'status-active' : 'status-inactive' ?>"><?= ucfirst($user['user_status']) ?></span></td>
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

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item"><a class="nav-link" href="sales_reports.php"><i class="bi bi-graph-up"></i><span>Reports</span></a></li>
            <li class="nav-item"><a class="nav-link" href="branch_records.php"><i class="bi bi-file-text"></i><span>Records</span></a></li>
            <li class="nav-item"><a class="nav-link" href="all_items.php"><i class="bi bi-box"></i><span>Items</span></a></li>
            <li class="nav-item"><a class="nav-link active" href="drivers.php"><i class="bi bi-people"></i><span>Users</span></a></li>
            <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span>Tickets</span></a></li>
            <li class="nav-item"><a class="nav-link" href="driver_tracking.php"><i class="bi bi-geo-alt"></i><span>Tracking</span></a></li>
            <li class="nav-item"><a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></li>
        </ul>
    </div>

    <!-- MODALS -->
    <div class="modal fade" id="profileModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div><h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4><p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p><button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></div></div></div></div>

    <div class="modal fade" id="branchModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header" style="background-color: #6f42c1; color: white;"><h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i><span id="branchModalTitle">Add Branch</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="branchForm"><div class="form-section"><div class="form-section-title"><i class="bi bi-diagram-3 me-2"></i>Branch Information</div><div class="row g-3"><div class="col-md-6"><label class="form-label">Branch Name *</label><input type="text" class="form-control" id="branchName" name="branch_name" required></div><div class="col-md-6"><label class="form-label">Branch Code *</label><input type="text" class="form-control" id="branchCode" name="branch_code" required></div><div class="col-md-6"><label class="form-label">Business Unit *</label><select class="form-select" id="branchBusinessUnit" name="business_unit" required onchange="toggleBusinessUnitCustomField()"><option value="">Select Business Unit</option><option value="Cement">Cement</option><option value="Oil">Oil</option><option value="General">General</option><option value="ETC">Others</option></select></div><div class="col-md-6" id="branchBusinessUnitCustomWrapper" style="display: none;"><label class="form-label">New Business Unit *</label><input type="text" class="form-control" id="branchBusinessUnitCustom" name="business_unit_custom" placeholder="Enter new business unit"></div><div class="col-md-6"><label class="form-label">Status</label><select class="form-select" id="branchStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn" style="background-color: #6f42c1; color: white;" onclick="saveBranch()">Save</button></div></div></div></div>

    <div class="modal fade" id="branchAdminModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header" style="background-color: #dc3545; color: white;"><h5 class="modal-title"><i class="bi bi-person-badge me-2"></i><span id="branchAdminModalTitle">Add Branch Admin</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="branchAdminForm" enctype="multipart/form-data"><input type="hidden" id="branchAdminId" name="user_id"><div class="form-section"><div class="form-section-title"><i class="bi bi-person-workspace me-2"></i>Admin Information</div><div class="row g-3"><div class="col-md-6"><label class="form-label">First Name *</label><input type="text" class="form-control" id="branchAdminFirstName" name="first_name" required></div><div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" class="form-control" id="branchAdminLastName" name="last_name" required></div><div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" id="branchAdminEmail" name="email" required></div><div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" id="branchAdminPassword" name="password" minlength="6"><div class="password-note" id="branchAdminPasswordNote"><i class="bi bi-info-circle"></i> Required for new users</div></div><div class="col-md-6"><label class="form-label">Profile Picture <small class="text-muted">(optional)</small></label><div class="profile-upload-wrapper"><label class="profile-upload-box">
                                            <input type="file" class="profile-picture-input" name="profile_picture" accept="image/*">
                                            <img class="profile-upload-preview" src="" alt="Profile preview">
                                            <span class="profile-upload-overlay"><i class="bi bi-camera-fill"></i></span>
                                        </label>
                                        <div class="profile-upload-caption">
                                            <span class="profile-upload-text">Profile Picture</span>
                                            <span class="profile-upload-hint">Optional • JPG, PNG, GIF, WEBP up to 5MB</span>
                                            <span class="profile-upload-filename">No file selected</span>
                                        </div>
                                    </div></div><div class="col-md-6"><label class="form-label">Contact</label><input type="text" class="form-control" id="branchAdminContact" name="contact_number"></div><div class="col-md-6"><label class="form-label">Branch *</label><select class="form-select" id="branchAdminBranchId" name="branch_id" required onchange="syncBranchAdminBusinessUnit()"><option value="">Select Branch</option><?php foreach ($branches as $branch): ?><option value="<?php echo $branch['branch_id']; ?>" data-business-unit="<?php echo htmlspecialchars($branch['business_unit'] ?? '', ENT_QUOTES); ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Business Unit *</label><select class="form-select" id="branchAdminBusinessUnit" name="business_unit" required onchange="toggleBranchAdminBusinessUnitCustomField()"><option value="">Select Business Unit</option><option value="Cement">Cement</option><option value="Oil">Oil</option><option value="General">General</option><option value="ETC">Others</option></select></div><div class="col-md-6" id="branchAdminBusinessUnitCustomWrapper" style="display: none;"><label class="form-label">New Business Unit *</label><input type="text" class="form-control" id="branchAdminBusinessUnitCustom" name="business_unit_custom" placeholder="Enter new business unit"></div><div class="col-md-6"><label class="form-label">Status</label><select class="form-select" id="branchAdminStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" onclick="saveBranchAdmin()">Save</button></div></div></div></div>

    <div class="modal fade" id="driverModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header" style="background-color: #0d6efd; color: white;"><h5 class="modal-title"><i class="bi bi-truck me-2"></i><span id="driverModalTitle">Add Driver</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="driverForm" enctype="multipart/form-data"><input type="hidden" id="driverId" name="driver_id"><input type="hidden" id="driverUserId" name="user_id"><div class="form-section"><div class="form-section-title"><i class="bi bi-truck me-2"></i>Driver Information</div><div class="row g-3"><div class="col-md-6"><label class="form-label">Driver Name *</label><input type="text" class="form-control" id="driverName" name="driver_name" required></div><div class="col-md-6"><label class="form-label">License # *</label><input type="text" class="form-control" id="licenseNumber" name="license_number" required></div><div class="col-md-6"><label class="form-label">License Expiry</label><input type="date" class="form-control" id="licenseExpiry" name="license_expiry"></div><div class="col-md-6"><label class="form-label">Contact</label><input type="text" class="form-control" id="contactNumber" name="contact_number"></div><div class="col-md-6"><label class="form-label">Vehicle Type</label><select class="form-select" id="vehicleType" name="vehicle_type"><option value="">Select</option><option value="Van">Van</option><option value="Truck">Truck</option><option value="Motorcycle">Motorcycle</option><option value="Car">Car</option></select></div><div class="col-md-6"><label class="form-label">Plate #</label><input type="text" class="form-control" id="vehiclePlate" name="vehicle_plate_number"></div><div class="col-md-6"><label class="form-label">Branch *</label><select class="form-select" id="driverBranchId" name="branch_id" required><option value="">Select Branch</option><?php foreach ($branches as $branch): ?><option value="<?php echo $branch['branch_id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Status</label><select class="form-select" id="driverStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div></div><div class="form-section"><div class="form-section-title"><i class="bi bi-person-circle me-2"></i>User Account</div><div class="row g-3"><div class="col-md-6"><label class="form-label">First Name *</label><input type="text" class="form-control" id="driverFirstName" name="first_name" required></div><div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" class="form-control" id="driverLastName" name="last_name" required></div><div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" id="driverEmail" name="email" required></div><div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" id="driverPassword" name="password" minlength="6"><div class="password-note" id="driverPasswordNote"><i class="bi bi-info-circle"></i> Required for new users</div></div><div class="col-md-6"><label class="form-label">Profile Picture <small class="text-muted">(optional)</small></label><div class="profile-upload-wrapper"><label class="profile-upload-box">
                                            <input type="file" class="profile-picture-input" name="profile_picture" accept="image/*">
                                            <img class="profile-upload-preview" src="" alt="Profile preview">
                                            <span class="profile-upload-overlay"><i class="bi bi-camera-fill"></i></span>
                                        </label>
                                        <div class="profile-upload-caption">
                                            <span class="profile-upload-text">Profile Picture</span>
                                            <span class="profile-upload-hint">Optional • JPG, PNG, GIF, WEBP up to 5MB</span>
                                            <span class="profile-upload-filename">No file selected</span>
                                        </div>
                                    </div></div></div></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" onclick="saveDriver()">Save</button></div></div></div></div>

    <div class="modal fade" id="warehouseModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header" style="background-color: #198754; color: white;"><h5 class="modal-title"><i class="bi bi-building me-2"></i><span id="warehouseModalTitle">Add Warehouse Staff</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="warehouseForm" enctype="multipart/form-data"><input type="hidden" id="warehouseId" name="user_id"><div class="form-section"><div class="form-section-title"><i class="bi bi-person-badge me-2"></i>Staff Information</div><div class="row g-3"><div class="col-md-6"><label class="form-label">First Name *</label><input type="text" class="form-control" id="warehouseFirstName" name="first_name" required></div><div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" class="form-control" id="warehouseLastName" name="last_name" required></div><div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" id="warehouseEmail" name="email" required></div><div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" id="warehousePassword" name="password" minlength="6"><div class="password-note"><i class="bi bi-info-circle"></i> Required for new users</div></div><div class="col-md-6"><label class="form-label">Profile Picture <small class="text-muted">(optional)</small></label><div class="profile-upload-wrapper"><label class="profile-upload-box">
                                            <input type="file" class="profile-picture-input" name="profile_picture" accept="image/*">
                                            <img class="profile-upload-preview" src="" alt="Profile preview">
                                            <span class="profile-upload-overlay"><i class="bi bi-camera-fill"></i></span>
                                        </label>
                                        <div class="profile-upload-caption">
                                            <span class="profile-upload-text">Profile Picture</span>
                                            <span class="profile-upload-hint">Optional • JPG, PNG, GIF, WEBP up to 5MB</span>
                                            <span class="profile-upload-filename">No file selected</span>
                                        </div>
                                    </div></div><div class="col-md-6"><label class="form-label">Category</label><select class="form-select" id="warehouseCategory" name="category"><option value="">Select</option><option value="Oil">Oil</option><option value="Cement">Cement</option><option value="General">General</option></select></div><div class="col-md-6"><label class="form-label">Contact</label><input type="text" class="form-control" id="warehouseContact" name="contact_number"></div><div class="col-md-6"><label class="form-label">Branch *</label><select class="form-select" id="warehouseBranchId" name="branch_id" required><option value="">Select Branch</option><?php foreach ($branches as $branch): ?><option value="<?php echo $branch['branch_id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Status</label><select class="form-select" id="warehouseStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-success" onclick="saveWarehouse()">Save</button></div></div></div></div>

    <div class="modal fade" id="salesModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header" style="background-color: #ffc107; color: #212529;"><h5 class="modal-title"><i class="bi bi-graph-up me-2"></i><span id="salesModalTitle">Add Sales Agent</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="salesForm" enctype="multipart/form-data"><input type="hidden" id="salesId" name="user_id"><div class="form-section"><div class="form-section-title"><i class="bi bi-person-workspace me-2"></i>Agent Information</div><div class="row g-3"><div class="col-md-6"><label class="form-label">First Name *</label><input type="text" class="form-control" id="salesFirstName" name="first_name" required></div><div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" class="form-control" id="salesLastName" name="last_name" required></div><div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" id="salesEmail" name="email" required></div><div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" id="salesPassword" name="password" minlength="6"><div class="password-note"><i class="bi bi-info-circle"></i> Required for new users</div></div><div class="col-md-6"><label class="form-label">Profile Picture <small class="text-muted">(optional)</small></label><div class="profile-upload-wrapper"><label class="profile-upload-box">
                                            <input type="file" class="profile-picture-input" name="profile_picture" accept="image/*">
                                            <img class="profile-upload-preview" src="" alt="Profile preview">
                                            <span class="profile-upload-overlay"><i class="bi bi-camera-fill"></i></span>
                                        </label>
                                        <div class="profile-upload-caption">
                                            <span class="profile-upload-text">Profile Picture</span>
                                            <span class="profile-upload-hint">Optional • JPG, PNG, GIF, WEBP up to 5MB</span>
                                            <span class="profile-upload-filename">No file selected</span>
                                        </div>
                                    </div></div><div class="col-md-6"><label class="form-label">Contact</label><input type="text" class="form-control" id="salesContact" name="contact_number"></div><div class="col-md-6"><label class="form-label">Branch *</label><select class="form-select" id="salesBranchId" name="branch_id" required><option value="">Select Branch</option><?php foreach ($branches as $branch): ?><option value="<?php echo $branch['branch_id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Status</label><select class="form-select" id="salesStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn" style="background-color: #ffc107; color: #212529;" onclick="saveSales()">Save</button></div></div></div></div>

    <div class="modal fade" id="viewUserModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header bg-info text-white"><h5 class="modal-title"><i class="bi bi-eye me-2"></i>User Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row" id="viewUserContent"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="button" class="btn btn-global-green" onclick="editFromView()"><i class="bi bi-pencil me-1"></i>Edit User</button><button type="button" class="btn btn-danger" onclick="deleteFromView()"><i class="bi bi-trash me-1"></i>Deactivate</button></div></div></div></div>

    <div class="modal fade" id="deleteUserModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Deactivate</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>Are you sure you want to deactivate this user?</p><p class="fw-bold" id="deleteUserName"></p><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>This action will deactivate the user. They will no longer be able to log in.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" onclick="confirmDeleteUser()">Deactivate</button></div></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let currentUserId = null, currentUserRole = null, currentDriverId = null;

    function viewUserFromRow(event, userId) {
        // Prevent if click came from action buttons (they already have stopPropagation)
        if (event.target.closest('.btn-action')) {
            return;
        }
        viewUser(userId);
    }

    function logout() {
        Swal.fire({
            title: 'Logout',
            text: 'Are you sure you want to logout?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, logout',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '../logout.php';
            }
        });
    }

    function showProfileModal() {
        new bootstrap.Modal(document.getElementById('profileModal')).show();
    }

    function confirmLogout() {
        bootstrap.Modal.getInstance(document.getElementById('profileModal')).hide();
        logout();
    }

    function toggleSidebar() {
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
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            document.querySelectorAll('.nav-text').forEach(t => t.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block');
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.remove('active');
        if (overlay) { overlay.classList.remove('active'); setTimeout(() => overlay.remove(), 300); }
    }

    function initMobileNav() {
        const mobileNav = document.getElementById('mobileNav');
        if (window.innerWidth <= 992) {
            mobileNav.style.display = 'block';
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('#mobileNav .nav-link:not(.logout-btn)').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === currentPage) link.classList.add('active');
            });
        } else { mobileNav.style.display = 'none'; }
    }

    function handleResponsiveLayout() {
        if (window.innerWidth <= 768) {
            addMobileCardStructure();
        } else {
            removeMobileCardStructure();
        }
    }

    function addMobileCardStructure() {
        const rows = document.querySelectorAll('#usersTableBody tr.user-row');
        rows.forEach(row => {
            // Skip if already has mobile structure
            if (row.querySelector('.mobile-card-left')) return;
            
            // Store original HTML in data attribute if not already stored
            if (!row.hasAttribute('data-original-html')) {
                const originalCells = [];
                const cells = row.querySelectorAll('td');
                cells.forEach(cell => {
                    originalCells.push(cell.innerHTML);
                });
                row.setAttribute('data-original-html', JSON.stringify(originalCells));
            }
            
            const branchName = row.getAttribute('data-branch-name') || '';
            const name = row.getAttribute('data-name') || '';
            const roleText = row.getAttribute('data-role-text') || '';
            const role = row.getAttribute('data-role') || '';
            const statusText = row.getAttribute('data-status-text') || '';
            const statusClass = row.getAttribute('data-status-class') || '';
            const actionHtml = row.cells[5]?.innerHTML || '';
            
            const roleBadgeClass = role === 'branch_admin' ? 'bg-danger' : (role === 'delivery' ? 'bg-primary' : (role === 'warehouse' ? 'bg-success' : 'bg-warning text-dark'));
            
            row.innerHTML = '';
            const leftDiv = document.createElement('div');
            leftDiv.className = 'mobile-card-left';
            leftDiv.innerHTML = `
                <div class="mobile-branch">${escapeHtml(branchName)}</div>
                <div class="mobile-name">${escapeHtml(name)}</div>
                <div class="mobile-role">${escapeHtml(roleText)}</span></div>
                <div class="mobile-status"><span class="status-badge ${statusClass}">${escapeHtml(statusText)}</span></div>
            `;
            const rightDiv = document.createElement('div');
            rightDiv.className = 'mobile-action';
            rightDiv.innerHTML = actionHtml;
            row.appendChild(leftDiv);
            row.appendChild(rightDiv);
        });
    }

    function removeMobileCardStructure() {
        const rows = document.querySelectorAll('#usersTableBody tr.user-row');
        rows.forEach(row => {
            if (row.hasAttribute('data-original-html')) {
                const originalCells = JSON.parse(row.getAttribute('data-original-html'));
                const cells = row.querySelectorAll('td');
                if (cells.length === 0) {
                    // Restore original structure
                    const newRow = document.createElement('tr');
                    newRow.className = row.className;
                    // Copy all data attributes
                    Array.from(row.attributes).forEach(attr => {
                        if (attr.name !== 'class') {
                            newRow.setAttribute(attr.name, attr.value);
                        }
                    });
                    
                    // Create cells
                    for (let i = 0; i < originalCells.length; i++) {
                        const td = document.createElement('td');
                        td.innerHTML = originalCells[i];
                        newRow.appendChild(td);
                    }
                    
                    row.parentNode.replaceChild(newRow, row);
                }
            }
        });
    }

    function escapeHtml(t) { if (!t) return ''; const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

    function filterUsers() {
        const branch = document.getElementById('branchFilter').value;
        const search = document.getElementById('searchInput').value.toLowerCase();
        document.querySelectorAll('.user-row').forEach(row => {
            let show = true;
            if (branch !== 'all' && row.dataset.branch !== branch) show = false;
            if (show && search) { const text = row.innerText.toLowerCase(); show = text.includes(search); }
            row.style.display = show ? '' : 'none';
        });
    }

    function showAddBranchModal() {
        document.getElementById('branchForm').reset();
        document.getElementById('branchModalTitle').textContent = 'Add Branch';
        document.getElementById('branchStatus').value = 'active';
        toggleBusinessUnitCustomField();
        new bootstrap.Modal(document.getElementById('branchModal')).show();
    }

    function toggleBusinessUnitCustomField() {
        const businessUnit = document.getElementById('branchBusinessUnit');
        const customWrapper = document.getElementById('branchBusinessUnitCustomWrapper');
        const customInput = document.getElementById('branchBusinessUnitCustom');
        if (!businessUnit || !customWrapper || !customInput) return;

        const useCustom = businessUnit.value === 'ETC';
        customWrapper.style.display = useCustom ? '' : 'none';
        customInput.required = useCustom;
        if (!useCustom) {
            customInput.value = '';
        }
    }

    function setBranchAdminBusinessUnitValue(value) {
        const select = document.getElementById('branchAdminBusinessUnit');
        const customInput = document.getElementById('branchAdminBusinessUnitCustom');
        if (!select) return;
        const normalizedValue = (value || '').trim();
        const predefined = ['Cement', 'Oil', 'General'];

        if (!normalizedValue) {
            select.value = '';
            if (customInput) customInput.value = '';
        } else if (predefined.includes(normalizedValue)) {
            select.value = normalizedValue;
            if (customInput) customInput.value = '';
        } else {
            select.value = 'ETC';
            if (customInput) customInput.value = normalizedValue;
        }
        toggleBranchAdminBusinessUnitCustomField();
    }

    function toggleBranchAdminBusinessUnitCustomField() {
        const businessUnit = document.getElementById('branchAdminBusinessUnit');
        const customWrapper = document.getElementById('branchAdminBusinessUnitCustomWrapper');
        const customInput = document.getElementById('branchAdminBusinessUnitCustom');
        if (!businessUnit || !customWrapper || !customInput) return;

        const useCustom = businessUnit.value === 'ETC';
        customWrapper.style.display = useCustom ? '' : 'none';
        customInput.required = useCustom;
        if (!useCustom) customInput.value = '';
    }

    function syncBranchAdminBusinessUnit() {
        const branchSelect = document.getElementById('branchAdminBranchId');
        if (!branchSelect) return;
        const selectedOption = branchSelect.options[branchSelect.selectedIndex];
        setBranchAdminBusinessUnitValue(selectedOption ? (selectedOption.dataset.businessUnit || '') : '');
    }

    function showAddBranchAdminModal() {
        document.getElementById('branchAdminForm').reset();
        document.getElementById('branchAdminId').value = '';
        document.getElementById('branchAdminModalTitle').textContent = 'Add Branch Admin';
        document.getElementById('branchAdminPassword').required = true;
        setBranchAdminBusinessUnitValue('');
        document.getElementById('branchAdminPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Required for new users';
        resetProfileUpload('branchAdminForm');
        new bootstrap.Modal(document.getElementById('branchAdminModal')).show();
    }

    function showAddDriverModal() {
        document.getElementById('driverForm').reset();
        document.getElementById('driverId').value = '';
        document.getElementById('driverUserId').value = '';
        document.getElementById('driverModalTitle').textContent = 'Add Driver';
        document.getElementById('driverPassword').required = true;
        document.getElementById('driverPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Required for new users';
        const d = new Date(); d.setFullYear(d.getFullYear() + 1);
        document.getElementById('licenseExpiry').value = d.toISOString().split('T')[0];
        resetProfileUpload('driverForm');
        new bootstrap.Modal(document.getElementById('driverModal')).show();
    }

    function showAddWarehouseModal() {
        document.getElementById('warehouseForm').reset();
        document.getElementById('warehouseId').value = '';
        document.getElementById('warehouseModalTitle').textContent = 'Add Warehouse Staff';
        document.getElementById('warehousePassword').required = true;
        resetProfileUpload('warehouseForm');
        new bootstrap.Modal(document.getElementById('warehouseModal')).show();
    }

    function showAddSalesModal() {
        document.getElementById('salesForm').reset();
        document.getElementById('salesId').value = '';
        document.getElementById('salesModalTitle').textContent = 'Add Sales Agent';
        document.getElementById('salesPassword').required = true;
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
        const fd = new FormData(); fd.append('action', 'get_user'); fd.append('user_id', id);
        fetch('drivers.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            Swal.close();
            if (data.success) {
                const u = data.user;
                currentUserId = u.user_id; currentUserRole = u.role;
                const created = u.created_at ? new Date(u.created_at).toLocaleString() : 'N/A';
                let html = '';
                if (u.role === 'branch_admin') {
                    html = `<div class="col-12"><div class="form-section"><h6 class="fw-bold mb-3">Branch Admin Info</h6><table class="table table-sm">\n<th>Name:</th><td>${u.first_name} ${u.last_name}</td><tr>\n<th>Email:</th><td>${u.email}</td></tr>\n<th>Contact:</th><td>${u.contact_number || 'N/A'}</td></tr>\n<th>Branch:</th><td>${u.branch_name || 'Branch ' + u.branch_id}</td></tr>\n<th>Status:</th><td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr>\n<th>Created:</th><td>${created}</td></tr></table></div></div>`;
                } else if (u.role === 'delivery') {
                    html = `<div class="col-md-6"><div class="form-section"><h6 class="fw-bold mb-3">Driver Info</h6><table class="table table-sm"><tr><th>Driver Name:</th><td>${u.driver_name || u.full_name}</td></tr><tr><th>License:</th><td>${u.license_number || 'N/A'}</td></tr><tr><th>Expiry:</th><td>${u.license_expiry ? new Date(u.license_expiry).toLocaleDateString() : 'N/A'}</td></tr><tr><th>Vehicle:</th><td>${u.vehicle_type || 'N/A'} - ${u.vehicle_plate_number || 'N/A'}</td></tr></table></div></div><div class="col-md-6"><div class="form-section"><h6 class="fw-bold mb-3">User Account</h6><table class="table table-sm"><tr><th>Name:</th><td>${u.first_name} ${u.last_name}</td></tr><tr><th>Email:</th><td>${u.email}</td></tr><tr><th>Branch:</th><td>${u.branch_name || 'Branch ' + u.branch_id}</td></tr><tr><th>Status:</th><td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr></tr></div></div>`;
                } else if (u.role === 'warehouse') {
                    html = `<div class="col-12"><div class="form-section"><h6 class="fw-bold mb-3">Warehouse Staff Info</h6><table class="table table-sm"><tr><th>Name:</th><td>${u.first_name} ${u.last_name}</td></tr><tr><th>Email:</th><td>${u.email}</td></tr><tr><th>Category:</th><td>${u.category || 'General'}</td></tr><tr><th>Contact:</th><td>${u.contact_number || 'N/A'}</td></tr><tr><th>Branch:</th><td>${u.branch_name || 'Branch ' + u.branch_id}</td></tr><tr><th>Status:</th><td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></td><tr><th>Created:</th><td>${created}</td></tr></table></div></div>`;
                } else {
                    html = `<div class="col-12"><div class="form-section"><h6 class="fw-bold mb-3">Sales Agent Info</h6><table class="table table-sm"><tr><th>Name:</th><td>${u.first_name} ${u.last_name}</td></tr><tr><th>Email:</th><td>${u.email}</td></tr><tr><th>Contact:</th><td>${u.contact_number || 'N/A'}</td></tr><tr><th>Branch:</th><td>${u.branch_name || 'Branch ' + u.branch_id}</td></tr><tr><th>Status:</th><td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr><tr><th>Created:</th><td>${created}</td></tr></table></div></div>`;
                }
                document.getElementById('viewUserContent').innerHTML = getProfilePictureHtml(u) + html;
                new bootstrap.Modal(document.getElementById('viewUserModal')).show();
            } else { Swal.fire('Error', data.message, 'error'); }
        }).catch(e => { Swal.close(); console.error(e); Swal.fire('Error', 'An error occurred', 'error'); });
    }

    function editFromView() { bootstrap.Modal.getInstance(document.getElementById('viewUserModal')).hide(); setTimeout(() => editUser(currentUserId), 300); }

    function deleteFromView() {
        const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewUserModal'));
        if (viewModal) viewModal.hide();
        setTimeout(() => deleteUser(currentUserId, currentUserRole, currentDriverId), 300);
    }

    function editUser(id) {
        showLoading();
        const fd = new FormData(); fd.append('action', 'get_user'); fd.append('user_id', id);
        fetch('drivers.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            Swal.close();
            if (data.success) {
                const u = data.user;
                currentUserId = u.user_id; currentUserRole = u.role; currentDriverId = u.driver_id;
                if (u.role === 'branch_admin') {
                    document.getElementById('branchAdminModalTitle').textContent = 'Edit Branch Admin';
                    document.getElementById('branchAdminId').value = u.user_id;
                    document.getElementById('branchAdminFirstName').value = u.first_name || '';
                    document.getElementById('branchAdminLastName').value = u.last_name || '';
                    document.getElementById('branchAdminEmail').value = u.email || '';
                    document.getElementById('branchAdminContact').value = u.contact_number || '';
                    document.getElementById('branchAdminBranchId').value = u.branch_id || '';
                    setBranchAdminBusinessUnitValue(u.business_unit || '');
                    document.getElementById('branchAdminStatus').value = u.status || 'active';
                    document.getElementById('branchAdminPassword').required = false;
                    document.getElementById('branchAdminPassword').value = '';
                    document.getElementById('branchAdminPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Leave blank to keep current password';
                    setProfileUploadPreview('branchAdminForm', u.profile_picture, u.profile_picture ? 'Current profile picture' : 'Default profile icon');
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
                    document.getElementById('driverPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Leave blank to keep current password';
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
                    document.getElementById('warehouseBranchId').value = u.branch_id || '';
                    document.getElementById('warehouseStatus').value = u.status || 'active';
                    document.getElementById('warehousePassword').required = false;
                    document.getElementById('warehousePassword').value = '';
                    setProfileUploadPreview('warehouseForm', u.profile_picture, u.profile_picture ? 'Current profile picture' : 'Default profile icon');
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
                    setProfileUploadPreview('salesForm', u.profile_picture, u.profile_picture ? 'Current profile picture' : 'Default profile icon');
                    new bootstrap.Modal(document.getElementById('salesModal')).show();
                }
            } else { Swal.fire('Error', data.message, 'error'); }
        }).catch(e => { Swal.close(); console.error(e); Swal.fire('Error', 'An error occurred', 'error'); });
    }

    function saveBranch() {
        const fd = new FormData(document.getElementById('branchForm'));
        fd.append('action', 'add_branch');
        showLoading();
        fetch('drivers.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            Swal.close();
            if (data.success) { Swal.fire({ icon: 'success', title: 'Success', text: data.message, timer: 1500 }).then(() => { bootstrap.Modal.getInstance(document.getElementById('branchModal'))?.hide(); location.reload(); }); }
            else { Swal.fire('Error', data.message, 'error'); }
        }).catch(e => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
    }

    function saveBranchAdmin() {
        const userId = document.getElementById('branchAdminId').value;
        const fd = new FormData(document.getElementById('branchAdminForm'));

        // Ensure Business Unit is always submitted, especially on edit.
        const buSelect = document.getElementById('branchAdminBusinessUnit');
        const buCustom = document.getElementById('branchAdminBusinessUnitCustom');
        if (buSelect) {
            fd.set('business_unit', buSelect.value || '');
        }
        if (buCustom) {
            fd.set('business_unit_custom', buCustom.value || '');
        }

        if (userId) { fd.append('action', 'update_user'); fd.append('user_role_type', 'branch_admin'); fd.append('user_id', userId); }
        else { fd.append('action', 'add_branch_admin'); }
        showLoading();
        fetch('drivers.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            Swal.close();
            if (data.success) { Swal.fire({ icon: 'success', title: 'Success', text: data.message, timer: 1500 }).then(() => { bootstrap.Modal.getInstance(document.getElementById('branchAdminModal'))?.hide(); location.reload(); }); }
            else { Swal.fire('Error', data.message, 'error'); }
        }).catch(e => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
    }

    function saveDriver() {
        const driverId = document.getElementById('driverId').value;
        const fd = new FormData();
        const driverProfilePicture = document.querySelector('#driverForm input[name="profile_picture"]');
        if (driverProfilePicture && driverProfilePicture.files.length > 0) fd.append('profile_picture', driverProfilePicture.files[0]);
        if (driverId) {
            fd.append('action', 'update_user'); fd.append('user_role_type', 'driver');
            fd.append('user_id', document.getElementById('driverUserId').value);
            fd.append('driver_id', driverId);
            fd.append('driver_name', document.getElementById('driverName').value);
            fd.append('license_number', document.getElementById('licenseNumber').value);
            fd.append('license_expiry', document.getElementById('licenseExpiry').value);
            fd.append('contact_number', document.getElementById('contactNumber').value);
            fd.append('vehicle_type', document.getElementById('vehicleType').value);
            fd.append('vehicle_plate_number', document.getElementById('vehiclePlate').value);
            fd.append('branch_id', document.getElementById('driverBranchId').value);
            fd.append('driver_status', document.getElementById('driverStatus').value);
            fd.append('first_name', document.getElementById('driverFirstName').value);
            fd.append('last_name', document.getElementById('driverLastName').value);
            fd.append('email', document.getElementById('driverEmail').value);
            if (document.getElementById('driverPassword').value) fd.append('password', document.getElementById('driverPassword').value);
            fd.append('status', document.getElementById('driverStatus').value);
        } else {
            fd.append('action', 'add_driver');
            fd.append('driver_name', document.getElementById('driverName').value);
            fd.append('license_number', document.getElementById('licenseNumber').value);
            fd.append('license_expiry', document.getElementById('licenseExpiry').value);
            fd.append('contact_number', document.getElementById('contactNumber').value);
            fd.append('vehicle_type', document.getElementById('vehicleType').value);
            fd.append('vehicle_plate_number', document.getElementById('vehiclePlate').value);
            fd.append('branch_id', document.getElementById('driverBranchId').value);
            fd.append('status', document.getElementById('driverStatus').value);
            fd.append('first_name', document.getElementById('driverFirstName').value);
            fd.append('last_name', document.getElementById('driverLastName').value);
            fd.append('email', document.getElementById('driverEmail').value);
            fd.append('password', document.getElementById('driverPassword').value);
        }
        showLoading();
        fetch('drivers.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            Swal.close();
            if (data.success) { Swal.fire({ icon: 'success', title: 'Success', text: data.message, timer: 1500 }).then(() => { bootstrap.Modal.getInstance(document.getElementById('driverModal'))?.hide(); location.reload(); }); }
            else { Swal.fire('Error', data.message, 'error'); }
        }).catch(e => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
    }

    function saveWarehouse() {
        const userId = document.getElementById('warehouseId').value;
        const fd = new FormData(document.getElementById('warehouseForm'));
        if (userId) { fd.append('action', 'update_user'); fd.append('user_role_type', 'warehouse'); fd.append('user_id', userId); }
        else { fd.append('action', 'add_warehouse'); }
        showLoading();
        fetch('drivers.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            Swal.close();
            if (data.success) { Swal.fire({ icon: 'success', title: 'Success', text: data.message, timer: 1500 }).then(() => { bootstrap.Modal.getInstance(document.getElementById('warehouseModal'))?.hide(); location.reload(); }); }
            else { Swal.fire('Error', data.message, 'error'); }
        }).catch(e => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
    }

    function saveSales() {
        const userId = document.getElementById('salesId').value;
        const fd = new FormData(document.getElementById('salesForm'));
        if (userId) { fd.append('action', 'update_user'); fd.append('user_role_type', 'sales'); fd.append('user_id', userId); }
        else { fd.append('action', 'add_sales'); }
        showLoading();
        fetch('drivers.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            Swal.close();
            if (data.success) { Swal.fire({ icon: 'success', title: 'Success', text: data.message, timer: 1500 }).then(() => { bootstrap.Modal.getInstance(document.getElementById('salesModal'))?.hide(); location.reload(); }); }
            else { Swal.fire('Error', data.message, 'error'); }
        }).catch(e => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
    }

    function deleteUser(id, role, driverId) {
        const row = document.querySelector(`.user-row[data-id="${id}"]`);
        if (!row) return;
        document.getElementById('deleteUserName').textContent = row.getAttribute('data-name') || 'this user';
        currentUserId = id; currentUserRole = role; currentDriverId = driverId;
        new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
    }

    function confirmDeleteUser() {
        if (!currentUserId) return Swal.fire('Error', 'No user selected', 'error');
        showLoading();
        const fd = new FormData(); fd.append('action', 'delete_user'); fd.append('user_id', currentUserId);
        fetch('drivers.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            Swal.close();
            if (data.success) { Swal.fire({ icon: 'success', title: 'Deactivated', text: data.message, timer: 1500 }).then(() => { bootstrap.Modal.getInstance(document.getElementById('deleteUserModal'))?.hide(); location.reload(); }); }
            else { Swal.fire('Error', data.message, 'error'); }
        }).catch(e => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
    }

    function exportToExcel() {
        const rows = document.querySelectorAll('.user-row:not([style*="display: none"])');
        if (!rows.length) return Swal.fire('Warning', 'No users to export', 'warning');
        const data = [['Branch', 'Name', 'Email', 'Role', 'Details', 'Status']];
        rows.forEach(row => {
            if (window.innerWidth <= 768 && row.hasAttribute('data-original-html')) {
                // For mobile view, use stored data
                const branchName = row.getAttribute('data-branch-name') || '';
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                const roleText = row.getAttribute('data-role-text') || '';
                const statusText = row.getAttribute('data-status-text') || '';
                data.push([branchName, name, email, roleText, '', statusText]);
            } else {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 5) {
                    data.push([
                        cells[0]?.innerText.trim() || '',
                        cells[1]?.querySelector('strong')?.innerText.trim() || '',
                        cells[1]?.querySelector('small')?.innerText.trim() || '',
                        cells[2]?.innerText.trim() || '',
                        cells[3]?.innerText.replace(/\n/g, ' | ') || '',
                        cells[4]?.innerText.trim() || ''
                    ]);
                }
            }
        });
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), 'Users');
        XLSX.writeFile(wb, `Global_Users_${new Date().toISOString().slice(0,10).replace(/-/g, '')}.xlsx`);
        Swal.fire({ icon: 'success', title: 'Export Complete', timer: 1500, showConfirmButton: false });
    }

    function showLoading() { Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() }); }

    document.addEventListener('DOMContentLoaded', function() {
        if (localStorage.getItem('sidebarCollapsed') === 'true') document.getElementById('sidebar').classList.add('collapsed');
        initMobileNav();
        document.getElementById('mobileToggleBtn').addEventListener('click', toggleSidebar);
        document.getElementById('desktopToggleBtn')?.addEventListener('click', e => { e.stopPropagation(); toggleSidebar(); });
        document.querySelectorAll('.sidebar .nav-link').forEach(l => l.addEventListener('click', () => { if (window.innerWidth <= 992) closeMobileSidebar(); }));
        
        // Handle responsive layout on load and resize
        handleResponsiveLayout();
        
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                initMobileNav();
                handleResponsiveLayout();
            }, 150);
        });
        
        ['branchAdminModal', 'driverModal', 'warehouseModal', 'salesModal', 'viewUserModal', 'deleteUserModal', 'profileModal'].forEach(id => {
            document.getElementById(id)?.addEventListener('hidden.bs.modal', () => { document.querySelector('.modal-backdrop')?.remove(); document.body.classList.remove('modal-open'); });
        });
        const d = new Date(); d.setFullYear(d.getFullYear() + 1);
        if (document.getElementById('licenseExpiry')) document.getElementById('licenseExpiry').value = d.toISOString().split('T')[0];
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
</script>


<script>
// ESC key backup: close any open Bootstrap modal
(function() {
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
            const openModal = document.querySelector('.modal.show');
            if (openModal && window.bootstrap && bootstrap.Modal) {
                const modalInstance = bootstrap.Modal.getInstance(openModal) || new bootstrap.Modal(openModal);
                modalInstance.hide();
            }
        }
    });
})();
</script>

</body>
</html>
<?php $conn->close(); ?>