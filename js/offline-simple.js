// offline-simple.js - I-save ito sa js folder mo

let pendingOrders = [];

// Load saved orders from storage
const saved = localStorage.getItem('pending_orders');
if (saved) {
    pendingOrders = JSON.parse(saved);
    console.log('Pending orders:', pendingOrders.length);
}

// Save order function - tawagin mo sa submit button
window.saveOrder = function(orderData) {
    const order = {
        id: Date.now(),
        data: orderData,
        date: new Date().toISOString()
    };
    
    pendingOrders.push(order);
    localStorage.setItem('pending_orders', JSON.stringify(pendingOrders));
    
    alert('Order saved! Will submit when online.');
    return true;
};

// Auto sync pag nagka-internet
window.addEventListener('online', function() {
    if (pendingOrders.length > 0) {
        alert('Back online! Submitting ' + pendingOrders.length + ' orders...');
        
        // Submit all pending orders
        pendingOrders.forEach(function(order) {
            console.log('Submitting order:', order);
            // Dito mo ilagay ang original submit function ng website mo
        });
        
        pendingOrders = [];
        localStorage.removeItem('pending_orders');
    }
});