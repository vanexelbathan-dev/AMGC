<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(bool $success, string $message = '', array $data = []): void {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

function tableExists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function cleanDateValue(?string $date): string {
    $date = trim((string)$date);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
}

function bindAndFetchAll(mysqli $conn, string $query, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return [];
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

if (!tableExists($conn, 'sales_orders') || !tableExists($conn, 'sales_order_items') || !tableExists($conn, 'items')) {
    jsonResponse(false, 'Required report tables are missing.');
}

$reportCategory = $_POST['report_category'] ?? 'sales';
$dateFrom = cleanDateValue($_POST['date_from'] ?? null);
$dateTo = cleanDateValue($_POST['date_to'] ?? null);
$branchId = (int)($_POST['branch_id'] ?? ($_SESSION['branch_id'] ?? 0));

if (strtotime($dateFrom) > strtotime($dateTo)) {
    jsonResponse(false, 'From date cannot be later than To date.');
}

$startDateTime = $dateFrom . ' 00:00:00';
$endDateTime = date('Y-m-d H:i:s', strtotime($dateTo . ' +1 day'));

$salesDateExpr = columnExists($conn, 'sales_orders', 'order_date')
    ? "COALESCE(NULLIF(so.order_date, '0000-00-00 00:00:00'), so.created_at)"
    : "so.created_at";

$orderAmountExpr = columnExists($conn, 'sales_orders', 'order_amount')
    ? "CASE WHEN COALESCE(so.order_amount, 0) > 0 THEN so.order_amount ELSE so.total_amount END"
    : "so.total_amount";

$siSelect = "'' AS si_number";
$documentTypeSelect = "'' AS document_type";
if (columnExists($conn, 'sales_orders', 'si_number')) {
    $siSelect = "COALESCE(NULLIF(so.si_number, ''), NULLIF(inv.si_number, ''), '') AS si_number";
}
if (columnExists($conn, 'sales_orders', 'document_type')) {
    $documentTypeSelect = "COALESCE(so.document_type, '') AS document_type";
}

$invoiceJoin = tableExists($conn, 'invoices')
    ? "LEFT JOIN invoices inv ON inv.so_id = so.so_id"
    : "LEFT JOIN (SELECT NULL AS so_id, NULL AS si_number, NULL AS invoice_number, NULL AS total_amount, NULL AS amount_paid, NULL AS balance, NULL AS status) inv ON 1=0";

$customerJoin = tableExists($conn, 'customers')
    ? "LEFT JOIN customers c ON c.customer_id = so.customer_id"
    : "";

$customerSelect = tableExists($conn, 'customers')
    ? "COALESCE(NULLIF(c.customer_name, ''), NULLIF(c.store_name, ''), 'Walk-in Customer') AS customer_name"
    : "'Walk-in Customer' AS customer_name";

$deliveryJoin = tableExists($conn, 'trip_tickets') && tableExists($conn, 'drivers')
    ? "LEFT JOIN trip_tickets tt ON tt.so_id = so.so_id
       LEFT JOIN drivers d ON d.driver_id = tt.driver_id"
    : "";

$driverSelect = tableExists($conn, 'trip_tickets') && tableExists($conn, 'drivers')
    ? "COALESCE(NULLIF(d.driver_name, ''), '') AS driver_name, COALESCE(NULLIF(d.vehicle_plate_number, ''), '') AS plate_number"
    : "'' AS driver_name, '' AS plate_number";

$paymentJoin = "";
$paymentSelect = "0 AS payment_amount, 0 AS cash_payment, 0 AS cheque_payment, 0 AS online_payment, '' AS payment_method, '' AS payment_reference";
if (tableExists($conn, 'payments')) {
    $paymentJoin = "LEFT JOIN (
        SELECT
            p.so_id,
            SUM(CASE WHEN LOWER(COALESCE(p.payment_method, '')) LIKE '%cash%' THEN COALESCE(p.amount, 0) ELSE 0 END) AS cash_payment,
            SUM(CASE WHEN LOWER(COALESCE(p.payment_method, '')) LIKE '%cheque%' OR LOWER(COALESCE(p.payment_method, '')) LIKE '%check%' THEN COALESCE(p.amount, 0) ELSE 0 END) AS cheque_payment,
            SUM(CASE WHEN LOWER(COALESCE(p.payment_method, '')) LIKE '%online%' OR LOWER(COALESCE(p.payment_method, '')) LIKE '%bank%' OR LOWER(COALESCE(p.payment_method, '')) LIKE '%transfer%' THEN COALESCE(p.amount, 0) ELSE 0 END) AS online_payment,
            SUM(COALESCE(p.amount, 0)) AS payment_amount,
            GROUP_CONCAT(DISTINCT NULLIF(p.payment_method, '') SEPARATOR ', ') AS payment_method,
            GROUP_CONCAT(DISTINCT NULLIF(p.reference_number, '') SEPARATOR ', ') AS payment_reference
        FROM payments p
        WHERE (p.status IS NULL OR p.status = '' OR p.status = 'completed')
        GROUP BY p.so_id
    ) pay ON pay.so_id = so.so_id";

    $paymentSelect = "COALESCE(pay.payment_amount, inv.amount_paid, 0) AS payment_amount,
        COALESCE(pay.cash_payment, 0) AS cash_payment,
        COALESCE(pay.cheque_payment, 0) AS cheque_payment,
        COALESCE(pay.online_payment, 0) AS online_payment,
        COALESCE(NULLIF(pay.payment_method, ''), '') AS payment_method,
        COALESCE(NULLIF(pay.payment_reference, ''), '') AS payment_reference";
}

$branchWhere = $branchId > 0 ? "AND so.branch_id = ?" : "";
$params = [$startDateTime, $endDateTime];
$types = 'ss';
if ($branchId > 0) {
    $params[] = $branchId;
    $types .= 'i';
}

$orderRows = bindAndFetchAll(
    $conn,
    "SELECT
        so.so_id,
        so.so_number,
        $siSelect,
        $documentTypeSelect,
        $customerSelect,
        $driverSelect,
        $orderAmountExpr AS invoice_amount,
        COALESCE(so.total_amount, 0) AS total_amount,
        $paymentSelect,
        CASE
            WHEN COALESCE(inv.balance, 0) > 0 THEN inv.balance
            WHEN COALESCE($orderAmountExpr, 0) - COALESCE(pay.payment_amount, inv.amount_paid, 0) > 0 THEN COALESCE($orderAmountExpr, 0) - COALESCE(pay.payment_amount, inv.amount_paid, 0)
            ELSE 0
        END AS balance,
        COALESCE(so.fulfillment_type, '') AS fulfillment_type,
        COALESCE(so.payment_status, inv.status, 'unpaid') AS payment_status,
        COALESCE(so.order_status, '') AS order_status,
        COALESCE(so.created_at, '') AS created_at,
        $salesDateExpr AS order_date
     FROM sales_orders so
     $invoiceJoin
     $customerJoin
     $deliveryJoin
     $paymentJoin
     WHERE $salesDateExpr >= ?
       AND $salesDateExpr < ?
       AND (so.order_status IS NULL OR so.order_status <> 'cancelled')
       $branchWhere
     GROUP BY so.so_id
     ORDER BY $salesDateExpr ASC, so.so_id ASC",
    $types,
    $params
);

if (empty($orderRows)) {
    jsonResponse(true, '', [
        'orders' => [],
        'report_category' => $reportCategory,
        'date_from' => $dateFrom,
        'date_to' => $dateTo
    ]);
}

$soIds = array_map(static fn($row) => (int)$row['so_id'], $orderRows);
$placeholders = implode(',', array_fill(0, count($soIds), '?'));
$itemTypes = str_repeat('i', count($soIds));

$itemRows = bindAndFetchAll(
    $conn,
    "SELECT
        soi.so_id,
        i.item_id,
        COALESCE(NULLIF(i.item_code, ''), CONCAT('ITEM-', i.item_id)) AS item_code,
        COALESCE(NULLIF(i.item_name, ''), CONCAT('Item #', i.item_id)) AS item_name,
        COALESCE(NULLIF(i.category, ''), '') AS category,
        COALESCE(NULLIF(i.volume, ''), '') AS volume,
        COALESCE(NULLIF(i.oil_type, ''), '') AS oil_type,
        COALESCE(NULLIF(soi.unit_type, ''), NULLIF(i.unit_type, ''), NULLIF(i.base_unit_type, ''), 'Piece') AS uom,
        COALESCE(soi.quantity_delivered, 0) AS delivered_qty,
        COALESCE(soi.quantity_ordered, 0) AS ordered_qty,
        CASE WHEN COALESCE(soi.quantity_delivered, 0) > 0 THEN soi.quantity_delivered ELSE soi.quantity_ordered END AS quantity,
        COALESCE(soi.unit_price, soi.net_price, soi.gross_price, i.unit_price, 0) AS unit_price,
        COALESCE(soi.order_amount, (soi.quantity_ordered * soi.unit_price), 0) AS line_amount
     FROM sales_order_items soi
     LEFT JOIN items i ON i.item_id = soi.item_id
     WHERE soi.so_id IN ($placeholders)
     ORDER BY soi.so_id ASC, i.item_code ASC, i.item_name ASC",
    $itemTypes,
    $soIds
);

$itemsByOrder = [];
foreach ($itemRows as $item) {
    $soId = (int)$item['so_id'];
    if (!isset($itemsByOrder[$soId])) {
        $itemsByOrder[$soId] = [];
    }
    $itemsByOrder[$soId][] = [
        'item_id' => (int)$item['item_id'],
        'item_code' => $item['item_code'],
        'code' => $item['item_code'],
        'product_code' => $item['item_code'],
        'item_name' => $item['item_name'],
        'product_name' => $item['item_name'],
        'category' => $item['category'],
        'volume' => $item['volume'],
        'oil_type' => $item['oil_type'],
        'uom' => $item['uom'],
        'quantity' => (float)$item['quantity'],
        'qty' => (float)$item['quantity'],
        'ordered_qty' => (float)$item['ordered_qty'],
        'delivered_qty' => (float)$item['delivered_qty'],
        'unit_price' => (float)$item['unit_price'],
        'price' => (float)$item['unit_price'],
        'line_amount' => (float)$item['line_amount']
    ];
}

$orders = [];
foreach ($orderRows as $order) {
    $soId = (int)$order['so_id'];
    $invoiceAmount = (float)$order['invoice_amount'];
    $paymentAmount = (float)$order['payment_amount'];
    $paymentStatus = strtolower(trim((string)$order['payment_status']));
    $paymentMethod = trim((string)$order['payment_method']);
    $balance = (float)$order['balance'];

    if ($paymentAmount <= 0 && ($paymentStatus === '' || $paymentStatus === 'unpaid' || $paymentStatus === 'not paid')) {
        $paymentMethod = 'Unpaid';
        $balance = $invoiceAmount;
    } elseif ($paymentMethod === '') {
        $paymentMethod = $paymentAmount > 0 ? 'Paid' : 'Unpaid';
    }

    $orders[] = [
        'so_id' => $soId,
        'so_number' => $order['so_number'],
        'si_number' => $order['si_number'],
        'document_type' => $order['document_type'],
        'customer_name' => $order['customer_name'],
        'driver_name' => $order['driver_name'],
        'plate_number' => $order['plate_number'],
        'invoice_amount' => $invoiceAmount,
        'order_amount' => $invoiceAmount,
        'total_amount' => (float)$order['total_amount'],
        'payment_amount' => $paymentAmount,
        'paid_amount' => $paymentAmount,
        'cash_payment' => (float)$order['cash_payment'],
        'cheque_payment' => (float)$order['cheque_payment'],
        'online_payment' => (float)$order['online_payment'],
        'balance' => $balance,
        'remaining_balance' => $balance,
        'payment_method' => $paymentMethod,
        'payment_status' => $order['payment_status'],
        'payment_reference' => $order['payment_reference'],
        'reference_number' => $order['payment_reference'],
        'fulfillment_type' => $order['fulfillment_type'],
        'delivery_type' => $order['fulfillment_type'],
        'order_status' => $order['order_status'],
        'created_at' => $order['created_at'],
        'order_date' => $order['order_date'],
        'items' => $itemsByOrder[$soId] ?? []
    ];
}

jsonResponse(true, '', [
    'orders' => $orders,
    'report_category' => $reportCategory,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
]);
