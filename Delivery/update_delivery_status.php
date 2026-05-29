<?php
// update_delivery_status.php
require_once '../config/database.php';
require_once '../config/session_handler.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
        $status = isset($_POST['status']) ? $_POST['status'] : '';
        $branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : 0;
        $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : '';
        
        if ($delivery_id <= 0 || empty($status)) {
            throw new Exception('Invalid parameters');
        }
        
        // Get the delivery details to find related sales order
        $delivery_query = "SELECT so_id, trip_id FROM deliveries WHERE delivery_id = ?";
        $delivery_stmt = $conn->prepare($delivery_query);
        $delivery_stmt->bind_param("i", $delivery_id);
        $delivery_stmt->execute();
        $delivery_result = $delivery_stmt->get_result();
        $delivery = $delivery_result->fetch_assoc();
        $delivery_stmt->close();
        
        if (!$delivery) {
            throw new Exception('Delivery not found');
        }
        
        $so_id = $delivery['so_id'];
        $trip_id = $delivery['trip_id'];
        
        // Update delivery status
        $update_query = "UPDATE deliveries SET delivery_status = ?, remarks = ?, updated_at = NOW() WHERE delivery_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ssi", $status, $remarks, $delivery_id);
        
        if ($update_stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Status updated successfully';
            
            // If status is in-transit (claimed from warehouse), update pick_list status
            if ($status === 'in-transit') {
                // Find the pick_list associated with this sales order
                $pick_list_query = "SELECT pick_list_id, pick_status FROM pick_lists WHERE so_id = ?";
                $pick_stmt = $conn->prepare($pick_list_query);
                $pick_stmt->bind_param("i", $so_id);
                $pick_stmt->execute();
                $pick_result = $pick_stmt->get_result();
                $pick_list = $pick_result->fetch_assoc();
                $pick_stmt->close();
                
                if ($pick_list) {
                    // Update pick_list status to 'in-transit' (claimed by driver)
                    $update_pick = "UPDATE pick_lists SET pick_status = 'in-transit', updated_at = NOW() WHERE pick_list_id = ?";
                    $update_pick_stmt = $conn->prepare($update_pick);
                    $update_pick_stmt->bind_param("i", $pick_list['pick_list_id']);
                    
                    if ($update_pick_stmt->execute()) {
                        $response['pick_list_updated'] = true;
                        
                        // Also update the sales order status to 'in-transit'
                        $update_so = "UPDATE sales_orders SET order_status = 'in-transit', updated_at = NOW() WHERE so_id = ?";
                        $update_so_stmt = $conn->prepare($update_so);
                        $update_so_stmt->bind_param("i", $so_id);
                        $update_so_stmt->execute();
                        $update_so_stmt->close();
                        
                        $response['message'] = 'Items claimed from warehouse. Pick list status updated to in-transit.';
                    } else {
                        $response['pick_list_warning'] = 'Delivery status updated but pick list status could not be updated';
                    }
                    $update_pick_stmt->close();
                } else {
                    $response['pick_list_warning'] = 'No pick list found for this order';
                }
            }
            
            // If status is delivered (completed), update pick_list and sales_order status
            if ($status === 'delivered') {
                // Update pick_list status to 'delivered'
                $pick_list_query = "SELECT pick_list_id FROM pick_lists WHERE so_id = ?";
                $pick_stmt = $conn->prepare($pick_list_query);
                $pick_stmt->bind_param("i", $so_id);
                $pick_stmt->execute();
                $pick_result = $pick_stmt->get_result();
                $pick_list = $pick_result->fetch_assoc();
                $pick_stmt->close();
                
                if ($pick_list) {
                    $update_pick = "UPDATE pick_lists SET pick_status = 'delivered', updated_at = NOW() WHERE pick_list_id = ?";
                    $update_pick_stmt = $conn->prepare($update_pick);
                    $update_pick_stmt->bind_param("i", $pick_list['pick_list_id']);
                    $update_pick_stmt->execute();
                    $update_pick_stmt->close();
                }
                
                // Update sales order status to 'delivered'
                $update_so = "UPDATE sales_orders SET order_status = 'delivered', updated_at = NOW() WHERE so_id = ?";
                $update_so_stmt = $conn->prepare($update_so);
                $update_so_stmt->bind_param("i", $so_id);
                $update_so_stmt->execute();
                $update_so_stmt->close();
            }
            
            // If status is partial, update pick_list status to 'partial'
            if ($status === 'partial') {
                $pick_list_query = "SELECT pick_list_id FROM pick_lists WHERE so_id = ?";
                $pick_stmt = $conn->prepare($pick_list_query);
                $pick_stmt->bind_param("i", $so_id);
                $pick_stmt->execute();
                $pick_result = $pick_stmt->get_result();
                $pick_list = $pick_result->fetch_assoc();
                $pick_stmt->close();
                
                if ($pick_list) {
                    $update_pick = "UPDATE pick_lists SET pick_status = 'partial', updated_at = NOW() WHERE pick_list_id = ?";
                    $update_pick_stmt = $conn->prepare($update_pick);
                    $update_pick_stmt->bind_param("i", $pick_list['pick_list_id']);
                    $update_pick_stmt->execute();
                    $update_pick_stmt->close();
                }
            }
            
        } else {
            throw new Exception('Failed to update status');
        }
        
        $update_stmt->close();
    }
    
} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
?>