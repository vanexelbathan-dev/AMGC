/**
 * Enhanced Geolocation Tracker - Real-time GPS tracking with online/offline detection
 * Works reliably on hosting with heartbeat system
 */

class GeolocationTracker {
    constructor(options = {}) {
        this.options = {
            updateInterval: options.updateInterval || 15000, // 15 seconds
            heartbeatInterval: options.heartbeatInterval || 30000, // 30 seconds heartbeat
            minAccuracy: options.minAccuracy || 100, // 100 meters for hosting compatibility
            minDistance: options.minDistance || 15, // 15 meters for movement threshold
            enableHighAccuracy: options.enableHighAccuracy !== false,
            timeout: options.timeout || 20000, // 20 seconds
            maximumAge: options.maximumAge || 0,
            apiEndpoint: options.apiEndpoint || '../Global/update_driver_location.php',
            heartbeatEndpoint: options.heartbeatEndpoint || '../Global/driver_heartbeat.php',
            debugMode: options.debugMode !== false, // Enable by default on hosting
            ...options
        };

        this.watchId = null;
        this.lastLocation = null;
        this.isTracking = false;
        this.isOnline = true;
        this.updateCount = 0;
        this.heartbeatCount = 0;
        this.failureCount = 0;
        this.consecutiveFailures = 0;
        this.statusCallback = options.statusCallback || null;
        this.dataCallback = options.dataCallback || null;
        this.lastHeartbeatTime = null;
        this.sessionToken = null;

        // Network monitoring
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());

