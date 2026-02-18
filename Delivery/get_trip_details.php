<?php
// get_trip_details.php
require_once '../config/database.php';

if (isset($_GET['trip_id'])) {
    $trip_id = intval($_GET['trip_id']);
    
    // UPDATED QUERY: Get driver from pick list and include pick list details
    $query = "SELECT 
                tt.*, 
                -- Get driver from pick list (priority) or fallback to trip ticket driver
                COALESCE(pl.driver_name, d.driver_name) as driver_name,
                COALESCE(pl.license_number, d.license_number) as license_number,
                COALESCE(pl.contact_number, d.contact_number) as contact_number,
                COALESCE(pl.vehicle_type, d.vehicle_type) as vehicle_type,
                COALESCE(pl.vehicle_plate_number, d.vehicle_plate_number) as vehicle_plate_number,
                b.branch_name,
                u.first_name, 
                u.last_name,
                pl.pick_list_id,
                pl.pick_list_number,
                pl.pick_status,
                -- Get customer information from the pick list's sales order
                so.so_number,
                c.customer_name,
                c.address,
                c.city,
                c.latitude,
                c.longitude,
                c.full_address,
                -- Count deliveries for this trip
                (SELECT COUNT(*) FROM deliveries WHERE trip_id = tt.trip_id) as delivery_count
              FROM trip_tickets tt
              LEFT JOIN drivers d ON tt.driver_id = d.driver_id
              LEFT JOIN branches b ON tt.branch_id = b.branch_id
              LEFT JOIN users u ON tt.created_by = u.user_id
              LEFT JOIN (
                SELECT 
                    pl.pick_list_id,
                    pl.pick_list_number,
                    pl.pick_status,
                    pl.so_id,
                    d.driver_name,
                    d.license_number,
                    d.contact_number,
                    d.vehicle_type,
                    d.vehicle_plate_number,
                    d.driver_id
                FROM pick_lists pl
                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
              ) pl ON tt.picklist_id = pl.pick_list_id
              LEFT JOIN sales_orders so ON pl.so_id = so.so_id
              LEFT JOIN customers c ON so.customer_id = c.customer_id
              WHERE tt.trip_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $trip_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Format status badge
        $status_badge = '';
        switch($row['trip_status']) {
            case 'completed': $status_badge = 'success'; break;
            case 'in-progress': $status_badge = 'warning'; break;
            case 'cancelled': $status_badge = 'danger'; break;
            case 'delayed': $status_badge = 'info'; break;
            default: $status_badge = 'secondary';
        }
        
        // Format dates
        $trip_date = date('F j, Y', strtotime($row['trip_date']));
        $created_date = date('F j, Y h:i A', strtotime($row['created_at']));
        $updated_date = date('F j, Y h:i A', strtotime($row['updated_at']));
        
        // Determine driver source
        $driver_source = !empty($row['pick_list_id']) ? ' (from pick list)' : '';
        $has_picklist = !empty($row['pick_list_number']);
        ?>
        
        <div class="row">
            <div class="col-md-6">
                <h6><i class="bi bi-ticket-perforated me-2"></i>Trip Information</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Ticket Number:</strong></td>
                        <td><span class="badge bg-light text-dark fs-6 p-2"><?php echo htmlspecialchars($row['trip_number']); ?></span></td>
                    </tr>
                    <?php if ($has_picklist): ?>
                    <tr>
                        <td><strong>Pick List:</strong></td>
                        <td>
                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($row['pick_list_number']); ?></span>
                            <?php if (!empty($row['pick_status'])): ?>
                                <br><small class="text-muted">Status: <?php echo ucfirst($row['pick_status']); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td><span class="badge bg-<?php echo $status_badge; ?> p-2"><?php echo ucfirst(str_replace('-', ' ', $row['trip_status'])); ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Trip Date:</strong></td>
                        <td><?php echo $trip_date; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Delivery Count:</strong></td>
                        <td><?php echo $row['delivery_count'] ?? 0; ?> stop(s)</td>
                    </tr>
                </table>
            </div>
            
            <div class="col-md-6">
                <h6><i class="bi bi-truck me-2"></i>Driver & Vehicle</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Driver:</strong></td>
                        <td>
                            <?php if (!empty($row['driver_name'])): ?>
                                    <?php echo htmlspecialchars($row['driver_name']); ?>
                                </span>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>License:</strong></td>
                        <td><?php echo htmlspecialchars($row['license_number'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Contact:</strong></td>
                        <td><?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Vehicle Type:</strong></td>
                        <td><?php echo htmlspecialchars($row['vehicle_type'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Plate Number:</strong></td>
                        <td><?php echo htmlspecialchars($row['vehicle_plate_number'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-md-6">
                <h6><i class="bi bi-building me-2"></i>Branch Information</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Branch:</strong></td>
                        <td><?php echo htmlspecialchars($row['branch_name'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="col-md-6">
                <h6><i class="bi bi-clock-history me-2"></i>Timestamps</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Created By:</strong></td>
                        <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Created At:</strong></td>
                        <td><?php echo $created_date; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Last Updated:</strong></td>
                        <td><?php echo $updated_date; ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Customer Information Section (if available) -->
        <?php if (!empty($row['customer_name'])): ?>
        <div class="row mt-3">
            <div class="col-12">
                <h6><i class="bi bi-person me-2"></i>Customer Information</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Customer:</strong></td>
                        <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                    </tr>
                    <?php if (!empty($row['address']) || !empty($row['full_address'])): ?>
                    <tr>
                        <td><strong>Address:</strong></td>
                        <td>
                            <?php 
                            if (!empty($row['full_address'])) {
                                echo htmlspecialchars($row['full_address']);
                            } else {
                                echo htmlspecialchars($row['address'] . ', ' . $row['city']);
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($row['latitude']) && !empty($row['longitude'])): ?>
                    <tr>
                        <td><strong>Coordinates:</strong></td>
                        <td>
                            <?php echo number_format($row['latitude'], 6); ?>, <?php echo number_format($row['longitude'], 6); ?>
                            <br>
                            <a href="https://www.google.com/maps?q=<?php echo $row['latitude']; ?>,<?php echo $row['longitude']; ?>" 
                               target="_blank" class="btn btn-sm btn-outline-primary mt-1">
                                <i class="bi bi-geo-alt-fill"></i> View on Google Maps
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong>Sales Order:</strong></td>
                        <td><?php echo htmlspecialchars($row['so_number'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($row['remarks'])): ?>
        <div class="row mt-3">
            <div class="col-12">
                <h6><i class="bi bi-chat-text me-2"></i>Remarks</h6>
                <div class="border p-3 rounded bg-light">
                    <?php echo nl2br(htmlspecialchars($row['remarks'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Delivery List Section -->
        <div class="row mt-4">
            <div class="col-12">
                <h6><i class="bi bi-truck me-2"></i>Deliveries for this Trip</h6>
                <?php
                // Get deliveries for this trip
                $delivery_query = "SELECT d.*, c.customer_name, c.address, c.city 
                                   FROM deliveries d
                                   JOIN customers c ON d.customer_id = c.customer_id
                                   WHERE d.trip_id = ?
                                   ORDER BY d.stop_sequence ASC";
                $delivery_stmt = $conn->prepare($delivery_query);
                $delivery_stmt->bind_param("i", $trip_id);
                $delivery_stmt->execute();
                $delivery_result = $delivery_stmt->get_result();
                
                if ($delivery_result->num_rows > 0) {
                    echo '<div class="table-responsive">';
                    echo '<table class="table table-sm table-hover">';
                    echo '<thead class="table-light"><tr>';
                    echo '<th>Stop</th>';
                    echo '<th>Customer</th>';
                    echo '<th>Address</th>';
                    echo '<th>Status</th>';
                    echo '<th>Delivery Date</th>';
                    echo '<th>Signed By</th>';
                    echo '</tr></thead><tbody>';
                    
                    while ($delivery = $delivery_result->fetch_assoc()) {
                        $delivery_status_badge = '';
                        switch($delivery['delivery_status']) {
                            case 'delivered': $delivery_status_badge = 'success'; break;
                            case 'pending': $delivery_status_badge = 'warning'; break;
                            case 'rejected': $delivery_status_badge = 'danger'; break;
                            case 'partial': $delivery_status_badge = 'info'; break;
                            default: $delivery_status_badge = 'secondary';
                        }
                        
                        echo '<tr>';
                        echo '<td>' . ($delivery['stop_sequence'] ?? '-') . '</td>';
                        echo '<td>' . htmlspecialchars($delivery['customer_name']) . '</td>';
                        echo '<td>' . htmlspecialchars($delivery['address'] . ', ' . $delivery['city']) . '</td>';
                        echo '<td><span class="badge bg-' . $delivery_status_badge . '">' . ucfirst($delivery['delivery_status']) . '</span></td>';
                        echo '<td>' . ($delivery['delivery_date'] ? date('Y-m-d H:i', strtotime($delivery['delivery_date'])) : '-') . '</td>';
                        echo '<td>' . htmlspecialchars($delivery['signed_by'] ?? '-') . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody></table>';
                    echo '</div>';
                } else {
                    echo '<p class="text-muted">No deliveries recorded for this trip yet.</p>';
                }
                $delivery_stmt->close();
                ?>
            </div>
        </div>
        
        <?php
    } else {
        echo '<div class="alert alert-danger">Trip ticket not found</div>';
    }
    
    $stmt->close();
} else {
    echo '<div class="alert alert-danger">No trip ID specified</div>';
}
?>

<style>
.driver-badge {
    display: inline-block;
    padding: 4px 10px;
    background-color: #e8f4fd;
    color: #084298;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    border-left: 3px solid #0d6efd;
}

.driver-badge i {
    margin-right: 4px;
    color: #0d6efd;
}

.table td {
    vertical-align: middle;
}

h6 {
    color: #495057;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #dee2e6;
}

h6 i {
    color: #0d6efd;
}
</style>