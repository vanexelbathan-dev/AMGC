<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Handle Add Driver Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_driver') {
    header('Content-Type: application/json');
    
    try {
        // Validate required fields
        $required_fields = ['driver_name', 'license_number', 'branch_id', 'email', 'password', 'first_name', 'last_name'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
            }
        }

        // Validate email format
        $email = trim($_POST['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }

        // Check if email already exists
        $email_check = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $email_check->bind_param("s", $email);
        $email_check->execute();
        $email_result = $email_check->get_result();
        
        if ($email_result->num_rows > 0) {
            throw new Exception('Email already exists in the system');
        }

        // Sanitize and validate inputs
        $driver_name = trim($_POST['driver_name']);
        $license_number = trim($_POST['license_number']);
        $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
        $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
        $vehicle_type = !empty($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : null;
        $vehicle_plate_number = !empty($_POST['vehicle_plate_number']) ? trim($_POST['vehicle_plate_number']) : null;
        $branch_id = intval($_POST['branch_id']);
        $status = isset($_POST['status']) ? $_POST['status'] : 'active';
        $password = $_POST['password'];
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);

        // Check if branch exists
        $branch_check = $conn->prepare("SELECT branch_id, branch_name FROM branches WHERE branch_id = ? AND status = 'active'");
        $branch_check->bind_param("i", $branch_id);
        $branch_check->execute();
        $branch_result = $branch_check->get_result();
        
        if ($branch_result->num_rows === 0) {
            throw new Exception('Selected branch not found or inactive');
        }

        // Check if license number already exists
        $license_check = $conn->prepare("SELECT driver_id FROM drivers WHERE license_number = ?");
        $license_check->bind_param("s", $license_number);
        $license_check->execute();
        $license_result = $license_check->get_result();
        
        if ($license_result->num_rows > 0) {
            throw new Exception('License number already exists in the system');
        }

        // Start transaction
        $conn->begin_transaction();

        // Hash the password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Create user account first
        $user_sql = "INSERT INTO users (email, password_hash, first_name, last_name, role, department, status, branch_id, created_at) 
                     VALUES (?, ?, ?, ?, 'delivery', 'Delivery', ?, ?, NOW())";
        
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("ssssssi", 
            $email,
            $password_hash,
            $first_name,
            $last_name,
            $status,
            $branch_id
        );
        
        if (!$user_stmt->execute()) {
            throw new Exception('Failed to create user account: ' . $conn->error);
        }
        
        $user_id = $conn->insert_id;

        // Insert new driver with user_id
        $insert_sql = "INSERT INTO drivers (driver_name, license_number, license_expiry, contact_number, 
                      vehicle_type, vehicle_plate_number, branch_id, status, user_id, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("ssssssisi", 
            $driver_name, 
            $license_number, 
            $license_expiry, 
            $contact_number, 
            $vehicle_type, 
            $vehicle_plate_number, 
            $branch_id, 
            $status,
            $user_id
        );
        
        if (!$insert_stmt->execute()) {
            throw new Exception('Failed to add driver: ' . $conn->error);
        }
        
        $driver_id = $conn->insert_id;

        // Update user record with driver_id
        $update_user_sql = "UPDATE users SET driver_id = ? WHERE user_id = ?";
        $update_user_stmt = $conn->prepare($update_user_sql);
        $update_user_stmt->bind_param("ii", $driver_id, $user_id);
        
        if (!$update_user_stmt->execute()) {
            throw new Exception('Failed to link user with driver: ' . $conn->error);
        }

        // Commit transaction
        $conn->commit();

        // Get the inserted driver data for response
        $select_sql = "SELECT d.*, b.branch_name, b.branch_code, u.email, u.first_name, u.last_name 
                      FROM drivers d 
                      LEFT JOIN branches b ON d.branch_id = b.branch_id 
                      LEFT JOIN users u ON d.user_id = u.user_id
                      WHERE d.driver_id = ?";
        $select_stmt = $conn->prepare($select_sql);
        $select_stmt->bind_param("i", $driver_id);
        $select_stmt->execute();
        $driver_data = $select_stmt->get_result()->fetch_assoc();

        // Format phone number for response
        $phone = $driver_data['contact_number'] ?? '';
        $formatted_phone = 'N/A';
        if (!empty($phone)) {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone) == 11 && substr($phone, 0, 2) == '09') {
                $formatted_phone = substr($phone, 0, 4) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7, 4);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Driver and user account created successfully',
            'driver' => [
                'id' => $driver_data['driver_id'],
                'name' => $driver_data['driver_name'],
                'email' => $driver_data['email'],
                'phone' => $formatted_phone,
                'location' => $driver_data['branch_name'] ?? 'Unassigned',
                'branch_code' => $driver_data['branch_code'] ?? ''
            ]
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle Update Driver Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_driver') {
    header('Content-Type: application/json');
    
    try {
        $driver_id = intval($_POST['driver_id']);
        
        // Validate required fields
        $required_fields = ['driver_name', 'license_number', 'branch_id', 'email', 'first_name', 'last_name'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
            }
        }

        // Validate email format
        $email = trim($_POST['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }

        // Sanitize and validate inputs
        $driver_name = trim($_POST['driver_name']);
        $license_number = trim($_POST['license_number']);
        $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
        $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
        $vehicle_type = !empty($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : null;
        $vehicle_plate_number = !empty($_POST['vehicle_plate_number']) ? trim($_POST['vehicle_plate_number']) : null;
        $branch_id = intval($_POST['branch_id']);
        $status = isset($_POST['status']) ? $_POST['status'] : 'active';
        $password = !empty($_POST['password']) ? $_POST['password'] : null;
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);

        // Check if email already exists for another user
        $email_check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != (SELECT user_id FROM drivers WHERE driver_id = ?)");
        $email_check->bind_param("si", $email, $driver_id);
        $email_check->execute();
        $email_result = $email_check->get_result();
        
        if ($email_result->num_rows > 0) {
            throw new Exception('Email already exists in the system');
        }

        // Check if license number already exists for another driver
        $license_check = $conn->prepare("SELECT driver_id FROM drivers WHERE license_number = ? AND driver_id != ?");
        $license_check->bind_param("si", $license_number, $driver_id);
        $license_check->execute();
        $license_result = $license_check->get_result();
        
        if ($license_result->num_rows > 0) {
            throw new Exception('License number already exists in the system');
        }

        // Check if branch exists
        $branch_check = $conn->prepare("SELECT branch_id FROM branches WHERE branch_id = ? AND status = 'active'");
        $branch_check->bind_param("i", $branch_id);
        $branch_check->execute();
        $branch_result = $branch_check->get_result();
        
        if ($branch_result->num_rows === 0) {
            throw new Exception('Selected branch not found or inactive');
        }

        // Start transaction
        $conn->begin_transaction();

        // Get user_id from driver
        $user_query = $conn->prepare("SELECT user_id FROM drivers WHERE driver_id = ?");
        $user_query->bind_param("i", $driver_id);
        $user_query->execute();
        $user_result = $user_query->get_result();
        $driver_data = $user_result->fetch_assoc();
        $user_id = $driver_data['user_id'];

        // Update user account
        if ($password) {
            // Update with new password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $user_sql = "UPDATE users SET email = ?, first_name = ?, last_name = ?, password_hash = ?, branch_id = ?, status = ? WHERE user_id = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("ssssssi", $email, $first_name, $last_name, $password_hash, $branch_id, $status, $user_id);
        } else {
            // Update without password
            $user_sql = "UPDATE users SET email = ?, first_name = ?, last_name = ?, branch_id = ?, status = ? WHERE user_id = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("sssssi", $email, $first_name, $last_name, $branch_id, $status, $user_id);
        }
        
        if (!$user_stmt->execute()) {
            throw new Exception('Failed to update user account: ' . $conn->error);
        }

        // Update driver
        $update_sql = "UPDATE drivers SET driver_name = ?, license_number = ?, license_expiry = ?, contact_number = ?, 
                       vehicle_type = ?, vehicle_plate_number = ?, branch_id = ?, status = ? WHERE driver_id = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ssssssssi", 
            $driver_name, 
            $license_number, 
            $license_expiry, 
            $contact_number, 
            $vehicle_type, 
            $vehicle_plate_number, 
            $branch_id, 
            $status,
            $driver_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update driver: ' . $conn->error);
        }

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Driver and user account updated successfully'
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle Delete Driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_driver') {
    header('Content-Type: application/json');
    
    try {
        $driver_id = intval($_POST['driver_id']);

        // Start transaction
        $conn->begin_transaction();

        // Get user_id from driver
        $user_query = $conn->prepare("SELECT user_id FROM drivers WHERE driver_id = ?");
        $user_query->bind_param("i", $driver_id);
        $user_query->execute();
        $user_result = $user_query->get_result();
        $driver_data = $user_result->fetch_assoc();
        $user_id = $driver_data['user_id'];

        // Check if driver is used in any pick lists or trip tickets
        $check_picklist = $conn->prepare("SELECT COUNT(*) as count FROM pick_lists WHERE driver_id = ?");
        $check_picklist->bind_param("i", $driver_id);
        $check_picklist->execute();
        $picklist_result = $check_picklist->get_result();
        $picklist_count = $picklist_result->fetch_assoc()['count'];

        $check_trip = $conn->prepare("SELECT COUNT(*) as count FROM trip_tickets WHERE driver_id = ?");
        $check_trip->bind_param("i", $driver_id);
        $check_trip->execute();
        $trip_result = $check_trip->get_result();
        $trip_count = $trip_result->fetch_assoc()['count'];

        if ($picklist_count > 0 || $trip_count > 0) {
            // Soft delete - update status to inactive
            $update_driver = $conn->prepare("UPDATE drivers SET status = 'inactive' WHERE driver_id = ?");
            $update_driver->bind_param("i", $driver_id);
            $update_driver->execute();

            $update_user = $conn->prepare("UPDATE users SET status = 'inactive' WHERE user_id = ?");
            $update_user->bind_param("i", $user_id);
            $update_user->execute();

            $message = 'Driver marked as inactive (used in existing transactions)';
        } else {
            // Hard delete
            $delete_user = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $delete_user->bind_param("i", $user_id);
            $delete_user->execute();

            $delete_driver = $conn->prepare("DELETE FROM drivers WHERE driver_id = ?");
            $delete_driver->bind_param("i", $driver_id);
            $delete_driver->execute();

            $message = 'Driver deleted successfully';
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => $message
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle Get Driver Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_driver') {
    header('Content-Type: application/json');
    
    try {
        $driver_id = intval($_POST['driver_id']);

        $sql = "SELECT 
                    d.*,
                    u.user_id,
                    u.email,
                    u.first_name,
                    u.last_name,
                    u.status as user_status,
                    u.role as user_role,
                    b.branch_name,
                    b.branch_code
                FROM drivers d
                LEFT JOIN users u ON d.user_id = u.user_id
                LEFT JOIN branches b ON d.branch_id = b.branch_id
                WHERE d.driver_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $driver_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $driver = $result->fetch_assoc();

        if ($driver) {
            echo json_encode([
                'success' => true,
                'driver' => $driver
            ]);
        } else {
            throw new Exception('Driver not found');
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$location = isset($_GET['location']) ? $_GET['location'] : '';
$license = isset($_GET['license']) ? $_GET['license'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';

// Handle AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $response = [
        'success' => true,
        'drivers' => [],
        'stats' => [
            'totalDrivers' => 0,
            'activeDrivers' => 0,
            'onLeave' => 0,
            'avgRating' => 0
        ]
    ];

    // Build WHERE clause for filtering
    $where_conditions = ["1=1"];
    $params = [];
    $types = "";

    // Status filter
    if (!empty($status)) {
        $where_conditions[] = "d.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    // Branch/Location filter - only show drivers assigned to selected branch
    if (!empty($location) && is_numeric($location)) {
        $where_conditions[] = "d.branch_id = ?";
        $params[] = $location;
        $types .= "i";
    }

    // License status filter
    if (!empty($license)) {
        $today = date('Y-m-d');
        if ($license == 'valid') {
            $where_conditions[] = "d.license_expiry > DATE_ADD(?, INTERVAL 30 DAY)";
            $params[] = $today;
            $types .= "s";
        } elseif ($license == 'expiring') {
            $where_conditions[] = "d.license_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)";
            $params[] = $today;
            $params[] = $today;
            $types .= "ss";
        } elseif ($license == 'expired') {
            $where_conditions[] = "d.license_expiry < ?";
            $params[] = $today;
            $types .= "s";
        }
    }

    $where_clause = implode(" AND ", $where_conditions);

    // Get drivers with branch and user information
    $sql = "SELECT 
                d.driver_id as id,
                d.driver_name as name,
                d.license_number as license_no,
                d.license_expiry,
                d.contact_number as phone,
                d.vehicle_type,
                d.vehicle_plate_number as vehicle_id,
                d.status,
                d.branch_id,
                d.user_id,
                b.branch_name as location,
                b.branch_code,
                u.email,
                u.first_name,
                u.last_name,
                CONCAT(u.first_name, ' ', u.last_name) as user_name
            FROM drivers d
            LEFT JOIN branches b ON d.branch_id = b.branch_id
            LEFT JOIN users u ON d.user_id = u.user_id
            WHERE $where_clause";

    // Add sorting
    switch ($sort) {
        case 'name':
            $sql .= " ORDER BY d.driver_name ASC";
            break;
        case 'rating':
            $sql .= " ORDER BY d.driver_name ASC"; // Will be sorted in PHP
            break;
        case 'trips':
            $sql .= " ORDER BY d.driver_name ASC"; // Will be sorted in PHP
            break;
        default:
            $sql .= " ORDER BY d.driver_name ASC";
    }

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $total_drivers = 0;
    $active_count = 0;
    $on_leave_count = 0;
    $total_rating = 0;
    $rating_count = 0;

    while ($row = $result->fetch_assoc()) {
        $total_drivers++;
        
        // Count by status
        if ($row['status'] == 'active') $active_count++;
        elseif ($row['status'] == 'on-leave') $on_leave_count++;
        
        // Calculate license status
        $license_status = 'valid';
        if (!empty($row['license_expiry']) && $row['license_expiry'] != '0000-00-00') {
            $expiry = strtotime($row['license_expiry']);
            $today = time();
            $days_until_expiry = ($expiry - $today) / (60 * 60 * 24);
            
            if ($expiry < $today) {
                $license_status = 'expired';
            } elseif ($days_until_expiry <= 30) {
                $license_status = 'expiring';
            }
        }

        // Format phone number to PH format
        $phone = $row['phone'] ?? '';
        $formatted_phone = 'N/A';
        if (!empty($phone)) {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone) == 11 && substr($phone, 0, 2) == '09') {
                $formatted_phone = substr($phone, 0, 4) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7, 4);
            } elseif (strlen($phone) > 0) {
                $formatted_phone = $phone;
            }
        }

        // Mock rating and trips (can be replaced with actual data later)
        $rating = 4.0;
        $trips = 0;
        
        if ($row['status'] == 'active') {
            $rating = 4.5;
            $trips = rand(80, 200);
        } elseif ($row['status'] == 'on-leave') {
            $rating = 4.0;
            $trips = rand(30, 79);
        } else {
            $rating = 3.5;
            $trips = rand(0, 29);
        }
        
        $total_rating += $rating;
        $rating_count++;

        $response['drivers'][] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'email' => $row['email'] ?? 'N/A',
            'license_no' => $row['license_no'],
            'license_expiry' => !empty($row['license_expiry']) && $row['license_expiry'] != '0000-00-00' ? date('M d, Y', strtotime($row['license_expiry'])) : 'N/A',
            'license_status' => $license_status,
            'vehicle_id' => $row['vehicle_id'] ?? $row['vehicle_type'] ?? 'N/A',
            'status' => $row['status'],
            'rating' => $rating,
            'trips_completed' => $trips,
            'phone' => $formatted_phone,
            'location' => $row['location'] ?? 'Unassigned',
            'branch_id' => $row['branch_id'] ?? null,
            'branch_code' => $row['branch_code'] ?? '',
            'user_id' => $row['user_id'] ?? null,
            'first_name' => $row['first_name'] ?? '',
            'last_name' => $row['last_name'] ?? ''
        ];
    }

    // Manual sorting for rating and trips
    if ($sort == 'rating') {
        usort($response['drivers'], function($a, $b) {
            return $b['rating'] <=> $a['rating'];
        });
    } elseif ($sort == 'trips') {
        usort($response['drivers'], function($a, $b) {
            return $b['trips_completed'] <=> $a['trips_completed'];
        });
    }

    $response['stats'] = [
        'totalDrivers' => $total_drivers,
        'activeDrivers' => $active_count,
        'onLeave' => $on_leave_count,
        'avgRating' => $rating_count > 0 ? round($total_rating / $rating_count, 1) : 0
    ];

    echo json_encode($response);
    exit;
}

// Handle AJAX request for driver details
if (isset($_GET['ajax_details']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $driver_id = intval($_GET['id']);
    
    $sql = "SELECT 
                d.*,
                u.user_id,
                u.email,
                u.first_name,
                u.last_name,
                u.status as user_status,
                u.role as user_role,
                b.branch_name,
                b.branch_code
            FROM drivers d
            LEFT JOIN users u ON d.user_id = u.user_id
            LEFT JOIN branches b ON d.branch_id = b.branch_id
            WHERE d.driver_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $driver_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Calculate license status
        $license_status = 'valid';
        if (!empty($row['license_expiry']) && $row['license_expiry'] != '0000-00-00') {
            $expiry = strtotime($row['license_expiry']);
            $today = time();
            $days_until_expiry = ($expiry - $today) / (60 * 60 * 24);
            
            if ($expiry < $today) {
                $license_status = 'expired';
            } elseif ($days_until_expiry <= 30) {
                $license_status = 'expiring';
            }
        }

        // Format phone number to PH format
        $phone = $row['contact_number'] ?? '';
        $formatted_phone = 'N/A';
        if (!empty($phone)) {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone) == 11 && substr($phone, 0, 2) == '09') {
                $formatted_phone = substr($phone, 0, 4) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7, 4);
            } elseif (strlen($phone) > 0) {
                $formatted_phone = $phone;
            }
        }

        // Calculate mock rating and trips
        $rating = 4.0;
        $trips = 0;
        
        if ($row['status'] == 'active') {
            $rating = 4.5;
            $trips = rand(80, 200);
        } elseif ($row['status'] == 'on-leave') {
            $rating = 4.0;
            $trips = rand(30, 79);
        } else {
            $rating = 3.5;
            $trips = rand(0, 29);
        }

        $response = [
            'success' => true,
            'driver' => [
                'id' => $row['driver_id'],
                'name' => $row['driver_name'],
                'email' => $row['email'] ?? 'N/A',
                'phone' => $formatted_phone,
                'license_no' => $row['license_number'],
                'license_expiry' => !empty($row['license_expiry']) && $row['license_expiry'] != '0000-00-00' ? date('F d, Y', strtotime($row['license_expiry'])) : 'N/A',
                'license_status' => $license_status,
                'vehicle_id' => $row['vehicle_plate_number'] ?? $row['vehicle_type'] ?? 'N/A',
                'vehicle_type' => $row['vehicle_type'] ?? 'N/A',
                'status' => $row['status'],
                'rating' => $rating,
                'trips_completed' => $trips,
                'joined_date' => !empty($row['created_at']) ? date('F d, Y', strtotime($row['created_at'])) : 'N/A',
                'location' => $row['branch_name'] ?? 'Unassigned',
                'branch_code' => $row['branch_code'] ?? '',
                'branch_id' => $row['branch_id'] ?? null,
                'user_id' => $row['user_id'] ?? null,
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'user_status' => $row['user_status'] ?? '',
                'user_role' => $row['user_role'] ?? ''
            ]
        ];
    } else {
        $response = ['success' => false, 'message' => 'Driver not found'];
    }
    
    echo json_encode($response);
    exit;
}

