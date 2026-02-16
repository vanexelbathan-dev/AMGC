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
    
    // Updated query to include order_status from sales_orders with branch filtering
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
                     b.branch_name, 
                     so.so_number, 
                     so.order_status,
                     c.customer_name,
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
        
        <div class="row">
            <!-- Item Information Column -->
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-box me-2"></i>Item Information</h6>
                    </div>
                    <div class="card-body py-2">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td width="40%"><strong>Item Code:</strong></td>
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
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Pick Details</h6>
                    </div>
                    <div class="card-body py-2">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td width="40%"><strong>Pick List:</strong></td>
                                <td>
                                    <span class="badge bg-light text-dark"><?php echo htmlspecialchars($row['pick_list_number']); ?></span>
                                    <?php echo $branch_indicator; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Pick Status:</strong></td>
                                <td>
                                    <span class="badge bg-<?php echo $status_badge; ?>">
                                        <?php echo $row['quantity_picked'] . '/' . $row['quantity_to_pick']; ?> (<?php echo $completion; ?>%)
                                    </span>
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
        
        <!-- Sales Order Information Section - NEW with Order Status -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header bg-light">
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
                                    <tr>
                                        <td><strong>Related SO Status:</strong></td>
                                        <td>
                                            <?php if ($row['order_status'] == 'delivered'): ?>
                                                <span class="badge bg-success">Completed</span>
                                            <?php elseif ($row['order_status'] == 'cancelled'): ?>
                                                <span class="badge bg-danger">Cancelled</span>
                                            <?php elseif ($row['order_status'] == 'ready'): ?>
                                                <span class="badge bg-success">Ready for Delivery</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">In Progress</span>
                                            <?php endif; ?>
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
                <div class="card mb-3 <?php echo empty($row['driver_name']) ? 'border-warning' : 'border-primary'; ?>">
                    <div class="card-header <?php echo empty($row['driver_name']) ? 'bg-warning bg-opacity-10' : 'bg-primary bg-opacity-10'; ?>">
                        <h6 class="mb-0">
                            <i class="bi bi-truck me-2"></i>Assigned Driver
                            <?php if (empty($row['driver_name'])): ?>
                                <span class="badge bg-warning ms-2">Not Assigned</span>
                            <?php else: ?>
                                <span class="badge bg-primary ms-2">Assigned</span>
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
                                                    <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($row['contact_number']); ?>
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
                                                <span class="badge bg-secondary">
                                                    <i class="bi bi-car-front me-1"></i>
                                                    <?php echo htmlspecialchars($row['vehicle_plate_number'] ?? 'N/A'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="bi bi-exclamation-circle text-warning me-2" style="font-size: 1.2rem;"></i>
                                <span class="fw-bold">No driver assigned to this pick list yet.</span>
                                <br>
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
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Pick Progress</h6>
                    </div>
                    <div class="card-body">
                        <div class="progress mb-2" style="height: 25px;">
                            <div class="progress-bar bg-<?php echo $status_badge; ?> progress-bar-striped" 
                                 role="progressbar" 
                                 style="width: <?php echo $completion; ?>%"
                                 aria-valuenow="<?php echo $completion; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <?php echo $completion; ?>%
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-light text-dark p-2">
                                    <i class="bi bi-box"></i> Picked: <strong><?php echo $row['quantity_picked']; ?></strong>
                                </span>
                            </div>
                            <div>
                                <span class="badge bg-light text-dark p-2">
                                    <i class="bi bi-box-seam"></i> Total: <strong><?php echo $row['quantity_to_pick']; ?></strong>
                                </span>
                            </div>
                            <div>
                                <span class="badge bg-<?php echo $status_badge; ?> p-2">
                                    <i class="bi bi-check-circle"></i> <?php echo $completion; ?>% Complete
                                </span>
                            </div>
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
        
        <!-- Action Buttons -->
        <div class="row mt-2">
            <div class="col-12 text-end">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Details
                </button>
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