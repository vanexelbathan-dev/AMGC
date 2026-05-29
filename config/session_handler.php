<?php
session_start();

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database
require_once __DIR__ . '/database.php';

// ========== REMEMBER ME FUNCTION ==========
function checkRememberMe() {
    global $conn;
    
    // Check if already logged in
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        return true;
    }
    
    // Check for remember me cookie
    if (isset($_COOKIE['remember_token'])) {
        list($user_id, $token) = explode(':', $_COOKIE['remember_token'], 2);
        
        $sql = "SELECT u.*, ut.token as stored_token, ut.expires_at 
                FROM users u 
                JOIN user_tokens ut ON u.user_id = ut.user_id 
                WHERE u.user_id = ? AND u.status = 'active' 
                AND ut.expires_at > NOW() 
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if (password_verify($token, $user['stored_token'])) {
                // Restore session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'] ?? $user['first_name'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['user_name'] = $user['first_name'] . " " . $user['last_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['department'] = $user['department'];
                $_SESSION['branch_id'] = isset($user['branch_id']) ? $user['branch_id'] : 0;
                $_SESSION['logged_in'] = true;
                
                // Refresh cookie expiration (30 days)
                $new_expiry = time() + (86400 * 30);
                // FIXED: Removed the 8th argument 'Lax'
                setcookie(
                    'remember_token',
                    $user_id . ':' . $token,
                    $new_expiry,
                    '/',
                    '',
                    false,
                    true
                );
                
                return true;
            }
        }
    }
    
    return false;
}

// ========== SESSION FUNCTIONS ==========

/* Check if user is logged in */
function isLoggedIn() {
    // If not logged in, try to restore from remember me
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        checkRememberMe();
    }
    
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function getUserName() {
    return $_SESSION['user_name'] ?? $_SESSION['username'] ?? '';
}

/* Get user role */
function getUserRole() {
    return $_SESSION['role'] ?? '';
}

function getUserId() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
}

function getUserBranchId() {
    return isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : 0;
}

function getUserEmail() {
    return $_SESSION['user_email'] ?? '';
}

/* Check role */
function hasRole($required_roles) {
    if (!isLoggedIn()) return false;

    $user_role = strtolower(getUserRole());

    if (is_array($required_roles)) {
        foreach ($required_roles as $role) {
            if ($user_role === strtolower($role)) {
                return true;
            }
        }
        return false;
    }

    return $user_role === strtolower($required_roles);
}

/* Require login - redirect to index.php not login.php */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: ../index.php");
        exit;
    }
}

/* Require specific role */
function requireRole($roles) {
    requireLogin();

    if (!hasRole($roles)) {
        header("HTTP/1.0 403 Forbidden");
        die("Access Denied. You do not have permission to access this page.");
    }
}

// ========== AUTO-RUN ON INCLUDE ==========
// This will automatically check remember me when the file is included
// But only if not on the login page
$current_file = basename($_SERVER['PHP_SELF']);
if ($current_file != 'index.php' && $current_file != 'login.php') {
    isLoggedIn(); // This will trigger remember me check
}
?>