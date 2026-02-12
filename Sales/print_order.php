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
            u.first_name as created_by
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            margin: 20px;
            color: #333;
            background-color: #fff;
        }
        
        .print-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px;
            background: white;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 25px;
            border-bottom: 2px solid #000;
        }
        
        .company-name {
            font-size: 32px;
            font-weight: bold;
            color: #000;
            margin-bottom: 10px;
            letter-spacing: 2px;
        }
        
        .document-type {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #333;
            font-weight: normal;
        }
        
        .order-title {
            font-size: 16px;
            margin: 30px 0 10px 0;
            color: #000;
            font-weight: bold;
        }
        
        .info-section {
            margin-bottom: 25px;
        }
        
        .info-section h4 {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            color: #000;
            margin-bottom: 12px;
            padding-bottom: 5px;
            border-bottom: 1px solid #000;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            align-items: flex-start;
            font-size: 12px;
        }
        
        .info-label {
            font-weight: bold;
            min-width: 130px;
            color: #000;
        }
        
        .info-value {
            flex: 1;
            color: #333;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 11px;
        }
        
        .items-table th {
            background: #f5f5f5;
            color: #000;
            border: 1px solid #000;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        
        .items-table td {
            border: 1px solid #ccc;
            padding: 10px;
        }
        
        .items-table tbody tr {
            background-color: #fff;
        }
        
        .text-right {
            text-align: right;
        }
        
        .total-section {
            margin-top: 25px;
            padding: 15px;
            background: #f5f5f5;
            border: 1px solid #ccc;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 12px;
        }
        
        .subtotal-label {
            font-weight: normal;
        }
        
        .grand-total {
            font-size: 14px;
            font-weight: bold;
            color: #000;
            border-top: 1px solid #000;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 15px;
            border-top: 1px solid #ccc;
            text-align: center;
            color: #666;
            font-size: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border: 1px solid #000;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
            background: #fff;
        }
        
        .notes-section {
            margin-top: 20px;
            padding: 12px;
            background-color: #f9f9f9;
            border-left: 2px solid #000;
        }
        
        .notes-section h4 {
            border-bottom: none;
            margin-bottom: 8px;
            color: #000;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
                background-color: white;
            }
            
            .print-container {
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="header">
            <div class="company-name">AMGC</div>
            <div class="document-type">Sales Order</div>
        </div>
        
        <div class="order-title">
            Order Number: <?php echo htmlspecialchars($order['so_number']); ?>
        </div>
        
        <div class="info-section">
            <div class="info-row">
                <div class="info-label">Order Date:</div>
                <div class="info-value"><?php echo date('F d, Y', strtotime($order['order_date'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Status:</div>
                <div class="info-value">
                    <span class="status-badge">
                        <?php echo strtoupper($order['order_status']); ?>
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Prepared By:</div>
                <div class="info-value"><?php echo htmlspecialchars($order['created_by']); ?></div>
            </div>
        </div>
        
        <div class="info-section">
            <h4>Bill To</h4>
            <div class="info-row">
                <div class="info-label">Name:</div>
                <div class="info-value"><?php echo htmlspecialchars($order['customer_name']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Email:</div>
                <div class="info-value"><?php echo htmlspecialchars($order['email']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Phone:</div>
                <div class="info-value"><?php echo htmlspecialchars($order['phone_number']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Address:</div>
                <div class="info-value"><?php echo htmlspecialchars($order['address']); ?></div>
            </div>
        </div>
        
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 40%;">Product</th>
                    <th style="width: 15%;">SKU</th>
                    <th style="width: 15%;" class="text-right">Quantity</th>
                    <th style="width: 15%;" class="text-right">Unit Price</th>
                    <th style="width: 15%;" class="text-right">Amount</th>
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
            <div class="total-row">
                <span style="font-weight: bold;">Total Amount:</span>
                <span style="font-weight: bold;">₱<?php echo number_format($order['total_amount'], 2); ?></span>
            </div>
        </div>
        
        <?php if (!empty($order['notes'])): ?>
        <div class="notes-section">
            <h4>Notes</h4>
            <p><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            This is a computer-generated document.
            <br/>Printed on: <?php echo date('F d, Y H:i', strtotime(date('Y-m-d H:i:s'))); ?>
        </div>
    </div>
    
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" class="btn btn-primary" style="padding: 8px 16px; margin-right: 10px;">
            <i class="bi bi-printer"></i> Print
        </button>
        <button onclick="window.close()" class="btn btn-secondary" style="padding: 8px 16px;">
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
