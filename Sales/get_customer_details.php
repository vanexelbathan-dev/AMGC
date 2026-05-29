<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

header('Content-Type: application/json');

// Protect page
requireLogin();
requireRole(['sales']);

try {
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'No customer ID provided'
        ]);
        exit;
    }

    $customer_id = (int)$_GET['id'];
    $branch_id = getUserBranchId();

    if ($customer_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid customer ID'
        ]);
        exit;
    }

    /*
     * Walk-in customer filter
     * This is column-safe. It will only use columns that exist in your customers table.
     * It avoids using customer_id NOT LIKE 'WALKIN-%' because customer_id is usually an integer.
     */
    $walkin_conditions = [];

    $check_customer_type = $conn->query("SHOW COLUMNS FROM customers LIKE 'customer_type'");
    if ($check_customer_type && $check_customer_type->num_rows > 0) {
        $walkin_conditions[] = "(c.customer_type IS NULL OR LOWER(c.customer_type) NOT IN ('walk_in', 'walk-in', 'walk in'))";
    }

    $check_is_walkin = $conn->query("SHOW COLUMNS FROM customers LIKE 'is_walk_in'");
    if ($check_is_walkin && $check_is_walkin->num_rows > 0) {
        $walkin_conditions[] = "(c.is_walk_in IS NULL OR c.is_walk_in = 0)";
    }

    $check_customer_code = $conn->query("SHOW COLUMNS FROM customers LIKE 'customer_code'");
    if ($check_customer_code && $check_customer_code->num_rows > 0) {
        $walkin_conditions[] = "(c.customer_code IS NULL OR c.customer_code NOT LIKE 'WALKIN-%')";
    }

    // Fallback filter based on customer name, since most systems save walk-in customers using this name.
    $walkin_conditions[] = "(c.customer_name IS NULL OR LOWER(c.customer_name) NOT IN ('walk-in customer', 'walk in customer', 'walkin customer'))";

    $walkin_filter_sql = '';
    if (!empty($walkin_conditions)) {
        $walkin_filter_sql = ' AND ' . implode(' AND ', $walkin_conditions);
    }

    // Get customer details with creator information
    $sql = "SELECT 
                c.*, 
                CONCAT(u.first_name, ' ', u.last_name) AS created_by_user
            FROM customers c
            LEFT JOIN users u ON c.created_by = u.user_id
            WHERE c.customer_id = ?
              AND c.branch_id = ?
              $walkin_filter_sql
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('ii', $customer_id, $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found or walk-in customer is not allowed for sales agent'
        ]);
        exit;
    }

    $customer = $result->fetch_assoc();

    // Get customer's orders
    $orders_sql = "SELECT 
                       so_id, 
                       so_number, 
                       order_date, 
                       total_amount, 
                       order_status 
                   FROM sales_orders 
                   WHERE customer_id = ?
                   ORDER BY order_date DESC 
                   LIMIT 20";

    $orders_stmt = $conn->prepare($orders_sql);

    if (!$orders_stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $orders_stmt->bind_param('i', $customer_id);
    $orders_stmt->execute();
    $orders_result = $orders_stmt->get_result();

    $orders = [];
    while ($order = $orders_result->fetch_assoc()) {
        $orders[] = $order;
    }

    $customer['orders'] = $orders;

    echo json_encode([
        'success' => true,
        'customer' => $customer
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>
