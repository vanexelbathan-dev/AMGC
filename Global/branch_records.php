<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get user info for display
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] ?? 'Quality Control';
$user_role = $_SESSION['role'] ?? 'global';
$view_all_branches = $_SESSION['view_all_branches'] ?? true;

// Get user's branch name for display (if applicable)
$branch_name = 'All Branches';
$user_branch_id = $_SESSION['branch_id'] ?? 0;
if (!$view_all_branches && $user_branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $user_branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
    $branch_stmt->close();
}

// Helper function to get branch name
function getBranchName($conn, $branch_id) {
    if (!$branch_id) return 'N/A';
    $sql = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['branch_name'] ?? 'Unknown Branch';
}

// Helper function to get user name
function getUserName($conn, $user_id) {
    if (!$user_id) return 'System';
    $sql = "SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['name'] ?? 'Unknown User';
}

// Get filter parameters
$branch_id = isset($_GET['branch']) ? $_GET['branch'] : '';
$record_type = isset($_GET['type']) ? $_GET['type'] : '';
$date_from = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['dateTo']) ? $_GET['dateTo'] : date('Y-m-d');

// Handle AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $records = [];

    // Load Sales Orders (main view)
    if (empty($record_type) || $record_type == 'sales_order') {
        $sql = "SELECT so.*, b.branch_name, c.customer_name
                FROM sales_orders so
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                WHERE DATE(so.order_date) BETWEEN ? AND ?";
        $params = [$date_from, $date_to];
        $types = "ss";
        
        // Add branch filter based on user permissions
        if (!$view_all_branches && $user_branch_id > 0) {
            $sql .= " AND so.branch_id = ?";
            $params[] = $user_branch_id;
            $types .= "i";
        } elseif (!empty($branch_id)) {
            $sql .= " AND so.branch_id = ?";
            $params[] = $branch_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY so.order_date DESC LIMIT 500";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $branch_name = $row['branch_name'] ?? getBranchName($conn, $row['branch_id']);
            
            $records[] = [
                'id' => $row['so_id'],
                'source' => 'sales_orders',
                'record_number' => $row['so_number'],
                'branch' => $branch_name,
                'branch_id' => $row['branch_id'],
                'type' => 'Sales Order',
                'description' => 'Sales Order #' . $row['so_number'] . ' - ' . ($row['customer_name'] ?? 'Unknown Customer'),
                'amount' => $row['total_amount'],
                'date' => $row['order_date'],
                'status' => $row['order_status']
            ];
        }
    }

    // Load Purchase Orders
    if (empty($record_type) || $record_type == 'purchase_order') {
        $sql = "SELECT po.*, b.branch_name 
                FROM purchase_orders po
                LEFT JOIN branches b ON po.branch_id = b.branch_id
                WHERE DATE(po.order_date) BETWEEN ? AND ?";
        $params = [$date_from, $date_to];
        $types = "ss";
        
        // Add branch filter based on user permissions
        if (!$view_all_branches && $user_branch_id > 0) {
            $sql .= " AND po.branch_id = ?";
            $params[] = $user_branch_id;
            $types .= "i";
        } elseif (!empty($branch_id)) {
            $sql .= " AND po.branch_id = ?";
            $params[] = $branch_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY po.order_date DESC LIMIT 500";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $branch_name = $row['branch_name'] ?? getBranchName($conn, $row['branch_id']);
            
            $records[] = [
                'id' => $row['po_id'],
                'source' => 'purchase_orders',
                'record_number' => $row['po_number'],
                'branch' => $branch_name,
                'branch_id' => $row['branch_id'],
                'type' => 'Purchase Order',
                'description' => 'Purchase Order #' . $row['po_number'] . ($row['supplier_name'] ? ' - ' . $row['supplier_name'] : ''),
                'amount' => $row['total_amount'],
                'date' => $row['order_date'],
                'status' => $row['po_status']
            ];
        }
    }

    // Sort records by date (newest first)
    usort($records, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    echo json_encode([
        'success' => true,
        'records' => $records
    ]);
    exit;
}

