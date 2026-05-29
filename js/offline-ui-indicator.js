/**
 * Offline UI Indicator
 * Manages the visual indication of offline status and sync progress
 */

class OfflineUIIndicator {
  constructor() {
    this.isOnline = navigator.onLine;
    this.isSyncing = false;
    this.init();
  }

  /**
   * Initialize UI indicator
   */
  init() {
    // Listen for offline status changes
    window.addEventListener('offline-status-change', (e) => {
      this.handleStatusChange(e.detail);
    });

    // Listen for sync events
    window.addEventListener('sync-started', (e) => {
      this.handleSyncStarted(e.detail);
    });

    window.addEventListener('sync-complete', (e) => {
      this.handleSyncComplete(e.detail);
    });

    // Create UI elements
    this.createOfflineBanner();
    this.createStatusIndicator();
    this.createSyncModal();

    // Initial update
    this.updateStatus();
  }

  /**
   * Create offline banner
   */
  createOfflineBanner() {
    const banner = document.createElement('div');
    banner.id = 'offline-banner';
    banner.className = 'offline-banner offline-banner--hidden';
    banner.innerHTML = `
      <div class="offline-banner__content">
        <span class="offline-banner__icon">📡</span>
        <span class="offline-banner__text">OFFLINE MODE - All changes will sync when you're back online</span>
        <button class="offline-banner__close" aria-label="Close">×</button>
      </div>
      <style>
        .offline-banner {
          position: fixed;
          top: 0;
          left: 0;
          right: 0;
          background: linear-gradient(90deg, #d32f2f 0%, #c62828 100%);
          color: white;
          padding: 12px 0;
          z-index: 9999;
          box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
          transition: transform 0.3s ease;
        }

        .offline-banner--hidden {
          display: none;
        }

        .offline-banner__content {
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 12px;
          max-width: 1200px;
          margin: 0 auto;
          padding: 0 20px;
        }

        .offline-banner__icon {
          font-size: 18px;
          animation: pulse 2s infinite;
        }

        .offline-banner__text {
          flex: 1;
          font-size: 14px;
          font-weight: 500;
          letter-spacing: 0.5px;
        }

        .offline-banner__close {
          background: none;
          border: none;
          color: white;
          font-size: 24px;
          cursor: pointer;
          padding: 0;
          display: flex;
          align-items: center;
        }

        .offline-banner__close:hover {
          opacity: 0.8;
        }

        @keyframes pulse {
          0%, 100% { opacity: 1; }
          50% { opacity: 0.5; }
        }

        body.has-offline-banner {
          padding-top: 45px;
        }
      </style>
    `;

    document.body.insertBefore(banner, document.body.firstChild);

    // Close button handler
    banner.querySelector('.offline-banner__close').addEventListener('click', () => {
      banner.classList.add('offline-banner--hidden');
      document.body.classList.remove('has-offline-banner');
    });
  }

  /**
   * Create status indicator (in header)
   */
  createStatusIndicator() {
    const indicator = document.createElement('div');
    indicator.id = 'sync-status-indicator';
    indicator.className = 'sync-status-indicator sync-status-indicator--online';
    indicator.innerHTML = `
      <div class="sync-status-indicator__dot sync-status-indicator__dot--online"></div>
      <span class="sync-status-indicator__text">Online</span>
      <div class="sync-status-indicator__queue" style="display: none;">
        <span class="sync-status-indicator__queue-count">0</span>
        <span class="sync-status-indicator__queue-text">pending</span>
      </div>
      <style>
        .sync-status-indicator {
          display: inline-flex;
          align-items: center;
          gap: 8px;
          padding: 6px 12px;
          border-radius: 20px;
          font-size: 12px;
          font-weight: 600;
          background: #e8f5e9;
          color: #2e7d32;
          transition: all 0.3s ease;
        }

        .sync-status-indicator--offline {
          background: #ffebee;
          color: #c62828;
        }

        .sync-status-indicator--syncing {
          background: #fff3e0;
          color: #e65100;
        }

        .sync-status-indicator__dot {
          width: 8px;
          height: 8px;
          border-radius: 50%;
          background: #2e7d32;
        }

        .sync-status-indicator__dot--online {
          background: #2e7d32;
          box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.2);
        }

        .sync-status-indicator__dot--offline {
          background: #c62828;
          box-shadow: 0 0 0 2px rgba(198, 40, 40, 0.2);
        }

        .sync-status-indicator__dot--syncing {
          background: #e65100;
          animation: sync-pulse 1s infinite;
        }

        @keyframes sync-pulse {
          0%, 100% { box-shadow: 0 0 0 2px rgba(230, 81, 0, 0.2); }
          50% { box-shadow: 0 0 0 6px rgba(230, 81, 0, 0.1); }
        }

        .sync-status-indicator__queue {
          display: flex;
          align-items: center;
          gap: 4px;
          padding-left: 8px;
          border-left: 1px solid currentColor;
          opacity: 0.7;
        }
      </style>
    `;

    // Try to add to header if exists, otherwise create container
    const header = document.querySelector('header') || document.querySelector('nav');
    if (header) {
      const rightSide = document.createElement('div');
      rightSide.className = 'header-right';
      rightSide.style.position = 'absolute';
      rightSide.style.right = '20px';
      rightSide.style.top = '50%';
      rightSide.style.transform = 'translateY(-50%)';
      rightSide.appendChild(indicator);
      header.style.position = 'relative';
      header.appendChild(rightSide);
    }

    // Re-add to document if not in header
    if (!indicator.parentElement) {
      const container = document.createElement('div');
      container.style.cssText = `
        position: fixed;
        top: 10px;
        right: 10px;
        z-index: 9998;
      `;
      container.appendChild(indicator);
      document.body.appendChild(container);
    }
  }

