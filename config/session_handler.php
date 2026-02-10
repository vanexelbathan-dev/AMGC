<?php
/**
 * Session Handler and Authentication Functions
 * 
 * This file contains session management and authentication functions
 * Include this file in any page that requires authentication
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 * 
 * @return bool True if user is logged in
 */
function isUserLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Redirect to login if user is not authenticated
 */
function requireLogin() {
    if (!isUserLoggedIn()) {
        header('Location: ' . getBaseUrl() . 'login.php');
        exit;
    }
}

/**
 * Check if user has a specific role
 * 
 * @param string|array $required_roles Role(s) to check against
 * @return bool True if user has required role
 */
function hasRole($required_roles) {
    if (!isUserLoggedIn()) {
        return false;
    }
    
    $user_role = $_SESSION['user_role'] ?? '';
    
    // If single role provided, convert to array
    if (is_string($required_roles)) {
        $required_roles = [$required_roles];
    }
    
    return in_array($user_role, $required_roles);
}

/**
 * Require user to have a specific role
 * 
 * @param string|array $required_roles Role(s) required to access page
 */
function requireRole($required_roles) {
    if (!hasRole($required_roles)) {
        header('HTTP/1.0 403 Forbidden');
        die('Access Denied. You do not have permission to access this page.');
    }
}

/**
 * Get current user information
 * 
 * @return array|null User data or null if not logged in
 */
function getCurrentUser() {
    if (!isUserLoggedIn()) {
        return null;
    }
    
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? null,
        'email' => $_SESSION['user_email'] ?? null,
        'role' => $_SESSION['user_role'] ?? null,
        'branch_id' => $_SESSION['branch_id'] ?? null
    ];
}

/**
 * Get user's branch ID
 * 
 * @return int|null Branch ID or null if not set
 */
function getUserBranchId() {
    return $_SESSION['branch_id'] ?? null;
}

/**
 * Get user's role
 * 
 * @return string|null User role or null if not logged in
 */
function getUserRole() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Get user's name
 * 
 * @return string|null User name or null if not logged in
 */
function getUserName() {
    return $_SESSION['user_name'] ?? null;
}

/**
 * Get user's email
 * 
 * @return string|null User email or null if not logged in
 */
function getUserEmail() {
    return $_SESSION['user_email'] ?? null;
}

/**
 * Get base URL of the application
 * 
 * @return string Base URL
 */
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
    $domainName = $_SERVER['HTTP_HOST'];
    
    // Get the directory path
    $path = dirname($_SERVER['SCRIPT_NAME']);
    if ($path !== '/') {
        $path = rtrim($path, '/') . '/';
    } else {
        $path = '/';
    }
    
    return $protocol . $domainName . $path;
}

/**
 * Check session timeout (optional)
 * 
 * @param int $timeout_minutes Minutes before session expires
 */
function checkSessionTimeout($timeout_minutes = 30) {
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        
        if ($elapsed > ($timeout_minutes * 60)) {
            // Session expired
            session_destroy();
            header('Location: login.php?session_expired=1');
            exit;
        }
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
}

/**
 * Get user display name (for navigation/header)
 * 
 * @return string User's display name
 */
function getUserDisplayName() {
    if (!isUserLoggedIn()) {
        return 'Guest';
    }
    
    $name = $_SESSION['user_name'] ?? '';
    if (empty($name)) {
        return $_SESSION['user_email'] ?? 'User';
    }
    
    return $name;
}

/**
 * Log user activity (optional)
 * 
 * @param string $action Action performed
 * @param string $description Description of activity
 */
function logUserActivity($action, $description = '') {
    if (!isUserLoggedIn()) {
        return false;
    }
    
    // This would require a user_activity table in the database
    // Implementation depends on your logging needs
    
    // Example:
    // $activity = [
    //     'user_id' => $_SESSION['user_id'],
    //     'action' => $action,
    //     'description' => $description,
    //     'timestamp' => date('Y-m-d H:i:s'),
    //     'ip_address' => $_SERVER['REMOTE_ADDR']
    // ];
    
    return true;
}

?>
