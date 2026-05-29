<?php
/**
 * Offline Mode Configuration
 * Manages all offline-related settings and constants
 */

// Offline mode enabled flag
define('OFFLINE_MODE_ENABLED', true);

// Cache configuration
define('OFFLINE_CACHE_VERSION', 'v1');
define('OFFLINE_DB_NAME', 'amgc_offline_db');
define('OFFLINE_SYNC_STORE', 'sync_queue');

// Cache sizes for different data types (in records)
define('CACHE_SIZE_CUSTOMERS', 200);
define('CACHE_SIZE_SALES_ORDERS', 100);
define('CACHE_SIZE_INVENTORY', 500);
define('CACHE_SIZE_DRIVERS', 50);
define('CACHE_SIZE_DELIVERIES', 100);
define('CACHE_SIZE_PICK_LISTS', 100);

// Offline session configuration
define('OFFLINE_SESSION_DURATION', 86400); // 24 hours in seconds
define('OFFLINE_SESSION_WARNING_HOURS', 20); // Warn user at 20 hours

// Sync configuration
define('SYNC_MAX_RETRIES', 3);
define('SYNC_RETRY_DELAY', 2000); // milliseconds
define('SYNC_BATCH_SIZE', 10); // Process 10 operations per sync cycle
define('SYNC_AUTO_INTERVAL', 30000); // Try auto-sync every 30 seconds when online

// Features allowed in offline mode
$OFFLINE_ALLOWED_FEATURES = [
    'login' => true,
    'sales_order_create' => true,
    'sales_order_view' => true,
    'delivery_status_update' => true,
    'inventory_view' => true,
    'driver_assignment' => false, // Requires server validation
    'real_time_tracking' => false,
    'reports' => false,
];

// Endpoints that require network connection
$NETWORK_ONLY_ENDPOINTS = [
    '/Global/driver_tracking.php',
    '/Global/get_driver_data.php',
    '/Sales/ph_locations_api.php',
];

// Tables to cache locally
$OFFLINE_CACHED_TABLES = [
    'users' => ['current_user_only' => true],
    'customers' => ['cache_size' => CACHE_SIZE_CUSTOMERS],
    'sales_orders' => ['cache_size' => CACHE_SIZE_SALES_ORDERS],
    'inventory' => ['cache_size' => CACHE_SIZE_INVENTORY],
    'drivers' => ['cache_size' => CACHE_SIZE_DRIVERS],
    'deliveries' => ['cache_size' => CACHE_SIZE_DELIVERIES],
    'pick_lists' => ['cache_size' => CACHE_SIZE_PICK_LISTS],
];

// Offline API response headers
function set_offline_response_headers() {
    header('X-Offline-Mode: true');
    header('X-Offline-Timestamp: ' . time());
    header('Cache-Control: no-cache, must-revalidate');
}

// Check if request is offline-enabled
function is_offline_request() {
    return isset($_SERVER['HTTP_X_OFFLINE_CLIENT']) || isset($_GET['offline']);
}

// Get offline allowed status for feature
function is_feature_offline_allowed($feature) {
    global $OFFLINE_ALLOWED_FEATURES;
    return isset($OFFLINE_ALLOWED_FEATURES[$feature]) && $OFFLINE_ALLOWED_FEATURES[$feature];
}

// Check if endpoint requires network
function requires_network($endpoint) {
    global $NETWORK_ONLY_ENDPOINTS;
    return in_array($endpoint, $NETWORK_ONLY_ENDPOINTS);
}
?>
