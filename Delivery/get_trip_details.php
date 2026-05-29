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
            /* No global html/body overflow: hidden - Bootstrap handles modal body scrolling */
            /* Allow scrolling on the modal body but hide scrollbar */
            .modal-body {
                overflow-y: auto;
                scrollbar-width: none; /* Firefox */
                -ms-overflow-style: none; /* IE and Edge */
                max-height: calc(90vh - 120px);
            }
            
            /* Chrome, Safari, Opera */
            .modal-body::-webkit-scrollbar {
                display: none;
            }
            
            /* Scope all styles to trip-details-container */
            .trip-details-container {
                font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
                padding-right: 2px; /* Prevent content shift when scrollbar hidden */
            }
            
            /* Color palette variables */
            .trip-details-container {
                --primary-green: #44D34E;      /* Light green from AMGC logo */
                --secondary-green: #44D34E;     /* Secondary green */
                --light-green: #d1fae5;         /* Light green background */
                --dark-green: #047857;           /* Dark teal/green */
                --success-green: #44D34E;        /* Success green */
                --primary-color: #44D34E;        /* For backward compatibility */
                --primary-dark: #05b01e;          /* Darker green */
                --primary-light: #44D34E;         /* Light green */
                --secondary-color: #047857;       /* Dark teal/green */
                --accent-color: #0d6efd;          /* Blue for accents */
                --text-primary: #212529;
                --text-secondary: #6c757d;
                --bg-light: #f8f9fa;
            }
            
            .trip-details-container .driver-badge {
                display: inline-block;
                padding: 4px 10px;
                background-color: rgba(68, 211, 78, 0.1);  /* Primary green with opacity */
                color: var(--dark-green);
                border-radius: 20px;
                font-size: 12px;
                font-weight: 500;
                border-left: 3px solid var(--primary-green);
                word-break: break-word;
                max-width: 100%;
            }
            
            .trip-details-container .driver-badge i {
                margin-right: 4px;
                color: var(--primary-green);
            }
            
            .trip-details-container .table td {
                vertical-align: middle;
            }
            
            .trip-details-container h6 {
                color: var(--dark-green);
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 2px solid #dee2e6;
                font-size: clamp(0.95rem, 2vw, 1.1rem); /* Responsive font size */
            }
            
            .trip-details-container h6 i {
                color: var(--primary-green);
            }
            
            .trip-details-container .detail-section {
                background: var(--bg-light);
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .trip-details-container .info-row {
                margin-bottom: 8px;
                display: flex;
            }
            
            .trip-details-container .info-label {
                font-weight: 600;
                color: var(--text-secondary);
                width: 120px;
                font-size: clamp(0.8rem, 1.5vw, 0.9rem); /* Responsive font size */
                flex-shrink: 0;
            }
            
            .trip-details-container .info-value {
                color: var(--text-primary);
                flex: 1;
                font-size: clamp(0.85rem, 1.8vw, 0.95rem); /* Responsive font size */
                word-break: break-word;
                overflow-wrap: break-word;
                hyphens: auto;
            }
            
            .trip-details-container .delivery-item {
                border-left: 3px solid var(--primary-green);
                padding-left: 10px;
                margin-bottom: 10px;
            }
            
            .trip-details-container .delivery-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 4px;
                font-size: clamp(0.65rem, 1.2vw, 0.75rem); /* Responsive font size */
                font-weight: 600;
                white-space: nowrap;
            }
            
            .trip-details-container .badge-delivered { 
                background-color: var(--light-green); 
                color: var(--dark-green); 
            }
            .trip-details-container .badge-pending { 
                background-color: #fff3cd; 
                color: #856404; 
            }
            .trip-details-container .badge-partial { 
                background-color: #cce5ff; 
                color: var(--dark-green); 
            }
            .trip-details-container .badge-rejected { 
                background-color: #f8d7da; 
                color: #721c24; 
            }

            /* Google Maps button styling */
            .trip-details-container .btn-map {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 4px;
                background-color: white;
                color: var(--dark-green);
                border: 1px solid var(--primary-green);
                border-radius: 20px;
                padding: 6px 12px;
                font-size: 0.85rem;
                font-weight: 500;
                transition: all 0.2s ease;
                white-space: nowrap;
                text-decoration: none;
            }

            .trip-details-container .btn-map i {
                color: var(--primary-green);
                font-size: 1rem;
                transition: all 0.2s ease;
            }

            .trip-details-container .btn-map:hover {
                background-color: var(--primary-green);
                color: white;
                border-color: var(--primary-green);
            }

            .trip-details-container .btn-map:hover i {
                color: white;
            }
            
            /* Stop and SO badges */
            .trip-details-container .stop-badge {
                background: var(--primary-green);
                color: white;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: clamp(0.75rem, 1.3vw, 0.85rem);
                font-weight: 600;
                white-space: nowrap;
            }

            .trip-details-container .so-badge {
                background: #e9ecef;
                color: var(--dark-green);
                padding: 4px 12px;
                border-radius: 20px;
                font-size: clamp(0.75rem, 1.3vw, 0.85rem);
                font-weight: 500;
                white-space: nowrap;
                max-width: 200px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .trip-details-container .so-badge i {
                color: var(--dark-green);
            }

            /* Deliveries List - Same style as Customer Information */
            .trip-details-container .deliveries-list {
                display: flex;
                flex-direction: column;
                gap: 20px;
            }

            .trip-details-container .delivery-item-container {
                border-bottom: 1px solid #e9ecef;
                padding-bottom: 15px;
            }

            .trip-details-container .delivery-item-container:last-child {
                border-bottom: none;
                padding-bottom: 0;
            }

            .trip-details-container .delivery-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px;
                padding: 8px 12px;
                background-color: rgba(68, 211, 78, 0.05);
                border-radius: 6px;
                flex-wrap: wrap;
                gap: 8px;
            }

            .trip-details-container .delivery-title {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
                flex: 1;
            }

            .trip-details-container .delivery-status-badge {
                flex-shrink: 0;
            }

            .trip-details-container .delivery-details-table {
                margin-bottom: 0;
            }

            .trip-details-container .delivery-details-table tr:last-child td {
                border-bottom: none;
            }

            /* Empty state styling */
            .trip-details-container .empty-deliveries {
                text-align: center;
                padding: 40px 20px;
                background: var(--bg-light);
                border-radius: 10px;
                color: var(--text-secondary);
            }

            .trip-details-container .empty-deliveries i {
                font-size: 3rem;
                margin-bottom: 15px;
                color: var(--primary-green);
            }
            
            /* Bootstrap overrides - but only within container */
            .trip-details-container .badge {
                font-weight: 500;
                padding: 0.35rem 0.65rem;
            }
            
            .trip-details-container .table-borderless td {
                padding: 0.3rem 0;
            }
            
            .trip-details-container .btn-outline-primary {
                color: var(--dark-green);
                border-color: var(--primary-green);
            }
            
            .trip-details-container .btn-outline-primary:hover {
                background-color: var(--primary-green);
                border-color: var(--primary-green);
                color: white;
            }
            
            .trip-details-container .btn-outline-primary i {
                color: var(--primary-green);
            }
            
            .trip-details-container .btn-outline-primary:hover i {
                color: white;
            }
            
            /* Status badge colors - using our palette */
            .trip-details-container .badge.bg-success { 
                background-color: var(--primary-green) !important; 
            }
            .trip-details-container .badge.bg-warning { 
                background-color: #f59e0b !important; 
            }
            .trip-details-container .badge.bg-primary { 
                background-color: var(--accent-color) !important; 
            }
            .trip-details-container .badge.bg-info { 
                background-color: #0dcaf0 !important; 
                color: #000 !important;
            }
            .trip-details-container .badge.bg-danger { 
                background-color: #dc3545 !important; 
            }
            .trip-details-container .badge.bg-secondary { 
                background-color: var(--text-secondary) !important; 
            }

            /* Responsive adjustments */
            @media (max-width: 768px) {
                .trip-details-container .info-label {
                    width: 90px;
                }
                
                .trip-details-container .btn-map {
                    padding: 4px 10px;
                    font-size: 0.75rem;
                    border-radius: 16px;
                }
                
                .trip-details-container .btn-map i {
                    font-size: 0.85rem;
                }
                
                .trip-details-container .delivery-header {
                    flex-direction: row;
                    align-items: center;
                    gap: 8px;
                    padding: 8px 10px;
                }
                
                .trip-details-container .delivery-title {
                    flex: 1;
                }
                
                .trip-details-container .delivery-status-badge {
                    margin-left: auto;
                }
                
                .trip-details-container .so-badge {
                    max-width: 150px;
                }
            }
            
            @media (max-width: 480px) {
                .trip-details-container .info-label {
                    width: 80px;
                    font-size: 0.75rem;
                }
                
                .trip-details-container .info-value {
                    font-size: 0.8rem;
                }
                
                .trip-details-container .stop-badge, 
                .trip-details-container .so-badge {
                    font-size: 0.7rem;
                    padding: 3px 8px;
                }
                
                .trip-details-container .so-badge {
                    max-width: 120px;
                }
                
                .trip-details-container .btn-map {
                    padding: 3px 8px;
                    font-size: 0.7rem;
                    border-radius: 14px;
                    gap: 3px;
                }
                
                .trip-details-container .btn-map i {
                    font-size: 0.75rem;
                }
                
                .trip-details-container .delivery-header {
                    padding: 6px 8px;
                }
                
                .trip-details-container .delivery-item-container {
                    padding-bottom: 10px;
                }
            }
            
            @media (max-width: 360px) {
                .trip-details-container .info-label {
                    width: 70px;
                    font-size: 0.7rem;
                }
                
                .trip-details-container .info-value {
                    font-size: 0.75rem;
                }
                
                .trip-details-container .so-badge {
                    max-width: 100px;
                    font-size: 0.65rem;
                }
                
                .trip-details-container .stop-badge {
                    font-size: 0.65rem;
                    padding: 2px 6px;
                }
                
                .trip-details-container .delivery-header {
                    flex-direction: column;
                    align-items: flex-start;
                }
                
                .trip-details-container .delivery-title {
                    width: 100%;
                    justify-content: flex-start;
                }
                
                .trip-details-container .delivery-status-badge {
                    margin-left: 0;
                    align-self: flex-start;
                }
                
                .trip-details-container .btn-map {
                    padding: 2px 6px;
                    font-size: 0.65rem;
                    border-radius: 12px;
                }
                
                .trip-details-container .btn-map i {
                    font-size: 0.7rem;
                }
            }

            /* Print styles */
            @media print {
                .trip-details-container .delivery-card {
                    break-inside: avoid;
                    box-shadow: none;
                    border: 1px solid #000;
                }
                
                .trip-details-container .btn-map {
                    display: none !important;
                }
            }
        </style>
        
        <div class="trip-details-container">
            <!-- Trip Information Section -->
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
            
            <!-- Branch Information Section -->
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
                                       target="_blank" class="btn-map mt-1">
                                        <i class="bi bi-geo-alt-fill"></i> View Map
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
            
            <!-- Deliveries List Section - Same style as Customer Information -->
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
                    echo '<div class="deliveries-list">';
                    
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
                        
                        // Delivery item container - same style as customer info section (no extra card)
                        echo '<div class="delivery-item-container">';
                        
                        // Header with Stop and Status (like a mini-title)
                        echo '<div class="delivery-header">';
                        echo '<div class="delivery-title">';
                        echo '<span class="stop-badge">Stop ' . ($delivery['stop_sequence'] ?? '-') . '</span>';
                        echo '<span class="so-badge"><i class="bi bi-receipt"></i> ' . htmlspecialchars($delivery['so_number'] ?? 'N/A') . '</span>';
                        echo '</div>';
                        echo '<span class="delivery-status-badge ' . $badge_class . '">' . $delivery_status_badge . '</span>';
                        echo '</div>';
                        
                        // Details in table format - same as customer information
                        echo '<table class="table table-sm table-borderless delivery-details-table">';
                        
                        // Customer
                        echo '<tr>';
                        echo '<td class="info-label">Customer:</td>';
                        echo '<td class="info-value">' . htmlspecialchars($delivery['customer_name']) . '</td>';
                        echo '</tr>';
                        
                        // Contact Person (if available)
                        if (!empty($delivery['contact_person'])) {
                            echo '<tr>';
                            echo '<td class="info-label">Contact:</td>';
                            echo '<td class="info-value">' . htmlspecialchars($delivery['contact_person']) . '</td>';
                            echo '</tr>';
                        }
                        
                        // Phone (if available)
                        if (!empty($delivery['phone_number'])) {
                            echo '<tr>';
                            echo '<td class="info-label">Phone:</td>';
                            echo '<td class="info-value">' . htmlspecialchars($delivery['phone_number']) . '</td>';
                            echo '</tr>';
                        }
                        
                        // Address
                        echo '<tr>';
                        echo '<td class="info-label">Address:</td>';
                        echo '<td class="info-value">' . htmlspecialchars($delivery['address'] . ', ' . $delivery['city']) . '</td>';
                        echo '</tr>';
                        
                        // Delivery Date
                        echo '<tr>';
                        echo '<td class="info-label">Date:</td>';
                        echo '<td class="info-value">' . ($delivery['delivery_date'] ? date('Y-m-d H:i', strtotime($delivery['delivery_date'])) : 'Pending') . '</td>';
                        echo '</tr>';
                        
                        // Signed By (if available)
                        if (!empty($delivery['signed_by'])) {
                            echo '<tr>';
                            echo '<td class="info-label">Signed:</td>';
                            echo '<td class="info-value">' . htmlspecialchars($delivery['signed_by']) . '</td>';
                            echo '</tr>';
                        }
                        
                        // Total Amount (if available)
                        if (!empty($delivery['total_amount'])) {
                            echo '<tr>';
                            echo '<td class="info-label">Amount:</td>';
                            echo '<td class="info-value">₱' . number_format($delivery['total_amount'], 2) . '</td>';
                            echo '</tr>';
                        }
                        
                        echo '</table>';
                        echo '</div>'; // End delivery-item-container
                    }
                    
                    echo '</div>'; // End deliveries-list
                } else {
                    echo '<div class="empty-deliveries">';
                    echo '<i class="bi bi-truck"></i>';
                    echo '<p class="mb-0">No deliveries recorded for this trip yet.</p>';
                    echo '</div>';
                }
                $delivery_stmt->close();
                ?>
            </div>
        </div> <!-- End of trip-details-container -->
        
        <?php
    } else {
        echo '<div class="alert alert-danger">Trip ticket not found</div>';
    }
    
    $stmt->close();
} else {
    echo '<div class="alert alert-danger">No trip ID specified</div>';
}
?>