  /**
   * Create sync modal
   */
  createSyncModal() {
    const modal = document.createElement('div');
    modal.id = 'sync-modal';
    modal.className = 'sync-modal sync-modal--hidden';
    modal.innerHTML = `
      <div class="sync-modal__backdrop"></div>
      <div class="sync-modal__content">
        <div class="sync-modal__header">
          <h2 class="sync-modal__title">Synchronizing Data</h2>
          <button class="sync-modal__close" aria-label="Close">×</button>
        </div>
        <div class="sync-modal__body">
          <div class="sync-modal__progress">
            <div class="sync-modal__progress-bar">
              <div class="sync-modal__progress-fill"></div>
            </div>
            <div class="sync-modal__progress-text">
              <span class="sync-modal__progress-count">0</span> / 
              <span class="sync-modal__progress-total">0</span>
            </div>
          </div>
          <div class="sync-modal__status">
            <p class="sync-modal__status-text">Syncing your changes...</p>
          </div>
        </div>
        <div class="sync-modal__footer" style="display: none;">
          <button class="sync-modal__button sync-modal__button--primary">Done</button>
        </div>
      </div>
      <style>
        .sync-modal {
          position: fixed;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          display: flex;
          align-items: center;
          justify-content: center;
          z-index: 9997;
        }

        .sync-modal--hidden {
          display: none;
        }

        .sync-modal__backdrop {
          position: absolute;
          inset: 0;
          background: rgba(0, 0, 0, 0.5);
          opacity: 0;
          transition: opacity 0.3s ease;
        }

        .sync-modal:not(.sync-modal--hidden) .sync-modal__backdrop {
          opacity: 1;
        }

        .sync-modal__content {
          position: relative;
          background: white;
          border-radius: 8px;
          box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
          width: 90%;
          max-width: 400px;
          padding: 24px;
          animation: slide-up 0.3s ease;
        }

        @keyframes slide-up {
          from {
            transform: translateY(20px);
            opacity: 0;
          }
          to {
            transform: translateY(0);
            opacity: 1;
          }
        }

        .sync-modal__header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 20px;
        }

        .sync-modal__title {
          margin: 0;
          font-size: 18px;
          font-weight: 600;
          color: #333;
        }

        .sync-modal__close {
          background: none;
          border: none;
          font-size: 24px;
          cursor: pointer;
          color: #999;
          padding: 0;
        }

        .sync-modal__close:hover {
          color: #333;
        }

        .sync-modal__progress {
          margin-bottom: 16px;
        }

        .sync-modal__progress-bar {
          height: 4px;
          background: #e0e0e0;
          border-radius: 2px;
          overflow: hidden;
          margin-bottom: 8px;
        }

        .sync-modal__progress-fill {
          height: 100%;
          background: linear-gradient(90deg, #2196f3 0%, #1976d2 100%);
          width: 0%;
          transition: width 0.3s ease;
        }

        .sync-modal__progress-text {
          text-align: center;
          font-size: 12px;
          color: #999;
        }

        .sync-modal__status-text {
          margin: 0;
          font-size: 14px;
          color: #666;
          text-align: center;
        }

        .sync-modal__footer {
          margin-top: 20px;
          text-align: right;
        }

        .sync-modal__button {
          padding: 8px 16px;
          border-radius: 4px;
          border: none;
          font-size: 14px;
          font-weight: 600;
          cursor: pointer;
          transition: all 0.3s ease;
        }

        .sync-modal__button--primary {
          background: #2196f3;
          color: white;
        }

        .sync-modal__button--primary:hover {
          background: #1976d2;
        }
      </style>
    `;

    document.body.appendChild(modal);

    // Close button
    modal.querySelector('.sync-modal__close').addEventListener('click', () => {
      modal.classList.add('sync-modal--hidden');
    });
  }

