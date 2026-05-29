/**
 * Offline Database Layer
 * Manages IndexedDB for local data persistence
 */

class OfflineDB {
  constructor() {
    this.dbName = 'amgc_offline_db';
    this.version = 1;
    this.db = null;
    this.stores = {
      users: 'id',
      customers: 'id',
      sales_orders: 'id',
      inventory: 'id',
      drivers: 'id',
      deliveries: 'id',
      pick_lists: 'id',
      sync_queue: 'id',
      cache_metadata: 'key',
    };
  }

  /**
   * Initialize IndexedDB
   */
  async init() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(this.dbName, this.version);

      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        this.db = request.result;
        console.log('[v0] IndexedDB initialized:', this.dbName);
        resolve(this.db);
      };

      request.onupgradeneeded = (event) => {
        const db = event.target.result;
        this._createStores(db);
      };
    });
  }

  /**
   * Create all object stores
   */
  _createStores(db) {
    Object.entries(this.stores).forEach(([name, keyPath]) => {
      if (!db.objectStoreNames.contains(name)) {
        const store = db.createObjectStore(name, { keyPath });
        
        // Add indexes for common queries
        if (name === 'sales_orders') {
          store.createIndex('customer_id', 'customer_id', { unique: false });
          store.createIndex('status', 'status', { unique: false });
          store.createIndex('created_at', 'created_at', { unique: false });
        }
        if (name === 'deliveries') {
          store.createIndex('status', 'status', { unique: false });
          store.createIndex('driver_id', 'driver_id', { unique: false });
        }
        if (name === 'sync_queue') {
          store.createIndex('status', 'status', { unique: false });
          store.createIndex('timestamp', 'timestamp', { unique: false });
          store.createIndex('endpoint', 'endpoint', { unique: false });
        }
        if (name === 'inventory') {
          store.createIndex('category', 'category', { unique: false });
        }

        console.log(`[v0] Created store: ${name}`);
      }
    });
  }

  /**
   * Save data to store
   */
  async save(storeName, data) {
    return new Promise((resolve, reject) => {
      if (!this.db) return reject(new Error('DB not initialized'));

      const transaction = this.db.transaction([storeName], 'readwrite');
      const store = transaction.objectStore(storeName);
      const request = store.put(data);

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result);
    });
  }

  /**
   * Save multiple records
   */
  async saveBatch(storeName, dataArray) {
    return new Promise((resolve, reject) => {
      if (!this.db) return reject(new Error('DB not initialized'));

      const transaction = this.db.transaction([storeName], 'readwrite');
      const store = transaction.objectStore(storeName);
      const results = [];

      dataArray.forEach((data) => {
        const request = store.put(data);
        request.onsuccess = () => results.push(request.result);
      });

      transaction.onerror = () => reject(transaction.error);
      transaction.oncomplete = () => resolve(results);
    });
  }

  /**
   * Get single record by ID
   */
  async get(storeName, id) {
    return new Promise((resolve, reject) => {
      if (!this.db) return reject(new Error('DB not initialized'));

      const transaction = this.db.transaction([storeName], 'readonly');
      const store = transaction.objectStore(storeName);
      const request = store.get(id);

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result);
    });
  }

  /**
   * Get all records from store
   */
  async getAll(storeName, limit = null) {
    return new Promise((resolve, reject) => {
      if (!this.db) return reject(new Error('DB not initialized'));

      const transaction = this.db.transaction([storeName], 'readonly');
      const store = transaction.objectStore(storeName);
      const request = store.getAll();

      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        let results = request.result;
        if (limit) {
          results = results.slice(0, limit);
        }
        resolve(results);
      };
    });
  }

  /**
   * Query by index
   */
  async query(storeName, indexName, value) {
    return new Promise((resolve, reject) => {
      if (!this.db) return reject(new Error('DB not initialized'));

      const transaction = this.db.transaction([storeName], 'readonly');
      const store = transaction.objectStore(storeName);
      const index = store.index(indexName);
      const request = index.getAll(value);

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result);
    });
  }

  /**
   * Delete record
   */
  async delete(storeName, id) {
    return new Promise((resolve, reject) => {
      if (!this.db) return reject(new Error('DB not initialized'));

      const transaction = this.db.transaction([storeName], 'readwrite');
      const store = transaction.objectStore(storeName);
      const request = store.delete(id);

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result);
    });
  }

  /**
   * Clear entire store
   */
  async clear(storeName) {
    return new Promise((resolve, reject) => {
      if (!this.db) return reject(new Error('DB not initialized'));

      const transaction = this.db.transaction([storeName], 'readwrite');
      const store = transaction.objectStore(storeName);
      const request = store.clear();

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result);
    });
  }

  /**
   * Get count of records
   */
  async count(storeName) {
    return new Promise((resolve, reject) => {
      if (!this.db) return reject(new Error('DB not initialized'));

      const transaction = this.db.transaction([storeName], 'readonly');
      const store = transaction.objectStore(storeName);
      const request = store.count();

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result);
    });
  }

  /**
   * Update cache metadata
   */
  async updateCacheMetadata(key, metadata) {
    const data = {
      key,
      ...metadata,
      updated_at: new Date().toISOString(),
    };
    return this.save('cache_metadata', data);
  }

  /**
   * Get cache metadata
   */
  async getCacheMetadata(key) {
    return this.get('cache_metadata', key);
  }
}

// Export singleton instance
const offlineDB = new OfflineDB();
