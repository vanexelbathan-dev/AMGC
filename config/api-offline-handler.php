<?php
/**
 * API Offline Handler
 * Handles requests from offline clients and manages sync responses
 */

require_once __DIR__ . '/offline-config.php';

class APIOfflineHandler {
  
  /**
   * Check if request is from offline client
   */
  public static function isOfflineRequest() {
    return isset($_SERVER['HTTP_X_OFFLINE_CLIENT']) || 
           isset($_SERVER['HTTP_X_OFFLINE_SYNC']) ||
           isset($_GET['offline']);
  }

  /**
   * Get sync ID from request
   */
  public static function getSyncId() {
    return $_SERVER['HTTP_X_SYNC_ID'] ?? null;
  }

  /**
   * Check if endpoint requires network
   */
  public static function requiresNetwork($endpoint) {
    return requires_network($endpoint);
  }

  /**
   * Handle offline GET request (return cached data)
   */
  public static function handleOfflineGet($data) {
    return [
      'success' => true,
      'data' => $data,
      'offline_cache' => true,
      'cached_at' => date('c'),
    ];
  }

  /**
   * Handle offline POST request (queue for sync)
   */
  public static function handleOfflinePost($syncId) {
    return [
      'success' => true,
      'message' => 'Operation queued for synchronization',
      'sync_id' => $syncId,
      'queued_at' => date('c'),
      'offline' => true,
    ];
  }

  /**
   * Send offline response
   */
  public static function sendOfflineResponse($response, $statusCode = 200) {
    header('Content-Type: application/json');
    header('X-Offline-Response: true');
    header('X-Offline-Timestamp: ' . time());
    http_response_code($statusCode);
    echo json_encode($response);
    exit;
  }

  /**
   * Log sync operation
   */
  public static function logSyncOperation($syncId, $endpoint, $method, $status, $details = []) {
    $logFile = __DIR__ . '/../logs/sync-operations.log';
    
    // Ensure logs directory exists
    if (!is_dir(dirname($logFile))) {
      mkdir(dirname($logFile), 0755, true);
    }

    $logEntry = [
      'timestamp' => date('c'),
      'sync_id' => $syncId,
      'endpoint' => $endpoint,
      'method' => $method,
      'status' => $status,
      'details' => $details,
    ];

    file_put_contents(
      $logFile,
      json_encode($logEntry) . PHP_EOL,
      FILE_APPEND
    );
  }

  /**
   * Validate sync request integrity
   */
  public static function validateSyncRequest() {
    if (!is_array($_REQUEST)) {
      return false;
    }

    // Check required fields
    if (self::isOfflineRequest() && !self::getSyncId()) {
      return false;
    }

    return true;
  }

  /**
   * Get sync queue status
   */
  public static function getSyncQueueStatus($userId) {
    $logFile = __DIR__ . '/../logs/sync-operations.log';
    
    if (!file_exists($logFile)) {
      return [
        'pending' => 0,
        'synced' => 0,
        'failed' => 0,
      ];
    }

    $lines = file($logFile);
    $pending = 0;
    $synced = 0;
    $failed = 0;

    foreach ($lines as $line) {
      $entry = json_decode($line, true);
      
      if ($entry && isset($entry['details']['user_id']) && $entry['details']['user_id'] == $userId) {
        switch ($entry['status']) {
          case 'pending':
            $pending++;
            break;
          case 'synced':
            $synced++;
            break;
          case 'failed':
            $failed++;
            break;
        }
      }
    }

    return [
      'pending' => $pending,
      'synced' => $synced,
      'failed' => $failed,
    ];
  }

  /**
   * Create response with sync metadata
   */
  public static function createSyncResponse($data, $syncId = null) {
    return [
      'success' => true,
      'data' => $data,
      'sync_id' => $syncId,
      'synced_at' => date('c'),
      'server_time' => time(),
    ];
  }

  /**
   * Handle conflict resolution
   * @param mixed $localData Data from offline client
   * @param mixed $serverData Data from server
   * @param string $strategy 'local', 'server', or 'merge'
   * @return mixed Resolved data
   */
  public static function resolveConflict($localData, $serverData, $strategy = 'local-wins') {
    switch ($strategy) {
      case 'local-wins':
        return $localData;
      
      case 'server-wins':
        return $serverData;
      
      case 'merge':
        // For merge, typically we keep server data but preserve certain fields
        if (is_array($localData) && is_array($serverData)) {
          return array_merge($serverData, $localData);
        }
        return $localData;
      
      default:
        return $localData;
    }
  }

  /**
   * Check if feature is allowed in offline mode
   */
  public static function isFeatureOfflineAllowed($feature) {
    return is_feature_offline_allowed($feature);
  }

  /**
   * Get offline allowed features
   */
  public static function getOfflineFeatures() {
    global $OFFLINE_ALLOWED_FEATURES;
    return $OFFLINE_ALLOWED_FEATURES;
  }
}

/**
 * Helper function for responses
 */
function send_offline_response($data, $sync_id = null) {
  $response = APIOfflineHandler::createSyncResponse($data, $sync_id);
  header('Content-Type: application/json');
  echo json_encode($response);
}

/**
 * Helper to check if request is offline
 */
function is_offline() {
  return APIOfflineHandler::isOfflineRequest();
}

/**
 * Helper to get sync ID
 */
function get_sync_id() {
  return APIOfflineHandler::getSyncId();
}
?>
