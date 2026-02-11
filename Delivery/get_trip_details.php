<?php
// get_trip_details.php
require_once '../config/database.php';

if (isset($_GET['trip_id'])) {
    $trip_id = intval($_GET['trip_id']);
    
    $query = "SELECT tt.*, d.driver_name, d.license_number, d.contact_number, 
                     d.vehicle_type, d.vehicle_plate_number, b.branch_name,
                     u.first_name, u.last_name
              FROM trip_tickets tt
              LEFT JOIN drivers d ON tt.driver_id = d.driver_id
              LEFT JOIN branches b ON tt.branch_id = b.branch_id
              LEFT JOIN users u ON tt.created_by = u.user_id
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
        ?>
        
        <div class="row">
            <div class="col-md-6">
                <h6>Trip Information</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Ticket Number:</strong></td>
                        <td><span class="badge bg-light text-dark"><?php echo $row['trip_number']; ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td><span class="badge bg-<?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('-', ' ', $row['trip_status'])); ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Trip Date:</strong></td>
                        <td><?php echo $trip_date; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Stops:</strong></td>
                        <td><?php echo $row['total_stops']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Delivered:</strong></td>
                        <td><?php echo $row['total_delivered']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Failed:</strong></td>
                        <td><?php echo $row['total_failed']; ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="col-md-6">
                <h6>Driver & Vehicle</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Driver:</strong></td>
                        <td><?php echo $row['driver_name'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>License:</strong></td>
                        <td><?php echo $row['license_number'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Contact:</strong></td>
                        <td><?php echo $row['contact_number'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Vehicle Type:</strong></td>
                        <td><?php echo $row['vehicle_type'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Plate Number:</strong></td>
                        <td><?php echo $row['vehicle_plate_number'] ?? 'N/A'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-md-6">
                <h6>Branch Information</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Branch:</strong></td>
                        <td><?php echo $row['branch_name'] ?? 'N/A'; ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="col-md-6">
                <h6>Timestamps</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Created By:</strong></td>
                        <td><?php echo $row['first_name'] . ' ' . $row['last_name']; ?></td>
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
        
        <?php if (!empty($row['remarks'])): ?>
        <div class="row mt-3">
            <div class="col-12">
                <h6>Remarks</h6>
                <div class="border p-3 rounded bg-light">
                    <?php echo nl2br(htmlspecialchars($row['remarks'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php
    } else {
        echo '<div class="alert alert-danger">Trip ticket not found</div>';
    }
    
    $stmt->close();
} else {
    echo '<div class="alert alert-danger">No trip ID specified</div>';
}
?>