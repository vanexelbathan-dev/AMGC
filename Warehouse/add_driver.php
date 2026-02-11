<?php
require_once '../config/database.php';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $driver_name = $_POST['driver_name'] ?? '';
    $license_number = $_POST['license_number'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $license_expiry = $_POST['license_expiry'] ?? null;
    $vehicle_type = $_POST['vehicle_type'] ?? null;
    $vehicle_plate_number = $_POST['vehicle_plate_number'] ?? null;
    $branch_id = $_POST['branch_id'] ?? null;
    $status = $_POST['status'] ?? 'active';

    // Validate required fields
    if (empty($driver_name) || empty($license_number)) {
        echo "<script>
            alert('Driver Name and License Number are required!');
            window.history.back();
        </script>";
        exit();
    }

    // Check if license number already exists
    $check_query = "SELECT COUNT(*) as count FROM drivers WHERE license_number = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("s", $license_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        echo "<script>
            alert('License number already exists! Please use a different license number.');
            window.history.back();
        </script>";
        exit();
    }

    // Prepare SQL statement
    $sql = "INSERT INTO drivers (
            driver_name, 
            license_number, 
            contact_number, 
            license_expiry, 
            vehicle_type, 
            vehicle_plate_number, 
            branch_id, 
            status,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

    $stmt = $conn->prepare($sql);
    
    // Convert empty string to null for nullable fields
    $license_expiry = empty($license_expiry) ? null : $license_expiry;
    $vehicle_type = empty($vehicle_type) ? null : $vehicle_type;
    $vehicle_plate_number = empty($vehicle_plate_number) ? null : $vehicle_plate_number;
    $branch_id = empty($branch_id) ? null : $branch_id;
    $contact_number = empty($contact_number) ? null : $contact_number;

    // Bind parameters
    $stmt->bind_param(
        "ssssssis", 
        $driver_name, 
        $license_number, 
        $contact_number, 
        $license_expiry, 
        $vehicle_type, 
        $vehicle_plate_number, 
        $branch_id, 
        $status
    );

    // Execute the statement
    if ($stmt->execute()) {
        // Get the last inserted ID
        $driver_id = $stmt->insert_id;
        
        // Check if user_id needs to be created (optional)
        // For now, we'll just redirect to success page
        
        echo "<script>
            alert('Driver added successfully!');
            window.location.href = 'drivers.php';
        </script>";
    } else {
        echo "<script>
            alert('Error adding driver: " . $conn->error . "');
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