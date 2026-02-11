<?php
require_once '../config/database.php';

if (isset($_GET['driver_id'])) {
    $driver_id = intval($_GET['driver_id']);
    
    $query = "SELECT d.*, b.branch_name, 
                     DATE_FORMAT(d.license_expiry, '%Y-%m-%d') as expiry_formatted,
                     DATE_FORMAT(d.created_at, '%Y-%m-%d %H:%i:%s') as created_formatted,
                     DATE_FORMAT(d.updated_at, '%Y-%m-%d %H:%i:%s') as updated_formatted
              FROM drivers d
              LEFT JOIN branches b ON d.branch_id = b.branch_id
              WHERE d.driver_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $driver_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $driver = $result->fetch_assoc();
        
        // Status badge color
        $status_badge = '';
        switch($driver['status']) {
            case 'active': $status_badge = 'success'; break;
            case 'inactive': $status_badge = 'secondary'; break;
            case 'on-leave': $status_badge = 'warning'; break;
            default: $status_badge = 'light';
        }
        
        // Check if license is expired
        $license_status = '';
        if ($driver['license_expiry']) {
            $expiry_date = new DateTime($driver['license_expiry']);
            $today = new DateTime();
            if ($expiry_date < $today) {
                $license_status = '<span class="badge bg-danger">Expired</span>';
            } else {
                $license_status = '<span class="badge bg-success">Valid</span>';
            }
        }
        
        echo '<div class="row">';
        echo '<div class="col-md-12">';
        echo '<h4>' . htmlspecialchars($driver['driver_name']) . '</h4>';
        echo '<span class="badge bg-' . $status_badge . ' mb-3">' . ucfirst(str_replace('-', ' ', $driver['status'])) . '</span>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="row mt-3">';
        echo '<div class="col-md-6">';
        echo '<h6>Driver Information</h6>';
        echo '<table class="table table-sm">';
        echo '<tr><td><strong>License Number:</strong></td><td>' . htmlspecialchars($driver['license_number']) . ' ' . $license_status . '</td></tr>';
        echo '<tr><td><strong>License Expiry:</strong></td><td>' . ($driver['expiry_formatted'] ? $driver['expiry_formatted'] : 'Not set') . '</td></tr>';
        echo '<tr><td><strong>Contact Number:</strong></td><td>' . ($driver['contact_number'] ? htmlspecialchars($driver['contact_number']) : 'N/A') . '</td></tr>';
        echo '<tr><td><strong>Branch:</strong></td><td>' . ($driver['branch_name'] ? htmlspecialchars($driver['branch_name']) : 'N/A') . '</td></tr>';
        echo '</table>';
        echo '</div>';
        
        echo '<div class="col-md-6">';
        echo '<h6>Vehicle Information</h6>';
        echo '<table class="table table-sm">';
        echo '<tr><td><strong>Vehicle Type:</strong></td><td>' . ($driver['vehicle_type'] ? htmlspecialchars($driver['vehicle_type']) : 'N/A') . '</td></tr>';
        echo '<tr><td><strong>Plate Number:</strong></td><td>' . ($driver['vehicle_plate_number'] ? htmlspecialchars($driver['vehicle_plate_number']) : 'N/A') . '</td></tr>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="row mt-3">';
        echo '<div class="col-md-12">';
        echo '<h6>System Information</h6>';
        echo '<table class="table table-sm">';
        echo '<tr><td><strong>Created:</strong></td><td>' . $driver['created_formatted'] . '</td></tr>';
        echo '<tr><td><strong>Last Updated:</strong></td><td>' . $driver['updated_formatted'] . '</td></tr>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
        
        // You can add trip history here if needed
        // $trip_query = "SELECT COUNT(*) as trip_count FROM trip_tickets WHERE driver_id = ?";
        // $trip_stmt = $conn->prepare($trip_query);
        // $trip_stmt->bind_param("i", $driver_id);
        // $trip_stmt->execute();
        // $trip_result = $trip_stmt->get_result();
        // $trip_data = $trip_result->fetch_assoc();
        // echo '<p><strong>Total Trips:</strong> ' . $trip_data['trip_count'] . '</p>';
        
    } else {
        echo '<div class="alert alert-warning">Driver not found!</div>';
    }
    
    $stmt->close();
} else {
    echo '<div class="alert alert-danger">Invalid request!</div>';
}

$conn->close();
?>