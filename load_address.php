<?php
// load_address.php - UPDATED VERSION with correct field mapping

require_once 'config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Load Address Data</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        body { padding: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Philippine Address Data Import</h1>";

// Check current data
echo "<h2>Current Database Status</h2>";
$tables = ['regions', 'provinces', 'cities', 'barangays'];

foreach ($tables as $table) {
    $result = $conn->query("SELECT COUNT(*) as count FROM $table");
    if ($result) {
        $count = $result->fetch_assoc()['count'];
        echo "<div class='alert alert-info'>Table $table: $count records</div>";
    }
}

// Clear tables
echo "<h2>Clearing old data...</h2>";
$conn->query("TRUNCATE TABLE regions");
$conn->query("TRUNCATE TABLE provinces");
$conn->query("TRUNCATE TABLE cities");
$conn->query("TRUNCATE TABLE barangays");
echo "<div class='alert alert-warning'>All tables cleared.</div>";

// Function to load JSON with correct mapping
function loadJSON($conn, $filename, $table, $mapping) {
    $file_path = __DIR__ . '/BranchAdmin/' . $filename;
    
    if (!file_exists($file_path)) {
        die("File not found: $filename");
    }
    
    $json_data = file_get_contents($file_path);
    $data = json_decode($json_data, true);
    
    if (!$data) {
        die("Invalid JSON: $filename");
    }
    
    echo "<p>Processing $filename... Found " . count($data) . " records.</p>";
    
    $columns = implode(', ', array_keys($mapping));
    $placeholders = implode(', ', array_fill(0, count($mapping), '?'));
    $types = str_repeat('s', count($mapping));
    
    $query = "INSERT INTO $table ($columns) VALUES ($placeholders)";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        die("Error preparing statement: " . $conn->error);
    }
    
    $count = 0;
    foreach ($data as $item) {
        $params = [];
        foreach ($mapping as $db_field => $json_field) {
            $value = $item[$json_field] ?? '';
            $params[] = $value;
        }
        
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            $count++;
        }
    }
    
    $stmt->close();
    echo "<div class='alert alert-success'>✓ Imported $count records into $table</div>";
    return $count;
}

// Import Regions - Correct field names
echo "<h2>Importing Regions...</h2>";
loadJSON($conn, 'regions.json', 'regions', [
    'psgc_code' => 'psgc_code',
    'region_code' => 'region_code',
    'region_name' => 'region_name'
]);

// Import Provinces
echo "<h2>Importing Provinces...</h2>";
loadJSON($conn, 'provinces.json', 'provinces', [
    'psgc_code' => 'psgc_code',
    'province_code' => 'province_code',
    'province_name' => 'province_name',
    'region_code' => 'region_code'
]);

// Import Cities
echo "<h2>Importing Cities...</h2>";
loadJSON($conn, 'cities.json', 'cities', [
    'psgc_code' => 'psgc_code',
    'city_code' => 'city_code',
    'city_name' => 'city_name',
    'province_code' => 'province_code'
]);

// Import Barangays
echo "<h2>Importing Barangays...</h2>";
loadJSON($conn, 'barangays.json', 'barangays', [
    'psgc_code' => 'psgc_code',
    'barangay_code' => 'brgy_code',
    'barangay_name' => 'brgy_name',
    'city_code' => 'city_code'
]);

echo "
        <hr>
        <p>
            <a href='BranchAdmin/supplier.php' class='btn btn-success'>Go to Supplier Page</a>
            <a href='load_address.php' class='btn btn-secondary'>Refresh</a>
        </p>
    </div>
</body>
</html>";
?>