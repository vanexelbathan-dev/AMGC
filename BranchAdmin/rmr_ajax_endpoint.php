<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

header('Content-Type: application/json; charset=utf-8');

$user_id = $_SESSION['user_id'] ?? 1;
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

function json_response($payload) {
    echo json_encode($payload);
    exit;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function table_exists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['action'])) {
        throw new Exception('Invalid request');
    }

    $action = $_POST['action'];
    $rmr_branch_column_exists = column_exists($conn, 'rmr_requests', 'branch_id');
    $delivery_branch_column_exists = column_exists($conn, 'deliveries', 'branch_id');

    if ($action === 'view_rmr') {
        $rmr_id = (int)($_POST['rmr_id'] ?? 0);
        if ($rmr_id <= 0) {
            throw new Exception('Invalid RMR ID');
        }

        $query = "
            SELECT 
                r.*,
                COALESCE(c.customer_name, dc.customer_name, 'N/A') AS customer_name,
                COALESCE(c.customer_id, dc.customer_id, r.customer_id) AS customer_id,
                i.item_code,
                i.item_name,
                i.unit_price,
                i.price_case,
                i.price_inner_pack,
                i.price_box,
                i.price_carton,
                i.unit_type,
                i.stock as current_stock,
                b.branch_name,
                CONCAT(u.first_name, ' ', u.last_name) as received_by_name
            FROM rmr_requests r
            LEFT JOIN customers c ON r.customer_id = c.customer_id
            LEFT JOIN deliveries d ON r.delivery_id = d.delivery_id
            LEFT JOIN customers dc ON d.customer_id = dc.customer_id
            JOIN items i ON r.item_id = i.item_id
            LEFT JOIN branches b ON r.branch_id = b.branch_id
            LEFT JOIN users u ON r.received_by = u.user_id
            WHERE r.rmr_id = ?
        ";

        if ($rmr_branch_column_exists && !$view_all_branches) {
            $query .= " AND r.branch_id = ?";
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('ii', $rmr_id, $branch_id);
        } else {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('i', $rmr_id);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rmr = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$rmr) {
            throw new Exception('RMR not found');
        }

        $approval = null;
        if (table_exists($conn, 'rmr_approvals')) {
            $approval_stmt = $conn->prepare("SELECT * FROM rmr_approvals WHERE rmr_id = ? ORDER BY approved_at DESC LIMIT 1");
            if ($approval_stmt) {
                $approval_stmt->bind_param('i', $rmr_id);
                $approval_stmt->execute();
                $approval_result = $approval_stmt->get_result();
                $approval = $approval_result ? $approval_result->fetch_assoc() : null;
                $approval_stmt->close();
            }
        }

        json_response(['success' => true, 'rmr' => $rmr, 'approval' => $approval]);
    }

    if ($action === 'process_rmr') {
        $rmr_id = (int)($_POST['rmr_id'] ?? 0);
        $inspector_name = trim($_POST['inspector_name'] ?? '');
        $inspection_type = trim($_POST['inspection_type'] ?? 'visual');
        if ($rmr_id <= 0 || $inspector_name === '') throw new Exception('Missing required fields');

        $sql = "UPDATE rmr_requests SET rmr_status = 'processing', inspector_name = ?, inspection_type = ?, updated_at = NOW() WHERE rmr_id = ?";
        $types = 'ssi';
        $params = [$inspector_name, $inspection_type, $rmr_id];
        if ($rmr_branch_column_exists && !$view_all_branches) {
            $sql .= " AND branch_id = ?";
            $types .= 'i';
            $params[] = $branch_id;
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new Exception('Failed to update RMR status');
        $stmt->close();
        json_response(['success' => true, 'message' => 'RMR is now being processed']);
    }

    if ($action === 'approve_rmr') {
        $rmr_id = (int)($_POST['rmr_id'] ?? 0);
        $disposition_type = trim($_POST['disposition_type'] ?? 'credit');
        $approved_amount = (float)($_POST['approved_amount'] ?? 0);
        $approval_notes = $_POST['approval_notes'] ?? null;
        if ($rmr_id <= 0) throw new Exception('Invalid RMR ID');

        $conn->begin_transaction();
        $sql = "UPDATE rmr_requests SET rmr_status = 'approved', disposition_type = ?, updated_at = NOW() WHERE rmr_id = ?";
        $types = 'si';
        $params = [$disposition_type, $rmr_id];
        if ($rmr_branch_column_exists && !$view_all_branches) {
            $sql .= " AND branch_id = ?";
            $types .= 'i';
            $params[] = $branch_id;
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new Exception('Failed to confirm RMR');
        $stmt->close();

        if (table_exists($conn, 'rmr_approvals')) {
            $history = $conn->prepare("INSERT INTO rmr_approvals (rmr_id, approved_amount, approval_notes, approved_by, approved_at) VALUES (?, ?, ?, ?, NOW())");
            if ($history) {
                $history->bind_param('idsi', $rmr_id, $approved_amount, $approval_notes, $user_id);
                $history->execute();
                $history->close();
            }
        }
        $conn->commit();
        json_response(['success' => true, 'message' => 'RMR confirmed successfully. Stock was not updated yet. Please receive this RMR through Receive Inventory > Returned Merchandise to return it to inventory.']);
    }

    if ($action === 'reject_rmr') {
        $rmr_id = (int)($_POST['rmr_id'] ?? 0);
        $rejection_reason = trim($_POST['rejection_reason'] ?? 'Rejected by QC');
        if ($rmr_id <= 0) throw new Exception('Invalid RMR ID');

        $sql = "UPDATE rmr_requests SET rmr_status = 'rejected', reason_details = CONCAT(IFNULL(reason_details, ''), ' | Rejected: ', ?), updated_at = NOW() WHERE rmr_id = ?";
        $types = 'si';
        $params = [$rejection_reason, $rmr_id];
        if ($rmr_branch_column_exists && !$view_all_branches) {
            $sql .= " AND branch_id = ?";
            $types .= 'i';
            $params[] = $branch_id;
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new Exception('Failed to reject RMR');
        $stmt->close();
        json_response(['success' => true, 'message' => 'RMR rejected successfully']);
    }

    if ($action === 'create_rmr_from_delivery') {
        $delivery_id = (int)($_POST['delivery_id'] ?? 0);
        $so_id = (int)($_POST['so_id'] ?? 0);
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $item_id = (int)($_POST['item_id'] ?? 0);
        $return_quantity = (int)($_POST['return_quantity'] ?? 0);
        $return_reason = trim($_POST['return_reason'] ?? '');
        $reason_details = $_POST['reason_details'] ?? '';
        $branch_id_for_insert = (int)($_POST['branch_id'] ?? $branch_id);
        if (!$delivery_id || !$so_id || !$customer_id || !$item_id || !$return_quantity || !$return_reason) {
            throw new Exception('All fields are required');
        }

        $check = $conn->prepare('SELECT rmr_id FROM rmr_requests WHERE delivery_id = ? LIMIT 1');
        if (!$check) throw new Exception('Prepare failed: ' . $conn->error);
        $check->bind_param('i', $delivery_id);
        $check->execute();
        $check_result = $check->get_result();
        if ($check_result && $check_result->num_rows > 0) throw new Exception('RMR already exists for this delivery');
        $check->close();

        $rmr_number = 'RMR-' . date('Ymd') . '-' . str_pad((string)$delivery_id, 5, '0', STR_PAD_LEFT);
        $insert = $conn->prepare("INSERT INTO rmr_requests (rmr_number, delivery_id, so_id, customer_id, item_id, return_quantity, return_reason, reason_details, rmr_status, branch_id, received_by, received_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())");
        if (!$insert) throw new Exception('Prepare failed: ' . $conn->error);
        $insert->bind_param('siiiissiii', $rmr_number, $delivery_id, $so_id, $customer_id, $item_id, $return_quantity, $return_reason, $reason_details, $branch_id_for_insert, $user_id);
        if (!$insert->execute()) throw new Exception('Failed to create RMR: ' . $insert->error);
        $rmr_id = $conn->insert_id;
        $insert->close();
        json_response(['success' => true, 'message' => 'RMR created successfully from rejected delivery', 'rmr_id' => $rmr_id, 'rmr_number' => $rmr_number]);
    }

    if ($action === 'print_rmr') {
        // Lightweight print data endpoint.
        $filter_data = json_decode($_POST['filter_data'] ?? '{}', true) ?: [];
        $where = ['1=1'];
        if (!empty($filter_data['status']) && $filter_data['status'] !== 'all') {
            $where[] = "r.rmr_status = '" . $conn->real_escape_string($filter_data['status']) . "'";
        }
        if (!empty($filter_data['reason']) && $filter_data['reason'] !== 'all') {
            $where[] = "r.return_reason = '" . $conn->real_escape_string($filter_data['reason']) . "'";
        }
        if ($rmr_branch_column_exists && !$view_all_branches) {
            $where[] = 'r.branch_id = ' . $branch_id;
        }
        $sql = "SELECT r.rmr_id, r.rmr_number, r.delivery_id, r.so_id, r.return_quantity, r.return_reason, r.reason_details, r.rmr_status, r.received_date, r.inspector_name, r.inspection_type, r.disposition_type, r.branch_id, r.created_at, COALESCE(c.customer_name, dc.customer_name, 'N/A') AS customer_name, i.item_code, i.item_name, i.unit_price, i.unit_type, b.branch_name, CONCAT(u.first_name, ' ', u.last_name) as received_by_name FROM rmr_requests r LEFT JOIN customers c ON r.customer_id = c.customer_id LEFT JOIN deliveries d ON r.delivery_id = d.delivery_id LEFT JOIN customers dc ON d.customer_id = dc.customer_id JOIN items i ON r.item_id = i.item_id LEFT JOIN branches b ON r.branch_id = b.branch_id LEFT JOIN users u ON r.received_by = u.user_id WHERE " . implode(' AND ', $where) . " ORDER BY r.received_date DESC, r.rmr_id DESC";
        $res = $conn->query($sql);
        $items = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        json_response(['success' => true, 'items' => $items, 'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches', 'view_all' => $view_all_branches, 'rmr_branch_column_exists' => $rmr_branch_column_exists]);
    }

    throw new Exception('Unsupported action');
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { @$conn->rollback(); } catch (Throwable $ignored) {}
    }
    json_response(['success' => false, 'message' => $e->getMessage()]);
}
