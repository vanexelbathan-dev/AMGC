<?php
// test_image.php
echo "<h2>Image Path Debugger</h2>";

$photo = '1771462453_69965f3584b78.jpg';
$possible_paths = [
    'uploads/deliveries/2026-02/' . $photo,
    'uploads/deliveries/' . $photo,
    '../uploads/deliveries/2026-02/' . $photo,
    '../uploads/deliveries/' . $photo,
    '/AMGC/uploads/deliveries/2026-02/' . $photo,
    '/AMGC/uploads/deliveries/' . $photo
];

echo "<h3>Checking possible paths:</h3>";
echo "<ul>";

foreach ($possible_paths as $path) {
    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/AMGC/' . str_replace(['../', '/AMGC/'], '', $path);
    $full_path = str_replace('//', '/', $full_path);
    
    echo "<li>";
    echo "<strong>Path:</strong> " . $path . "<br>";
    echo "<strong>Full server path:</strong> " . $full_path . "<br>";
    
    if (file_exists($full_path)) {
        echo "<span style='color:green; font-weight:bold'>✓ FILE EXISTS!</span><br>";
        echo "<strong>URL:</strong> https://amgc.byethost15.com/AMGC/" . str_replace(['../', '/AMGC/'], '', $path) . "<br>";
    } else {
        echo "<span style='color:red;'>✗ File not found</span>";
    }
    echo "</li><br>";
}
echo "</ul>";

// Also check the deliveries table
require_once 'config/database.php';
require_once 'config/session_handler.php';

$query = "SELECT delivery_id, remarks FROM deliveries WHERE remarks LIKE '%1771462453_69965f3584b78%'";
$result = $conn->query($query);

echo "<h3>Checking database:</h3>";
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "Delivery ID: " . $row['delivery_id'] . "<br>";
        echo "Remarks: " . htmlspecialchars($row['remarks']) . "<br>";
        
        // Extract photo path
        if (preg_match('/Proof Photo: ([^\n]+)/', $row['remarks'], $matches)) {
            $photo_path = trim($matches[1]);
            echo "Photo path in database: " . $photo_path . "<br>";
        }
    }
} else {
    echo "No matching delivery found";
}

// List all files in uploads directory
echo "<h3>Files in uploads directory:</h3>";
$upload_dirs = [
    $_SERVER['DOCUMENT_ROOT'] . '/AMGC/uploads/deliveries/',
    $_SERVER['DOCUMENT_ROOT'] . '/AMGC/uploads/deliveries/2026-02/'
];

foreach ($upload_dirs as $dir) {
    echo "<strong>Checking: " . $dir . "</strong><br>";
    if (is_dir($dir)) {
        $files = scandir($dir);
        $found = false;
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                echo " - " . $file . "<br>";
                $found = true;
            }
        }
        if (!$found) {
            echo " - No files found<br>";
        }
    } else {
        echo " - Directory does not exist<br>";
    }
    echo "<br>";
}
?>