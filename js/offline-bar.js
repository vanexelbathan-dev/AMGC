/**
 * OFFLINE STATUS BAR - Shows offline/online status
 * Nag-display sa top ng page kung offline ang user
 */

(function() {
    'use strict';

    // Create offline bar HTML
    function createOfflineBar() {
        // Check if bar already exists
        if (document.getElementById('offline-status-bar')) {
            return;
        }

        const bar = document.createElement('div');
        bar.id = 'offline-status-bar';
        bar.className = 'offline-bar-online';
        bar.innerHTML = `
            <div class="offline-bar-content">
                <span class="offline-bar-icon">●</span>
                <span class="offline-bar-text">Online</span>
            </div>
        `;
        
        // Add CSS
        const style = document.createElement('style');
        style.textContent = `
            #offline-status-bar {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 99999;
                height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 500;
                transition: all 0.3s ease;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            #offline-status-bar.offline-bar-offline {
                background-color: #fbbf24;
                color: #78350f;
                border-bottom: 2px solid #f59e0b;
            }

            #offline-status-bar.offline-bar-online {
                background-color: #10b981;
                color: #ffffff;
                border-bottom: 2px solid #059669;
            }

            .offline-bar-content {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 0 20px;
            }

            .offline-bar-icon {
                font-size: 12px;
                animation: pulse 1s infinite;
            }

            .offline-bar-text {
                font-size: 14px;
            }

            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.6; }
            }

            /* Adjust page content when bar is visible */
            body {
                padding-top: 40px;
            }
        `;
        document.head.appendChild(style);
        document.body.insertBefore(bar, document.body.firstChild);
    }

    // Update bar status
    function updateOfflineBar() {
        const bar = document.getElementById('offline-status-bar');
        if (!bar) {
            createOfflineBar();
            return;
        }

        if (navigator.onLine) {
            bar.className = 'offline-bar-online';
            bar.innerHTML = `
                <div class="offline-bar-content">
                    <span class="offline-bar-icon">●</span>
                    <span class="offline-bar-text">Online</span>
                </div>
            `;
        } else {
            bar.className = 'offline-bar-offline';
            bar.innerHTML = `
                <div class="offline-bar-content">
                    <span class="offline-bar-icon">●</span>
                    <span class="offline-bar-text">Offline - You can view pages but cannot submit data</span>
                </div>
            `;
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            createOfflineBar();
            updateOfflineBar();
        });
    } else {
        createOfflineBar();
        updateOfflineBar();
    }

    // Listen for online/offline changes
    window.addEventListener('online', updateOfflineBar);
    window.addEventListener('offline', updateOfflineBar);

})();
