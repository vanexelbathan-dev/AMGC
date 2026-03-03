/**
 * Driver Tracking Initialization
 * Automatically initializes GPS tracking on driver pages
 */

let globalTracker = null;

/**
 * Initialize driver tracking
 */
function initializeDriverTracking(options = {}) {
    const config = {
        driverId: options.driverId || null,
        driverName: options.driverName || 'Driver',
        updateInterval: options.updateInterval || 15000,
        heartbeatInterval: options.heartbeatInterval || 30000,
        minDistance: options.minDistance || 15,
        enableHighAccuracy: options.enableHighAccuracy !== false,
        debugMode: options.debugMode !== false,
        autoStart: options.autoStart !== false,
        apiEndpoint: options.apiEndpoint || '../Global/update_driver_location.php',
        ...options
    };

    // Check if already initialized
    if (globalTracker && globalTracker.isTracking) {
        console.log('[Driver Tracker] Already initialized and tracking');
        return globalTracker;
    }

    // Create tracker instance
    globalTracker = new GeolocationTracker({
        updateInterval: config.updateInterval,
        heartbeatInterval: config.heartbeatInterval,
        minDistance: config.minDistance,
        enableHighAccuracy: config.enableHighAccuracy,
        debugMode: config.debugMode,
        apiEndpoint: config.apiEndpoint,
        
        statusCallback: function(status) {
            console.log('[Driver Tracker] Status:', status.status, '-', status.message);
            updateTrackerUI(status);
            
            // Trigger custom event
            document.dispatchEvent(new CustomEvent('trackerStatusChanged', {
                detail: status
            }));
        },
        
        dataCallback: function(data) {
            console.log('[Driver Tracker] Location data:', data);
            
            // Trigger custom event
            document.dispatchEvent(new CustomEvent('trackerLocationUpdated', {
                detail: data
            }));
        }
    });

    // Request permissions
    globalTracker.requestPermission();

    // Auto-start tracking
    if (config.autoStart) {
        setTimeout(() => {
            console.log('[Driver Tracker] Auto-starting...');
            globalTracker.start();
        }, 1000);
    }

    console.log('[Driver Tracker] Initialized for ' + config.driverName);
    return globalTracker;
}

/**
 * Start tracking
 */
function startTracking() {
    if (!globalTracker) {
        console.error('[Driver Tracker] Not initialized');
        return false;
    }
    globalTracker.start();
    return true;
}

/**
 * Stop tracking
 */
function stopTracking() {
    if (!globalTracker) return false;
    globalTracker.stop();
    return true;
}

/**
 * Get tracking status
 */
function getTrackerStatus() {
    if (!globalTracker) return null;
    return globalTracker.getStatus();
}

/**
 * Update UI with tracker status
 */
function updateTrackerUI(status) {
    // Update status badge
    const statusBadge = document.getElementById('tracker-status-badge');
    if (statusBadge) {
        let badgeClass = 'bg-secondary';
        let badgeText = 'Offline';
        
        if (status.status === 'tracking' && status.isOnline !== false) {
            badgeClass = 'bg-success';
            badgeText = 'Tracking ✓';
        } else if (status.status === 'error') {
            badgeClass = 'bg-danger';
            badgeText = 'Error: ' + status.message;
        } else if (status.status === 'ready') {
            badgeClass = 'bg-info';
            badgeText = 'Ready';
        }
        
        statusBadge.className = 'badge ' + badgeClass;
        statusBadge.textContent = badgeText;
    }

    // Update status message
    const statusMsg = document.getElementById('tracker-status-message');
    if (statusMsg) {
        statusMsg.textContent = status.message;
    }

    // Update update count
    const updateCount = document.getElementById('tracker-update-count');
    if (updateCount) {
        updateCount.textContent = status.updateCount || 0;
    }

    // Update heartbeat count
    const heartbeatCount = document.getElementById('tracker-heartbeat-count');
    if (heartbeatCount) {
        heartbeatCount.textContent = status.heartbeatCount || 0;
    }

    // Update network status
    const networkStatus = document.getElementById('tracker-network-status');
    if (networkStatus) {
        networkStatus.textContent = status.isOnline ? '● Online' : '● Offline';
        networkStatus.className = status.isOnline ? 'text-success' : 'text-danger';
    }
}

/**
 * Auto-initialize if data attributes are present
 */
document.addEventListener('DOMContentLoaded', function() {
    const trackerEl = document.querySelector('[data-init-tracker]');
    
    if (trackerEl) {
        const config = {
            driverId: trackerEl.getAttribute('data-driver-id'),
            driverName: trackerEl.getAttribute('data-driver-name') || 'Driver',
            updateInterval: parseInt(trackerEl.getAttribute('data-update-interval') || 15000),
            heartbeatInterval: parseInt(trackerEl.getAttribute('data-heartbeat-interval') || 30000),
            debugMode: trackerEl.getAttribute('data-debug-mode') !== 'false',
            autoStart: trackerEl.getAttribute('data-auto-start') !== 'false'
        };
        
        initializeDriverTracking(config);
    }
});

/**
 * Handle page visibility to pause/resume tracking
 */
document.addEventListener('visibilitychange', function() {
    if (!globalTracker) return;
    
    if (document.hidden) {
        console.log('[Driver Tracker] Page hidden - tracking continues in background');
    } else {
        console.log('[Driver Tracker] Page visible');
    }
});

/**
 * Clean up on page unload
 */
window.addEventListener('beforeunload', function() {
    if (globalTracker && globalTracker.isTracking) {
        globalTracker.stop();
    }
});
