<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page
requireLogin();
requireRole(['sales']);

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$branch_id = getUserBranchId();

if ($order_id <= 0) {
    die('Invalid order ID');
}

// Get order details
$sql = "SELECT 
            so.*,
            c.customer_name,
            c.email,
            c.phone_number,
            c.address,
            u.user_id as created_by
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        LEFT JOIN users u ON so.created_by = u.user_id
        WHERE so.so_id = ? AND so.branch_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $order_id, $branch_id);
$stmt->execute();
$result = $stmt->get_result();
$order = $result->fetch_assoc();

if (!$order) {
    die('Order not found');
}

// Get order items
$items_sql = "SELECT 
                soi.*,
                i.item_name,
                i.item_code,
                i.unit_type
             FROM sales_order_items soi
             JOIN items i ON soi.item_id = i.item_id
             WHERE soi.so_id = ?
             ORDER BY soi.so_item_id";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param('i', $order_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = $items_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order <?php echo $order['so_number']; ?> - Print</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #333;
        }
        
        .print-container {
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #ddd;
            padding: 30px;
            background: white;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .order-title {
            font-size: 20px;
            margin: 20px 0;
            color: #2c3e50;
        }
        
        .info-section {
            margin-bottom: 30px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 10px;
        }
        
        .info-label {
            font-weight: bold;
            min-width: 150px;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .items-table th {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        
        .items-table td {
            border: 1px solid #dee2e6;
            padding: 10px;
        }
        
        .text-right {
            text-align: right;
        }
        
        .total-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #333;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .grand-total {
            font-size: 20px;
            font-weight: bold;
            color: #28a745;
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-processing { background: #cce5ff; color: #004085; }
        .status-shipped { background: #d1ecf1; color: #0c5460; }
        .status-delivered { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .print-container {
                border: none;
                padding: 0;
            }
            
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="header">
            <div class="company-name">Your Company Name</div>
            <div>Sales Order</div>
        </div>
        
        <div class="order-title">
            Order Number: <?php echo htmlspecialchars($order['so_number']); ?>
        </div>
        
        <div class="info-section">
            <div class="info-row">
                <div class="info-label">Order Date:</div>
                <div><?php echo date('F d, Y h:i A', strtotime($order['order_date'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Status:</div>
                <div>
                    <span class="status-badge status-<?php echo $order['order_status']; ?>">
                        <?php echo ucfirst($order['order_status']); ?>
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Created By:</div>
                <div><?php echo htmlspecialchars($order['created_by']); ?></div>
            </div>
        </div>
        
        <div class="info-section">
            <h4>Customer Information</h4>
            <div class="info-row">
                <div class="info-label">Customer Name:</div>
                <div><?php echo htmlspecialchars($order['customer_name']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Email:</div>
                <div><?php echo htmlspecialchars($order['email']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Phone:</div>
                <div><?php echo htmlspecialchars($order['phone_number']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Address:</div>
                <div><?php echo htmlspecialchars($order['address']); ?></div>
            </div>
        </div>
        
        <h4>Order Items</h4>
        <table class="items-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                        <td class="text-right"><?php echo $item['quantity_ordered']; ?></td>
                        <td class="text-right">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="text-right">₱<?php echo number_format($item['line_total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="total-section">
            <div class="total-row grand-total">
                <span>Grand Total:</span>
                <span>₱<?php echo number_format($order['total_amount'], 2); ?></span>
            </div>
        </div>
        
        <?php if (!empty($order['notes'])): ?>
        <div class="info-section">
            <h4>Notes</h4>
            <p><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            <p>This is a computer-generated document. No signature required.</p>
            <p>Printed on: <?php echo date('F d, Y h:i A'); ?></p>
        </div>
    </div>
    
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="bi bi-printer"></i> Print
        </button>
        <button onclick="window.close()" class="btn btn-secondary">
            <i class="bi bi-x-circle"></i> Close
        </button>
    </div>
    
    <script>
        // Auto print on page load
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>