/**
 * GPS Tracker Client for Drivers
 * Wrapper around enhanced GeolocationTracker for backward compatibility
 */

class GPSTrackerClient {
    constructor(config = {}) {
        this.config = {
            driverId: config.driverId,
            driverName: config.driverName,
            updateInterval: config.updateInterval || 15000,
            heartbeatInterval: config.heartbeatInterval || 30000,
            minDistanceMeters: config.minDistanceMeters || 15,
            enableHighAccuracy: config.enableHighAccuracy !== false,
            timeout: config.timeout || 20000,
            debugMode: config.debugMode !== false,
            ...config
        };
        
        // Use enhanced GeolocationTracker internally
        this.tracker = new GeolocationTracker({
            updateInterval: this.config.updateInterval,
            heartbeatInterval: this.config.heartbeatInterval,
            minDistance: this.config.minDistanceMeters,
            enableHighAccuracy: this.config.enableHighAccuracy,
            timeout: this.config.timeout,
            debugMode: this.config.debugMode,
            statusCallback: (status) => {
                if (this.config.onStatusChange) {
                    this.config.onStatusChange(status);
                }
            },
            dataCallback: (data) => {
                if (this.config.onUpdate) {
                    this.config.onUpdate({
                        ...data,
                        updateCount: this.tracker.updateCount,
                        lastUpdate: new Date()
                    });
                }
            }
        });
        
        this.isTracking = false;
    }
    
    /**
     * Start tracking
     */
    start() {
        if (!navigator.geolocation) {
            this.handleError('Geolocation not supported by browser');
            return false;
        }
        
        if (this.isTracking) {
            console.log('[GPS] Tracking already active');
            return;
        }
        
        console.log('[GPS] Starting tracker for driver: ' + this.config.driverId);
        this.tracker.start();
        this.isTracking = true;
        return true;
    }
    
    /**
     * Stop tracking
     */
    stop() {
        console.log('[GPS] Stopping tracker');
        this.tracker.stop();
        this.isTracking = false;
        return true;
    }
    
    /**
     * Handle error callback
     */
    handleError(message) {
        console.error('[GPS] Error:', message);
        if (this.config.onError) {
            this.config.onError(message);
        }
    }
    
    /**
     * Get current status
     */
    getStatus() {
        const trackerStatus = this.tracker.getStatus();
        return {
            isTracking: this.isTracking,
            updateCount: trackerStatus.updateCount,
            heartbeatCount: trackerStatus.heartbeatCount,
            lastLocation: trackerStatus.lastLocation,
            lastUpdateTime: trackerStatus.lastHeartbeatTime,
            isOnline: trackerStatus.isOnline
        };
    }
}

// Global instance
window.gpsTracker = null;

/**
 * Initialize tracker on page
 */
function initializeGPSTracker(driverId, driverName, config = {}) {
    window.gpsTracker = new GPSTrackerClient({
        driverId: driverId,
        driverName: driverName,
        ...config
    });
    return window.gpsTracker;
}

/**
 * Start tracking
 */
function startGPSTracking() {
    if (!window.gpsTracker) {
        console.error('[GPS] Tracker not initialized');
        return false;
    }
    window.gpsTracker.start();
    return true;
}

/**
 * Stop tracking
 */
function stopGPSTracking() {
    if (!window.gpsTracker) return false;
    window.gpsTracker.stop();
    return true;
}

/**
 * Get tracker status
 */
function getGPSStatus() {
    if (!window.gpsTracker) return null;
    return window.gpsTracker.getStatus();
}