        this.log("GeolocationTracker initialized - Debug mode: " + this.options.debugMode);
    }

    /**
     * Request geolocation permission
     */
    requestPermission() {
        if (!navigator.geolocation) {
            this.log("ERROR: Geolocation is not supported");
            this.updateStatus('error', 'Geolocation not supported');
            return;
        }

        this.log("Requesting geolocation permission...");
        navigator.geolocation.getCurrentPosition(
            (position) => {
                this.log("✓ Permission granted. GPS ready.");
                this.updateStatus('ready', 'Location access granted');
            },
            (error) => {
                this.log("✗ Permission denied: " + error.message);
                this.updateStatus('error', 'Location access denied');
            }
        );
    }

    /**
     * Start tracking with heartbeat
     */
    start(driverId = null) {
        if (this.isTracking) {
            this.log("Tracking already active");
            return;
        }

        if (!navigator.geolocation) {
            this.log("ERROR: Geolocation unavailable");
            this.updateStatus('error', 'Geolocation unavailable');
            return;
        }

        this.log("▶ Starting GPS tracker...");
        this.isTracking = true;
        this.isOnline = true;
        this.sessionToken = Date.now().toString();

        // Capture initial location
        this.captureLocation();

        // Regular location updates
        this.locationInterval = setInterval(() => {
            if (this.isTracking) {
                this.captureLocation();
            }
        }, this.options.updateInterval);

        // Heartbeat to report online status
        this.heartbeatInterval = setInterval(() => {
            if (this.isTracking) {
                this.sendHeartbeat();
            }
        }, this.options.heartbeatInterval);

        this.updateStatus('tracking', 'GPS tracking active');
        this.log("✓ Tracker started");
    }

    /**
     * Capture current location
     */
    captureLocation() {
        if (!this.isTracking) return;

        navigator.geolocation.getCurrentPosition(
            (position) => this.handlePositionSuccess(position),
            (error) => this.handlePositionError(error),
            {
                enableHighAccuracy: this.options.enableHighAccuracy,
                timeout: this.options.timeout,
                maximumAge: this.options.maximumAge
            }
        );
    }

    /**
     * Handle successful position
     */
    handlePositionSuccess(position) {
        const coords = position.coords;
        const timestamp = new Date().toISOString();

        const locationData = {
            latitude: coords.latitude,
            longitude: coords.longitude,
            accuracy: coords.accuracy,
            speed: coords.speed || 0,
            heading: coords.heading || 0,
            altitude: coords.altitude || 0,
            timestamp: timestamp
        };

        // Check distance threshold
        if (this.shouldUpdate(locationData)) {
            this.lastLocation = locationData;
            this.sendLocation(locationData);
            this.updateCount++;
            this.consecutiveFailures = 0;
        }

        this.updateStatus('tracking', `Tracking active (${this.updateCount} updates)`);
    }

    /**
     * Determine if location should be sent
     */
    shouldUpdate(newLocation) {
        if (!this.lastLocation) return true;

        // Skip if accuracy too low
        if (newLocation.accuracy > this.options.minAccuracy) {
            this.log("⊘ Low accuracy: " + newLocation.accuracy.toFixed(0) + "m");
            return false;
        }

        // Check distance
        const distance = this.calculateDistance(
            this.lastLocation.latitude,
            this.lastLocation.longitude,
            newLocation.latitude,
            newLocation.longitude
        );

        if (distance < this.options.minDistance) {
            return false;
        }

        return true;
    }

    /**
     * Haversine distance calculation
     */
    calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371000;
        const φ1 = (lat1 * Math.PI) / 180;
        const φ2 = (lat2 * Math.PI) / 180;
        const Δφ = ((lat2 - lat1) * Math.PI) / 180;
        const Δλ = ((lon2 - lon1) * Math.PI) / 180;

        const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
                  Math.cos(φ1) * Math.cos(φ2) * Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

        return R * c;
    }

    /**
     * Send location to server
     */
    sendLocation(locationData) {
        const speedKmh = locationData.speed ? Math.round(locationData.speed * 3.6) : 0;

        const payload = {
            latitude: locationData.latitude,
            longitude: locationData.longitude,
            accuracy: locationData.accuracy,
            speed: speedKmh,
            heading: locationData.heading,
            altitude: locationData.altitude,
            timestamp: locationData.timestamp,
            session_token: this.sessionToken,
            is_heartbeat: 0
        };

        fetch(this.options.apiEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                this.log("✓ Location #" + this.updateCount + " sent");
                this.isOnline = true;
            } else {
                this.log("✗ Server: " + data.message);
                this.consecutiveFailures++;
            }
            if (this.dataCallback) this.dataCallback(data);
        })
        .catch(error => {
            this.log("✗ Network error: " + error.message);
            this.consecutiveFailures++;
            this.handleOffline();
        });
    }

    /**
     * Send heartbeat to mark driver online
     */
    sendHeartbeat() {
        const payload = {
            session_token: this.sessionToken,
            is_heartbeat: 1,
            timestamp: new Date().toISOString()
        };

        if (this.lastLocation) {
            payload.latitude = this.lastLocation.latitude;
            payload.longitude = this.lastLocation.longitude;
            payload.accuracy = this.lastLocation.accuracy;
        }

        fetch(this.options.heartbeatEndpoint || this.options.apiEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            this.heartbeatCount++;
            this.lastHeartbeatTime = new Date();
            this.isOnline = true;
            this.log("♥ Heartbeat #" + this.heartbeatCount);
        })
        .catch(error => {
            this.log("♥ Heartbeat failed: " + error.message);
            this.handleOffline();
        });
    }

    /**
     * Handle geolocation errors
     */
    handlePositionError(error) {
        this.failureCount++;
        let msg = 'Unknown error';

        switch (error.code) {
            case error.PERMISSION_DENIED:
                msg = 'Permission denied';
                this.stop();
                break;
            case error.POSITION_UNAVAILABLE:
                msg = 'Position unavailable';
                break;
            case error.TIMEOUT:
                msg = 'GPS timeout';
                break;
        }

        this.log("⚠ Position error: " + msg);
    }

    /**
     * Handle online state
     */
    handleOnline() {
        this.isOnline = true;
        this.log("● Network: ONLINE");
        this.updateStatus('tracking', 'Network restored');
    }

    /**
     * Handle offline state
     */
    handleOffline() {
        this.isOnline = false;
        this.log("● Network: OFFLINE");
        this.updateStatus('error', 'Network offline');
    }

    /**
     * Stop tracking
     */
    stop() {
        if (!this.isTracking) return;

        clearInterval(this.locationInterval);
        clearInterval(this.heartbeatInterval);
        if (this.watchId) navigator.geolocation.clearWatch(this.watchId);

        this.isTracking = false;
        this.log("■ Tracking stopped");
        this.updateStatus('stopped', 'Tracking stopped');
    }

    /**
     * Update status callback
     */
    updateStatus(status, message) {
        if (this.statusCallback) {
            this.statusCallback({
                status: status,
                message: message,
                timestamp: new Date().toISOString(),
                updateCount: this.updateCount,
                heartbeatCount: this.heartbeatCount,
                isOnline: this.isOnline,
                failureCount: this.failureCount
            });
        }
    }

    /**
     * Debug logging with visual indicators
     */
    log(message) {
        if (this.options.debugMode) {
            console.log('[GPS] ' + message);
        }
    }

    /**
     * Get status
     */
    getStatus() {
        return {
            isTracking: this.isTracking,
            isOnline: this.isOnline,
            updateCount: this.updateCount,
            heartbeatCount: this.heartbeatCount,
            failureCount: this.failureCount,
            lastLocation: this.lastLocation,
            lastHeartbeatTime: this.lastHeartbeatTime
        };
    }
}

// Export for use in different environments
if (typeof module !== 'undefined' && module.exports) {
    module.exports = GeolocationTracker;
}
