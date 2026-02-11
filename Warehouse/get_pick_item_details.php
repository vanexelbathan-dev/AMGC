<?php
// get_pick_item_details.php
require_once '../config/database.php';

if (isset($_GET['pick_item_id'])) {
    $pick_item_id = intval($_GET['pick_item_id']);
    
    $query = "SELECT pli.*, pl.pick_list_number, pl.pick_status as list_status, 
                     i.item_name, i.item_code, i.unit_price, i.unit_type,
                     b.branch_name, so.so_number, c.customer_name,
                     (pli.quantity_picked / pli.quantity_to_pick * 100) as completion_percentage
              FROM pick_list_items pli
              JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
              JOIN items i ON pli.item_id = i.item_id
              JOIN branches b ON pl.branch_id = b.branch_id
              LEFT JOIN sales_orders so ON pl.so_id = so.so_id
              LEFT JOIN customers c ON so.customer_id = c.customer_id
              WHERE pli.pick_item_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $pick_item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Format status badge
        $status_badge = '';
        if ($row['quantity_picked'] >= $row['quantity_to_pick']) {
            $status_badge = 'success';
        } elseif ($row['quantity_picked'] > 0) {
            $status_badge = 'info';
        } else {
            $status_badge = 'warning';
        }
        
        // Calculate values
        $total_value = $row['quantity_to_pick'] * $row['unit_price'];
        $completion = number_format($row['completion_percentage'] ?? 0, 1);
        ?>
        
        <div class="row">
            <div class="col-md-6">
                <h6>Item Information</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Item Code:</strong></td>
                        <td><?php echo $row['item_code']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Item Name:</strong></td>
                        <td><?php echo $row['item_name']; ?></td>
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
            
            <div class="col-md-6">
                <h6>Pick Details</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Pick List:</strong></td>
                        <td><span class="badge bg-light text-dark"><?php echo $row['pick_list_number']; ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td><span class="badge bg-<?php echo $status_badge; ?>">
                            <?php echo $row['quantity_picked'] . '/' . $row['quantity_to_pick']; ?> (<?php echo $completion; ?>%)
                        </span></td>
                    </tr>
                    <tr>
                        <td><strong>Location Bin:</strong></td>
                        <td><?php echo $row['location_bin'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Value:</strong></td>
                        <td>₱<?php echo number_format($total_value, 2); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-md-6">
                <h6>Related Information</h6>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Branch:</strong></td>
                        <td><?php echo $row['branch_name']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Sales Order:</strong></td>
                        <td><?php echo $row['so_number'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Customer:</strong></td>
                        <td><?php echo $row['customer_name'] ?? 'N/A'; ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="col-md-6">
                <h6>Progress</h6>
                <div class="progress mb-2" style="height: 20px;">
                    <div class="progress-bar bg-<?php echo $status_badge; ?>" 
                         role="progressbar" 
                         style="width: <?php echo $completion; ?>%"
                         aria-valuenow="<?php echo $completion; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100">
                        <?php echo $completion; ?>%
                    </div>
                </div>
                <small class="text-muted">
                    <?php echo $row['quantity_picked']; ?> of <?php echo $row['quantity_to_pick']; ?> picked
                </small>
            </div>
        </div>
        
        <?php
    } else {
        echo '<div class="alert alert-danger">Pick list item not found</div>';
    }
    
    $stmt->close();
} else {
    echo '<div class="alert alert-danger">No item ID specified</div>';
}
?>