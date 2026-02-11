<?php
require_once '../config/database.php';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $item_id = intval($_POST['item_id'] ?? 0);
    $item_name = trim($_POST['item_name'] ?? '');
    $item_code = trim($_POST['item_code'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $stock = intval($_POST['stock'] ?? 0);
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $reorder_level = intval($_POST['reorder_level'] ?? 50);
    $description = trim($_POST['description'] ?? '');
    $unit_type = $_POST['unit_type'] ?? 'piece';
    $status = $_POST['status'] ?? 'active';

    // Validate required fields
    $errors = [];
    
    if (empty($item_name)) {
        $errors[] = 'Item Name is required!';
    }
    
    if (empty($item_code)) {
        $errors[] = 'Item Code is required!';
    }
    
    if (empty($category)) {
        $errors[] = 'Category is required!';
    }
    
    if ($stock < 0) {
        $errors[] = 'Stock must be a non-negative number!';
    }
    
    if ($unit_price < 0) {
        $errors[] = 'Unit Price must be a non-negative number!';
    }
    
    if ($reorder_level < 0) {
        $errors[] = 'Reorder Level must be a non-negative number!';
    }
    
    // If there are validation errors
    if (!empty($errors)) {
        echo "<script>
            alert('" . implode('\\n', $errors) . "');
            window.history.back();
        </script>";
        exit();
    }

    // Prepare SQL statement for updating items table
    $sql = "UPDATE items SET 
            item_name = ?, 
            description = ?, 
            category = ?, 
            stock = ?, 
            unit_type = ?, 
            unit_price = ?, 
            reorder_level = ?, 
            status = ?,
            updated_at = NOW()
            WHERE item_id = ? AND item_code = ?";

    $stmt = $conn->prepare($sql);
    
    // Convert empty description to null
    $description = empty($description) ? null : $description;
    
    // Bind parameters
    $stmt->bind_param(
        "sssisdisiss", 
        $item_name, 
        $description, 
        $category, 
        $stock, 
        $unit_type, 
        $unit_price, 
        $reorder_level, 
        $status,
        $item_id,
        $item_code
    );

    // Execute the statement
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "<script>
                alert('Item \"$item_name\" updated successfully!');
                window.location.href = 'currentinventory.php';
            </script>";
        } else {
            echo "<script>
                alert('No changes made or item not found!');
                window.history.back();
            </script>";
        }
    } else {
        $error_message = addslashes($conn->error);
        echo "<script>
            alert('Error updating item: $error_message');
            window.history.back();
        </script>";
    }

    $stmt->close();
} else {
    // If not POST request, redirect back
    echo "<script>
        alert('Invalid request method!');
        window.history.back();
    </script>";
}

$conn->close();
?>