<?php
// get_pick_item_details.php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo '<div class="alert alert-danger">Unauthorized access. Please login again.</div>';
    exit();
}

// Get user's branch context
$user_branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

if (isset($_GET['pick_item_id'])) {
    $pick_item_id = intval($_GET['pick_item_id']);
    
    // Updated query to include customer location data
    $query = "SELECT pli.*, 
                     pl.pick_list_number, 
                     pl.pick_status as list_status,
                     pl.branch_id,
                     pl.driver_id,
                     d.driver_name,
                     d.license_number,
                     d.vehicle_type,
                     d.vehicle_plate_number,
                     d.contact_number,
                     i.item_name, 
                     i.item_code, 
                     i.unit_price, 
                     i.unit_type,
                     i.price_case,
                     i.price_inner_pack,
                     i.price_box,
                     i.price_carton,
                     b.branch_name, 
                     so.so_number, 
                     so.order_status,
                     c.customer_name,
                     c.full_address,
                     c.address as customer_address,
                     c.latitude,
                     c.longitude,
                     c.delivery_instructions,
                     (pli.quantity_picked / pli.quantity_to_pick * 100) as completion_percentage
              FROM pick_list_items pli
              JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
              JOIN items i ON pli.item_id = i.item_id
              JOIN branches b ON pl.branch_id = b.branch_id
              LEFT JOIN drivers d ON pl.driver_id = d.driver_id
              LEFT JOIN sales_orders so ON pl.so_id = so.so_id
              LEFT JOIN customers c ON so.customer_id = c.customer_id
              WHERE pli.pick_item_id = ?";
    
    // Add branch filter if user doesn't have view_all_branches permission
    if (!$view_all_branches && $user_branch_id > 0) {
        $query .= " AND pl.branch_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $pick_item_id, $user_branch_id);
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $pick_item_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if user has access to this branch (additional security check)
        if (!$view_all_branches && $row['branch_id'] != $user_branch_id) {
            echo '<div class="alert alert-danger">You do not have permission to view this item. This item belongs to a different branch.</div>';
            exit();
        }
        
        // Format location data for display
        $location_display = '';
        $has_location = false;
        $location_raw = '';
        $coordinates = '';
        
        // Check for full_address first
        if (!empty($row['full_address'])) {
            $location_display .= '<span class="address-text full-address"><i class="bi bi-pin-map-fill"></i> ' . 
                               htmlspecialchars($row['full_address']) . '</span>';
            $location_raw = $row['full_address'];
            $has_location = true;
        }
        // Check for legacy address field
        else if (!empty($row['customer_address'])) {
            $location_display .= '<span class="address-text"><i class="bi bi-pin-map-fill"></i> ' . 
                               htmlspecialchars($row['customer_address']) . '</span>';
            $location_raw = $row['customer_address'];
            $has_location = true;
        }
        
        // Check for coordinates
        if (!empty($row['latitude']) && !empty($row['longitude'])) {
            $coordinates = number_format($row['latitude'], 6) . ', ' . number_format($row['longitude'], 6);
            
            if (!empty($location_display)) {
                $location_display .= '<br>';
            }
            
            $location_display .= '<span class="coordinate-badge"><i class="bi bi-geo-alt-fill"></i> ' . 
                               $coordinates . '</span>';
            
            $location_display .= '<br><a href="https://www.google.com/maps?q=' . $row['latitude'] . ',' . $row['longitude'] . '" target="_blank" class="map-link">';
            $location_display .= '<i class="bi bi-box-arrow-up-right"></i> View Map</a>';
            
            $has_location = true;
            
            // If no address but we have coordinates, use coordinates as raw location
            if (empty($location_raw)) {
                $location_raw = 'GPS: ' . $coordinates;
            }
        }
        
        // Add delivery instructions if available
        if (!empty($row['delivery_instructions'])) {
            $location_display .= '<br><small class="text-info"><i class="bi bi-info-circle"></i> ' . 
                               htmlspecialchars($row['delivery_instructions']) . '</small>';
        }
        
        if (!$has_location) {
            $location_display = '<span class="text-muted fst-italic">— No location data available —</span>';
            $location_raw = 'No location data';
        }
        
        // Format status badge for pick status
        $status_badge = '';
        if ($row['quantity_picked'] >= $row['quantity_to_pick']) {
            $status_badge = 'success';
        } elseif ($row['quantity_picked'] > 0) {
            $status_badge = 'info';
        } else {
            $status_badge = 'warning';
        }
        
        // Format order status badge
        $order_status_badge = '';
        $order_status_text = '';
        switch($row['order_status']) {
            case 'pending':
                $order_status_badge = 'warning';
                $order_status_text = 'Pending';
                break;
            case 'confirmed':
                $order_status_badge = 'info';
                $order_status_text = 'Confirmed';
                break;
            case 'processing':
                $order_status_badge = 'primary';
                $order_status_text = 'Processing';
                break;
            case 'ready':
                $order_status_badge = 'success';
                $order_status_text = 'Ready for Delivery';
                break;
            case 'delivered':
                $order_status_badge = 'success';
                $order_status_text = 'Delivered';
                break;
            case 'cancelled':
                $order_status_badge = 'danger';
                $order_status_text = 'Cancelled';
                break;
            default:
                $order_status_badge = 'secondary';
                $order_status_text = ucfirst($row['order_status'] ?? 'N/A');
        }
        
        // Calculate values
        $total_value = $row['quantity_to_pick'] * $row['unit_price'];
        $completion = number_format($row['completion_percentage'] ?? 0, 1);
        
        // Show branch indicator for admin users
        $branch_indicator = '';
        if ($view_all_branches) {
            $branch_indicator = '<span class="badge bg-info ms-2">' . htmlspecialchars($row['branch_name']) . '</span>';
        }
        ?>
        
        <!-- Hidden data for print function -->
        <div id="print-data" 
             data-picklist="<?php echo htmlspecialchars($row['pick_list_number']); ?>"
             data-itemname="<?php echo htmlspecialchars($row['item_name']); ?>"
             data-itemcode="<?php echo htmlspecialchars($row['item_code']); ?>"
             data-customer="<?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?>"
             data-sonumber="<?php echo htmlspecialchars($row['so_number'] ?? 'N/A'); ?>"
             data-driver="<?php echo htmlspecialchars($row['driver_name'] ?? 'No Driver Assigned'); ?>"
             data-vehicle="<?php echo htmlspecialchars($row['vehicle_type'] ?? 'No vehicle'); ?>"
             data-plate="<?php echo htmlspecialchars($row['vehicle_plate_number'] ?? 'No plate'); ?>"
             data-location="<?php echo htmlspecialchars($location_raw); ?>"
             data-coordinates="<?php echo htmlspecialchars($coordinates); ?>"
             data-quantity-to-pick="<?php echo $row['quantity_to_pick']; ?>"
             data-quantity-picked="<?php echo $row['quantity_picked']; ?>"
             data-completion="<?php echo $completion; ?>"
             data-order-status="<?php echo $order_status_text; ?>">
        </div>
        
        <div class="row">
            <!-- Item Information Column -->
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-box me-2"></i>Item Information</h6>
                    </div>
                    <div class="card-body py-2">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td width="40%"><strong>Pick List:</strong></td>
                                <td><?php echo htmlspecialchars($row['pick_list_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Item Code:</strong></td>
                                <td><?php echo htmlspecialchars($row['item_code']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Item Name:</strong></td>
                                <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Unit Type:</strong></td>
                                <td><?php echo ucfirst($row['unit_type']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Unit Price:</strong></td>
                                <td>₱<?php echo number_format($row['unit_price'], 2); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Pick Details Column -->
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Pick Details</h6>
                    </div>
                    <div class="card-body py-2">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td width="40%"><strong>Pick Status:</strong></td>
                                <td>
                                    <span class="badge bg-<?php echo $status_badge; ?>">
                                        <?php echo $row['quantity_picked'] . '/' . $row['quantity_to_pick']; ?> (<?php echo $completion; ?>%)
                                    </span>
                                    <?php echo $branch_indicator; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Location Bin:</strong></td>
                                <td><?php echo htmlspecialchars($row['location_bin'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Total Value:</strong></td>
                                <td>₱<?php echo number_format($total_value, 2); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Customer Location Section - Enhanced -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header" style="background: linear-gradient(135deg, var(--dark-green), var(--primary-green));">
                        <h6 class="mb-0 text-white">
                            <i class="bi bi-geo-alt me-2 text-white"></i>Delivery Location
                            <?php if ($has_location): ?>
                                <span class="badge bg-light text-dark ms-2">Available</span>
                            <?php else: ?>
                                <span class="badge bg-warning ms-2">No Location</span>
                            <?php endif; ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if ($has_location): ?>
                            <div class="row">
                                <div class="col-12">
                                    <div class="location-display p-3" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px;">
                                        <?php echo $location_display; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-geo-alt text-muted" style="font-size: 3rem;"></i>
                                <p class="mb-1 fw-bold">No location data available for this customer</p>
                                <small class="text-muted">Please update customer information to add delivery location.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sales Order Information Section -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-cart me-2"></i>Sales Order Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td width="40%"><strong>SO Number:</strong></td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                <?php echo htmlspecialchars($row['so_number'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Customer:</strong></td>
                                        <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Branch:</strong></td>
                                        <td><?php echo htmlspecialchars($row['branch_name']); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td width="40%"><strong>Order Status:</strong></td>
                                        <td>
                                            <span class="badge bg-<?php echo $order_status_badge; ?>">
                                                <?php echo $order_status_text; ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Driver Information Section -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header" style="background: linear-gradient(135deg, var(--dark-green), var(--primary-green));">
                        <h6 class="mb-0 text-white">
                            <i class="bi bi-truck me-2 text-white"></i>Assigned Driver
                            <?php if (empty($row['driver_name'])): ?>
                                <span class="badge bg-warning ms-2">Not Assigned</span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark ms-2">Assigned</span>
                            <?php endif; ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($row['driver_name'])): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td width="40%"><strong>Driver Name:</strong></td>
                                            <td>
                                                <span class="fw-bold"><?php echo htmlspecialchars($row['driver_name']); ?></span>
                                                <?php if (!empty($row['license_number'])): ?>
                                                    <br><small class="text-muted">License: <?php echo htmlspecialchars($row['license_number']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Contact Number:</strong></td>
                                            <td>
                                                <?php if (!empty($row['contact_number'])): ?>
                                                    <i class="bi bi-telephone me-1" style="color: var(--primary-green);"></i>
                                                    <?php echo htmlspecialchars($row['contact_number']); ?>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td width="40%"><strong>Vehicle Type:</strong></td>
                                            <td><?php echo htmlspecialchars($row['vehicle_type'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Plate Number:</strong></td>
                                            <td>
                                                <span class="badge" style="background: var(--light-green); color: var(--dark-green);">
                                                    <i class="bi bi-car-front me-1"></i>
                                                    <?php echo htmlspecialchars($row['vehicle_plate_number'] ?? 'N/A'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-exclamation-circle text-warning me-2" style="font-size: 2rem;"></i>
                                <p class="mb-1 fw-bold">No driver assigned to this pick list yet.</p>
                                <small class="text-muted">A driver can be assigned by editing the pick list in the Branch Admin panel.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Progress Section -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Pick Progress</h6>
                    </div>
                    <div class="card-body">
                        <!-- Progress Bar -->
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar bg-<?php echo $status_badge; ?> progress-bar-striped" 
                                 role="progressbar" 
                                 style="width: <?php echo $completion; ?>%"
                                 aria-valuenow="<?php echo $completion; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <?php echo $completion; ?>%
                            </div>
                        </div>
                        
                        <!-- Progress Stats -->
                        <div class="row g-2 justify-content-center">
                            <div class="col-6 col-md-5">
                                <div class="progress-stat-card">
                                    <div class="d-flex flex-column align-items-center">
                                        <span class="progress-stat-label">Picked</span>
                                        <span class="progress-stat-value" style="color: var(--primary-green);"><?php echo number_format($row['quantity_picked']); ?></span>
                                        <small class="text-muted">units</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-5">
                                <div class="progress-stat-card">
                                    <div class="d-flex flex-column align-items-center">
                                        <span class="progress-stat-label">Total</span>
                                        <span class="progress-stat-value"><?php echo number_format($row['quantity_to_pick']); ?></span>
                                        <small class="text-muted">units</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Completion Badge -->
                        <div class="text-center mt-3">
                            <span class="badge bg-<?php echo $status_badge; ?> p-2" style="font-size: 0.9rem;">
                                <i class="bi bi-check-circle me-1"></i> <?php echo $completion; ?>% Complete
                            </span>
                        </div>
                        
                        <?php if ($row['order_status'] == 'delivered'): ?>
                            <div class="alert alert-success mt-3 mb-0">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                This sales order has been marked as <strong>Delivered</strong>.
                            </div>
                        <?php elseif ($row['order_status'] == 'cancelled'): ?>
                            <div class="alert alert-danger mt-3 mb-0">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                This sales order has been <strong>Cancelled</strong>.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        

        
        <style>
            .card {
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                border: 1px solid rgba(0,0,0,0.08);
            }
            .card-header {
                background-color: #f8f9fa;
                border-bottom: 1px solid rgba(0,0,0,0.05);
                padding: 12px 16px;
                font-weight: 600;
            }
            .table-borderless td {
                padding: 6px 0;
                border: none;
            }
            .progress {
                background-color: #e9ecef;
                border-radius: 12px;
                box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
            }
            .progress-bar {
                border-radius: 12px;
                transition: width 0.3s ease;
            }
            .badge {
                font-weight: 500;
                padding: 6px 10px;
            }
            .location-display {
                font-size: 14px;
                line-height: 1.6;
            }
            .coordinate-badge {
                background-color: #e9ecef;
                color: #495057;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                display: inline-block;
                margin: 2px 0;
            }
            .coordinate-badge i {
                color: #0d6efd;
            }
            .address-text {
                font-size: 12px;
                color: #212529;
                display: inline-block;
                padding: 2px 0;
            }
            .address-text i {
                color: #198754;
            }
            .full-address {
                font-weight: 500;
            }
            .map-link {
                font-size: 11px;
                color: #0d6efd;
                text-decoration: none;
                display: inline-block;
                margin-left: 5px;
            }
            .map-link:hover {
                text-decoration: underline;
            }
            .progress-stat-card {
                background: white;
                border: 1px solid #d1e7dd;
                border-radius: 16px;
                padding: 15px 10px;
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
                height: 100%;
                transition: all 0.3s ease;
                box-shadow: 0 2px 8px rgba(4, 120, 87, 0.05);
            }
            .progress-stat-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 16px rgba(4, 120, 87, 0.1);
                border-color: #198754;
            }
            .progress-stat-label {
                display: block;
                font-size: 0.7rem;
                color: #6b7280;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 4px;
            }
            .progress-stat-value {
                display: block;
                font-size: 1.3rem;
                font-weight: 700;
                line-height: 1.2;
            }
            
            /* Mobile responsiveness */
            @media (max-width: 576px) {
                .stat-value-responsive {
                    font-size: 1.1rem;
                }
                .stat-label-responsive {
                    font-size: 0.65rem;
                }
                .progress-stat-card {
                    padding: 10px 5px;
                }
            }
        </style>
        
        <script>
        // Function to set update pick item values
        function setUpdatePickItem(pickItemId, quantityToPick, quantityPicked) {
            // Close the view modal
            var viewModal = bootstrap.Modal.getInstance(document.getElementById('viewItemModal'));
            if (viewModal) {
                viewModal.hide();
            }
            
            // Open the update modal after a short delay
            setTimeout(function() {
                // Set the values in the update modal
                document.getElementById('update_pick_item_id').value = pickItemId;
                document.getElementById('update_quantity_to_pick').value = quantityToPick;
                document.getElementById('update_quantity_picked').value = quantityPicked;
                document.getElementById('update_quantity_picked').max = quantityToPick;
                
                // Open the update modal
                var updateModal = new bootstrap.Modal(document.getElementById('updatePickModal'));
                updateModal.show();
            }, 500);
        }
        </script>
        
        <?php
    } else {
        echo '<div class="alert alert-danger">Pick list item not found or you do not have permission to view it</div>';
    }
    
    $stmt->close();
} else {
    echo '<div class="alert alert-danger">No item ID specified</div>';
}

$conn->close();
?>