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
    // First, extract photo path
    $photo = extractPhotoFromRemarks($remarks);
    
    // Remove the "Proof Photo: filename" line from remarks
    $remarks_without_photo = preg_replace('/Proof Photo: [^\n]+\n?/', '', $remarks);
    $remarks_without_photo = trim($remarks_without_photo);
    
    // Format the remaining remarks
    $formatted_remarks = nl2br(htmlspecialchars($remarks_without_photo));
    
    return $formatted_remarks;
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

// Get photo from remarks
$photo = extractPhotoFromRemarks($delivery['remarks'] ?? '');

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
    /* No global html/body overflow: hidden - Bootstrap handles modal body scrolling */
    /* Allow scrolling on the container but hide scrollbar */
    .delivery-details-container {
        overflow-y: auto;
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
        height: 100%;
        padding-right: 2px; /* Prevent content shift */
    }
    
    /* Chrome, Safari, Opera */
    .delivery-details-container::-webkit-scrollbar {
        display: none;
    }
    
    /* Scope all styles to delivery-details-container */
    .delivery-details-container {
        font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
    }
    
    /* Color palette variables - same as trip tickets */
    .delivery-details-container {
        --primary-green: #44D34E;
        --light-green: #d1fae5;
        --dark-green: #047857;
        --text-primary: #212529;
        --text-secondary: #6c757d;
        --bg-light: #f8f9fa;
    }
    
    .delivery-details-container h6 {
        color: var(--dark-green);
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid #dee2e6;
        font-size: clamp(0.95rem, 2vw, 1.1rem);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .delivery-details-container h6 i {
        color: var(--primary-green);
    }
    
    .delivery-details-container .detail-section {
        background: var(--bg-light);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .delivery-details-container .info-label {
        font-weight: 600;
        color: var(--text-secondary);
        width: 80px;
        font-size: clamp(0.8rem, 1.5vw, 0.9rem);
        flex-shrink: 0;
    }
    
    .delivery-details-container .info-value {
        color: var(--text-primary);
        flex: 1;
        font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        word-break: break-word;
        overflow-wrap: break-word;
    }
    
    .delivery-details-container .badge {
        font-weight: 500;
        padding: 0.35rem 0.65rem;
    }
    
    .delivery-details-container .badge.bg-success { 
        background-color: var(--primary-green) !important; 
    }
    
    .delivery-details-container .border {
        border-color: #e9ecef !important;
    }
    
    .delivery-details-container .bg-light {
        background-color: var(--bg-light) !important;
    }
    
    /* Header with SO on right and badge below - responsive text */
    .delivery-details-container .delivery-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
        flex-wrap: nowrap; /* Prevent wrapping */
        gap: 10px;
    }
    
    .delivery-details-container .delivery-title {
        display: flex;
        flex-direction: column;
        gap: 4px;
        flex: 0 1 auto;
    }
    
    .delivery-details-container .delivery-title h6 {
        margin-bottom: 0;
        padding-bottom: 0;
        border-bottom: none;
        font-size: clamp(0.9rem, 2vw, 1.1rem);
        white-space: nowrap;
    }
    
    .delivery-details-container .delivery-right {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 6px;
        flex: 0 1 auto;
        max-width: 60%; /* Limit width on mobile */
    }
    
    .delivery-details-container .so-badge-right {
        background: #e9ecef;
        color: var(--dark-green);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: clamp(0.75rem, 1.8vw, 0.85rem);
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    .delivery-details-container .so-badge-right i {
        color: var(--dark-green);
        font-size: clamp(0.7rem, 1.5vw, 0.8rem);
    }
    
    .delivery-details-container .status-badge-right {
        font-size: clamp(0.7rem, 1.5vw, 0.8rem);
        padding: 4px 12px;
        white-space: nowrap;
    }
    
    /* Items Section - Card-based layout (no table) */
    .delivery-details-container .items-container {
        margin-top: 10px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .delivery-details-container .item-card {
        background: white;
        border-radius: 8px;
        padding: 12px;
        border: 1px solid #e9ecef;
        transition: all 0.2s ease;
    }
    
    .delivery-details-container .item-card:hover {
        border-color: var(--primary-green);
        box-shadow: 0 2px 8px rgba(68, 211, 78, 0.1);
    }
    
    .delivery-details-container .item-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .delivery-details-container .item-name {
        font-weight: 600;
        color: var(--text-primary);
        font-size: clamp(0.9rem, 2vw, 1rem);
        word-break: break-word;
        flex: 1;
    }
    
    .delivery-details-container .item-qty-badge {
        background-color: rgba(68, 211, 78, 0.15);
        color: var(--dark-green);
        font-weight: 600;
        border-radius: 20px;
        padding: 4px 12px;
        font-size: clamp(0.75rem, 1.5vw, 0.85rem);
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }
    
    .delivery-details-container .item-qty-badge i {
        color: var(--primary-green);
        font-size: clamp(0.7rem, 1.3vw, 0.8rem);
    }
    
    .delivery-details-container .item-details {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 8px;
        border-top: 1px dashed #e9ecef;
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .delivery-details-container .item-price {
        color: var(--text-secondary);
        font-size: clamp(0.8rem, 1.6vw, 0.9rem);
    }
    
    .delivery-details-container .item-price span {
        color: var(--text-primary);
        font-weight: 500;
        margin-left: 4px;
    }
    
    .delivery-details-container .item-total {
        font-weight: 700;
        color: var(--dark-green);
        font-size: clamp(1rem, 2.2vw, 1.1rem);
        font-family: 'Courier New', monospace;
    }
    
    .delivery-details-container .items-total-card {
        background: linear-gradient(135deg, rgba(68, 211, 78, 0.1) 0%, rgba(68, 211, 78, 0.05) 100%);
        border-radius: 10px;
        margin-top: 15px;
        padding: 15px;
        border: 1px solid rgba(68, 211, 78, 0.3);
    }
    
    .delivery-details-container .items-total-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .delivery-details-container .total-label {
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-size: clamp(0.8rem, 1.6vw, 0.9rem);
    }
    
    .delivery-details-container .total-amount {
        font-size: clamp(1.2rem, 2.5vw, 1.3rem);
        font-weight: 700;
        color: var(--dark-green);
        font-family: 'Courier New', monospace;
    }
    
    /* Button styles */
    .delivery-details-container .btn-outline-primary {
        color: var(--dark-green);
        border-color: var(--primary-green);
    }
    
    .delivery-details-container .btn-outline-primary:hover {
        background-color: var(--primary-green);
        border-color: var(--primary-green);
        color: white;
    }
    
    .delivery-details-container .btn-outline-primary i {
        color: var(--primary-green);
    }
    
    .delivery-details-container .btn-outline-primary:hover i {
        color: white;
    }
    
    /* Responsive - text only adjustments, position stays same */
    @media (max-width: 768px) {
        .delivery-details-container .detail-section {
            padding: 12px;
            margin-bottom: 15px;
        }
        
        .delivery-details-container h6 {
            font-size: 1rem;
            margin-bottom: 10px;
            padding-bottom: 6px;
        }
        
        .delivery-details-container .info-label {
            width: 70px;
            font-size: 0.8rem;
        }
        
        .delivery-details-container .info-value {
            font-size: 0.85rem;
        }
        
        .delivery-details-container .btn-sm {
            padding: 0.2rem 0.4rem;
            font-size: 0.7rem;
        }
        
        /* Stack columns on mobile but keep header position */
        .delivery-details-container .row {
            margin-left: -5px;
            margin-right: -5px;
        }
        
        .delivery-details-container .col-md-6 {
            padding-left: 5px;
            padding-right: 5px;
        }
        
        /* Header adjustments - only text size changes, position same */
        .delivery-details-container .delivery-title h6 {
            font-size: 0.95rem;
        }
        
        .delivery-details-container .so-badge-right {
            font-size: 0.75rem;
            padding: 3px 10px;
        }
        
        .delivery-details-container .so-badge-right i {
            font-size: 0.7rem;
        }
        
        .delivery-details-container .status-badge-right {
            font-size: 0.7rem;
            padding: 3px 10px;
        }
        
        /* Items card adjustments */
        .delivery-details-container .item-card {
            padding: 10px;
        }
        
        .delivery-details-container .item-name {
            font-size: 0.9rem;
        }
        
        .delivery-details-container .item-qty-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
        }
        
        .delivery-details-container .item-details {
            flex-direction: row; /* Keep row direction */
            align-items: center;
        }
        
        .delivery-details-container .item-price {
            font-size: 0.8rem;
        }
        
        .delivery-details-container .item-total {
            font-size: 1rem;
        }
        
        .delivery-details-container .total-amount {
            font-size: 1.2rem;
        }
    }
    
    @media (max-width: 480px) {
        .delivery-details-container .info-label {
            width: 65px;
            font-size: 0.75rem;
        }
        
        .delivery-details-container .info-value {
            font-size: 0.8rem;
        }
        
        /* Header further text size reduction */
        .delivery-details-container .delivery-title h6 {
            font-size: 0.9rem;
        }
        
        .delivery-details-container .so-badge-right {
            font-size: 0.7rem;
            padding: 2px 8px;
        }
        
        .delivery-details-container .so-badge-right i {
            font-size: 0.65rem;
        }
        
        .delivery-details-container .status-badge-right {
            font-size: 0.65rem;
            padding: 2px 8px;
        }
        
        .delivery-details-container .item-qty-badge {
            padding: 2px 6px;
            font-size: 0.7rem;
        }
        
        .delivery-details-container .total-amount {
            font-size: 1.1rem;
        }
        
        /* Keep item details in row even on very small screens */
        .delivery-details-container .item-details {
            flex-direction: row;
        }
    }
    
    @media (max-width: 360px) {
        .delivery-details-container .delivery-right {
            max-width: 55%; /* Slightly smaller on very small screens */
        }
        
        .delivery-details-container .so-badge-right {
            font-size: 0.65rem;
            padding: 2px 6px;
        }
        
        .delivery-details-container .status-badge-right {
            font-size: 0.6rem;
            padding: 2px 6px;
        }
        
        .delivery-details-container .item-name {
            font-size: 0.85rem;
        }
        
        .delivery-details-container .item-price {
            font-size: 0.75rem;
        }
        
        .delivery-details-container .item-total {
            font-size: 0.95rem;
        }
    }
</style>

<!-- Add the same map button style from trip tickets -->
<style>
    .delivery-details-container .btn-map {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        background-color: white;
        color: var(--dark-green);
        border: 1px solid var(--primary-green);
        border-radius: 20px;
        padding: 4px 12px;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.2s ease;
        white-space: nowrap;
        text-decoration: none;
        cursor: pointer;
    }

    .delivery-details-container .btn-map i {
        color: var(--primary-green);
        font-size: 0.9rem;
    }

    .delivery-details-container .btn-map:hover {
        background-color: var(--primary-green);
        color: white;
        border-color: var(--primary-green);
    }

    .delivery-details-container .btn-map:hover i {
        color: white;
    }

    @media (max-width: 768px) {
        .delivery-details-container .btn-map {
            padding: 3px 10px;
            font-size: 0.75rem;
        }
        
        .delivery-details-container .btn-map i {
            font-size: 0.8rem;
        }
    }
</style>

<div class="delivery-details-container">
    <!-- Header with Status - SO on right, badge below (position same on all devices) -->
    <div class="detail-section">
        <div class="delivery-header">
            <div class="delivery-title">
                <h6 class="mb-0">
                    <i class="bi bi-truck me-2"></i>
                    Delivery #<?php echo $delivery['delivery_id']; ?>
                </h6>
            </div>
            <div class="delivery-right">
                <span class="so-badge-right"><i class="bi bi-receipt"></i> <?php echo $delivery['so_number']; ?></span>
                <span class="badge bg-<?php echo $status_color; ?> p-2 status-badge-right">
                    <?php echo ucfirst(str_replace('-', ' ', $delivery['delivery_status'])); ?>
                </span>
            </div>
        </div>

        <div class="row">
            <!-- Order Information -->
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <td class="info-label">Order #:</td>
                        <td class="info-value"><?php echo $delivery['so_number']; ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Date:</td>
                        <td class="info-value"><?php echo date('M d, Y', strtotime($delivery['order_date'])); ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Amount:</td>
                        <td class="info-value"><span class="fw-bold text-success">₱<?php echo number_format($delivery['total_amount'], 2); ?></span></td>
                    </tr>
                </table>
            </div>

            <!-- Delivery Information -->
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <td class="info-label">Stop #:</td>
                        <td class="info-value"><?php echo $delivery['stop_sequence'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Schedule:</td>
                        <td class="info-value"><?php echo $delivery['delivery_date'] ? date('M d, h:i A', strtotime($delivery['delivery_date'])) : 'N/A'; ?></td>
                    </tr>
                    <?php if ($delivery['signed_by']): ?>
                    <tr>
                        <td class="info-label">Signed:</td>
                        <td class="info-value"><span class="fw-bold"><?php echo $delivery['signed_by']; ?></span></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Customer and Trip Information -->
    <div class="detail-section">
        <div class="row">
            <!-- Customer Information -->
            <div class="col-md-6">
                <h6><i class="bi bi-person me-2"></i>Customer Information</h6>
                <table class="table table-sm table-borderless">
                    <tr>
                        <td class="info-label">Name:</td>
                        <td class="info-value"><?php echo $delivery['customer_name']; ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Contact:</td>
                        <td class="info-value"><?php echo $delivery['phone_number'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Address:</td>
                        <td class="info-value"><?php echo $delivery['address'] . ', ' . $delivery['city']; ?></td>
                    </tr>
                </table>
            </div>

            <!-- Trip Information -->
            <div class="col-md-6">
                <h6><i class="bi bi-ticket me-2"></i>Trip Information</h6>
                <?php if ($delivery['trip_number']): ?>
                <table class="table table-sm table-borderless">
                    <tr>
                        <td class="info-label">Trip #:</td>
                        <td class="info-value"><?php echo $delivery['trip_number']; ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Driver:</td>
                        <td class="info-value"><?php echo $delivery['driver_name']; ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Vehicle:</td>
                        <td class="info-value"><?php echo $delivery['vehicle_plate_number']; ?></td>
                    </tr>
                </table>
                <?php else: ?>
                <p class="text-muted">Not assigned to any trip</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Items List - Card-based layout (no table) -->
    <div class="detail-section">
        <h6><i class="bi bi-box-seam me-2"></i>Items (<?php echo count($items_list); ?>)</h6>
        <?php if (!empty($items_list)): ?>
            <div class="items-container">
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
                <div class="item-card">
                    <div class="item-header">
                        <span class="item-name"><?php echo $item_name; ?></span>
                        <span class="item-qty-badge">
                            <i class="bi bi-box"></i> <?php echo $qty; ?> pcs
                        </span>
                    </div>
                    <div class="item-details">
                        <span class="item-price">
                            Unit Price: <span>₱<?php echo number_format($price, 2); ?></span>
                        </span>
                        <span class="item-total">₱<?php echo number_format($total, 2); ?></span>
                    </div>
                </div>
                <?php 
                    endif;
                endforeach; 
                ?>
                
                <!-- Total Amount Card -->
                <div class="items-total-card">
                    <div class="items-total-row">
                        <span class="total-label">Total Amount</span>
                        <span class="total-amount">₱<?php echo number_format($total_amount, 2); ?></span>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <p class="text-muted">No items listed</p>
        <?php endif; ?>
    </div>

    <!-- Delivered Details (if delivered) -->
    <?php if ($delivery['delivery_status'] == 'delivered'): ?>
    <div class="detail-section">
        <h6><i class="bi bi-check-circle-fill me-2 text-success"></i>Delivery Completion</h6>
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <td class="info-label">Signed By:</td>
                        <td class="info-value fw-bold"><?php echo $delivery['signed_by'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Delivered:</td>
                        <td class="info-value"><?php echo $delivery['delivery_date'] ? date('M d, Y h:i A', strtotime($delivery['delivery_date'])) : 'N/A'; ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <?php if ($photo): ?>
                <div class="d-flex align-items-center mt-2">
                    <span class="info-label">Proof:</span>
                    <button class="btn-map ms-2 view-photo-btn" data-photo="../uploads/deliveries/<?php echo $photo; ?>">
                        <i class="bi bi-image"></i> View Photo
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Remarks (if any) - WITHOUT the photo line -->
    <?php 
    $formatted_remarks = formatRemarks($delivery['remarks'] ?? '');
    if (!empty($formatted_remarks)): 
    ?>
    <div class="detail-section">
        <h6><i class="bi bi-chat-dots me-2"></i>Remarks</h6>
        <div class="border p-3 rounded bg-white" style="max-height: 150px; overflow-y: auto; scrollbar-width: none; -ms-overflow-style: none;">
            <div style="overflow-y: auto; scrollbar-width: none; -ms-overflow-style: none;">
                <?php echo $formatted_remarks; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Simple script for photo viewer -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.view-photo-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const photoUrl = this.getAttribute('data-photo');
            // Call the parent page's showPhotoModal function if it exists
            if (typeof window.parent.showPhotoModal === 'function') {
                window.parent.showPhotoModal(photoUrl);
            } else if (typeof window.showPhotoModal === 'function') {
                window.showPhotoModal(photoUrl);
            } else {
                // Fallback
                window.open(photoUrl, '_blank');
            }
        });
    });
});
</script>