// Get branches for filter dropdown and add driver form
$branches_sql = "SELECT branch_id, branch_name, branch_code FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = $conn->query($branches_sql);
$branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $branches[] = $row;
}

// Get user info from session
$user_name = $_SESSION['user_name'] ?? 'Quality Control';
$user_role = $_SESSION['user_role'] ?? 'QC Officer';
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
    $user_initials = 'AD';
}

// Helper functions for status badges
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

function formatDate($dateStr) {
    if (!$dateStr || $dateStr == '0000-00-00') return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Drivers</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/global.css">
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
        /* Table styles - identical to BranchAdmin */
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
        .col-branch { width: 8%; }
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
        
        /* Filter section - identical to BranchAdmin */
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
        
        /* Modal styling - identical to BranchAdmin */
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
            color: #FF6B35;
            border-bottom: 2px solid #FF6B35;
            padding-bottom: 5px;
        }

        .add-driver-btn {
            background-color: #FF6B35;
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 5px;
            font-weight: 500;
            transition: background-color 0.3s;
            min-width: 180px;
        }
        .add-driver-btn:hover {
            background-color: #e55a2b;
            color: white;
        }
        .modal-header.bg-primary {
            background-color: #FF6B35 !important;
        }
        .btn-primary {
            background-color: #FF6B35;
            border-color: #FF6B35;
        }
        .btn-primary:hover {
            background-color: #e55a2b;
            border-color: #e55a2b;
        }
        .form-section-title {
            color: #FF6B35;
            border-bottom-color: #FF6B35;
        }

        /* Hide ID column */
        .id-column, th:nth-child(1), td:nth-child(1) {
            display: none;
        }

        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .stat-card {
                padding: 12px;
                min-height: 85px;
                margin-bottom: 8px;
            }
            .stat-icon {
                font-size: 2rem;
                margin-right: 12px;
            }
            .stat-value {
                font-size: 1.5rem;
            }
            .stat-label {
                font-size: 0.8rem;
            }
            .col-md-3 {
                width: 50%;
                padding-left: 8px;
                padding-right: 8px;
            }
            .row.g-3 {
                margin-left: -8px;
                margin-right: -8px;
            }
            .mb-3 {
                margin-bottom: 8px !important;
            }
            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-controls {
                flex-direction: column;
            }
            .filter-dropdowns {
                width: 100%;
            }
            .filter-dropdown {
                width: 100%;
            }
            .search-box {
                width: 100%;
            }
        }
        @media (max-width: 576px) {
            .stat-card {
                min-height: 80px;
                padding: 10px;
            }
            .stat-icon {
                font-size: 1.8rem;
                margin-right: 10px;
            }
            .stat-value {
                font-size: 1.3rem;
            }
            .stat-label {
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
                        <i class="bi bi-list"></i>
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
                            <i class="bi bi-person-badge"></i>
                            <span class="nav-text">Drivers</span>
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
                </ul>
            </div>
            <!-- User Profile Section at the bottom of sidebar -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                        <span class="user-role-sidebar"><?php echo htmlspecialchars($user_role); ?></span>
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
            <!-- DRIVERS PAGE -->
            <div id="driversContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>

                    <div class="page-title">
                        <h2>All Drivers</h2>
                        <p>Manage and view all drivers across all locations</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalDrivers">0</div>
                            <div class="stat-label">Total Drivers</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sales">
                            <div class="stat-value" id="activeDrivers">0</div>
                            <div class="stat-label">Active Drivers</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="onLeave">0</div>
                            <div class="stat-label">On Leave</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="avgRating">0/5</div>
                            <div class="stat-label">Avg Rating</div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION - Identical to BranchAdmin -->
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
                            
                            <!-- Location Filter -->
                            <div class="filter-dropdown">
                                <select class="form-select" id="locationFilter" onchange="filterDrivers()">
                                    <option value="all">All Locations</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['branch_id']; ?>">
                                            <?php echo htmlspecialchars($branch['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- License Status Filter -->
                            <div class="filter-dropdown">
                                <select class="form-select" id="licenseFilter" onchange="filterDrivers()">
                                    <option value="all">All License</option>
                                    <option value="valid">Valid</option>
                                    <option value="expiring">Expiring Soon</option>
                                    <option value="expired">Expired</option>
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
                        <button class="add-driver-btn" onclick="showAddDriverModal()">
                            <i class="bi bi-plus-circle me-1"></i> Add Driver
                        </button>
                    </div>
                </div>

                <!-- DRIVERS TABLE - Identical to BranchAdmin structure -->
                <div class="data-table">
                    <div class="table-responsive">
                        <table class="table driver-table" id="driversTable">
                            <thead>
                                <tr>
                                    <th class="id-column">ID</th>
                                    <th class="col-name">DRIVER NAME</th>
                                    <th class="col-license">LICENSE #</th>
                                    <th class="col-license">EXPIRY</th>
                                    <th class="col-contact">CONTACT</th>
                                    <th class="col-vehicle">VEHICLE</th>
                                    <th class="col-status">STATUS</th>
                                    <th class="col-branch">BRANCH</th>
                                    <th class="col-user">USER ACCOUNT</th>
                                    <th class="col-actions">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="driversTableBody">
                                <tr>
                                    <td colspan="10" class="text-center py-4">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading drivers...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT DRIVER MODAL - Identical to BranchAdmin -->
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
                                    <label for="branchId" class="form-label">Branch *</label>
                                    <select class="form-select" id="branchId" name="branch_id" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id']; ?>">
                                                <?php echo htmlspecialchars($branch['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
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

    <!-- VIEW DRIVER MODAL - Identical to BranchAdmin -->
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

    <!-- DELETE CONFIRMATION MODAL - Identical to BranchAdmin -->
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
                } else {
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => overlay?.remove(), 300);
                    }
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
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
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('active', 'collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                overlay?.remove();
                sidebar.classList.remove('active');
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
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
                    
                    const expiryDate = driver.license_expiry && driver.license_expiry != '0000-00-00' ? 
                        new Date(driver.license_expiry).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Not set';
                    const createdDate = driver.created_at ? new Date(driver.created_at).toLocaleString() : 'N/A';
                    
                    let branchHtml = '';
                    if (driver.branch_id) {
                        branchHtml = `
                            <tr>
                                <td class="detail-label">Branch:</td>
                                <td><span class="badge bg-info">${driver.location || 'Branch ' + driver.branch_id}</span></td>
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
                                    <span class="badge ${driver.user_status === 'active' ? 'bg-success' : 'bg-secondary'}">${driver.user_status || 'active'}</span>
                                    <br>
                                    <small class="text-muted">Role: ${driver.user_role || 'delivery'}</small>
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
                                        <td class="fw-bold">${escapeHtml(driver.name)}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">License Number:</td>
                                        <td>${escapeHtml(driver.license_no)}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">License Expiry:</td>
                                        <td>
                                            ${expiryDate}
                                            ${driver.license_status === 'expired' ? '<span class="badge bg-danger ms-2">Expired</span>' : ''}
                                            ${driver.license_status === 'expiring' ? '<span class="badge bg-warning ms-2">Expiring Soon</span>' : ''}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Contact Number:</td>
                                        <td>${escapeHtml(driver.phone) || 'Not provided'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Vehicle Type:</td>
                                        <td>${escapeHtml(driver.vehicle_type) || 'Not specified'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Plate Number:</td>
                                        <td>${escapeHtml(driver.vehicle_id) || 'Not specified'}</td>
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
                                        <td class="detail-label">Joined Date:</td>
                                        <td>${escapeHtml(driver.joined_date)}</td>
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
                    document.getElementById('driverId').value = driver.id;
                    
                    // Driver fields
                    document.getElementById('driverName').value = driver.name || '';
                    document.getElementById('licenseNumber').value = driver.license_no || '';
                    document.getElementById('licenseExpiry').value = driver.license_expiry ? new Date(driver.license_expiry).toISOString().split('T')[0] : '';
                    document.getElementById('contactNumber').value = driver.phone ? driver.phone.replace(/-/g, '') : '';
                    document.getElementById('vehicleType').value = driver.vehicle_type || '';
                    document.getElementById('vehiclePlate').value = driver.vehicle_id || '';
                    document.getElementById('branchId').value = driver.branch_id || '';
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
            const branchId = document.getElementById('branchId').value;
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
            
            if (!branchId) {
                Swal.fire('Warning', 'Branch is required', 'warning');
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
                        loadDrivers(); // Reload the drivers list
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
            const row = document.querySelector(`#driversTableBody tr[data-id="${id}"]`);
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
                        loadDrivers(); // Reload the drivers list
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

        // ========== FILTER FUNCTIONS ==========
        function filterDrivers() {
            const statusFilter = document.getElementById('statusFilter').value;
            const locationFilter = document.getElementById('locationFilter').value;
            const licenseFilter = document.getElementById('licenseFilter').value;
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            
            const rows = document.querySelectorAll('#driversTableBody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                if (row.cells.length <= 1) return; // Skip empty state row
                
                let showRow = true;
                
                // Status filter
                if (statusFilter !== 'all') {
                    const statusCell = row.cells[6]; // Status column index
                    const statusText = statusCell ? statusCell.innerText.trim().toLowerCase() : '';
                    if (!statusText.includes(statusFilter.toLowerCase())) {
                        showRow = false;
                    }
                }
                
                // Location filter
                if (showRow && locationFilter !== 'all') {
                    const branchCell = row.cells[7]; // Branch column index
                    const branchText = branchCell ? branchCell.innerText.trim() : '';
                    if (!branchText.includes(locationFilter)) {
                        showRow = false;
                    }
                }
                
                // License filter
                if (showRow && licenseFilter !== 'all') {
                    const expiryCell = row.cells[3]; // License expiry column
                    const expiryText = expiryCell ? expiryCell.innerText.toLowerCase() : '';
                    
                    if (licenseFilter === 'expired' && !expiryText.includes('expired')) {
                        showRow = false;
                    } else if (licenseFilter === 'expiring' && !expiryText.includes('days')) {
                        showRow = false;
                    } else if (licenseFilter === 'valid' && (expiryText.includes('expired') || expiryText.includes('days'))) {
                        showRow = false;
                    }
                }
                
                // Search filter
                if (showRow && searchTerm !== '') {
                    const rowText = row.innerText.toLowerCase();
                    if (!rowText.includes(searchTerm)) {
                        showRow = false;
                    }
                }
                
                row.style.display = showRow ? '' : 'none';
                if (showRow) visibleCount++;
            });
        }

        // ========== LOAD DRIVERS ==========
        async function loadDrivers() {
            const tbody = document.getElementById('driversTableBody');
            tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading drivers...</p></td></tr>';
            
            try {
                const status = document.getElementById('statusFilter').value === 'all' ? '' : document.getElementById('statusFilter').value;
                const location = document.getElementById('locationFilter').value === 'all' ? '' : document.getElementById('locationFilter').value;
                const license = document.getElementById('licenseFilter').value === 'all' ? '' : document.getElementById('licenseFilter').value;
                const sort = document.getElementById('sortFilter')?.value || 'name';

                const params = new URLSearchParams({
                    ajax: 1,
                    status: status,
                    location: location,
                    license: license,
                    sort: sort
                });

                const response = await fetch('drivers.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    displayDrivers(data.drivers || []);
                    updateDriverStats(data.stats || {});
                } else {
                    tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-danger">Error loading drivers</td></tr>';
                }
            } catch (error) {
                console.error('Error loading drivers:', error);
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-danger">Error loading drivers</td></tr>';
            }
        }

        function displayDrivers(drivers) {
            const tbody = document.getElementById('driversTableBody');
            
            if (drivers.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10" class="empty-state-table">
                            <i class="bi bi-truck"></i>
                            <h5>No Drivers Found</h5>
                            <p class="text-muted">Click "Add Driver" to create a new driver with user account.</p>
                            <button class="btn btn-primary mt-2" onclick="showAddDriverModal()">
                                <i class="bi bi-plus-circle me-1"></i> Add Driver
                            </button>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = drivers.map(driver => {
                // Check license expiry
                let expiryWarning = '';
                if (driver.license_status === 'expired') {
                    expiryWarning = '<span class="license-expiry-warning"><i class="bi bi-exclamation-triangle"></i> Expired</span>';
                } else if (driver.license_status === 'expiring') {
                    const days = Math.ceil((new Date(driver.license_expiry) - new Date()) / (1000 * 60 * 60 * 24));
                    expiryWarning = `<span class="license-expiry-warning"><i class="bi bi-clock"></i> ${days} days</span>`;
                }

                // User account badge
                const userBadge = driver.user_id ? 
                    `<span> ${escapeHtml(driver.email)}</span>` : 
                    `<span class="text-muted fst-italic">No account</span>`;

                return `
                <tr class="driver-row" 
                    data-id="${driver.id}"
                    data-name="${escapeHtml(driver.name)}"
                    data-license="${escapeHtml(driver.license_no)}"
                    data-status="${driver.status}">
                    <td class="id-column">${driver.id}</td>
                    <td class="col-name">
                        <strong>${escapeHtml(driver.name)}</strong>
                    </td>
                    <td class="col-license">${escapeHtml(driver.license_no)}</td>
                    <td class="col-license">
                        ${escapeHtml(driver.license_expiry)}
                        ${expiryWarning}
                    </td>
                    <td class="col-contact">${escapeHtml(driver.phone)}</td>
                    <td class="col-vehicle">
                        ${escapeHtml(driver.vehicle_id || 'N/A')}
                        <small class="d-block text-muted">${escapeHtml(driver.vehicle_type || '')}</small>
                    </td>
                    <td class="col-status">
                        <span class="status-badge ${getStatusClass(driver.status)}">
                            ${getStatusText(driver.status)}
                        </span>
                    </td>
                    <td class="col-branch">
                        <span class="badge bg-info">${escapeHtml(driver.location)}</span>
                    </td>
                    <td class="col-user">
                        ${userBadge}
                    </td>
                    <td class="col-actions">
                        <div class="action-buttons">
                            <button class="table-btn btn-view" onclick="viewDriver(${driver.id})" title="View">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="table-btn btn-edit" onclick="editDriver(${driver.id})" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="table-btn btn-delete" onclick="deleteDriver(${driver.id})" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            }).join('');
        }

        function updateDriverStats(stats) {
            document.getElementById('totalDrivers').textContent = stats.totalDrivers || 0;
            document.getElementById('activeDrivers').textContent = stats.activeDrivers || 0;
            document.getElementById('onLeave').textContent = stats.onLeave || 0;
            document.getElementById('avgRating').textContent = (stats.avgRating || 0).toFixed(1) + '/5';
        }

        // ========== EXCEL EXPORT ==========
        function exportToExcel() {
            const rows = document.querySelectorAll('#driversTableBody tr.driver-row');
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
                'Branch',
                'Email',
                'User First Name',
                'User Last Name',
                'User Status'
            ];
            excelData.push(headers);

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                let cellIndex = 1; // Skip ID column
                
                const name = cells[cellIndex++]?.innerText || '';
                const license = cells[cellIndex++]?.innerText || '';
                const expiry = cells[cellIndex++]?.innerText || '';
                const contact = cells[cellIndex++]?.innerText || '';
                const vehicle = cells[cellIndex++]?.innerText || '';
                const status = cells[cellIndex++]?.innerText || '';
                const branch = cells[cellIndex++]?.innerText || '';
                const userCell = cells[cellIndex++]?.innerHTML || '';
                
                // Parse user info
                let email = '', userFirstName = '', userLastName = '', userStatus = '';
                if (userCell.includes('person-check')) {
                    const emailMatch = userCell.match(/>([^<]+)</);
                    if (emailMatch) email = emailMatch[1].trim();
                    
                    // Simplified - actual data would need proper parsing
                    userStatus = 'active';
                }
                
                // Parse vehicle info
                let vehicleType = '', plateNumber = '';
                const vehicleParts = vehicle.split('\n');
                vehicleType = vehicleParts[0]?.trim() || '';
                plateNumber = vehicleParts[1]?.trim() || '';
                
                const rowData = [
                    name,
                    license,
                    expiry.replace(/[^A-Za-z0-9\s,]/g, ''), // Remove badges
                    contact,
                    vehicleType,
                    plateNumber,
                    status,
                    branch.replace(/<[^>]*>/g, ''), // Remove HTML tags
                    email,
                    '', // First name - would need actual data
                    '', // Last name - would need actual data
                    userStatus
                ];
                
                excelData.push(rowData);
            });

            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet(excelData);

            const colWidths = [
                { wch: 20 }, { wch: 15 }, { wch: 15 }, { wch: 15 }, 
                { wch: 15 }, { wch: 12 }, { wch: 10 }, { wch: 15 },
                { wch: 25 }, { wch: 15 }, { wch: 15 }, { wch: 12 }
            ];
            ws['!cols'] = colWidths;

            XLSX.utils.book_append_sheet(wb, ws, 'Drivers');

            const date = new Date();
            const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
            const filename = `Global_Drivers_${dateStr}.xlsx`;

            XLSX.writeFile(wb, filename);
            
            Swal.fire({
                icon: 'success',
                title: 'Export Complete',
                text: 'Drivers exported successfully!',
                timer: 2000,
                showConfirmButton: false
            });
        }

        // ========== HELPER FUNCTIONS ==========
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

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
                confirmButtonColor: '#FF6B35',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // ========== INITIALIZE ==========
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
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
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileToggleBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileToggleBtn || !mobileToggleBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            window.addEventListener('resize', handleSidebarResize);

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

            // Add sort filter dropdown if not exists
            if (!document.getElementById('sortFilter')) {
                const filterControls = document.querySelector('.filter-dropdowns');
                if (filterControls) {
                    const sortDiv = document.createElement('div');
                    sortDiv.className = 'filter-dropdown';
                    sortDiv.innerHTML = `
                        <select class="form-select" id="sortFilter" onchange="loadDrivers()">
                            <option value="name">Sort By: Name</option>
                            <option value="rating">Rating (Highest First)</option>
                            <option value="trips">Trips (Most First)</option>
                        </select>
                    `;
                    filterControls.appendChild(sortDiv);
                }
            }

            loadDrivers();
        });

        // ========== KEYBOARD SHORTCUTS ==========
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            else if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                showAddDriverModal();
            }
            else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
            else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                loadDrivers();
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>