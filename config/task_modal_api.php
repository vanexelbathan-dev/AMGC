<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

try {
    require_once __DIR__ . '/database.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection was not found.');
    }

    $user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['employee_id'] ?? 0);
    if ($user_id <= 0) {
        throw new Exception('Session expired. Please log in again.');
    }

    function tm_table_exists(mysqli $conn, string $table): bool {
        $safe = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }

    if (!tm_table_exists($conn, 'user_tasks') || !tm_table_exists($conn, 'user_task_assignees')) {
        throw new Exception('Task tables are not installed. Please import the SQL file first.');
    }

    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

    if (in_array($action, ['view_attachment', 'download_attachment'], true)) {
        if (!tm_table_exists($conn, 'user_task_attachments')) {
            throw new Exception('Task attachment table is not installed.');
        }

        $attachment_id = (int)($_GET['attachment_id'] ?? $_POST['attachment_id'] ?? 0);
        if ($attachment_id <= 0) throw new Exception('Invalid attachment.');

        $stmt = $conn->prepare("SELECT att.original_name, att.stored_path, att.mime_type, att.file_size
            FROM user_task_attachments att
            INNER JOIN user_task_assignees a ON a.task_id = att.task_id
            WHERE att.attachment_id = ? AND a.user_id = ?
            LIMIT 1");
        if (!$stmt) throw new Exception('Unable to prepare attachment request.');
        $stmt->bind_param('ii', $attachment_id, $user_id);
        $stmt->execute();
        $attachment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$attachment) throw new Exception('Attachment was not found or access is denied.');

        $relativePath = str_replace(['\\', '../'], ['/', ''], (string)$attachment['stored_path']);
        $relativePath = ltrim($relativePath, '/');
        $projectRoot = dirname(__DIR__);
        $absolutePath = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        $allowedRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'task_attachments');
        if (!$absolutePath || !$allowedRoot || !is_file($absolutePath) || strpos($absolutePath, $allowedRoot . DIRECTORY_SEPARATOR) !== 0) {
            throw new Exception('Attachment file is unavailable.');
        }

        $originalName = basename((string)$attachment['original_name']);
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $originalName) ?: 'attachment';
        $mime = trim((string)($attachment['mime_type'] ?? '')) ?: 'application/octet-stream';
        $disposition = $action === 'download_attachment' ? 'attachment' : 'inline';

        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($absolutePath));
        header("Content-Disposition: {$disposition}; filename=\"{$safeName}\"; filename*=UTF-8''" . rawurlencode($originalName));
        header('Cache-Control: private, max-age=300');
        readfile($absolutePath);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'mark_seen') {
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_status') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        $status = (string)($_POST['status'] ?? 'pending');
        $note = trim((string)($_POST['assignee_note'] ?? ''));
        $allowed = ['pending','in_progress','completed','cancelled'];
        if ($task_id <= 0 || !in_array($status, $allowed, true)) {
            throw new Exception('Invalid task status request.');
        }

        $stmt = $conn->prepare("UPDATE user_task_assignees
            SET assignee_status = ?, assignee_note = ?, notify_seen = 1, seen_at = COALESCE(seen_at, NOW())
            WHERE task_id = ? AND user_id = ?");
        $stmt->bind_param('ssii', $status, $note, $task_id, $user_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            throw new Exception('Task was not found for this account.');
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS total,
                SUM(CASE WHEN assignee_status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN assignee_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                SUM(CASE WHEN assignee_status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count
            FROM user_task_assignees WHERE task_id = ?");
        $stmt->bind_param('i', $task_id);
        $stmt->execute();
        $agg = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $total = (int)($agg['total'] ?? 0);
        $completed = (int)($agg['completed_count'] ?? 0);
        $cancelled = (int)($agg['cancelled_count'] ?? 0);
        $inProgress = (int)($agg['in_progress_count'] ?? 0);
        $taskStatus = 'pending';
        if ($total > 0 && $completed === $total) {
            $taskStatus = 'completed';
        } elseif ($total > 0 && $cancelled === $total) {
            $taskStatus = 'cancelled';
        } elseif ($inProgress > 0 || $completed > 0) {
            $taskStatus = 'in_progress';
        }
        $stmt = $conn->prepare("UPDATE user_tasks SET status = ? WHERE task_id = ?");
        $stmt->bind_param('si', $taskStatus, $task_id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Task status updated successfully.']);
        exit;
    }

    throw new Exception('Invalid task action.');
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
