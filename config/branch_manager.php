<?php
/**
 * Branch Manager Helper Functions
 * Provides centralized functions for branch-based data access control
 */

// Get user's branch ID from session
function getBranchId() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : null;
}

// Check if user is admin (branch_id = 0 means access to all branches)
function isAdmin() {
    return isset($_SESSION['branch_id']) && $_SESSION['branch_id'] == 0;
}

// Get current user's branch ID, ensuring they're logged in
function getCurrentBranchId() {
    if (!isset($_SESSION['user_id'])) {
        die("Access denied: User not authenticated");
    }
    $branch_id = getBranchId();
    if ($branch_id === null) {
        die("Access denied: Branch not assigned to user");
    }
    return $branch_id;
}

// Build WHERE clause for branch filtering
function getBranchWhereClause($tableName = null) {
    $branch_id = getBranchId();
    
    if (isAdmin()) {
        // Admin sees all branches, no WHERE clause needed
        return "";
    }
    
    // Regular user sees only their branch
    if ($tableName) {
        return "WHERE {$tableName}.branch_id = {$branch_id}";
    }
    return "WHERE branch_id = {$branch_id}";
}

// Build WHERE clause for JOIN queries
function getBranchJoinWhereClause($tableAlias) {
    $branch_id = getBranchId();
    
    if (isAdmin()) {
        return "";
    }
    
    return "WHERE {$tableAlias}.branch_id = {$branch_id}";
}

// Verify user has access to specific branch
function verifyBranchAccess($required_branch_id) {
    $user_branch_id = getBranchId();
    
    // Admin can access any branch
    if (isAdmin()) {
        return true;
    }
    
    // Regular user can only access their own branch
    return $user_branch_id == $required_branch_id;
}

// Get branch name from ID
function getBranchName($branch_id, $connection) {
    if ($branch_id == 0) {
        return "All Branches (Admin)";
    }
    
    $query = "SELECT branch_name FROM branches WHERE branch_id = {$branch_id}";
    $result = $connection->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['branch_name'];
    }
    
    return "Branch #" . $branch_id;
}

// Get all accessible branches for user
function getAccessibleBranches($connection) {
    if (isAdmin()) {
        // Admin sees all branches
        $query = "SELECT branch_id, branch_name FROM branches ORDER BY branch_name";
    } else {
        // Regular user sees only their branch
        $branch_id = getBranchId();
        $query = "SELECT branch_id, branch_name FROM branches WHERE branch_id = {$branch_id}";
    }
    
    $result = $connection->query($query);
    $branches = array();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
        }
    }
    
    return $branches;
}

// Apply branch filter to query with ORDER BY
function applyBranchFilter($baseQuery, $tableName = null) {
    $branch_id = getBranchId();
    
    if (isAdmin()) {
        return $baseQuery;
    }
    
    // Insert WHERE clause after SELECT
    if ($tableName) {
        $whereClause = "WHERE {$tableName}.branch_id = {$branch_id}";
    } else {
        $whereClause = "WHERE branch_id = {$branch_id}";
    }
    
    // Simple insertion - assumes query doesn't already have WHERE
    return $baseQuery . " " . $whereClause;
}

// For INSERT operations - add branch_id automatically
function addBranchIdToInsert($branch_id_value = null) {
    if ($branch_id_value !== null) {
        return $branch_id_value;
    }
    
    $branch_id = getCurrentBranchId();
    return $branch_id;
}

// Verify record belongs to user's branch before allowing modifications
function verifyRecordBelongsToBranch($table, $record_id, $connection, $id_column = 'id') {
    if (isAdmin()) {
        return true; // Admin can modify anything
    }
    
    $user_branch_id = getBranchId();
    $query = "SELECT branch_id FROM {$table} WHERE {$id_column} = {$record_id}";
    $result = $connection->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['branch_id'] == $user_branch_id;
    }
    
    return false;
}
?>