  /**
   * Handle status change
   */
  handleStatusChange(detail) {
    const { online } = detail;
    this.isOnline = online;
    this.updateStatus();

    const banner = document.getElementById('offline-banner');
    if (!online) {
      banner.classList.remove('offline-banner--hidden');
      document.body.classList.add('has-offline-banner');
    }
  }

  /**
   * Handle sync started
   */
  handleSyncStarted(detail) {
    this.isSyncing = true;
    const modal = document.getElementById('sync-modal');
    const total = detail.total || 0;

    modal.querySelector('.sync-modal__progress-total').textContent = total;
    modal.querySelector('.sync-modal__progress-count').textContent = '0';
    modal.querySelector('.sync-modal__progress-fill').style.width = '0%';
    modal.classList.remove('sync-modal--hidden');

    this.updateStatus();
  }

  /**
   * Handle sync complete
   */
  handleSyncComplete(detail) {
    this.isSyncing = false;
    const { synced, failed, remaining } = detail;

    const modal = document.getElementById('sync-modal');
    const total = synced + failed;
    const percentage = total > 0 ? (synced / total) * 100 : 0;

    modal.querySelector('.sync-modal__progress-fill').style.width = percentage + '%';
    modal.querySelector('.sync-modal__progress-count').textContent = total;

    let statusText = `✓ Synced ${synced} items`;
    if (failed > 0) {
      statusText += ` (${failed} failed)`;
    }
    if (remaining > 0) {
      statusText += ` (${remaining} pending)`;
    }

    modal.querySelector('.sync-modal__status-text').textContent = statusText;

    // Show footer with close button
    const footer = modal.querySelector('.sync-modal__footer');
    footer.style.display = 'block';

    this.updateStatus();
  }

  /**
   * Update status indicator
   */
  updateStatus() {
    const indicator = document.getElementById('sync-status-indicator');
    if (!indicator) return;

    const dot = indicator.querySelector('.sync-status-indicator__dot');
    const text = indicator.querySelector('.sync-status-indicator__text');
    const queue = indicator.querySelector('.sync-status-indicator__queue');
    const queueCount = queue.querySelector('.sync-status-indicator__queue-count');

    if (!this.isOnline) {
      indicator.className = 'sync-status-indicator sync-status-indicator--offline';
      dot.className = 'sync-status-indicator__dot sync-status-indicator__dot--offline';
      text.textContent = 'Offline';
    } else if (this.isSyncing) {
      indicator.className = 'sync-status-indicator sync-status-indicator--syncing';
      dot.className = 'sync-status-indicator__dot sync-status-indicator__dot--syncing';
      text.textContent = 'Syncing...';
    } else {
      indicator.className = 'sync-status-indicator sync-status-indicator--online';
      dot.className = 'sync-status-indicator__dot sync-status-indicator__dot--online';
      text.textContent = 'Online';
    }

    // Update queue indicator
    const status = syncManager.getSyncStatus();
    if (status.pending > 0 || status.failed > 0) {
      queue.style.display = 'flex';
      queueCount.textContent = status.pending + status.failed;
    } else {
      queue.style.display = 'none';
    }
  }

  /**
   * Show toast notification
   */
  showToast(message, type = 'info', duration = 3000) {
    const toast = document.createElement('div');
    toast.className = `offline-toast offline-toast--${type}`;
    toast.textContent = message;
    toast.style.cssText = `
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: ${type === 'success' ? '#4caf50' : type === 'error' ? '#f44336' : '#2196f3'};
      color: white;
      padding: 12px 20px;
      border-radius: 4px;
      z-index: 9996;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
      animation: slide-in 0.3s ease;
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
      toast.style.animation = 'slide-out 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  }
}

// Export singleton instance
const uiIndicator = new OfflineUIIndicator();
