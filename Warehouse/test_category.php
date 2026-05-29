<?php
// Save as test_category.php in the same folder
require_once '../config/database.php';
require_once '../config/session_handler.php';

$user_id = $_SESSION['user_id'] ?? 0;
echo "User ID: $user_id<br>";

$cat_query = "SELECT category FROM users WHERE user_id = ?";
$cat_stmt = $conn->prepare($cat_query);
$cat_stmt->bind_param("i", $user_id);
$cat_stmt->execute();
$cat_result = $cat_stmt->get_result();
$user_category = $cat_result->fetch_assoc()['category'] ?? 'NONE';
echo "User Category from DB: '$user_category'<br>";

$items_query = "SELECT category, COUNT(*) as count FROM items GROUP BY category";
$result = $conn->query($items_query);
echo "<br>Available categories in items table:<br>";
while($row = $result->fetch_assoc()) {
    echo "- " . $row['category'] . ": " . $row['count'] . " items<br>";
}

echo "<br>Query that will be used:<br>";
echo "WHERE category = '" . $user_category . "'";
?>