<?php
// test_ajax.php - Direct test ng AJAX call

require_once 'config/database.php';

// Kunin ang regions
$query = "SELECT region_code, region_name FROM regions ORDER BY region_name";
$result = $conn->query($query);
$regions = $result->fetch_all(MYSQLI_ASSOC);

echo "<h2>Direct Database Query Result:</h2>";
echo "<pre>";
print_r($regions);
echo "</pre>";
echo "<p>Total regions found: " . count($regions) . "</p>";

// Test AJAX call sa supplier.php
echo "<h2>Test AJAX Call to supplier.php</h2>";
echo "<button onclick='testAJAX()'>Test AJAX</button>";
echo "<div id='result'></div>";

echo "
<script>
function testAJAX() {
    const resultDiv = document.getElementById('result');
    resultDiv.innerHTML = 'Loading...';
    
    const formData = new FormData();
    formData.append('action', 'get_regions');
    
    fetch('BranchAdmin/supplier.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        resultDiv.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
    })
    .catch(error => {
        resultDiv.innerHTML = 'Error: ' + error;
    });
}
</script>
";