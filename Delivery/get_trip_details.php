<?php
// get_trip_details.php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo '<div class="alert alert-danger">Not authenticated</div>';
    exit();
}

if (isset($_GET['trip_id'])) {
    $trip_id = intval($_GET['trip_id']);
    $branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
    $view_all = isset($_GET['view_all']) ? $_GET['view_all'] === 'true' : false;
    
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
                pl.so_id as pick_list_so_id,
                -- Get customer information from the pick list's sales order
                so.so_number,
                so.order_date as so_order_date,
                c.customer_id,
                c.customer_name,
                c.contact_person,
                c.phone_number,
                c.address,
                c.city,
                c.latitude,
                c.longitude,
                -- Count deliveries for this trip
                (SELECT COUNT(*) FROM deliveries WHERE trip_id = tt.trip_id) as delivery_count,
                -- Get delivery details for this trip
                GROUP_CONCAT(
                    CONCAT(
                        'DELIVERY:', d2.delivery_id, '|',
                        'STATUS:', d2.delivery_status, '|',
                        'CUSTOMER:', c2.customer_name, '|',
                        'ADDRESS:', c2.address, ', ', c2.city, '|',
                        'DATE:', IFNULL(d2.delivery_date, ''), '|',
                        'SIGNED:', IFNULL(d2.signed_by, '')
                    ) SEPARATOR ';;'
                ) as delivery_details
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
              LEFT JOIN deliveries d2 ON tt.trip_id = d2.trip_id
              LEFT JOIN customers c2 ON d2.customer_id = c2.customer_id
              WHERE tt.trip_id = ?
              GROUP BY tt.trip_id";
    
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
        
        // Parse delivery details
        $deliveries = [];
        if (!empty($row['delivery_details'])) {
            $delivery_strings = explode(';;', $row['delivery_details']);
            foreach ($delivery_strings as $delivery_str) {
                if (empty($delivery_str)) continue;
                
                $delivery_data = [];
                $parts = explode('|', $delivery_str);
                foreach ($parts as $part) {
                    if (strpos($part, ':') !== false) {
                        list($key, $value) = explode(':', $part, 2);
                        $delivery_data[trim($key)] = trim($value);
                    }
                }
                if (!empty($delivery_data)) {
                    $deliveries[] = $delivery_data;
                }
            }
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
            
            .detail-section {
                background: #f8f9fa;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .info-row {
                margin-bottom: 8px;
                display: flex;
            }
            
            .info-label {
                font-weight: 600;
                color: #6c757d;
                width: 120px;
                font-size: 0.9rem;
            }
            
            .info-value {
                color: #212529;
                flex: 1;
            }
            
            .delivery-item {
                border-left: 3px solid #0d6efd;
                padding-left: 10px;
                margin-bottom: 10px;
            }
            
            .delivery-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
            }
            
            .badge-delivered { background-color: #d4edda; color: #155724; }
            .badge-pending { background-color: #fff3cd; color: #856404; }
            .badge-partial { background-color: #cce5ff; color: #004085; }
            .badge-rejected { background-color: #f8d7da; color: #721c24; }
        </style>
        
        <div class="detail-section">
            <div class="row">
                <div class="col-md-6">
                    <h6><i class="bi bi-ticket-perforated me-2"></i>Trip Information</h6>
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="info-label">Ticket Number:</td>
                            <td class="info-value"><span class="badge bg-light text-dark fs-6 p-2"><?php echo htmlspecialchars($row['trip_number']); ?></span></td>
                        </tr>
                        <?php if ($has_picklist): ?>
                        <tr>
                            <td class="info-label">Pick List:</td>
                            <td class="info-value">
                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($row['pick_list_number']); ?></span>
                                <?php if (!empty($row['pick_status'])): ?>
                                    <br><small class="text-muted">Status: <?php echo ucfirst($row['pick_status']); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="info-label">Status:</td>
                            <td class="info-value"><span class="badge bg-<?php echo $status_badge; ?> p-2"><?php echo ucfirst(str_replace('-', ' ', $row['trip_status'])); ?></span></td>
                        </tr>
                        <tr>
                            <td class="info-label">Trip Date:</td>
                            <td class="info-value"><?php echo $trip_date; ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Delivery Count:</td>
                            <td class="info-value"><?php echo $row['delivery_count'] ?? 0; ?> stop(s)</td>
                        </tr>
                    </table>
                </div>
                
                <div class="col-md-6">
                    <h6><i class="bi bi-truck me-2"></i>Driver & Vehicle</h6>
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="info-label">Driver:</td>
                            <td class="info-value">
                                <?php if (!empty($row['driver_name'])): ?>
                                    <span class="driver-badge">
                                        <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($row['driver_name']); ?>
                                    </span>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="info-label">License:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['license_number'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Contact:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Vehicle Type:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['vehicle_type'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Plate Number:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['vehicle_plate_number'] ?? 'N/A'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="detail-section">
            <div class="row">
                <div class="col-md-6">
                    <h6><i class="bi bi-building me-2"></i>Branch Information</h6>
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="info-label">Branch:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['branch_name'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Branch ID:</td>
                            <td class="info-value"><?php echo $row['branch_id']; ?></td>
                        </tr>
                    </table>
                </div>
                
                <div class="col-md-6">
                    <h6><i class="bi bi-clock-history me-2"></i>Timestamps</h6>
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="info-label">Created By:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Created At:</td>
                            <td class="info-value"><?php echo $created_date; ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Last Updated:</td>
                            <td class="info-value"><?php echo $updated_date; ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Customer Information Section (if available from pick list) -->
        <?php if (!empty($row['customer_name'])): ?>
        <div class="detail-section">
            <h6><i class="bi bi-person me-2"></i>Customer Information (from Pick List)</h6>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="info-label">Customer:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Contact Person:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['contact_person'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Phone:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['phone_number'] ?? 'N/A'); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="info-label">Address:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['address'] . ', ' . $row['city']); ?></td>
                        </tr>
                        <?php if (!empty($row['latitude']) && !empty($row['longitude'])): ?>
                        <tr>
                            <td class="info-label">Coordinates:</td>
                            <td class="info-value">
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
                            <td class="info-label">Sales Order:</td>
                            <td class="info-value"><?php echo htmlspecialchars($row['so_number'] ?? 'N/A'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Remarks Section -->
        <?php if (!empty($row['remarks'])): ?>
        <div class="detail-section">
            <h6><i class="bi bi-chat-text me-2"></i>Remarks</h6>
            <div class="border p-3 rounded bg-white">
                <?php echo nl2br(htmlspecialchars($row['remarks'])); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Deliveries List Section -->
        <div class="detail-section">
            <h6><i class="bi bi-truck me-2"></i>Deliveries for this Trip</h6>
            <?php
            // Get deliveries for this trip (direct query para sure)
            $delivery_query = "SELECT d.*, c.customer_name, c.address, c.city, c.contact_person, c.phone_number,
                                      so.so_number, so.total_amount
                               FROM deliveries d
                               JOIN customers c ON d.customer_id = c.customer_id
                               LEFT JOIN sales_orders so ON d.so_id = so.so_id
                               WHERE d.trip_id = ?
                               ORDER BY d.stop_sequence ASC, d.delivery_id ASC";
            $delivery_stmt = $conn->prepare($delivery_query);
            $delivery_stmt->bind_param("i", $trip_id);
            $delivery_stmt->execute();
            $delivery_result = $delivery_stmt->get_result();
            
            if ($delivery_result->num_rows > 0) {
                echo '<div class="table-responsive">';
                echo '<table class="table table-sm table-hover">';
                echo '<thead class="table-light"><tr>';
                echo '<th>Stop</th>';
                echo '<th>SO #</th>';
                echo '<th>Customer</th>';
                echo '<th>Address</th>';
                echo '<th>Contact</th>';
                echo '<th>Status</th>';
                echo '<th>Delivery Date</th>';
                echo '<th>Signed By</th>';
                echo '</tr></thead><tbody>';
                
                while ($delivery = $delivery_result->fetch_assoc()) {
                    $delivery_status_badge = '';
                    $badge_class = '';
                    switch($delivery['delivery_status']) {
                        case 'delivered': 
                            $badge_class = 'badge-delivered'; 
                            $delivery_status_badge = 'Delivered';
                            break;
                        case 'pending': 
                            $badge_class = 'badge-pending'; 
                            $delivery_status_badge = 'Pending';
                            break;
                        case 'rejected': 
                            $badge_class = 'badge-rejected'; 
                            $delivery_status_badge = 'Rejected';
                            break;
                        case 'partial': 
                            $badge_class = 'badge-partial'; 
                            $delivery_status_badge = 'Partial';
                            break;
                        case 'in-transit': 
                            $badge_class = 'badge-pending'; 
                            $delivery_status_badge = 'In Transit';
                            break;
                        default: 
                            $badge_class = 'badge-pending'; 
                            $delivery_status_badge = ucfirst($delivery['delivery_status']);
                    }
                    
                    echo '<tr>';
                    echo '<td>' . ($delivery['stop_sequence'] ?? '-') . '</td>';
                    echo '<td><span class="badge bg-light text-dark">' . htmlspecialchars($delivery['so_number'] ?? 'N/A') . '</span></td>';
                    echo '<td>' . htmlspecialchars($delivery['customer_name']) . '</td>';
                    echo '<td>' . htmlspecialchars($delivery['address'] . ', ' . $delivery['city']) . '</td>';
                    echo '<td>' . htmlspecialchars($delivery['phone_number'] ?? '-') . '</td>';
                    echo '<td><span class="delivery-status-badge ' . $badge_class . '">' . $delivery_status_badge . '</span></td>';
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
        
        <?php
    } else {
        echo '<div class="alert alert-danger">Trip ticket not found</div>';
    }
    
    $stmt->close();
} else {
    echo '<div class="alert alert-danger">No trip ID specified</div>';
}
?>