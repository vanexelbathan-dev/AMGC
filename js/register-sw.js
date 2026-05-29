// /AMGC/js/register-sw.js
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/AMGC/service-worker.js', { 
            scope: '/AMGC/' 
        }).then(function(registration) {
            console.log('✅ SW registered! Scope:', registration.scope);
            
            // Check for updates
            registration.addEventListener('updatefound', function() {
                console.log('🔄 New version available!');
            });
        }).catch(function(error) {
            console.log('❌ SW failed:', error);
        });
    });
    
    // Online/Offline status indicator
    function updateOnlineStatus() {
        const status = navigator.onLine ? '🟢 Online' : '🔴 Offline';
        console.log(status);
    }
    
    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
}