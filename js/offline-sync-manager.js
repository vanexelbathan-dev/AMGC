/**
 * Offline Sync Manager
 * Manages the sync queue and handles synchronization with server
 */

class OfflineSyncManager {
  constructor() {
    this.syncQueue = [];
    this.isSyncing = false;
    this.isOnline = navigator.onLine;
    this.syncCallbacks = [];
    this.maxRetries = 3;
    this.retryDelay = 2000;
    this.init();
  }

  /**
   * Initialize sync manager
   */
  init() {
    // Listen for online/offline events
    window.addEventListener('online', () => this.handleOnline());
    window.addEventListener('offline', () => this.handleOffline());

    // Load existing queue from localStorage
    this.loadQueue();

    // Periodically attempt to sync
    setInterval(() => {
      if (this.isOnline && !this.isSyncing && this.syncQueue.length > 0) {
        this.startSync();
      }
    }, 30000); // Every 30 seconds
  }

  /**
   * Handle coming online
   */
  handleOnline() {
    console.log('[v0] Connection restored');
    this.isOnline = true;
    
    // Notify UI that we're online
    window.dispatchEvent(new CustomEvent('offline-status-change', {
      detail: { online: true }
    }));

    // Start syncing if there are pending items
    if (this.syncQueue.length > 0) {
      this.startSync();
    }
  }

  /**
   * Handle going offline
   */
  handleOffline() {
    console.log('[v0] Connection lost');
    this.isOnline = false;
    
    // Notify UI that we're offline
    window.dispatchEvent(new CustomEvent('offline-status-change', {
      detail: { online: false }
    }));
  }

  /**
   * Queue an operation
   */
  async queueOperation(endpoint, method, data) {
    const operation = {
      id: this.generateUUID(),
      endpoint,
      method,
      data,
      timestamp: Date.now(),
      status: 'pending',
      retries: 0,
      errorMessage: '',
    };

    this.syncQueue.push(operation);
    this.persistQueue();

    console.log('[v0] Operation queued:', operation);
    
    // Notify listeners
    window.dispatchEvent(new CustomEvent('sync-queue-updated', {
      detail: { queue: this.syncQueue }
    }));

    return operation.id;
  }

  /**
   * Start synchronization process
   */
  async startSync() {
    if (this.isSyncing) {
      console.log('[v0] Sync already in progress');
      return;
    }

    this.isSyncing = true;
    console.log('[v0] Starting sync of', this.syncQueue.length, 'operations');

    // Notify UI that sync is starting
    window.dispatchEvent(new CustomEvent('sync-started', {
      detail: { total: this.syncQueue.length }
    }));

    let syncedCount = 0;
    let failedCount = 0;

    for (const operation of this.syncQueue) {
      if (operation.status === 'pending' || operation.status === 'failed') {
        try {
          await this.syncOperation(operation);
          syncedCount++;
        } catch (error) {
          console.error('[v0] Sync failed for operation:', operation.id, error);
          failedCount++;
        }
      }
    }

    this.isSyncing = false;

    // Clean up synced items
    this.syncQueue = this.syncQueue.filter(
      (op) => op.status !== 'synced'
    );
    this.persistQueue();

    // Notify UI that sync is complete
    window.dispatchEvent(new CustomEvent('sync-complete', {
      detail: {
        synced: syncedCount,
        failed: failedCount,
        remaining: this.syncQueue.length,
      }
    }));

    console.log('[v0] Sync complete:', { syncedCount, failedCount });
  }

  /**
   * Sync a single operation
   */
  async syncOperation(operation) {
    return new Promise(async (resolve, reject) => {
      const maxAttempts = this.maxRetries + 1;

      for (let attempt = 0; attempt < maxAttempts; attempt++) {
        try {
          operation.status = 'syncing';

          const response = await fetch(operation.endpoint, {
            method: operation.method,
            headers: {
              'Content-Type': 'application/json',
              'X-Offline-Sync': 'true',
              'X-Sync-ID': operation.id,
            },
            body: JSON.stringify(operation.data),
          });

          if (response.ok) {
            operation.status = 'synced';
            operation.syncedAt = new Date().toISOString();
            this.persistQueue();
            
            console.log('[v0] Operation synced:', operation.id);
            resolve(operation);
            return;
          } else {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
          }
        } catch (error) {
          operation.errorMessage = error.message;
          operation.retries = attempt + 1;

          if (attempt < maxAttempts - 1) {
            // Wait before retrying (exponential backoff)
            const delay = this.retryDelay * Math.pow(2, attempt);
            console.log(`[v0] Retrying in ${delay}ms...`);
            await new Promise(resolve => setTimeout(resolve, delay));
          } else {
            operation.status = 'failed';
            this.persistQueue();
            reject(error);
          }
        }
      }
    });
  }

  /**
   * Get current sync status
   */
  getSyncStatus() {
    const pending = this.syncQueue.filter(op => op.status === 'pending').length;
    const syncing = this.syncQueue.filter(op => op.status === 'syncing').length;
    const failed = this.syncQueue.filter(op => op.status === 'failed').length;
    const synced = this.syncQueue.filter(op => op.status === 'synced').length;

    return {
      isOnline: this.isOnline,
      isSyncing: this.isSyncing,
      pending,
      syncing,
      failed,
      synced,
      total: this.syncQueue.length,
    };
  }

  /**
   * Get sync queue
   */
  getQueue() {
    return [...this.syncQueue];
  }

  /**
   * Clear all queued operations
   */
  clearQueue() {
    this.syncQueue = [];
    this.persistQueue();
    
    window.dispatchEvent(new CustomEvent('sync-queue-updated', {
      detail: { queue: this.syncQueue }
    }));
  }

  /**
   * Persist queue to localStorage
   */
  persistQueue() {
    try {
      localStorage.setItem('amgc_sync_queue', JSON.stringify(this.syncQueue));
    } catch (error) {
      console.error('[v0] Failed to persist queue:', error);
    }
  }

  /**
   * Load queue from localStorage
   */
  loadQueue() {
    try {
      const saved = localStorage.getItem('amgc_sync_queue');
      if (saved) {
        this.syncQueue = JSON.parse(saved);
        console.log('[v0] Loaded sync queue with', this.syncQueue.length, 'items');
      }
    } catch (error) {
      console.error('[v0] Failed to load queue:', error);
      this.syncQueue = [];
    }
  }

  /**
   * Register callback for sync events
   */
  onSync(callback) {
    this.syncCallbacks.push(callback);
  }

  /**
   * Generate UUID
   */
  generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = Math.random() * 16 | 0;
      const v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  /**
   * Retry failed operations
   */
  async retryFailed() {
    const failedOps = this.syncQueue.filter(op => op.status === 'failed');
    console.log('[v0] Retrying', failedOps.length, 'failed operations');
    
    failedOps.forEach(op => {
      op.status = 'pending';
      op.retries = 0;
    });
    
    this.persistQueue();
    
    if (this.isOnline) {
      await this.startSync();
    }
  }
}

// Export singleton instance
const syncManager = new OfflineSyncManager();
