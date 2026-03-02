<?php
// get_delivery_details.php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Helper function to extract photo path from remarks
function extractPhotoFromRemarks($remarks) {
    if (preg_match('/Proof Photo: ([^\n]+)/', $remarks, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

// Helper function to format remarks for display
function formatRemarks($remarks) {
    $remarks = nl2br(htmlspecialchars($remarks));
    // Highlight photo links - make them open in modal
    $remarks = preg_replace_callback('/Proof Photo: ([^\n]+)/', function($matches) {
        $photoPath = trim($matches[1]);
        $fullPath = '../uploads/deliveries/' . $photoPath;
        return '<strong>Proof Photo:</strong> <button class="btn btn-sm btn-primary view-photo-btn" data-photo="' . $fullPath . '"><i class="bi bi-image"></i> View Photo</button>';
    }, $remarks);
    return $remarks;
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo '<div class="alert alert-danger">Not authenticated</div>';
    exit();
}

$delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : 0;

if (!$delivery_id) {
    echo '<div class="alert alert-danger">Invalid delivery ID</div>';
    exit();
}

// Get delivery details
$query = "
    SELECT 
        d.*,
        so.so_number,
        so.total_amount,
        so.order_date,
        c.customer_name,
        c.contact_person,
        c.phone_number,
        c.address,
        c.city,
        t.trip_number,
        t.trip_status,
        dr.driver_name,
        dr.license_number,
        dr.contact_number as driver_contact,
        dr.vehicle_type,
        dr.vehicle_plate_number,
        GROUP_CONCAT(CONCAT(i.item_name, ' (', soi.quantity_ordered, ') - ₱', soi.unit_price) SEPARATOR '||') as items_list,
        GROUP_CONCAT(CONCAT(i.item_name, ' (', soi.quantity_ordered, ')') SEPARATOR '; ') as items_short
    FROM deliveries d
    INNER JOIN sales_orders so ON d.so_id = so.so_id
    INNER JOIN customers c ON d.customer_id = c.customer_id
    LEFT JOIN trip_tickets t ON d.trip_id = t.trip_id
    LEFT JOIN drivers dr ON t.driver_id = dr.driver_id
    LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
    LEFT JOIN items i ON soi.item_id = i.item_id
    WHERE d.delivery_id = ?
    GROUP BY d.delivery_id
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$result = $stmt->get_result();
$delivery = $result->fetch_assoc();

if (!$delivery) {
    echo '<div class="alert alert-warning">Delivery not found</div>';
    exit();
}

// Parse items
$items_list = [];
if (!empty($delivery['items_list'])) {
    $items = explode('||', $delivery['items_list']);
    foreach ($items as $item) {
        $items_list[] = $item;
    }
}

// Status badge color
$status_color = '';
switch ($delivery['delivery_status']) {
    case 'pending':
        $status_color = 'warning';
        break;
    case 'in-transit':
        $status_color = 'primary';
        break;
    case 'partial':
        $status_color = 'info';
        break;
    case 'delivered':
        $status_color = 'success';
        break;
    case 'rejected':
        $status_color = 'danger';
        break;
    default:
        $status_color = 'secondary';
}
?>
<style>
/* ===== REMOVE ALL TABLE BORDERS COMPLETELY ===== */
.details-modal-content .custom-table,
.details-modal-content .custom-table thead,
.details-modal-content .custom-table tbody,
.details-modal-content .custom-table tfoot,
.details-modal-content .custom-table tr,
.details-modal-content .custom-table th,
.details-modal-content .custom-table td {
    border: none !important;
    border-width: 0 !important;
    border-style: none !important;
    border-color: transparent !important;
    outline: none !important;
}

/* Remove Bootstrap's default table borders */
.details-modal-content .table,
.details-modal-content .table thead,
.details-modal-content .table tbody,
.details-modal-content .table tfoot,
.details-modal-content .table tr,
.details-modal-content .table th,
.details-modal-content .table td {
    border: none !important;
    border-width: 0 !important;
    border-style: none !important;
    border-color: transparent !important;
}

/* Remove border from card and container */
.details-modal-content .card,
.details-modal-content .table-container,
.details-modal-content .table-responsive {
    border: none !important;
    outline: none !important;
}

/* Add a very subtle separation between rows if desired */
.details-modal-content .custom-table tbody tr td {
    border-bottom: 1px solid rgba(0,0,0,0.05) !important;
}

/* Remove bottom border from last row */
.details-modal-content .custom-table tbody tr:last-child td {
    border-bottom: none !important;
}

/* Style the table header with gradient */
.details-modal-content .custom-table thead {
    background: linear-gradient(90deg, var(--dark-green), var(--secondary-green)) !important;
    color: white;
}

.details-modal-content .custom-table thead th {
    border: none !important;
    color: white;
    font-weight: 600;
    padding: 0.75rem 1rem;
}



.details-modal-content .custom-table tfoot th,
.details-modal-content .custom-table tfoot td {
    border: none !important;
    color: white;
    font-weight: 600;
    padding: 0.75rem 1rem;
}

/* Override any table-light class that might be applied */
.details-modal-content .custom-table tfoot.table-light {
    background: var(--dark-green) !important;
}

.details-modal-content .custom-table tfoot.table-light th,
.details-modal-content .custom-table tfoot.table-light td {
    color: white !important;
    background: transparent !important;
}

/* Style table cells */
.details-modal-content .custom-table td {
    padding: 0.75rem 1rem;
    border: none !important;
}

/* Your existing styles remain below */
.details-modal-content {
    font-size: 0.9rem;
}

/* ===== RESPONSIVE BOXES ===== */
.details-section {
    background: #f8f9fa;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 10px;
    transition: all 0.3s ease;
}

/* Mobile responsive styles for boxes */
@media (max-width: 768px) {
    .details-section {
        padding: 10px;
        margin-bottom: 8px;
        border-radius: 8px;
    }
    
    .details-section h6 {
        font-size: 0.85rem;
        margin-bottom: 6px;
        padding-bottom: 3px;
    }
    
    /* Stack info rows vertically on very small screens */
    .info-row {
        flex-direction: column;
        margin-bottom: 8px;
    }
    
    .info-label {
        width: 100%;
        font-size: 0.7rem;
        margin-bottom: 2px;
        color: #6c757d;
    }
    
    .info-value {
        font-size: 0.8rem;
        padding-left: 8px;
        border-left: 2px solid #dee2e6;
    }
    
    /* Make the row layout stack on mobile */
    .row.g-1 {
        --bs-gutter-x: 0.25rem;
    }
    
    /* Full width columns on mobile */
    .col-md-6 {
        width: 100%;
        flex: 0 0 auto;
    }
}

@media (max-width: 576px) {
    .details-section {
        padding: 8px;
        margin-bottom: 6px;
        border-radius: 6px;
    }
    
    .details-section h6 {
        font-size: 0.8rem;
        margin-bottom: 4px;
    }
    
    .info-row {
        margin-bottom: 6px;
    }
    
    .info-label {
        font-size: 0.65rem;
    }
    
    .info-value {
        font-size: 0.75rem;
        padding-left: 6px;
    }
    
    /* Header adjustments */
    .details-modal-content h5 {
        font-size: 0.9rem !important;
    }
    
    .badge {
        font-size: 0.6rem !important;
        padding: 0.2rem 0.5rem !important;
    }
    
    /* Remarks box adjustments */
    .remarks-box {
        max-height: 150px;
        font-size: 0.75rem;
        padding: 8px !important;
    }
    
    /* Button adjustments */
    .btn-sm {
        padding: 0.2rem 0.4rem;
        font-size: 0.7rem;
    }
    
    .btn-sm i {
        font-size: 0.7rem;
    }
}

/* Extra small devices */
@media (max-width: 375px) {
    .details-section {
        padding: 6px;
    }
    
    .info-value {
        font-size: 0.7rem;
        word-break: break-word;
    }
    
    .info-label {
        font-size: 0.6rem;
    }
    
    /* Make tables scroll horizontally if needed */
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .custom-table {
        min-width: 100%;
    }
    
    .custom-table th,
    .custom-table td {
        padding: 0.4rem 0.5rem !important;
        font-size: 0.65rem !important;
        white-space: nowrap;
    }
    
    /* Allow only specific columns to wrap if needed */
    .custom-table td:first-child {
        white-space: normal;
        word-break: break-word;
    }
    
    /* Adjust footer on very small screens */
    .custom-table tfoot th,
    .custom-table tfoot td {
        padding: 0.4rem 0.5rem !important;
        font-size: 0.65rem !important;
    }
}

.details-section h6 {
    color: #495057;
    font-weight: 600;
    margin-bottom: 8px;
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 4px;
    font-size: 0.9rem;
}

.info-row {
    margin-bottom: 4px;
    display: flex;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    width: 90px;
    font-size: 0.8rem;
}

.info-value {
    color: #212529;
    flex: 1;
    font-size: 0.85rem;
}

.remarks-box {
    max-height: 200px;
    overflow-y: auto;
    white-space: pre-wrap;
    font-size: 0.85rem;
    line-height: 1.4;
    font-family: monospace;
}

.view-photo-btn {
    margin: 5px 0;
}

.delivered-badge {
    background-color: #d4edda;
    color: #155724;
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 5px;
}

.partial-badge {
    background-color: #fff3cd;
    color: #856404;
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 5px;
}

/* ===== MOBILE RESPONSIVE ===== */
@media (max-width: 992px) {
    .table thead th,
    .table tbody td,
    .custom-table thead th,
    .custom-table tbody td,
    .compact-table thead th,
    .compact-table tbody td,
    .custom-table tfoot th,
    .custom-table tfoot td {
        padding: 0.7rem 0.9rem !important;
    }
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.85rem;
    }
    
    .table thead th,
    .table tbody td,
    .custom-table thead th,
    .custom-table tbody td,
    .compact-table thead th,
    .compact-table tbody td,
    .custom-table tfoot th,
    .custom-table tfoot td {
        padding: 0.6rem 0.75rem !important;
        font-size: 0.8rem !important;
    }
    
    .badge {
        padding: 0.25rem 0.75rem !important;
        font-size: 0.7rem !important;
    }
    
    .table-footer {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .pagination {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 576px) {
    .table thead th,
    .table tbody td,
    .custom-table thead th,
    .custom-table tbody td,
    .compact-table thead th,
    .compact-table tbody td,
    .custom-table tfoot th,
    .custom-table tfoot td {
        padding: 0.5rem 0.6rem !important;
        font-size: 0.75rem !important;
    }
    
    .badge {
        padding: 0.2rem 0.6rem !important;
        font-size: 0.65rem !important;
    }
}

/* Tables */
.data-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--light-green);
    margin-bottom: 1.5rem;
}

.table-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--light-green);
    background: #f9fafb;
}

