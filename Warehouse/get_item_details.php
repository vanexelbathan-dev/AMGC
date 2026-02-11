<?php
require_once '../config/database.php';

$action = $_GET['action'] ?? '';
$item_id = $_GET['item_id'] ?? 0;
$item_code = $_GET['item_code'] ?? '';

if ($action === 'view' && $item_id) {
    // Get item details for viewing
    $query = "SELECT * FROM items WHERE item_id = ? AND status = 'active'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        
        // Determine status badge
        $status_badge = 'bg-success';
        $status_text = 'In Stock';
        
        if ($item['stock'] <= 0) {
            $status_badge = 'bg-danger';
            $status_text = 'Out of Stock';
        } elseif ($item['stock'] <= $item['reorder_level']) {
            $status_badge = 'bg-warning';
            $status_text = 'Low Stock';
        }
        
        // UPDATED: Removed financial information (unit_price and related calculations)
        echo '
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Item Code</label>
                <p class="form-control-static">' . htmlspecialchars($item['item_code']) . '</p>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Status</label>
                <p><span class="badge ' . $status_badge . '">' . $status_text . '</span></p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Item Name</label>
                <p class="form-control-static">' . htmlspecialchars($item['item_name']) . '</p>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Category</label>
                <p class="form-control-static">' . htmlspecialchars($item['category'] ?? 'N/A') . '</p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Current Stock</label>
                <p class="form-control-static">' . number_format($item['stock']) . ' ' . htmlspecialchars($item['unit_type'] ?? 'units') . '</p>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Reorder Level</label>
                <p class="form-control-static">' . number_format($item['reorder_level']) . ' ' . htmlspecialchars($item['unit_type'] ?? 'units') . '</p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Unit Type</label>
                <p class="form-control-static">' . htmlspecialchars($item['unit_type'] ?? 'N/A') . '</p>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Created Date</label>
                <p class="form-control-static">' . date('F d, Y', strtotime($item['created_at'])) . '</p>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label fw-bold">Description</label>
            <div class="border rounded p-3 bg-light">' . nl2br(htmlspecialchars($item['description'] ?? 'No description available')) . '</div>
        </div>';
        
    } else {
        echo '<div class="alert alert-danger">Item not found</div>';
    }
    
} elseif ($action === 'edit' && $item_code) {
    // Get item details for editing
    $query = "SELECT * FROM items WHERE item_code = ? AND status = 'active'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        
        // UPDATED: Removed unit_price field from edit form
        echo '
        <form id="editInventoryForm" action="update_inventory.php" method="POST">
            <input type="hidden" name="item_id" value="' . $item['item_id'] . '">
            <input type="hidden" name="item_code" value="' . htmlspecialchars($item['item_code']) . '">
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Item Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="item_name" value="' . htmlspecialchars($item['item_name']) . '" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Item Code <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" value="' . htmlspecialchars($item['item_code']) . '" readonly disabled>
                    <small class="text-muted">Item code cannot be changed</small>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Category <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="category" value="' . htmlspecialchars($item['category'] ?? '') . '" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Current Stock <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="stock" value="' . $item['stock'] . '" required min="0">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Reorder Level <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="reorder_level" value="' . $item['reorder_level'] . '" required min="0">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Unit Type <span class="text-danger">*</span></label>
                    <select class="form-select" name="unit_type" required>
                        <option value="piece"' . ($item['unit_type'] == 'piece' ? ' selected' : '') . '>Piece</option>
                        <option value="case"' . ($item['unit_type'] == 'case' ? ' selected' : '') . '>Case</option>
                        <option value="inner-pack"' . ($item['unit_type'] == 'inner-pack' ? ' selected' : '') . '>Inner Pack</option>
                        <option value="box"' . ($item['unit_type'] == 'box' ? ' selected' : '') . '>Box</option>
                        <option value="carton"' . ($item['unit_type'] == 'carton' ? ' selected' : '') . '>Carton</option>
                    </select>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="3">' . htmlspecialchars($item['description'] ?? '') . '</textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Item</button>
            </div>
        </form>';
        
    } else {
        echo '<div class="alert alert-danger">Item not found</div>';
    }
} else {
    echo '<div class="alert alert-danger">Invalid request</div>';
}
?>