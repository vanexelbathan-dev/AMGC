/**
 * Offline Mode Initialization
 * Initializes all offline features when the page loads
 */

(async function initializeOfflineMode() {
  console.log('[v0] Initializing Offline Mode...');

  try {
    // 1. Initialize IndexedDB
    await offlineDB.init();
    console.log('[v0] IndexedDB initialized');

    // 2. Register Service Worker
    if ('serviceWorker' in navigator) {
      try {
        const registration = await navigator.serviceWorker.register('/js/service-worker.js', {
          scope: '/'
        });
        console.log('[v0] Service Worker registered:', registration);

        // Listen for updates
        registration.addEventListener('updatefound', () => {
          const newWorker = registration.installing;
          newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              // New service worker is ready
              console.log('[v0] New Service Worker ready');
              uiIndicator.showToast('App update available. Reload to update.', 'info', 5000);
            }
          });
        });
      } catch (error) {
        console.warn('[v0] Service Worker registration failed:', error);
      }
    }

    // 3. Initialize Sync Manager
    // Already initialized in offline-sync-manager.js

    // 4. Initialize UI Indicator
    // Already initialized in offline-ui-indicator.js

    // 5. Set up offline data caching
    setupOfflineDataCache();

    // 6. Initialize offline session
    initializeOfflineSession();

    // 7. Set up auto-save
    setupAutoSave();

    console.log('[v0] Offline Mode fully initialized');

    // Fire initialization complete event
    window.dispatchEvent(new CustomEvent('offline-mode-ready'));
  } catch (error) {
    console.error('[v0] Failed to initialize Offline Mode:', error);
  }
})();

/**
 * Set up offline data caching
 */
async function setupOfflineDataCache() {
  // Cache user data on login
  window.addEventListener('user-logged-in', async (e) => {
    const user = e.detail;
    await offlineDB.save('users', {
      ...user,
      cached_at: new Date().toISOString(),
    });
    console.log('[v0] Cached user data:', user.id);
  });

  // Setup periodic cache refresh (every 30 minutes)
  setInterval(() => {
    refreshOfflineCache();
  }, 30 * 60 * 1000);
}

/**
 * Refresh offline cache from server
 */
async function refreshOfflineCache() {
  if (!navigator.onLine) return;

  console.log('[v0] Refreshing offline cache...');

  try {
    // Refresh customers
    const customersResponse = await fetch('/Sales/get_customer_details.php?action=get_all');
    if (customersResponse.ok) {
      const customers = await customersResponse.json();
      await offlineDB.saveBatch('customers', customers.slice(0, 200));
      await offlineDB.updateCacheMetadata('customers', { synced_at: Date.now() });
    }

    // Refresh inventory
    const inventoryResponse = await fetch('/Warehouse/currentinventory.php?action=get_all');
    if (inventoryResponse.ok) {
      const inventory = await inventoryResponse.json();
      await offlineDB.saveBatch('inventory', inventory.slice(0, 500));
      await offlineDB.updateCacheMetadata('inventory', { synced_at: Date.now() });
    }

    // Refresh drivers
    const driversResponse = await fetch('/Warehouse/drivers.php?action=get_all');
    if (driversResponse.ok) {
      const drivers = await driversResponse.json();
      await offlineDB.saveBatch('drivers', drivers);
      await offlineDB.updateCacheMetadata('drivers', { synced_at: Date.now() });
    }

    console.log('[v0] Cache refresh complete');
  } catch (error) {
    console.warn('[v0] Cache refresh failed:', error);
  }
}

/**
 * Initialize offline session
 */
function initializeOfflineSession() {
  const offlineSessionStart = localStorage.getItem('offline_session_start');
  
  if (!offlineSessionStart) {
    localStorage.setItem('offline_session_start', Date.now().toString());
  } else {
    const sessionDuration = Date.now() - parseInt(offlineSessionStart);
    const hoursPassed = sessionDuration / (1000 * 60 * 60);
    const hoursRemaining = 24 - hoursPassed;

    if (hoursRemaining < 4) {
      uiIndicator.showToast(
        `Offline session expires in ${Math.round(hoursRemaining)} hours. Please connect to internet.`,
        'warning',
        8000
      );
    }

    if (hoursPassed > 86400) {
      // Session expired, force logout
      localStorage.clear();
      window.location.href = '/login.php?offline_session_expired=1';
    }
  }
}

/**
 * Set up auto-save for form data
 */
function setupAutoSave() {
  const forms = document.querySelectorAll('form[data-offline-enabled]');
  
  forms.forEach((form) => {
    // Save form state to localStorage periodically
    const formId = form.id || 'form_' + Math.random().toString(36).substr(2, 9);
    
    form.addEventListener('input', () => {
      const formData = new FormData(form);
      const data = Object.fromEntries(formData);
      localStorage.setItem(`form_draft_${formId}`, JSON.stringify(data));
    });

    // Restore form state on load
    const savedData = localStorage.getItem(`form_draft_${formId}`);
    if (savedData) {
      try {
        const data = JSON.parse(savedData);
        Object.entries(data).forEach(([key, value]) => {
          const field = form.elements[key];
          if (field) {
            field.value = value;
          }
        });
      } catch (error) {
        console.warn('[v0] Failed to restore form data:', error);
      }
    }
  });
}

/**
 * Helper: Queue an API call for offline sync
 */
window.queueOfflineOperation = async function(endpoint, method = 'POST', data = {}) {
  const syncId = await syncManager.queueOperation(endpoint, method, data);
  console.log('[v0] Operation queued:', syncId);
  
  if (navigator.onLine) {
    // Attempt immediate sync if online
    setTimeout(() => syncManager.startSync(), 1000);
  }

  return syncId;
};

/**
 * Helper: Get current offline status
 */
window.getOfflineStatus = function() {
  return {
    online: navigator.onLine,
    syncStatus: syncManager.getSyncStatus(),
    hasCache: offlineDB.db !== null,
  };
};

/**
 * Helper: Manually trigger sync
 */
window.triggerSync = async function() {
  if (syncManager.isOnline) {
    await syncManager.startSync();
    return true;
  }
  uiIndicator.showToast('You are offline. Sync will start when online.', 'info');
  return false;
};

console.log('[v0] Offline initialization script loaded');
