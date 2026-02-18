<?php
// update_delivery_status.php
session_start();
require_once "../config/database.php";

header("Content-Type: application/json");

if (!isset($_SESSION["user_id"]) || !isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    echo json_encode(["success" => false, "message" => "Not authenticated"]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
    exit();
}

$delivery_id = isset($_POST["delivery_id"]) ? intval($_POST["delivery_id"]) : 0;
$status = isset($_POST["status"]) ? $_POST["status"] : "";
$branch_id = isset($_POST["branch_id"]) ? intval($_POST["branch_id"]) : 0;
$remarks = isset($_POST["remarks"]) ? trim($_POST["remarks"]) : "";

if (!$delivery_id || !$status) {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit();
}

// Validate status
$allowed_status = ['in-transit', 'partial'];
if (!in_array($status, $allowed_status)) {
    echo json_encode(["success" => false, "message" => "Invalid status"]);
    exit();
}

// For partial status, remarks are required
if ($status === 'partial' && empty($remarks)) {
    echo json_encode(["success" => false, "message" => "Remarks are required for partial delivery"]);
    exit();
}

try {
    if ($status === 'partial') {
        // For partial delivery, update status and add remarks with timestamp
        $formatted_remarks = "\n[" . date('Y-m-d H:i:s') . "] PARTIAL DELIVERY: " . $remarks;
        
        $query = "UPDATE deliveries SET 
                  delivery_status = ?, 
                  remarks = CONCAT(IFNULL(remarks, ''), ?), 
                  updated_at = NOW() 
                  WHERE delivery_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssi", $status, $formatted_remarks, $delivery_id);
    } else {
        // For in-transit, just update status
        $query = "UPDATE deliveries SET delivery_status = ?, updated_at = NOW() WHERE delivery_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("si", $status, $delivery_id);
    }
    
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        echo json_encode(["success" => true, "message" => "Status updated successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "No changes made or delivery not found"]);
    }
    
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}

$conn->close();
?>