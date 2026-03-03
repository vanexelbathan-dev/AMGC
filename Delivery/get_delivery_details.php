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

<!-- ===== SIMPLE HTML CONTENT ONLY - NO STYLES ===== -->
<!-- Use Bootstrap classes only, no custom CSS that might affect the main page -->

<div class="delivery-details-container">
    <!-- Header with Status -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0">
            <i class="bi bi-truck text-primary me-1"></i>
            Delivery #<?php echo $delivery['delivery_id']; ?> - <?php echo $delivery['so_number']; ?>
        </h6>
        <span class="badge bg-<?php echo $status_color; ?>">
            <?php echo ucfirst(str_replace('-', ' ', $delivery['delivery_status'])); ?>
        </span>
    </div>

    <div class="row g-2">
        <!-- Order Information -->
        <div class="col-md-6">
            <div class="border rounded p-2 bg-light mb-2">
                <small class="fw-bold text-secondary d-block mb-1"><i class="bi bi-cart-check me-1"></i>Order</small>
                <div class="small">
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Order #:</span>
                        <span class="fw-medium"><?php echo $delivery['so_number']; ?></span>
                    </div>
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Date:</span>
                        <span><?php echo date('M d, Y', strtotime($delivery['order_date'])); ?></span>
                    </div>
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Amount:</span>
                        <span class="fw-bold text-primary">₱<?php echo number_format($delivery['total_amount'], 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delivery Information -->
        <div class="col-md-6">
            <div class="border rounded p-2 bg-light mb-2">
                <small class="fw-bold text-secondary d-block mb-1"><i class="bi bi-geo-alt me-1"></i>Delivery</small>
                <div class="small">
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Stop #:</span>
                        <span><?php echo $delivery['stop_sequence'] ?? 'N/A'; ?></span>
                    </div>
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Schedule:</span>
                        <span><?php echo $delivery['delivery_date'] ? date('M d, h:i A', strtotime($delivery['delivery_date'])) : 'N/A'; ?></span>
                    </div>
                    <?php if ($delivery['signed_by']): ?>
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Signed:</span>
                        <span class="fw-bold"><?php echo $delivery['signed_by']; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-2">
        <!-- Customer Information -->
        <div class="col-md-6">
            <div class="border rounded p-2 bg-light mb-2">
                <small class="fw-bold text-secondary d-block mb-1"><i class="bi bi-person me-1"></i>Customer</small>
                <div class="small">
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Name:</span>
                        <span class="fw-medium"><?php echo $delivery['customer_name']; ?></span>
                    </div>
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Contact:</span>
                        <span><?php echo $delivery['phone_number'] ?? 'N/A'; ?></span>
                    </div>
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Address:</span>
                        <span><?php echo $delivery['address'] . ', ' . $delivery['city']; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trip Information -->
        <div class="col-md-6">
            <div class="border rounded p-2 bg-light mb-2">
                <small class="fw-bold text-secondary d-block mb-1"><i class="bi bi-ticket me-1"></i>Trip</small>
                <div class="small">
                    <?php if ($delivery['trip_number']): ?>
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Trip #:</span>
                        <span><?php echo $delivery['trip_number']; ?></span>
                    </div>
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Driver:</span>
                        <span><?php echo $delivery['driver_name']; ?></span>
                    </div>
                    <div class="d-flex">
                        <span style="width: 70px;" class="text-muted">Vehicle:</span>
                        <span><?php echo $delivery['vehicle_plate_number']; ?></span>
                    </div>
                    <?php else: ?>
                    <div class="text-muted">Not assigned to trip</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Items List -->
    <div class="border rounded p-2 bg-light mb-2">
        <small class="fw-bold text-secondary d-block mb-1"><i class="bi bi-box-seam me-1"></i>Items (<?php echo count($items_list); ?>)</small>
        <?php if (!empty($items_list)): ?>
            <div class="table-responsive">
                <table class="table table-sm table-borderless small mb-0">
                    <thead>
                        <tr class="border-bottom">
                            <th>Item</th>
                            <th class="text-center" width="50">Qty</th>
                            <th class="text-end" width="70">Price</th>
                            <th class="text-end" width="70">Total</th>
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
                            <td class="text-center"><?php echo $qty; ?></td>
                            <td class="text-end">₱<?php echo number_format($price, 2); ?></td>
                            <td class="text-end">₱<?php echo number_format($total, 2); ?></td>
                        </tr>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </tbody>
                    <tfoot class="border-top">
                        <tr>
                            <th colspan="3" class="text-end">TOTAL:</th>
                            <th class="text-end">₱<?php echo number_format($total_amount, 2); ?></th>
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
    <div class="border rounded p-2 bg-light mb-2">
        <small class="fw-bold text-success d-block mb-1"><i class="bi bi-check-circle-fill me-1"></i>Delivery Completion</small>
        <div class="row small">
            <div class="col-md-6">
                <div class="d-flex">
                    <span style="width: 80px;" class="text-muted">Signed By:</span>
                    <span class="fw-bold"><?php echo $delivery['signed_by'] ?? 'N/A'; ?></span>
                </div>
                <div class="d-flex">
                    <span style="width: 80px;" class="text-muted">Delivered:</span>
                    <span><?php echo $delivery['delivery_date'] ? date('M d, Y h:i A', strtotime($delivery['delivery_date'])) : 'N/A'; ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <?php if ($photo): ?>
                <div class="d-flex">
                    <span style="width: 80px;" class="text-muted">Proof:</span>
                    <span>
                        <button class="btn btn-sm btn-outline-primary py-0 view-photo-btn" data-photo="../uploads/deliveries/<?php echo $photo; ?>">
                            <i class="bi bi-image"></i> View Photo
                        </button>
                    </span>
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
    <div class="border rounded p-2 bg-light">
        <small class="fw-bold text-secondary d-block mb-1"><i class="bi bi-chat-dots me-1"></i>Remarks</small>
        <div class="small p-2 bg-white rounded" style="max-height: 150px; overflow-y: auto;">
            <?php echo $formatted_remarks; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Simple script for photo viewer - but use existing showPhotoModal function -->
<script>
// Just trigger the existing showPhotoModal function from parent page
document.querySelectorAll('.view-photo-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const photoUrl = this.getAttribute('data-photo');
        // Call the parent page's showPhotoModal function if it exists
        if (typeof window.parent.showPhotoModal === 'function') {
            window.parent.showPhotoModal(photoUrl);
        } else if (typeof showPhotoModal === 'function') {
            showPhotoModal(photoUrl);
        } else {
            // Fallback
            window.open(photoUrl, '_blank');
        }
    });
});
</script>