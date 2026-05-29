<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment data']);
    exit;
}

$so_id = (int)($data['so_id'] ?? 0);
$payment_method = $data['payment_method'] ?? '';
$amount = (float)($data['amount'] ?? 0);
$payment_date = date('Y-m-d H:i:s');

if (!$so_id || !$payment_method || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing required payment fields']);
    exit;
}

// Update overdue invoices first so old pending invoices become overdue automatically
$conn->query("UPDATE invoices
            SET status = 'overdue'
            WHERE due_date < CURDATE()
              AND (status IS NULL OR TRIM(status) = '' OR status = 'pending')");

// Get invoice and customer details together with order delivery status
$invoice_query = "SELECT i.invoice_id, i.customer_id, i.total_amount, i.status, COALESCE(so.order_status, '') AS order_status
                  FROM invoices i
                  LEFT JOIN sales_orders so ON i.so_id = so.so_id
                  WHERE i.so_id = ?";
$stmt = $conn->prepare($invoice_query);
$stmt->bind_param("i", $so_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    echo json_encode(['success' => false, 'message' => 'Invoice not found for this order']);
    exit;
}

if ($invoice['status'] === 'paid') {
    echo json_encode(['success' => false, 'message' => 'Invoice already paid']);
    exit;
}

if (($invoice['order_status'] ?? '') !== 'delivered') {
    echo json_encode(['success' => false, 'message' => 'Payment can only be collected after the order has been delivered']);
    exit;
}

$invoice_total = (float)$invoice['total_amount'];

if ($amount < $invoice_total) {
    echo json_encode(['success' => false, 'message' => 'Payment amount must be at least the invoice total']);
    exit;
}

$conn->begin_transaction();

// Prepare payment details
$reference_number = null;
$check_date = null;
$bank_name = null;
$bank_branch = null;
$check_number = null;
$cash_tendered = null;
$cash_change = null;

if ($payment_method === 'cash') {
    $cash_tendered = (float)($data['cash_tendered'] ?? 0);
    if ($cash_tendered < $invoice_total) {
        echo json_encode(['success' => false, 'message' => 'Tendered amount is less than invoice amount']);
        exit;
    }
    $cash_change = $cash_tendered - $invoice_total;
    $amount = $invoice_total;
} elseif ($payment_method === 'check') {
    $check_date = $data['check_date'] ?? '';
    $bank_name = $data['bank_name'] ?? '';
    $bank_branch = $data['bank_branch'] ?? '';
    $check_number = $data['check_number'] ?? '';
    $reference_number = $check_number;
    if (!$check_date || !$bank_name || !$bank_branch || !$check_number) {
        echo json_encode(['success' => false, 'message' => 'All check details are required']);
        exit;
    }
} elseif ($payment_method === 'online_transfer') {
    $reference_number = trim($data['reference_number'] ?? '');
    $bank_wallet_id = (int)($data['bank_wallet_id'] ?? 0);
    if (!$reference_number || $bank_wallet_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Reference number and online transfer sub account are required']);
        exit;
    }

    $online_stmt = $conn->prepare("SELECT b.bank_name, COALESCE(b.bank_branch, '') AS bank_branch, COALESCE(pb.bank_name, '') AS parent_bank_name
                                  FROM banks b
                                  LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
                                  INNER JOIN bank_payment_methods bpm ON bpm.bank_id = b.bank_id AND bpm.payment_method = 'online_transfer'
                                  WHERE b.bank_id = ? AND b.status = 'active' AND b.parent_bank_id IS NOT NULL LIMIT 1");
    if (!$online_stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to validate online transfer account']);
        exit;
    }
    $online_stmt->bind_param('i', $bank_wallet_id);
    $online_stmt->execute();
    $online_bank = $online_stmt->get_result()->fetch_assoc();
    $online_stmt->close();

    if (!$online_bank) {
        echo json_encode(['success' => false, 'message' => 'Please select a registered online transfer sub account']);
        exit;
    }

    $bank_name = trim(($online_bank['parent_bank_name'] ? $online_bank['parent_bank_name'] . ' / ' : '') . $online_bank['bank_name']);
    $bank_branch = trim($online_bank['bank_branch'] ?? '');
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
    exit;
}

// Insert payment
$insert_payment = "INSERT INTO payments
                   (invoice_id, customer_id, payment_method, amount, payment_date,
                    reference_number, check_date, bank_name, bank_branch, check_number,
                    cash_tendered, cash_change, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($insert_payment);
$stmt->bind_param(
    "iisdssssssddi",
    $invoice['invoice_id'],
    $invoice['customer_id'],
    $payment_method,
    $amount,
    $payment_date,
    $reference_number,
    $check_date,
    $bank_name,
    $bank_branch,
    $check_number,
    $cash_tendered,
    $cash_change,
    $user_id
);

if (!$stmt->execute()) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to record payment: ' . $stmt->error]);
    exit;
}
$stmt->close();

// Update invoice status to paid
$update_invoice = "UPDATE invoices SET status = 'paid' WHERE invoice_id = ?";
$stmt = $conn->prepare($update_invoice);
$stmt->bind_param("i", $invoice['invoice_id']);
if (!$stmt->execute()) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to update invoice']);
    exit;
}
$stmt->close();

// Recalculate customer credit used (total unpaid invoices)
function recalcCustomerCreditUsed($conn, $customer_id) {
    $sql = "SELECT COALESCE(SUM(total_amount), 0) AS total_unpaid
            FROM invoices
            WHERE customer_id = ?
            AND (
                status IS NULL
                OR TRIM(status) = ''
                OR status IN ('pending', 'overdue')
            )";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $unpaid = floatval($row['total_unpaid'] ?? 0);
    $stmt->close();

    $update = "UPDATE customers SET credit_used = ? WHERE customer_id = ?";
    $upd_stmt = $conn->prepare($update);
    $upd_stmt->bind_param("di", $unpaid, $customer_id);
    $upd_stmt->execute();
    $upd_stmt->close();
    return $unpaid;
}
recalcCustomerCreditUsed($conn, $invoice['customer_id']);

$conn->commit();

echo json_encode(['success' => true, 'message' => 'Payment recorded successfully']);