.table-header h5 {
    margin: 0;
    color: var(--dark-color);
    font-weight: 600;
    font-size: 1.1rem;
}

.custom-table {
    margin: 0;
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.custom-table tbody tr:hover {
    background-color: rgba(209, 250, 229, 0.3);
}

/* Fallback colors in case CSS variables aren't defined */
:root {
    --dark-green: #198754;
    --secondary-green: #20c997;
    --light-green: #d1e7dd;
    --dark-color: #212529;
}

</style>
<div class="details-modal-content">
    <!-- Header with Status -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0" style="font-size: 1rem;">
            <i class="bi bi-truck text-primary me-1"></i>
            Delivery #<?php echo $delivery['delivery_id']; ?> - <?php echo $delivery['so_number']; ?>
        </h5>
        <span class="badge bg-<?php echo $status_color; ?> p-1" style="font-size: 0.7rem;">
            <?php echo ucfirst(str_replace('-', ' ', $delivery['delivery_status'])); ?>
        </span>
    </div>

    <div class="row g-1">
        <!-- Order Information -->
        <div class="col-md-6">
            <div class="details-section">
                <h6><i class="bi bi-cart-check me-1"></i>Order</h6>
                <div class="info-row">
                    <span class="info-label">Order #:</span>
                    <span class="info-value"><?php echo $delivery['so_number']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date:</span>
                    <span class="info-value"><?php echo date('M d, Y', strtotime($delivery['order_date'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Amount:</span>
                    <span class="info-value">₱<?php echo number_format($delivery['total_amount'], 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Delivery Information -->
        <div class="col-md-6">
            <div class="details-section">
                <h6><i class="bi bi-geo-alt me-1"></i>Delivery</h6>
                <div class="info-row">
                    <span class="info-label">Stop #:</span>
                    <span class="info-value"><?php echo $delivery['stop_sequence'] ?? 'N/A'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Schedule:</span>
                    <span class="info-value"><?php echo $delivery['delivery_date'] ? date('M d, h:i A', strtotime($delivery['delivery_date'])) : 'N/A'; ?></span>
                </div>
                <?php if ($delivery['signed_by']): ?>
                <div class="info-row">
                    <span class="info-label">Signed:</span>
                    <span class="info-value"><strong><?php echo $delivery['signed_by']; ?></strong></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-1">
        <!-- Customer Information -->
        <div class="col-md-6">
            <div class="details-section">
                <h6><i class="bi bi-person me-1"></i>Customer</h6>
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span class="info-value"><?php echo $delivery['customer_name']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Contact:</span>
                    <span class="info-value"><?php echo $delivery['phone_number'] ?? 'N/A'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Address:</span>
                    <span class="info-value"><?php echo $delivery['address'] . ', ' . $delivery['city']; ?></span>
                </div>
            </div>
        </div>

        <!-- Trip Information -->
        <div class="col-md-6">
            <div class="details-section">
                <h6><i class="bi bi-ticket me-1"></i>Trip</h6>
                <?php if ($delivery['trip_number']): ?>
                <div class="info-row">
                    <span class="info-label">Trip #:</span>
                    <span class="info-value"><?php echo $delivery['trip_number']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Driver:</span>
                    <span class="info-value"><?php echo $delivery['driver_name']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Vehicle:</span>
                    <span class="info-value"><?php echo $delivery['vehicle_plate_number']; ?></span>
                </div>
                <?php else: ?>
                <div class="info-value text-muted small">Not assigned to trip</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Items List -->
    <div class="details-section">
        <h6><i class="bi bi-box-seam me-1"></i>Items (<?php echo count($items_list); ?>)</h6>
        <?php if (!empty($items_list)): ?>
            <div class="card">
            <div class="table-container">
                <table class="table custom-table compact-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th width="40">Qty</th>
                            <th width="60">Price</th>
                            <th width="60">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_amount = 0;
                        foreach ($items_list as $item):
                            preg_match('/(.+) \((\d+)\) - ₱([\d.]+)/', $item, $matches);
                            if (count($matches) == 4):
                                $item_name = $matches[1];
                                $qty = $matches[2];
                                $price = $matches[3];
                                $total = $qty * $price;
                                $total_amount += $total;
                        ?>
                        <tr>
                            <td><?php echo $item_name; ?></td>
                            <td><?php echo $qty; ?></td>
                            <td>₱<?php echo number_format($price, 2); ?></td>
                            <td>₱<?php echo number_format($total, 2); ?></td>
                        </tr>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">TOTAL:</th>
                            <th>₱<?php echo number_format($total_amount, 2); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted small mb-0">No items listed</p>
        <?php endif; ?>
    </div>

    <!-- Delivered Details (if delivered) -->
    <?php if ($delivery['delivery_status'] == 'delivered'): ?>
    <div class="details-section">
        <h6><i class="bi bi-check-circle-fill text-success me-1"></i>Delivery Completion Details</h6>
        <div class="row">
            <div class="col-md-6">
                <div class="info-row">
                    <span class="info-label">Signed By:</span>
                    <span class="info-value"><strong><?php echo $delivery['signed_by'] ?? 'N/A'; ?></strong></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Delivered On:</span>
                    <span class="info-value"><?php echo $delivery['delivery_date'] ? date('M d, Y h:i A', strtotime($delivery['delivery_date'])) : 'N/A'; ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <?php 
                $photo = extractPhotoFromRemarks($delivery['remarks'] ?? '');
                if ($photo): 
                ?>
                <div class="info-row">
                    <span class="info-label">Proof Photo:</span>
                    <span class="info-value">
                        <button class="btn btn-sm btn-primary view-photo-btn" data-photo="../uploads/deliveries/<?php echo $photo; ?>">
                            <i class="bi bi-image"></i> View Photo
                        </button>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Remarks (if any) -->
    <?php if (!empty($delivery['remarks'])): ?>
    <div class="details-section">
        <h6><i class="bi bi-chat-dots me-1"></i>Remarks 
            <?php if (strpos($delivery['remarks'], 'PARTIAL') !== false): ?>
                <span class="partial-badge">Partial</span>
            <?php elseif (strpos($delivery['remarks'], 'DELIVERY COMPLETED') !== false): ?>
                <span class="delivered-badge">Completed</span>
            <?php endif; ?>
        </h6>
        <div class="remarks-box small p-2 bg-white rounded border">
            <?php echo formatRemarks($delivery['remarks']); ?>
        </div>
    </div>
    <?php endif; ?>
</div>