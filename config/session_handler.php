<?php
session_start();

/* Check if user is logged in */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}
function getUserName() {
    return $_SESSION['username'] ?? '';
}
/* Get user role */
function getUserRole() {
    return $_SESSION['role'] ?? '';
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

/* Require login */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: ../login.php");
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
