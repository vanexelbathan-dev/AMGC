<?php
require_once '../config/database.php';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $item_name = trim($_POST['item_name'] ?? '');
    $item_code = trim($_POST['item_code'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $stock = intval($_POST['stock'] ?? 0);
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $reorder_level = intval($_POST['reorder_level'] ?? 50);
    $description = trim($_POST['description'] ?? '');
    $unit_type = $_POST['unit_type'] ?? 'piece';
    $status = 'active'; // Default status for new items

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

    // Check if item code already exists
    $check_query = "SELECT COUNT(*) as count FROM items WHERE item_code = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['count'] > 0) {
        echo "<script>
            alert('Item code \"$item_code\" already exists! Please use a different item code.');
            window.history.back();
        </script>";
        exit();
    }

    // Prepare SQL statement for items table
    $sql = "INSERT INTO items (
            item_code, 
            item_name, 
            description, 
            category, 
            stock, 
            unit_type, 
            unit_price, 
            reorder_level, 
            status,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

    $stmt = $conn->prepare($sql);
    
    // Convert empty description to null
    $description = empty($description) ? null : $description;
    
    // Bind parameters
    $stmt->bind_param(
        "ssssisdis", 
        $item_code, 
        $item_name, 
        $description, 
        $category, 
        $stock, 
        $unit_type, 
        $unit_price, 
        $reorder_level, 
        $status
    );

    // Execute the statement
    if ($stmt->execute()) {
        // Get the last inserted ID
        $item_id = $stmt->insert_id;
        
        echo "<script>
            alert('Item \"$item_name\" added successfully!');
            window.location.href = 'currentinventory.php';
        </script>";
    } else {
        $error_message = addslashes($conn->error);
        echo "<script>
            alert('Error adding item: $error_message');
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