// Handle AJAX request for record details with full transaction history
if (isset($_GET['ajax_details']) && isset($_GET['id']) && isset($_GET['source'])) {
    header('Content-Type: application/json');
    
    $id = intval($_GET['id']);
    $source = $_GET['source'];
    $record = null;

    try {
        switch ($source) {
            case 'sales_orders':
                // Get main Sales Order details
                $sql = "SELECT so.*, b.branch_name, c.customer_name, c.address, c.phone_number,
                        CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                        FROM sales_orders so
                        LEFT JOIN branches b ON so.branch_id = b.branch_id
                        LEFT JOIN customers c ON so.customer_id = c.customer_id
                        LEFT JOIN users u ON so.created_by = u.user_id
                        WHERE so.so_id = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare sales order query: ' . $conn->error);
                }
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    // Get Sales Order Items (line_total is the actual column name)
                    $items_sql = "SELECT soi.*, i.item_name, i.item_code, i.category
                                 FROM sales_order_items soi
                                 LEFT JOIN items i ON soi.item_id = i.item_id
                                 WHERE soi.so_id = ?";
                    $items_stmt = $conn->prepare($items_sql);
                    if ($items_stmt) {
                        $items_stmt->bind_param("i", $id);
                        $items_stmt->execute();
                        $items_result = $items_stmt->get_result();
                    }
                    
                    $items = [];
                    if (isset($items_result)) {
                        while ($item = $items_result->fetch_assoc()) {
                            $items[] = [
                                'item_name' => $item['item_name'] ?? 'Unknown Item',
                                'item_code' => $item['item_code'] ?? 'N/A',
                                'category' => $item['category'] ?? 'N/A',
                                'quantity' => $item['quantity_ordered'],
                                'unit_price' => $item['unit_price'],
                                'total_price' => $item['line_total'] ?? ($item['quantity_ordered'] * $item['unit_price'])
                            ];
                        }
                    }
                    
                    // Get related Pick Lists
                    $pick_lists = [];
                    $pick_sql = "SELECT pl.*, d.driver_name,
                                CONCAT(u1.first_name, ' ', u1.last_name) as picked_by_name,
                                CONCAT(u2.first_name, ' ', u2.last_name) as verified_by_name
                                FROM pick_lists pl
                                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                                LEFT JOIN users u1 ON pl.picked_by = u1.user_id
                                LEFT JOIN users u2 ON pl.verified_by = u2.user_id
                                WHERE pl.so_id = ?
                                ORDER BY pl.created_at DESC";
                    $pick_stmt = $conn->prepare($pick_sql);
                    if ($pick_stmt) {
                        $pick_stmt->bind_param("i", $id);
                        $pick_stmt->execute();
                        $pick_result = $pick_stmt->get_result();
                        
                        while ($pick = $pick_result->fetch_assoc()) {
                            // Get Pick List Items (quantity_to_pick is the actual column)
                            $pick_items_sql = "SELECT pli.*, i.item_name, i.item_code
                                             FROM pick_list_items pli
                                             LEFT JOIN items i ON pli.item_id = i.item_id
                                             WHERE pli.pick_list_id = ?";
                            $pick_items_stmt = $conn->prepare($pick_items_sql);
                            
                            $pick_items = [];
                            if ($pick_items_stmt) {
                                $pick_items_stmt->bind_param("i", $pick['pick_list_id']);
                                $pick_items_stmt->execute();
                                $pick_items_result = $pick_items_stmt->get_result();
                                
                                while ($pitem = $pick_items_result->fetch_assoc()) {
                                    $pick_items[] = [
                                        'item_name' => $pitem['item_name'] ?? 'Unknown',
                                        'quantity_ordered' => $pitem['quantity_to_pick'],
                                        'quantity_picked' => $pitem['quantity_picked']
                                    ];
                                }
                            }
                            
                            $pick_lists[] = [
                                'pick_list_id' => $pick['pick_list_id'],
                                'pick_list_number' => $pick['pick_list_number'],
                                'status' => $pick['pick_status'],
                                'created_at' => $pick['created_at'],
                                'pick_date' => $pick['pick_date'],
                                'driver_name' => $pick['driver_name'] ?? 'Unassigned',
                                'picked_by' => $pick['picked_by_name'] ?? 'N/A',
                                'verified_by' => $pick['verified_by_name'] ?? 'N/A',
                                'items' => $pick_items
                            ];
                        }
                    }
                    
                    // Get related Invoices (no created_by column, status not invoice_status, no paid_at)
                    $invoices = [];
                    $inv_sql = "SELECT i.*
                               FROM invoices i
                               WHERE i.so_id = ?
                               ORDER BY i.created_at DESC";
                    $inv_stmt = $conn->prepare($inv_sql);
                    if ($inv_stmt) {
                        $inv_stmt->bind_param("i", $id);
                        $inv_stmt->execute();
                        $inv_result = $inv_stmt->get_result();
                        
                        while ($inv = $inv_result->fetch_assoc()) {
                            $invoices[] = [
                                'invoice_id' => $inv['invoice_id'],
                                'invoice_number' => $inv['invoice_number'],
                                'amount' => $inv['total_amount'],
                                'status' => $inv['status'],
                                'created_at' => $inv['created_at'],
                                'due_date' => $inv['due_date'] ?? null,
                                'paid_at' => null,
                                'created_by' => 'System'
                            ];
                        }
                    }
                    
                    // Get related Trip Tickets (no vehicles table, use driver plate; start_time/end_time not departure/arrival)
                    $trip_tickets = [];
                    $trip_sql = "SELECT tt.*, d.driver_name, d.vehicle_plate_number,
                               CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                               FROM trip_tickets tt
                               LEFT JOIN drivers d ON tt.driver_id = d.driver_id
                               LEFT JOIN users u ON tt.created_by = u.user_id
                               WHERE tt.so_id = ?
                               ORDER BY tt.created_at DESC";
                    $trip_stmt = $conn->prepare($trip_sql);
                    if ($trip_stmt) {
                        $trip_stmt->bind_param("i", $id);
                        $trip_stmt->execute();
                        $trip_result = $trip_stmt->get_result();
                        
                        while ($trip = $trip_result->fetch_assoc()) {
                            $trip_tickets[] = [
                                'trip_id' => $trip['trip_id'],
                                'trip_number' => $trip['trip_number'],
                                'driver_name' => $trip['driver_name'] ?? 'Unassigned',
                                'plate_number' => $trip['vehicle_plate_number'] ?? 'N/A',
                                'departure_time' => $trip['start_time'] ?? $trip['trip_date'],
                                'arrival_time' => $trip['end_time'],
                                'status' => $trip['trip_status'],
                                'route' => $trip['remarks'] ?? 'N/A'
                            ];
                        }
                    }
                    
                    // Get Delivery Status (no delivery_number, delivered_at=delivery_date, signed_by, remarks)
                    $delivery = null;
                    $del_sql = "SELECT d.*, dr.driver_name
                              FROM deliveries d
                              LEFT JOIN drivers dr ON d.driver_id = dr.driver_id
                              WHERE d.so_id = ?
                              ORDER BY d.created_at DESC LIMIT 1";
                    $del_stmt = $conn->prepare($del_sql);
                    if ($del_stmt) {
                        $del_stmt->bind_param("i", $id);
                        $del_stmt->execute();
                        $del_result = $del_stmt->get_result();
                        
                        if ($del_row = $del_result->fetch_assoc()) {
                            $delivery = [
                                'delivery_id' => $del_row['delivery_id'],
                                'delivery_number' => 'DEL-' . $del_row['delivery_id'],
                                'status' => $del_row['delivery_status'],
                                'delivered_at' => $del_row['delivery_date'],
                                'received_by' => $del_row['signed_by'] ?? $del_row['driver_name'] ?? 'N/A',
                                'recipient_signature' => null,
                                'delivery_notes' => $del_row['remarks'] ?? ''
                            ];
                        }
                    }
                    
                    // Build complete transaction history
                    $history = [];
                    
                    // Order Creation
                    $history[] = [
                        'timestamp' => $row['created_at'] ?? $row['order_date'],
                        'action' => 'Order Created',
                        'user' => $row['created_by_name'] ?? 'System',
                        'details' => 'Sales order created'
                    ];
                    
                    // Status change to confirmed/processing (use updated_at as approximation)
                    if (in_array($row['order_status'], ['confirmed', 'processing', 'ready', 'delivered'])) {
                        $history[] = [
                            'timestamp' => $row['updated_at'] ?? $row['order_date'],
                            'action' => 'Order Confirmed',
                            'user' => $row['created_by_name'] ?? 'System',
                            'details' => 'Sales order confirmed for processing'
                        ];
                    }
                    
                    // Pick List Creation
                    foreach ($pick_lists as $pick) {
                        $history[] = [
                            'timestamp' => $pick['created_at'],
                            'action' => 'Pick List Created',
                            'user' => $pick['picked_by'],
                            'details' => 'Pick List #' . $pick['pick_list_number'] . ' created'
                        ];
                        
                        if ($pick['pick_date'] && $pick['status'] === 'completed') {
                            $history[] = [
                                'timestamp' => $pick['pick_date'],
                                'action' => 'Items Picked',
                                'user' => $pick['picked_by'],
                                'details' => 'Items picked and verified'
                            ];
                        }
                    }
                    
                    // Invoice Creation
                    foreach ($invoices as $inv) {
                        $history[] = [
                            'timestamp' => $inv['created_at'],
                            'action' => 'Invoice Generated',
                            'user' => $inv['created_by'],
                            'details' => 'Invoice #' . $inv['invoice_number'] . ' generated for P' . number_format($inv['amount'], 2)
                        ];
                    }
                    
                    // Trip Assignment
                    foreach ($trip_tickets as $trip) {
                        $trip_time = $trip['departure_time'] ?? $trip['arrival_time'];
                        if ($trip_time) {
                            $history[] = [
                                'timestamp' => $trip_time,
                                'action' => 'Trip Assigned',
                                'user' => 'System',
                                'details' => 'Trip #' . $trip['trip_number'] . ' assigned to driver ' . $trip['driver_name']
                            ];
                        }
                        
                        if ($trip['arrival_time']) {
                            $history[] = [
                                'timestamp' => $trip['arrival_time'],
                                'action' => 'Trip Completed',
                                'user' => 'System',
                                'details' => 'Trip #' . $trip['trip_number'] . ' completed'
                            ];
                        }
                    }
                    
                    // Delivery
                    if ($delivery) {
                        if ($delivery['delivered_at']) {
                            $history[] = [
                                'timestamp' => $delivery['delivered_at'],
                                'action' => 'Order Delivered',
                                'user' => $delivery['received_by'],
                                'details' => 'Order delivered - signed by ' . $delivery['received_by']
                            ];
                        }
                        if ($delivery['status'] === 'rejected') {
                            $history[] = [
                                'timestamp' => $delivery['delivered_at'] ?? date('Y-m-d H:i:s'),
                                'action' => 'Delivery Rejected',
                                'user' => $delivery['received_by'],
                                'details' => 'Delivery was rejected'
                            ];
                        }
                    }
                    
                    // Sort history by timestamp
                    usort($history, function($a, $b) {
                        $timeA = strtotime($a['timestamp'] ?? '0');
                        $timeB = strtotime($b['timestamp'] ?? '0');
                        return $timeA - $timeB;
                    });
                    
                    $record = [
                        'type' => 'Sales Order',
                        'record_number' => $row['so_number'],
                        'branch' => $row['branch_name'] ?? 'N/A',
                        'customer' => [
                            'name' => $row['customer_name'] ?? 'N/A',
                            'address' => $row['address'] ?? 'N/A',
                            'contact' => $row['phone_number'] ?? 'N/A'
                        ],
                        'order_details' => [
                            'order_date' => $row['order_date'],
                            'total_amount' => $row['total_amount'],
                            'status' => $row['order_status'],
                            'payment_terms' => 'N/A',
                            'delivery_date' => $row['delivery_date'] ?? null,
                            'notes' => ''
                        ],
                        'items' => $items,
                        'pick_lists' => $pick_lists,
                        'invoices' => $invoices,
                        'trip_tickets' => $trip_tickets,
                        'delivery' => $delivery,
                        'history' => $history
                    ];
                }
                break;

            case 'purchase_orders':
                // Get main Purchase Order details
                $sql = "SELECT po.*, b.branch_name,
                        CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                        FROM purchase_orders po
                        LEFT JOIN branches b ON po.branch_id = b.branch_id
                        LEFT JOIN users u ON po.created_by = u.user_id
                        WHERE po.po_id = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare purchase order query: ' . $conn->error);
                }
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    // Get PO Items
                    $items_sql = "SELECT poi.*, i.item_name, i.item_code, i.category
                                 FROM purchase_order_items poi
                                 LEFT JOIN items i ON poi.item_id = i.item_id
                                 WHERE poi.po_id = ?";
                    $items_stmt = $conn->prepare($items_sql);
                    $items = [];
                    if ($items_stmt) {
                        $items_stmt->bind_param("i", $id);
                        $items_stmt->execute();
                        $items_result = $items_stmt->get_result();
                        while ($item = $items_result->fetch_assoc()) {
                            $items[] = [
                                'item_name' => $item['item_name'] ?? 'Unknown Item',
                                'item_code' => $item['item_code'] ?? 'N/A',
                                'category' => $item['category'] ?? 'N/A',
                                'quantity' => $item['quantity_ordered'],
                                'unit_price' => $item['unit_price'],
                                'total_price' => $item['line_total'] ?? ($item['quantity_ordered'] * $item['unit_price'])
                            ];
                        }
                    }

                    // Build transaction history
                    $history = [];
                    $history[] = [
                        'timestamp' => $row['created_at'] ?? $row['order_date'],
                        'action' => 'PO Created',
                        'user' => $row['created_by_name'] ?? 'System',
                        'details' => 'Purchase order created'
                    ];

                    if ($row['po_status'] !== 'draft') {
                        $history[] = [
                            'timestamp' => $row['updated_at'] ?? $row['order_date'],
                            'action' => 'PO Submitted',
                            'user' => $row['created_by_name'] ?? 'System',
                            'details' => 'Purchase order submitted'
                        ];
                    }

                    usort($history, function($a, $b) {
                        return strtotime($a['timestamp'] ?? '0') - strtotime($b['timestamp'] ?? '0');
                    });

                    $record = [
                        'type' => 'Purchase Order',
                        'record_number' => $row['po_number'],
                        'branch' => $row['branch_name'] ?? 'N/A',
                        'customer' => [
                            'name' => $row['supplier_name'] ?? 'N/A',
                            'address' => 'N/A',
                            'contact' => 'N/A'
                        ],
                        'order_details' => [
                            'order_date' => $row['order_date'],
                            'total_amount' => $row['total_amount'],
                            'status' => $row['po_status'],
                            'payment_terms' => 'N/A',
                            'delivery_date' => $row['expected_delivery'] ?? null,
                            'notes' => ''
                        ],
                        'items' => $items,
                        'pick_lists' => [],
                        'invoices' => [],
                        'trip_tickets' => [],
                        'delivery' => null,
                        'history' => $history
                    ];
                }
                break;
        }

        if ($record) {
            echo json_encode(['success' => true, 'record' => $record]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Record not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Get branches for filter dropdown
$branches_sql = "SELECT branch_id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = $conn->query($branches_sql);
$branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $branches[] = $row;
}

// Get statistics
$active_branches_sql = "SELECT COUNT(*) as count FROM branches WHERE status = 'active'";
$active_branches_result = $conn->query($active_branches_sql);
$active_branches = $active_branches_result->fetch_assoc()['count'] ?? 0;

// Add branch filter to statistics if user doesn't have view_all_branches permission
$sales_filter = "";
$purchase_filter = "";
if (!$view_all_branches && $user_branch_id > 0) {
    $sales_filter = " WHERE branch_id = $user_branch_id";
    $purchase_filter = " WHERE branch_id = $user_branch_id";
}

$total_records_sql = "SELECT 
                        (SELECT COUNT(*) FROM sales_orders $sales_filter) +
                        (SELECT COUNT(*) FROM purchase_orders $purchase_filter) +
                        (SELECT COUNT(*) FROM pick_lists $sales_filter) +
                        (SELECT COUNT(*) FROM rmr_requests) as total";
$total_records_result = $conn->query($total_records_sql);
$total_records = $total_records_result->fetch_assoc()['total'] ?? 0;

$total_transactions_sql = "SELECT 
                            (SELECT IFNULL(SUM(total_amount), 0) FROM sales_orders WHERE order_status != 'cancelled' $sales_filter) +
                            (SELECT IFNULL(SUM(total_amount), 0) FROM purchase_orders WHERE po_status != 'cancelled' $purchase_filter) as total";
$total_transactions_result = $conn->query($total_transactions_sql);
$total_transactions = $total_transactions_result->fetch_assoc()['total'] ?? 0;

// Get initials for avatar
$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $user_initials .= strtoupper(substr($part, 0, 1));
        }
    }
}
if (empty($user_initials)) {
    $user_initials = 'AD';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Branch Records</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/global.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        
        /* ===== Modal Shell ===== */
        #recordModal .modal-content { border:none; border-radius:10px; overflow:hidden; }
        #recordModal .modal-header { background:#1e293b; color:#fff; padding:14px 20px; border:none; }
        #recordModal .modal-header .modal-title { font-size:15px; font-weight:600; display:flex; align-items:center; gap:8px; }
        #recordModal .modal-header .btn-close { filter:invert(1); opacity:.65; }
        #recordModal .modal-header .btn-close:hover { opacity:1; }
        #recordModal .modal-body { padding:0; background:#f1f5f9; max-height:78vh; overflow-y:auto; }
        #recordModal .modal-footer { background:#fff; border-top:1px solid #e2e8f0; padding:10px 20px; }

        /* ===== Summary Bar ===== */
        .m-summary { background:#fff; padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; flex-wrap:wrap; gap:12px 28px; align-items:center; }
        .m-summary-item { display:flex; flex-direction:column; gap:1px; }
        .m-summary-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; }
        .m-summary-val { font-size:13px; font-weight:500; color:#1e293b; }
        .m-summary-val.lg { font-size:17px; font-weight:700; color:#0f172a; }

        /* ===== 2-Column Layout ===== */
        .m-layout { display:flex; gap:0; }
        .m-left { flex:1; min-width:0; padding:20px; border-right:1px solid #e2e8f0; }
        .m-right { width:320px; flex-shrink:0; padding:20px; background:#fff; }

        /* ===== Section heading ===== */
        .m-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin:0 0 10px; display:flex; align-items:center; gap:6px; }
        .m-section-title:not(:first-child) { margin-top:20px; }
        .m-section-title i { font-size:13px; }

        /* ===== Card ===== */
        .m-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:14px; overflow:hidden; }
        .m-card-head { padding:10px 14px; font-size:12px; font-weight:600; color:#334155; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:6px; }
        .m-card-head i { color:#64748b; font-size:14px; }
        .m-card-head .pill-right { margin-left:auto; }
        .m-card-body { padding:14px; }

        /* ===== Info Grid ===== */
        .m-info { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; }
        .m-info-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; margin-bottom:1px; }
        .m-info-val { font-size:13px; color:#1e293b; word-break:break-word; }

        /* ===== Status Pill ===== */
        .s-pill { display:inline-flex; align-items:center; padding:2px 9px; border-radius:20px; font-size:11px; font-weight:600; line-height:1.5; }
        .s-pill.green  { background:#dcfce7; color:#166534; }
        .s-pill.yellow { background:#fef9c3; color:#854d0e; }
        .s-pill.red    { background:#fee2e2; color:#991b1b; }
        .s-pill.blue   { background:#dbeafe; color:#1e40af; }
        .s-pill.gray   { background:#f1f5f9; color:#475569; }

        /* ===== Table ===== */
        .m-tbl { width:100%; border-collapse:collapse; font-size:12px; }
        .m-tbl thead th { padding:8px 10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left; white-space:nowrap; }
        .m-tbl tbody td { padding:8px 10px; color:#334155; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .m-tbl tbody tr:last-child td { border-bottom:none; }
        .m-tbl tbody tr:hover td { background:#f8fafc; }
        .m-tbl tfoot td, .m-tbl tfoot th { padding:8px 10px; font-weight:700; color:#0f172a; border-top:2px solid #e2e8f0; }

        /* ===== Timeline ===== */
        .tl { list-style:none; padding:0; margin:0; position:relative; }
        .tl::before { content:''; position:absolute; top:6px; bottom:6px; left:11px; width:2px; background:#e2e8f0; border-radius:1px; }
        .tl-item { position:relative; padding:0 0 18px 36px; }
        .tl-item:last-child { padding-bottom:0; }
        .tl-dot { position:absolute; left:4px; top:3px; width:16px; height:16px; border-radius:50%; border:2.5px solid #fff; }
        .tl-dot.blue   { background:#3b82f6; box-shadow:0 0 0 2px #bfdbfe; }
        .tl-dot.green  { background:#22c55e; box-shadow:0 0 0 2px #bbf7d0; }
        .tl-dot.yellow { background:#eab308; box-shadow:0 0 0 2px #fef08a; }
        .tl-dot.red    { background:#ef4444; box-shadow:0 0 0 2px #fecaca; }
        .tl-dot.cyan   { background:#06b6d4; box-shadow:0 0 0 2px #a5f3fc; }
        .tl-dot.gray   { background:#94a3b8; box-shadow:0 0 0 2px #e2e8f0; }
        .tl-action { font-size:12px; font-weight:600; color:#1e293b; line-height:1.3; }
        .tl-detail { font-size:11px; color:#64748b; margin-top:1px; }
        .tl-meta { display:flex; flex-wrap:wrap; gap:6px; margin-top:3px; font-size:10px; color:#94a3b8; }
        .tl-meta span { display:inline-flex; align-items:center; gap:3px; }
        .tl-meta i { font-size:10px; }

        /* ===== Empty State ===== */
        .m-empty { text-align:center; padding:28px 12px; color:#94a3b8; }
        .m-empty i { font-size:24px; margin-bottom:6px; display:block; }
        .m-empty p { margin:0; font-size:12px; }

        /* ===== Page-level badges ===== */
        .status-badge { padding:3px 8px; border-radius:12px; font-size:11px; font-weight:500; }
        .status-completed,.status-delivered,.status-approved,.status-received { background:#dcfce7; color:#166534; }
        .status-pending,.status-draft,.status-planned,.status-open,.status-processing { background:#fef9c3; color:#854d0e; }
        .status-cancelled,.status-rejected { background:#fee2e2; color:#991b1b; }

        /* ===== Mobile Profile Modal Styles ===== */
        .user-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto;
            border: 4px solid #d1fae5;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #profileModal .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }

        #profileModal .modal-header {
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }

        #profileModal .modal-header .modal-title {
            color: white;
            font-weight: 600;
        }

        #profileModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        #profileModal .modal-header .btn-close:hover {
            opacity: 1;
            transform: rotate(90deg);
        }

        #profileModal .modal-body {
            padding: 2rem;
            background: linear-gradient(135deg, #f9fefc 0%, #f0fdf4 100%);
        }

        #profileModal .branch-info {
            background: #d1fae5;
            color: #047857;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: 500;
        }

        #profileModal .btn-danger {
            background: linear-gradient(135deg, #dc3545, #f87171);
            border: none;
            padding: 1rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        #profileModal .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }

        /* Mobile Logout Button in Bottom Nav */
        .mobile-nav .nav-link.logout-btn {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn i {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active,
        .mobile-nav .nav-link.logout-btn:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active i,
        .mobile-nav .nav-link.logout-btn:hover i {
            color: #dc3545;
        }

        /* ===== Responsive ===== */
        @media(max-width:991px){
            .m-layout { flex-direction:column; }
            .m-left { border-right:none; border-bottom:1px solid #e2e8f0; }
            .m-right { width:100%; }
        }
        @media(max-width:768px){
            .m-summary-item { text-align:left; }
            .m-summary-item:last-child { text-align:right; }
            .m-summary-val.lg { font-size:15px; }
            .m-left, .m-right { padding:16px; }
            .m-info { grid-template-columns:1fr 1fr; }
            .m-tbl { font-size:11px; }
            .m-tbl thead th, .m-tbl tbody td { padding:6px 8px; }
            .m-card-body { padding:12px; }
            .m-section-title { font-size:10px; }
        }
        @media(max-width:480px){
            .m-info { grid-template-columns:1fr; }
        }
 /* ===== FILTER REPORTS & DROPDOWN - FIXED RESPONSIVE CSS ===== */

/* Form Card - Base */
.form-card {
    background: white;
    border-radius: clamp(14px, 3vw, 20px);
    padding: clamp(0.8rem, 3vw, 1.5rem);
    box-shadow: 0 8px 20px -5px rgba(4, 120, 87, 0.12);
    border: 1px solid rgba(68, 211, 78, 0.2);
    margin-bottom: clamp(1rem, 2vw, 1.5rem);
    transition: all 0.3s ease;
    width: 100%;
}

/* Card Header */
.form-card h5 {
    color: var(--dark-green);
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: clamp(1rem, 4vw, 1.3rem);
    margin-bottom: clamp(0.5rem, 2vw, 1rem);
    padding-bottom: clamp(0.3rem, 1.5vw, 0.5rem);
    border-bottom: 2px solid rgba(68, 211, 78, 0.2);
    width: 100%;
}

.form-card h5 i {
    color: var(--primary-green);
    background: rgba(68, 211, 78, 0.1);
    padding: clamp(0.3rem, 1.5vw, 0.5rem);
    border-radius: clamp(6px, 2vw, 10px);
    font-size: clamp(0.9rem, 3.5vw, 1.2rem);
}

/* Form Labels */
.form-label {
    font-weight: 600;
    color: var(--dark-color);
    margin-bottom: clamp(0.2rem, 1vw, 0.4rem);
    display: flex;
    align-items: center;
    gap: 0.3rem;
    font-size: clamp(0.75rem, 3vw, 0.9rem);
}

.form-label i {
    color: var(--primary-green);
    font-size: clamp(0.8rem, 3.5vw, 1rem);
}

/* FORM CONTROLS - UNIFIED (SELECT & INPUT) */
.form-select, 
.form-control {
    border: 2px solid #e5e7eb;
    border-radius: clamp(6px, 2vw, 10px);
    padding: clamp(0.35rem, 2vw, 0.7rem) clamp(0.7rem, 3vw, 1rem);
    font-size: clamp(0.75rem, 3.5vw, 0.95rem);
    height: auto;
    min-height: clamp(32px, 7vw, 42px);
    width: 100%;
    background-color: white;
    transition: all 0.2s ease;
    line-height: 1.4;
    box-sizing: border-box;
}

/* SELECT SPECIFIC - WITH CUSTOM ARROW */
.form-select {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23047857' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right clamp(0.5rem, 2vw, 0.75rem) center;
    background-size: clamp(10px, 2.5vw, 14px) clamp(8px, 2vw, 12px);
    padding-right: clamp(1.8rem, 6vw, 2.2rem);
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
}

/* INPUT SPECIFIC */
.form-control {
    padding-right: clamp(0.7rem, 3vw, 1rem);
}

/* Focus States */
.form-select:focus, 
.form-control:focus {
    border-color: var(--primary-green);
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15);
    outline: none;
}

/* Hover States */
.form-select:hover, 
.form-control:hover {
    border-color: var(--primary-green);
    background-color: rgba(68, 211, 78, 0.02);
}

/* Calendar Icon */
input[type="date"]::-webkit-calendar-picker-indicator,
input[type="month"]::-webkit-calendar-picker-indicator {
    width: clamp(14px, 3.5vw, 18px);
    height: clamp(14px, 3.5vw, 18px);
    padding: clamp(1px, 0.5vw, 3px);
    cursor: pointer;
    opacity: 0.6;
    transition: all 0.2s ease;
}

input[type="date"]::-webkit-calendar-picker-indicator:hover,
input[type="month"]::-webkit-calendar-picker-indicator:hover {
    opacity: 1;
    background: rgba(68, 211, 78, 0.1);
    transform: scale(1.1);
}

/* ===== RESPONSIVE GRID - FIXED VERSION ===== */

/* Remove conflicting row styles */
.form-card .row {
    display: flex;
    flex-wrap: wrap;
    margin-right: -0.5rem;
    margin-left: -0.5rem;
}

.form-card .row > [class*="col-"] {
    padding-right: 0.5rem;
    padding-left: 0.5rem;
    margin-bottom: 1rem;
}

/* Gutter spacing */
.g-3 {
    --bs-gutter-x: 1rem;
    --bs-gutter-y: 1rem;
}

/* ===== MOBILE (below 768px) - 2 COLUMNS ===== */
@media (max-width: 767px) {
    /* Force 2 columns sa mobile */
    .form-card .row > .col-12,
    .form-card .row > .col-sm-6,
    .form-card .row > .col-md-3 {
        flex: 0 0 50% !important;
        max-width: 50% !important;
    }
    
    /* Adjust spacing */
    .form-card {
        padding: 1rem;
    }
    
    .form-card h5 {
        font-size: 1.1rem;
        margin-bottom: 0.8rem;
    }
    
    .form-label {
        font-size: 0.8rem;
        margin-bottom: 0.2rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.8rem;
        padding: 0.35rem 0.6rem;
        min-height: 36px;
    }
}

/* ===== TABLET TO DESKTOP (768px and up) - 4 COLUMNS ===== */
@media (min-width: 768px) {
    .form-card .row > .col-md-3 {
        flex: 0 0 25%;
        max-width: 25%;
    }
}

/* ===== EXTRA SMALL (below 400px) ===== */
@media (max-width: 399px) {
    .form-card {
        padding: 0.7rem;
    }
    
    .form-card h5 {
        font-size: 1rem;
    }
    
    .form-label {
        font-size: 0.7rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
        min-height: 32px;
    }
}

/* ===== DROPDOWN OPTIONS ===== */
.form-select option {
    font-size: inherit;
    padding: clamp(0.2rem, 1vw, 0.4rem);
}

/* ===== ANIMATION ===== */
.form-card {
    animation: fadeInUp 0.3s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
    </style>
</head>
<body>
    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="sales_reports.php">
                            <i class="bi bi-graph-up"></i>
                            <span class="nav-text">Sales Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="branch_records.php">
                            <i class="bi bi-file-text"></i>
                            <span class="nav-text">Branch Records</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="all_items.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">All Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">User Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_tracking.php">
                            <i class="bi bi-geo-alt"></i>
                            <span class="nav-text">Driver Tracking</span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                    </div>
                </div>
                
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div id="recordsContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Branch Records</h2>
                        <p>View all activities and transactions from branch managers</p>
                    </div>
                </div>
                
               <div class="row stat-card-row g-1 g-sm-2">
    <!-- Card 1 - Total Records -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-file-text"></i>
            <div class="stat-content">
                <div class="stat-value" id="totalRecords"><?php echo number_format($total_records); ?></div>
                <div class="stat-label">Total Records</div>
            </div>
        </div>
    </div>
    
    <!-- Card 2 - Total Transactions -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-cash-stack"></i>
            <div class="stat-content">
                <div class="stat-value" id="totalTransactions">₱<?php echo number_format($total_transactions, 2); ?></div>
                <div class="stat-label">Total Transactions</div>
            </div>
        </div>
    </div>
    
    <!-- Card 3 - Active Branches -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-building"></i>
            <div class="stat-content">
                <div class="stat-value" id="activeBranches"><?php echo number_format($active_branches); ?></div>
                <div class="stat-label">Active Branches</div>
            </div>
        </div>
    </div>
</div>
               <!-- FILTER SECTION - BRANCH RECORDS -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="form-card">
            <div class="filter-header">
                <h5 class="mb-0">
                    <i class="bi bi-funnel"></i> Filter Records
                </h5>
                <button class="filter-toggle-btn" id="toggleBranchFilter" onclick="toggleFilter('branch')" title="Toggle Filter">
                    <i class="bi bi-chevron-down" id="branchFilterIcon"></i>
                </button>
            </div>
            <div class="filter-content" id="branchFilterContent">
                <div class="row mt-3 g-3">
                    <!-- Branch Filter -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label">
                            <i class="bi bi-building"></i> Branch
                        </label>
                        <select class="form-select" id="branchFilter" onchange="loadRecords()">
                            <option value="">All Branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['branch_id']; ?>">
                                    <?php echo htmlspecialchars($branch['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Record Type Filter -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label">
                            <i class="bi bi-tags"></i> Record Type
                        </label>
                        <select class="form-select" id="recordTypeFilter" onchange="loadRecords()">
                            <option value="">All Types</option>
                            <option value="sales_order">Sales Order</option>
                            <option value="purchase_order">Purchase Order</option>
                        </select>
                    </div>
                    
                    <!-- Date From -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label">
                            <i class="bi bi-calendar"></i> Date From
                        </label>
                        <input type="date" class="form-control" id="dateFromFilter" value="<?php echo $date_from; ?>" onchange="loadRecords()">
                    </div>
                    
                    <!-- Date To -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label">
                            <i class="bi bi-calendar-check"></i> Date To
                        </label>
                        <input type="date" class="form-control" id="dateToFilter" value="<?php echo $date_to; ?>" onchange="loadRecords()">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Branch Activity Log</h5>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table compact-table">
                            <thead>
                                <tr>
                                    <th style="display: none;">ID</th>
                                    <th>Branch</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="recordsTable">
                                <tr>
                                    <td colspan="7" class="text-center py-4">Loading records...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="sales_reports.php">
                    <i class="bi bi-graph-up"></i>
                    <span>Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="branch_records.php">
                    <i class="bi bi-file-text"></i>
                    <span>Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="all_items.php">
                    <i class="bi bi-box"></i>
                    <span>Items</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="drivers.php">
                    <i class="bi bi-people"></i>
                    <span>Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="driver_tracking.php">
                    <i class="bi bi-geo-alt"></i>
                    <span>Tracking</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile/Logout Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <!-- User Avatar -->
                    <div class="user-avatar-large mb-3">
                        <?php echo $user_initials; ?>
                    </div>
                    
                    <!-- User Name -->
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    
                    <!-- User Role -->
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction Details Modal -->
    <div class="modal fade" id="recordModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-receipt"></i>
                        <span id="modalTitle">Transaction Details</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="recordDetails">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2" style="color:#64748b;font-size:14px;">Loading transaction details...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ================= SIDEBAR FUNCTIONS =================
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    
                    overlay.addEventListener('click', () => {
                        closeMobileSidebar();
                    });
                    
                    setTimeout(() => {
                        overlay.classList.add('active');
                    }, 10);
                } else {
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => {
                            if (overlay && overlay.parentNode) {
                                overlay.remove();
                            }
                        }, 300);
                    }
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

        function closeMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.remove('active');
            
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => {
                    if (overlay.parentNode) {
                        overlay.remove();
                    }
                }, 300);
            }
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        // ================= MOBILE NAVIGATION FUNCTIONS =================
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
                // Set active state based on current page (excluding logout)
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
            }
        }

        // ================= PROFILE/LOGOUT FUNCTIONS =================
        function showProfileModal() {
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
        }

        function confirmLogout() {
            // Close the modal first
            const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
            if (modal) {
                modal.hide();
            }
            
            // Show confirmation dialog
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // Original logout function for sidebar
        function logout() {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#07d826',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // ================= RECORDS FUNCTIONS =================
        async function loadRecords() {
            const tbody = document.getElementById('recordsTable');
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading records...</p></td></tr>';
            
            try {
                const branch = document.getElementById('branchFilter').value;
                const recordType = document.getElementById('recordTypeFilter').value;
                const dateFrom = document.getElementById('dateFromFilter').value;
                const dateTo = document.getElementById('dateToFilter').value;

                const params = new URLSearchParams({
                    ajax: 1,
                    branch: branch,
                    type: recordType,
                    dateFrom: dateFrom,
                    dateTo: dateTo
                });

                const response = await fetch('branch_records.php?' + params);
                const data = await response.json();
                
                if (data.success && data.records.length > 0) {
                    displayRecords(data.records);
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">No records found</td></tr>';
                }
            } catch (error) {
                console.error('Error loading records:', error);
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-danger">Error loading records</td></tr>';
            }
        }

        function displayRecords(records) {
            const tbody = document.getElementById('recordsTable');
            
            tbody.innerHTML = records.map(record => {
                let statusClass = '';
                let statusText = record.status;
                
                const completed = ['completed', 'delivered', 'approved', 'received'];
                const pending = ['pending', 'draft', 'planned', 'open', 'processing'];
                const cancelled = ['cancelled', 'rejected'];
                
                if (completed.includes(record.status)) {
                    statusClass = 'status-completed';
                } else if (pending.includes(record.status)) {
                    statusClass = 'status-pending';
                } else if (cancelled.includes(record.status)) {
                    statusClass = 'status-cancelled';
                }
                
                return `
                    <tr>
                        <td style="display: none;">${record.id}</td>
                        <td><strong>${escapeHtml(record.branch)}</strong></td>
                        <td><span class="badge bg-info">${escapeHtml(record.type)}</span></td>
                        <td>${escapeHtml(record.description)}</td>
                        <td>₱${parseFloat(record.amount || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}</td>
                        <td>${new Date(record.date).toLocaleDateString()}</td>
                        <td>
                            <span class="status-badge ${statusClass}">
                                ${escapeHtml(statusText)}
                            </span>
                        </td>
                        <td>
                            <button class="btn-action btn-view" onclick="viewTransaction(${record.id}, '${record.source}')" title="View Full Transaction Details">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDateTime(timestamp) {
            if (!timestamp) return 'N/A';
            try {
                const date = new Date(timestamp);
                if (isNaN(date.getTime())) return 'N/A';
                return date.toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                });
            } catch (e) {
                return 'N/A';
            }
        }

        function viewTransaction(id, source) {
            const modal = new bootstrap.Modal(document.getElementById('recordModal'));
            const details = document.getElementById('recordDetails');
            const modalTitle = document.getElementById('modalTitle');
            
            modalTitle.textContent = 'Loading Transaction...';
            details.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading complete transaction details...</p></div>';
            modal.show();
            
            fetch(`branch_records.php?ajax_details=1&id=${id}&source=${source}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        const record = data.record;
                        modalTitle.textContent = `Transaction: ${record.record_number}`;
                        displayFullTransaction(record);
                    } else {
                        details.innerHTML = `<p class="text-danger">Failed to load transaction details: ${data.message || 'Unknown error'}</p>`;
                    }
                })
                .catch(error => {
                    console.error('Error loading transaction details:', error);
                    details.innerHTML = `<p class="text-danger">Error loading transaction details: ${error.message}</p>`;
                });
        }

        function displayFullTransaction(record) {
            const el = document.getElementById('recordDetails');

            // helpers
            function pill(status) {
                if (!status) return '';
                const s = status.toLowerCase();
                let c = 'gray';
                if (['completed','delivered','approved','received','paid'].includes(s)) c = 'green';
                else if (['pending','draft','planned','open','processing','in_transit'].includes(s)) c = 'yellow';
                else if (['cancelled','rejected'].includes(s)) c = 'red';
                return `<span class="s-pill ${c}">${escapeHtml(status)}</span>`;
            }
            function peso(v) { const n = parseFloat(v||0); return 'P' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }

            const itemCount   = (record.items||[]).length;
            const pickCount   = (record.pick_lists||[]).length;
            const invCount    = (record.invoices||[]).length;
            const tripCount   = (record.trip_tickets||[]).length;
            const hasDel      = !!record.delivery;
            const histCount   = (record.history||[]).length;

            /* ---- LEFT: Details ---- */
            // Customer
            let leftHtml = `
                <div class="m-section-title"><i class="bi bi-person"></i> Customer / Supplier</div>
                <div class="m-card"><div class="m-card-body">
                    <div class="m-info">
                        <div><div class="m-info-lbl">Name</div><div class="m-info-val">${escapeHtml(record.customer.name)}</div></div>
                        <div><div class="m-info-lbl">Address</div><div class="m-info-val">${escapeHtml(record.customer.address)}</div></div>
                        <div><div class="m-info-lbl">Contact</div><div class="m-info-val">${escapeHtml(record.customer.contact)}</div></div>
                    </div>
                </div></div>`;

            // Order
            leftHtml += `
                <div class="m-section-title"><i class="bi bi-file-earmark-text"></i> Order Details</div>
                <div class="m-card"><div class="m-card-body">
                    <div class="m-info">
                        <div><div class="m-info-lbl">Order #</div><div class="m-info-val" style="font-weight:700">${escapeHtml(record.record_number)}</div></div>
                        <div><div class="m-info-lbl">Branch</div><div class="m-info-val">${escapeHtml(record.branch)}</div></div>
                        <div><div class="m-info-lbl">Order Date</div><div class="m-info-val">${formatDateTime(record.order_details.order_date)}</div></div>
                        <div><div class="m-info-lbl">Status</div><div class="m-info-val">${pill(record.order_details.status)}</div></div>
                        ${record.order_details.delivery_date ? `<div><div class="m-info-lbl">Delivery Date</div><div class="m-info-val">${formatDateTime(record.order_details.delivery_date)}</div></div>` : ''}
                    </div>
                </div></div>`;

            // Items
            if (itemCount > 0) {
                leftHtml += `
                    <div class="m-section-title"><i class="bi bi-box-seam"></i> Items (${itemCount})</div>
                    <div class="m-card"><div class="m-card-body" style="padding:0"><div style="overflow-x:auto">
                        <table class="m-tbl">
                            <thead><tr><th>Item</th><th>Code</th><th>Category</th><th style="text-align:right">Qty</th><th style="text-align:right">Price</th><th style="text-align:right">Total</th></tr></thead>
                            <tbody>${record.items.map(i=>`<tr>
                                <td>${escapeHtml(i.item_name)}</td>
                                <td style="color:#64748b">${escapeHtml(i.item_code)}</td>
                                <td>${escapeHtml(i.category)}</td>
                                <td style="text-align:right">${i.quantity}</td>
                                <td style="text-align:right">${peso(i.unit_price)}</td>
                                <td style="text-align:right;font-weight:600">${peso(i.total_price)}</td>
                            </tr>`).join('')}</tbody>
                            <tfoot><tr><th colspan="5" style="text-align:right">Total</th><th style="text-align:right">${peso(record.order_details.total_amount)}</th></tr></tfoot>
                        </table>
                    </div></div></div>`;
            }

            // Pick Lists
            if (pickCount > 0) {
                leftHtml += `<div class="m-section-title"><i class="bi bi-clipboard-check"></i> Pick Lists (${pickCount})</div>`;
                record.pick_lists.forEach(pl => {
                    leftHtml += `<div class="m-card">
                        <div class="m-card-head"><i class="bi bi-clipboard-check"></i>${escapeHtml(pl.pick_list_number)}<span class="pill-right">${pill(pl.status)}</span></div>
                        <div class="m-card-body">
                            <div class="m-info" style="margin-bottom:10px">
                                <div><div class="m-info-lbl">Driver</div><div class="m-info-val">${escapeHtml(pl.driver_name)}</div></div>
                                <div><div class="m-info-lbl">Picked By</div><div class="m-info-val">${escapeHtml(pl.picked_by)}</div></div>
                                <div><div class="m-info-lbl">Verified By</div><div class="m-info-val">${escapeHtml(pl.verified_by)}</div></div>
                                <div><div class="m-info-lbl">Pick Date</div><div class="m-info-val">${pl.pick_date?formatDateTime(pl.pick_date):'N/A'}</div></div>
                            </div>
                            ${(pl.items||[]).length?`<div style="overflow-x:auto"><table class="m-tbl">
                                <thead><tr><th>Item</th><th style="text-align:right">To Pick</th><th style="text-align:right">Picked</th></tr></thead>
                                <tbody>${pl.items.map(pi=>`<tr><td>${escapeHtml(pi.item_name)}</td><td style="text-align:right">${pi.quantity_ordered}</td><td style="text-align:right">${pi.quantity_picked}</td></tr>`).join('')}</tbody>
                            </table></div>`:''}
                        </div></div>`;
                });
            }

            // Invoices
            if (invCount > 0) {
                leftHtml += `
                    <div class="m-section-title"><i class="bi bi-receipt"></i> Invoices (${invCount})</div>
                    <div class="m-card"><div class="m-card-body" style="padding:0"><div style="overflow-x:auto">
                        <table class="m-tbl">
                            <thead><tr><th>Invoice #</th><th style="text-align:right">Amount</th><th>Status</th><th>Created</th><th>Due Date</th></tr></thead>
                            <tbody>${record.invoices.map(inv=>`<tr>
                                <td style="font-weight:600">${escapeHtml(inv.invoice_number)}</td>
                                <td style="text-align:right">${peso(inv.amount)}</td>
                                <td>${pill(inv.status)}</td>
                                <td>${formatDateTime(inv.created_at)}</td>
                                <td>${inv.due_date?formatDateTime(inv.due_date):'N/A'}</td>
                            </tr>`).join('')}</tbody>
                        </table>
                    </div></div></div>`;
            }

            // Trip Tickets
            if (tripCount > 0) {
                leftHtml += `<div class="m-section-title"><i class="bi bi-truck"></i> Trip Tickets (${tripCount})</div>`;
                record.trip_tickets.forEach(t => {
                    leftHtml += `<div class="m-card">
                        <div class="m-card-head"><i class="bi bi-truck"></i>${escapeHtml(t.trip_number)}<span class="pill-right">${pill(t.status)}</span></div>
                        <div class="m-card-body"><div class="m-info">
                            <div><div class="m-info-lbl">Driver</div><div class="m-info-val">${escapeHtml(t.driver_name)}</div></div>
                            <div><div class="m-info-lbl">Plate #</div><div class="m-info-val">${escapeHtml(t.plate_number)}</div></div>
                            <div><div class="m-info-lbl">Departure</div><div class="m-info-val">${formatDateTime(t.departure_time)}</div></div>
                            <div><div class="m-info-lbl">Arrival</div><div class="m-info-val">${t.arrival_time?formatDateTime(t.arrival_time):'N/A'}</div></div>
                            <div><div class="m-info-lbl">Remarks</div><div class="m-info-val">${escapeHtml(t.route)}</div></div>
                        </div></div></div>`;
                });
            }

            // Delivery
            if (hasDel) {
                const d = record.delivery;
                leftHtml += `
                    <div class="m-section-title"><i class="bi bi-geo-alt"></i> Delivery</div>
                    <div class="m-card">
                        <div class="m-card-head"><i class="bi bi-geo-alt"></i>Delivery<span class="pill-right">${pill(d.status)}</span></div>
                        <div class="m-card-body"><div class="m-info">
                            <div><div class="m-info-lbl">Delivery #</div><div class="m-info-val">${escapeHtml(d.delivery_number)}</div></div>
                            <div><div class="m-info-lbl">Delivered At</div><div class="m-info-val">${d.delivered_at?formatDateTime(d.delivered_at):'N/A'}</div></div>
                            <div><div class="m-info-lbl">Signed By</div><div class="m-info-val">${escapeHtml(d.received_by)}</div></div>
                            ${d.delivery_notes?`<div><div class="m-info-lbl">Remarks</div><div class="m-info-val">${escapeHtml(d.delivery_notes)}</div></div>`:''}
                        </div></div></div>`;
            }

            /* ---- RIGHT: Transaction History Timeline ---- */
            let rightHtml = `<div class="m-section-title" style="margin-top:0"><i class="bi bi-clock-history"></i> Transaction History</div>`;
            if (histCount > 0) {
                rightHtml += `<ul class="tl">${record.history.map(ev => {
                    let dot = 'gray';
                    const a = ev.action;
                    if (a.includes('Created'))  dot = 'blue';
                    else if (a.includes('Confirmed')||a.includes('Approved')) dot = 'cyan';
                    else if (a.includes('Pick')||a.includes('Picked')) dot = 'cyan';
                    else if (a.includes('Invoice')||a.includes('Payment')) dot = 'green';
                    else if (a.includes('Trip')||a.includes('Assigned')) dot = 'yellow';
                    else if (a.includes('Delivered')) dot = 'green';
                    else if (a.includes('Rejected')) dot = 'red';
                    else if (a.includes('Submitted')) dot = 'blue';
                    return `<li class="tl-item">
                        <div class="tl-dot ${dot}"></div>
                        <div class="tl-action">${escapeHtml(ev.action)}</div>
                        <div class="tl-detail">${escapeHtml(ev.details)}</div>
                        <div class="tl-meta">
                            <span><i class="bi bi-person-fill"></i>${escapeHtml(ev.user)}</span>
                            <span><i class="bi bi-clock"></i>${formatDateTime(ev.timestamp)}</span>
                        </div>
                    </li>`;
                }).join('')}</ul>`;
            } else {
                rightHtml += `<div class="m-empty"><i class="bi bi-clock"></i><p>No history recorded yet.</p></div>`;
            }

            /* ---- Assemble ---- */
            el.innerHTML = `
                <div class="m-summary">
                    <div class="m-summary-item"><div class="m-summary-lbl">${escapeHtml(record.type)}</div><div class="m-summary-val" style="font-weight:700">${escapeHtml(record.record_number)}</div></div>
                    <div class="m-summary-item"><div class="m-summary-lbl">Branch</div><div class="m-summary-val">${escapeHtml(record.branch)}</div></div>
                    <div class="m-summary-item"><div class="m-summary-lbl">Status</div><div class="m-summary-val">${pill(record.order_details.status)}</div></div>
                    <div class="m-summary-item" style="margin-left:auto"><div class="m-summary-lbl">Total Amount</div><div class="m-summary-val lg">${peso(record.order_details.total_amount)}</div></div>
                </div>
                <div class="m-layout">
                    <div class="m-left">${leftHtml}</div>
                    <div class="m-right">${rightHtml}</div>
                </div>`;
        }

        // ================= INITIALIZATION =================
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            initMobileNav();
            
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileToggleBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileToggleBtn || !mobileToggleBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });
            
            loadRecords();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            } else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            } else if (e.key === 'Escape') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(profileModal).hide();
                }
            } else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                loadRecords();
            }
        });

        // ================= FILTER TOGGLE FUNCTIONS =================
// Toggle filter section visibility with localStorage
function toggleFilter(filterType) {
    const contentId = filterType + 'FilterContent';
    const iconId = filterType + 'FilterIcon';
    
    const content = document.getElementById(contentId);
    const icon = document.getElementById(iconId);
    
    if (content && icon) {
        if (content.classList.contains('collapsed')) {
            // Show filter
            content.classList.remove('collapsed');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'false');
        } else {
            // Hide filter
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'true');
        }
    }
}

// ================= FILTER TOGGLE FUNCTIONS =================
// Toggle filter section visibility with localStorage
function toggleFilter(filterType) {
    const contentId = filterType + 'FilterContent';
    const iconId = filterType + 'FilterIcon';
    
    const content = document.getElementById(contentId);
    const icon = document.getElementById(iconId);
    
    if (content && icon) {
        if (content.classList.contains('collapsed')) {
            // Show filter
            content.classList.remove('collapsed');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'false');
        } else {
            // Hide filter
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'true');
        }
    }
}

// Initialize filter states on page load - DEFAULT CLOSED
function initFilterStates() {
    const filterTypes = ['sales', 'branch', 'items', 'driver', 'trip'];
    
    filterTypes.forEach(type => {
        const contentId = type + 'FilterContent';
        const iconId = type + 'FilterIcon';
        
        const content = document.getElementById(contentId);
        const icon = document.getElementById(iconId);
        
        if (content && icon) {
            // DEFAULT: CLOSED sa simula
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            
            // Save sa localStorage na closed para consistent
            localStorage.setItem(type + 'FilterHidden', 'true');
        }
    });
}

// Call this sa loob ng DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    // ... existing code ...
    
    // Initialize filter states - lahat closed
    initFilterStates();
});
    </script>
</body>
</html>
<?php $conn->close(); ?>