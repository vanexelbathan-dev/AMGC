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

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order <?php echo $order['so_number']; ?> - Print</title>
    <style>
        /* OPTIMIZED FOR PRINT - BLACK AND WHITE, MINIMAL WHITESPACE */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 11px;
            line-height: 1.2;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        
        .print-container {
            max-width: 100%;
            margin: 0;
            padding: 15px;
        }
        
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #000;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .company-logo {
            width: 50px;
            height: auto;
            /* Only colored element */
        }
        
        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #000;
            letter-spacing: 1px;
        }
        
        .document-type {
            font-size: 12px;
            font-weight: bold;
            color: #000;
        }
        
        .order-title {
            font-size: 14px;
            font-weight: bold;
            margin: 12px 0 8px 0;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
        }
        
        .info-section {
            margin-bottom: 12px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 3px;
            font-size: 11px;
        }
        
        .info-label {
            font-weight: bold;
            min-width: 100px;
            color: #000;
        }
        
        .info-value {
            flex: 1;
            color: #000;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
            font-size: 10px;
        }
        
        .items-table th {
            background: #f0f0f0;
            color: #000;
            border: 1px solid #000;
            padding: 6px;
            text-align: left;
            font-weight: bold;
        }
        
        .items-table td {
            border: 1px solid #000;
            padding: 5px;
        }
        
        .items-table tbody tr {
            background-color: #fff;
        }
        
        .text-right {
            text-align: right;
        }
        
        .total-section {
            margin-top: 10px;
            padding: 8px;
            border: 1px solid #000;
            background: #f9f9f9;
            text-align: right;
        }
        
        .grand-total {
            font-size: 12px;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 1px solid #000;
            text-align: center;
            color: #333;
            font-size: 9px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border: 1px solid #000;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9px;
            background: #fff;
        }
        
        .notes-section {
            margin-top: 12px;
            padding: 8px;
            border-left: 2px solid #000;
        }
        
        .no-print {
            text-align: center;
            margin-top: 15px;
        }
        
        .no-print button {
            padding: 6px 12px;
            margin: 0 5px;
            border: 1px solid #000;
            background: #fff;
            cursor: pointer;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .print-container {
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            /* Ensure black and white only (except logo) */
            th, td, .total-section, .header, .footer {
                background: #fff !important;
                color: #000 !important;
                border-color: #000 !important;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="header">
            <div class="logo-section">
                <img src="<?php echo $logo_base64; ?>" alt="AMGC Logo" class="company-logo">
                <span class="company-name">AMGC</span>
            </div>
            <div class="document-type">
                SALES ORDER #<?php echo htmlspecialchars($order['so_number']); ?>
            </div>
        </div>
        
        <div class="order-title">ORDER INFORMATION</div>
        <div class="info-section">
            <div class="info-row">
                <div class="info-label">Order Date:</div>
                <div class="info-value"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Status:</div>
                <div class="info-value"><?php echo strtoupper($order['order_status']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Prepared By:</div>
                <div class="info-value"><?php echo htmlspecialchars($order['created_by']); ?></div>
            </div>
        </div>
        
        <div class="order-title">CUSTOMER INFORMATION</div>
        <div class="info-section">
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
        
        <div class="order-title">ORDER ITEMS</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 40%;">Product</th>
                    <th style="width: 15%;">SKU</th>
                    <th style="width: 15%;" class="text-right">Qty</th>
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
            <span class="grand-total">TOTAL AMOUNT: ₱<?php echo number_format($order['total_amount'], 2); ?></span>
        </div>
        
        <?php if (!empty($order['notes'])): ?>
        <div class="notes-section">
            <strong>NOTES:</strong> <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            Printed on: <?php echo date('M d, Y H:i'); ?> | Computer-generated document
        </div>
    </div>
    
    <div class="no-print">
        <button onclick="window.print()">🖨️ Print</button>
        <button onclick="window.close()">✖️ Close</button>
    </div>
    
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 300);
        };
    </script>
</body>
</html>