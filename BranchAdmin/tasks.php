<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (function_exists('requireLogin')) {
    requireLogin();
}

$current_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$allowed_roles = ['branch_admin', 'admin', 'super_duper_admin', 'sales', 'warehouse', 'warehouseman', 'delivery', 'motorpool', 'it'];
if (!in_array($current_role, $allowed_roles, true)) {
    header('Location: ../POS/posdashboard.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = isset($_SESSION['first_name']) ? trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '')) : 'User';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = !empty($_SESSION['view_all_branches']) || in_array($current_role, ['admin', 'super_duper_admin'], true);

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1));
}
if ($user_initials === '') $user_initials = 'U';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function taskCalendarFormatDuration(int $minutes): string
{
    return number_format(max(0, $minutes) / 60, 2, '.', '');
}
function getTaskCalendarPhilippineHolidayInfo(string $date): array
{
    $ts = strtotime($date);
    if ($ts === false) return ['type' => 'regular', 'name' => ''];

    $year = (int)date('Y', $ts);
    $md = date('m-d', $ts);
    $fixed = [
        '01-01' => ['type' => 'regular_holiday', 'name' => "New Year's Day"],
        '02-25' => ['type' => 'special_non_working', 'name' => 'EDSA People Power Revolution Anniversary'],
        '04-09' => ['type' => 'regular_holiday', 'name' => 'Araw ng Kagitingan'],
        '05-01' => ['type' => 'regular_holiday', 'name' => 'Labor Day'],
        '06-12' => ['type' => 'regular_holiday', 'name' => 'Independence Day'],
        '08-21' => ['type' => 'special_non_working', 'name' => 'Ninoy Aquino Day'],
        '11-01' => ['type' => 'special_non_working', 'name' => "All Saints' Day"],
        '11-30' => ['type' => 'regular_holiday', 'name' => 'Bonifacio Day'],
        '12-08' => ['type' => 'special_non_working', 'name' => 'Feast of Immaculate Conception of Mary'],
        '12-24' => ['type' => 'special_non_working', 'name' => 'Additional Special Non-Working Day'],
        '12-25' => ['type' => 'regular_holiday', 'name' => 'Christmas Day'],
        '12-30' => ['type' => 'regular_holiday', 'name' => 'Rizal Day'],
        '12-31' => ['type' => 'special_non_working', 'name' => 'Last Day of the Year']
    ];
    if (isset($fixed[$md])) return $fixed[$md];

    if ($date === date('Y-m-d', strtotime('last monday of august ' . $year))) {
        return ['type' => 'regular_holiday', 'name' => 'National Heroes Day'];
    }

    $easterTs = easter_date($year);
    $holyWeek = [
        date('Y-m-d', strtotime('-3 days', $easterTs)) => ['type' => 'regular_holiday', 'name' => 'Maundy Thursday'],
        date('Y-m-d', strtotime('-2 days', $easterTs)) => ['type' => 'regular_holiday', 'name' => 'Good Friday'],
        date('Y-m-d', strtotime('-1 day', $easterTs)) => ['type' => 'special_non_working', 'name' => 'Black Saturday']
    ];
    if (isset($holyWeek[$date])) return $holyWeek[$date];

    $movable = [
        2024 => [
            '2024-02-10' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2024-04-10' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2024-06-17' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2025 => [
            '2025-01-29' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2025-03-31' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2025-06-06' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2026 => [
            '2026-02-17' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2026-03-20' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2026-05-27' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2027 => [
            '2027-02-06' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2027-03-10' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2027-05-17' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2028 => [
            '2028-01-26' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2028-02-27' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2028-05-05' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2029 => [
            '2029-02-13' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2029-02-15' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2029-04-24' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2030 => [
            '2030-02-03' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2030-02-05' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2030-04-14' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ]
    ];
    return $movable[$year][$date] ?? ['type' => 'regular', 'name' => ''];
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}
function columnExists(mysqli $conn, string $table, string $col): bool
{
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $safeCol = $conn->real_escape_string($col);
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeCol'");
    return $res && $res->num_rows > 0;
}
function addColumnIfMissing(mysqli $conn, string $table, string $col, string $definition): void
{
    if (!columnExists($conn, $table, $col)) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
    }
}
function ensureTaskTables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS user_tasks (
        task_id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NULL,
        created_by INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        task_date DATE NOT NULL,
        task_time TIME NOT NULL,
        due_datetime DATETIME NOT NULL,
        reminder_days INT NOT NULL DEFAULT 1,
        status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
        priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tasks_branch_due (branch_id, due_datetime),
        INDEX idx_tasks_status (status),
        INDEX idx_tasks_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS user_task_assignees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        notify_seen TINYINT(1) NOT NULL DEFAULT 0,
        seen_at DATETIME NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_task_user (task_id, user_id),
        INDEX idx_assignee_user (user_id),
        CONSTRAINT fk_task_assignee_task FOREIGN KEY (task_id) REFERENCES user_tasks(task_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    addColumnIfMissing($conn, 'user_tasks', 'priority', "ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER status");
    addColumnIfMissing($conn, 'user_tasks', 'is_recurring', "TINYINT(1) NOT NULL DEFAULT 0 AFTER priority");
    addColumnIfMissing($conn, 'user_tasks', 'recurrence_interval', "INT NULL AFTER is_recurring");
    addColumnIfMissing($conn, 'user_tasks', 'recurrence_unit', "ENUM('day','week','month','year') NULL AFTER recurrence_interval");
    addColumnIfMissing($conn, 'user_tasks', 'recurrence_until', "DATE NULL AFTER recurrence_unit");
    addColumnIfMissing($conn, 'user_tasks', 'recurrence_group', "VARCHAR(64) NULL AFTER recurrence_until");
    addColumnIfMissing($conn, 'user_task_assignees', 'notify_seen', "TINYINT(1) NOT NULL DEFAULT 0 AFTER user_id");
    addColumnIfMissing($conn, 'user_task_assignees', 'seen_at', "DATETIME NULL AFTER notify_seen");
    addColumnIfMissing($conn, 'user_task_assignees', 'assignee_status', "ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending' AFTER seen_at");
    addColumnIfMissing($conn, 'user_task_assignees', 'assignee_note', "TEXT NULL AFTER assignee_status");
    addColumnIfMissing($conn, 'user_task_assignees', 'updated_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER assignee_note");
    $conn->query("CREATE TABLE IF NOT EXISTS user_task_attachments (
        attachment_id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_path VARCHAR(500) NOT NULL,
        mime_type VARCHAR(120) NULL,
        file_size BIGINT NOT NULL DEFAULT 0,
        uploaded_by INT NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_task_attachment_task (task_id),
        CONSTRAINT fk_task_attachment_task FOREIGN KEY (task_id) REFERENCES user_tasks(task_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS branch_calendar_holidays (
        holiday_id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NULL,
        holiday_date DATE NOT NULL,
        holiday_name VARCHAR(180) NOT NULL,
        holiday_type ENUM('regular_holiday','special_non_working') NOT NULL DEFAULT 'special_non_working',
        location_name VARCHAR(180) NULL,
        notes TEXT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_branch_holiday_date_name (branch_id, holiday_date, holiday_name),
        INDEX idx_branch_holiday_date (branch_id, holiday_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
ensureTaskTables($conn);

function collectTaskAttachmentUploads(array $files, array &$errors): array
{
    $saved = [];
    if (empty($files['name']) || !is_array($files['name'])) return $saved;

    $allowedExtensions = ['pdf','doc','docx','xls','xlsx','csv','txt','jpg','jpeg','png','webp'];
    $maxBytes = 10 * 1024 * 1024;
    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'task_attachments';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        $errors[] = 'Unable to create the task attachment folder.';
        return [];
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    foreach ($files['name'] as $i => $originalName) {
        $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed for ' . basename((string)$originalName) . '.';
            continue;
        }
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        $size = (int)($files['size'][$i] ?? 0);
        $cleanOriginal = basename((string)$originalName);
        $ext = strtolower(pathinfo($cleanOriginal, PATHINFO_EXTENSION));
        if ($cleanOriginal === '' || !in_array($ext, $allowedExtensions, true)) {
            $errors[] = 'Unsupported attachment type: ' . ($cleanOriginal ?: 'unknown file') . '.';
            continue;
        }
        if ($size <= 0 || $size > $maxBytes) {
            $errors[] = $cleanOriginal . ' must be 10 MB or smaller.';
            continue;
        }
        if (!is_uploaded_file($tmp)) {
            $errors[] = 'Invalid uploaded file: ' . $cleanOriginal . '.';
            continue;
        }
        $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destination = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($tmp, $destination)) {
            $errors[] = 'Unable to save ' . $cleanOriginal . '.';
            continue;
        }
        $mime = $finfo ? (string)finfo_file($finfo, $destination) : (string)($files['type'][$i] ?? 'application/octet-stream');
        $saved[] = [
            'original_name' => $cleanOriginal,
            'stored_path' => '../uploads/task_attachments/' . $storedName,
            'absolute_path' => $destination,
            'mime_type' => $mime ?: 'application/octet-stream',
            'file_size' => $size
        ];
    }
    if ($finfo) finfo_close($finfo);
    return $saved;
}

function attachFilesToTask(mysqli $conn, int $taskId, int $userId, array $files): void
{
    if ($taskId <= 0 || empty($files)) return;
    $stmt = $conn->prepare("INSERT INTO user_task_attachments (task_id, original_name, stored_path, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) return;
    foreach ($files as $file) {
        $name = (string)$file['original_name'];
        $path = (string)$file['stored_path'];
        $mime = (string)$file['mime_type'];
        $size = (int)$file['file_size'];
        $stmt->bind_param('isssii', $taskId, $name, $path, $mime, $size, $userId);
        $stmt->execute();
    }
    $stmt->close();
}

function currentUserCanAccessTask(mysqli $conn, int $taskId, int $userId, int $branchId, bool $viewAll): bool
{
    $sql = "SELECT t.task_id FROM user_tasks t LEFT JOIN user_task_assignees a ON a.task_id=t.task_id AND a.user_id=? WHERE t.task_id=? AND (t.created_by=? OR a.user_id=?" . ($viewAll ? " OR 1=1" : " OR t.branch_id=?") . ") LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if ($viewAll) $stmt->bind_param('iiii', $userId, $taskId, $userId, $userId);
    else $stmt->bind_param('iiiii', $userId, $taskId, $userId, $userId, $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

$toast = ['type' => '', 'msg' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_task' || $action === 'update_task') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $task_date = trim($_POST['task_date'] ?? '');
        $task_time = trim($_POST['task_time'] ?? '');
        $reminder_days = max(0, (int)($_POST['reminder_days'] ?? 1));
        $priority = $_POST['priority'] ?? 'normal';
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) $priority = 'normal';
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $recurrence_interval = max(1, (int)($_POST['recurrence_interval'] ?? 1));
        $recurrence_unit = $_POST['recurrence_unit'] ?? 'month';
        if (!in_array($recurrence_unit, ['day', 'week', 'month', 'year'], true)) $recurrence_unit = 'month';
        $recurrence_until = trim($_POST['recurrence_until'] ?? '');
        if ($is_recurring && ($recurrence_until === '' || $recurrence_until < $task_date)) {
            $recurrence_until = $task_date;
        }
        $assignees = array_values(array_unique(array_filter(array_map('intval', $_POST['assignees'] ?? []))));
        $attachmentErrors = [];
        $uploadedAttachments = collectTaskAttachmentUploads($_FILES['task_attachments'] ?? [], $attachmentErrors);
        if (!empty($attachmentErrors)) {
            foreach ($uploadedAttachments as $uploadedAttachment) @unlink((string)($uploadedAttachment['absolute_path'] ?? ''));
            $toast = ['type' => 'error', 'msg' => implode(' ', $attachmentErrors)];
        } elseif ($title === '' || $task_date === '' || $task_time === '') {
            $toast = ['type' => 'error', 'msg' => 'Please complete the title, date, and time.'];
        } elseif (empty($assignees)) {
            $toast = ['type' => 'error', 'msg' => 'Please select at least one assigned user.'];
        } else {
            $due = $task_date . ' ' . $task_time . ':00';
            if ($action === 'add_task') {
                $created_count = 0;
                $recurrence_group = $is_recurring ? ('REC-' . date('YmdHis') . '-' . mt_rand(1000, 9999)) : null;
                $dateObj = new DateTime($task_date);
                $untilObj = new DateTime($is_recurring ? $recurrence_until : $task_date);
                $intervalSpec = 'P' . $recurrence_interval . strtoupper(substr($recurrence_unit, 0, 1));
                if ($recurrence_unit === 'day') $intervalSpec = 'P' . $recurrence_interval . 'D';
                if ($recurrence_unit === 'week') $intervalSpec = 'P' . ($recurrence_interval * 7) . 'D';
                if ($recurrence_unit === 'month') $intervalSpec = 'P' . $recurrence_interval . 'M';
                if ($recurrence_unit === 'year') $intervalSpec = 'P' . $recurrence_interval . 'Y';
                while ($dateObj <= $untilObj) {
                    $loop_date = $dateObj->format('Y-m-d');
                    $loop_due = $loop_date . ' ' . $task_time . ':00';
                    $stmt = $conn->prepare("INSERT INTO user_tasks (branch_id, created_by, title, description, task_date, task_time, due_datetime, reminder_days, priority, is_recurring, recurrence_interval, recurrence_unit, recurrence_until, recurrence_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('iisssssisiisss', $branch_id, $user_id, $title, $description, $loop_date, $task_time, $loop_due, $reminder_days, $priority, $is_recurring, $recurrence_interval, $recurrence_unit, $recurrence_until, $recurrence_group);
                    if ($stmt->execute()) {
                        $new_task_id = $stmt->insert_id;
                        if ($created_count === 0) $task_id = $new_task_id;
                        $ins = $conn->prepare("INSERT IGNORE INTO user_task_assignees (task_id, user_id) VALUES (?, ?)");
                        foreach ($assignees as $aid) {
                            $ins->bind_param('ii', $new_task_id, $aid);
                            $ins->execute();
                        }
                        $ins->close();
                        attachFilesToTask($conn, $new_task_id, $user_id, $uploadedAttachments);
                        $created_count++;
                    }
                    $stmt->close();
                    if (!$is_recurring) break;
                    $dateObj->add(new DateInterval($intervalSpec));
                    if ($created_count > 370) break;
                }
                $toast = ['type' => 'success', 'msg' => $is_recurring ? ("Recurring tasks created successfully ({$created_count} schedules).") : 'Task added successfully.'];
            } else {
                if (!currentUserCanAccessTask($conn, $task_id, $user_id, $branch_id, $view_all_branches)) {
                    $toast = ['type' => 'error', 'msg' => 'You do not have access to this task.'];
                } else {
                    $stmt = $conn->prepare("UPDATE user_tasks SET title=?, description=?, task_date=?, task_time=?, due_datetime=?, reminder_days=?, priority=?, is_recurring=?, recurrence_interval=?, recurrence_unit=?, recurrence_until=? WHERE task_id=?");
                    $stmt->bind_param('sssssisiissi', $title, $description, $task_date, $task_time, $due, $reminder_days, $priority, $is_recurring, $recurrence_interval, $recurrence_unit, $recurrence_until, $task_id);
                    $stmt->execute();
                    $stmt->close();
                    $conn->query("DELETE FROM user_task_assignees WHERE task_id=" . (int)$task_id);
                    $toast = ['type' => 'success', 'msg' => 'Task updated successfully.'];
                }
            }
            if ($task_id > 0 && $toast['type'] !== 'error' && $action === 'update_task') {
                $ins = $conn->prepare("INSERT IGNORE INTO user_task_assignees (task_id, user_id) VALUES (?, ?)");
                foreach ($assignees as $aid) {
                    $ins->bind_param('ii', $task_id, $aid);
                    $ins->execute();
                }
                $ins->close();
                attachFilesToTask($conn, $task_id, $user_id, $uploadedAttachments);
            }
        }
    } elseif ($action === 'status') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        $new_status = $_POST['assignee_status'] ?? '';
        if (!in_array($new_status, ['in_progress', 'completed', 'cancelled'], true)) {
            $toast = ['type' => 'error', 'msg' => 'Invalid task status.'];
        } else {
            $stmt = $conn->prepare("UPDATE user_task_assignees SET assignee_status=?, updated_at=NOW() WHERE task_id=? AND user_id=?");
            if ($stmt) {
                $stmt->bind_param('sii', $new_status, $task_id, $user_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected > 0) {
                    $label = $new_status === 'in_progress' ? 'In Progress' : ($new_status === 'completed' ? 'Completed' : 'Cancelled');
                    $toast = ['type' => 'success', 'msg' => 'Your task status was updated to ' . $label . '.'];
                } else {
                    $toast = ['type' => 'error', 'msg' => 'You can only update tasks assigned to your account.'];
                }
            } else {
                $toast = ['type' => 'error', 'msg' => 'Unable to update task status.'];
            }
        }
    } elseif ($action === 'delete') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        if (currentUserCanAccessTask($conn, $task_id, $user_id, $branch_id, $view_all_branches)) {
            $attachmentPaths = [];
            $pathStmt = $conn->prepare("SELECT stored_path FROM user_task_attachments WHERE task_id=?");
            if ($pathStmt) {
                $pathStmt->bind_param('i', $task_id);
                $pathStmt->execute();
                $pathResult = $pathStmt->get_result();
                while ($pathResult && ($pathRow = $pathResult->fetch_assoc())) $attachmentPaths[] = (string)$pathRow['stored_path'];
                $pathStmt->close();
            }
            $stmt = $conn->prepare("DELETE FROM user_tasks WHERE task_id=?");
            $stmt->bind_param('i', $task_id);
            $stmt->execute();
            $stmt->close();
            foreach ($attachmentPaths as $storedPath) {
                $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim(str_replace('../', '', $storedPath), '/\\');
                if (is_file($absolutePath)) @unlink($absolutePath);
            }
            $toast = ['type' => 'success', 'msg' => 'Task deleted.'];
        }
    } elseif ($action === 'save_calendar_holiday') {
        $holiday_id = (int)($_POST['holiday_id'] ?? 0);
        $holiday_date = trim((string)($_POST['holiday_date'] ?? ''));
        $holiday_name = trim((string)($_POST['holiday_name'] ?? ''));
        $holiday_type = trim((string)($_POST['holiday_type'] ?? 'special_non_working'));
        $location_name = trim((string)($_POST['location_name'] ?? ''));
        $holiday_notes = trim((string)($_POST['holiday_notes'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday_date) || $holiday_name === '') {
            $toast = ['type' => 'error', 'msg' => 'Holiday date and holiday name are required.'];
        } elseif (!in_array($holiday_type, ['regular_holiday', 'special_non_working'], true)) {
            $toast = ['type' => 'error', 'msg' => 'Invalid holiday type.'];
        } else {
            $holiday_branch_id = $view_all_branches ? (int)($_POST['holiday_branch_id'] ?? $branch_id) : $branch_id;
            if ($holiday_id > 0) {
                $sqlHoliday = $view_all_branches
                    ? "UPDATE branch_calendar_holidays SET branch_id=?, holiday_date=?, holiday_name=?, holiday_type=?, location_name=?, notes=?, updated_at=NOW() WHERE holiday_id=?"
                    : "UPDATE branch_calendar_holidays SET holiday_date=?, holiday_name=?, holiday_type=?, location_name=?, notes=?, updated_at=NOW() WHERE holiday_id=? AND branch_id=?";
                $stmt = $conn->prepare($sqlHoliday);
                if ($view_all_branches) $stmt->bind_param('isssssi', $holiday_branch_id, $holiday_date, $holiday_name, $holiday_type, $location_name, $holiday_notes, $holiday_id);
                else $stmt->bind_param('sssssii', $holiday_date, $holiday_name, $holiday_type, $location_name, $holiday_notes, $holiday_id, $branch_id);
                $stmt->execute();
                $stmt->close();
                $toast = ['type' => 'success', 'msg' => 'Local holiday updated successfully.'];
            } else {
                $stmt = $conn->prepare("INSERT INTO branch_calendar_holidays (branch_id, holiday_date, holiday_name, holiday_type, location_name, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('isssssi', $holiday_branch_id, $holiday_date, $holiday_name, $holiday_type, $location_name, $holiday_notes, $user_id);
                if ($stmt->execute()) $toast = ['type' => 'success', 'msg' => 'Local holiday added successfully.'];
                else $toast = ['type' => 'error', 'msg' => 'Unable to save the local holiday. It may already exist.'];
                $stmt->close();
            }
        }
    } elseif ($action === 'delete_calendar_holiday') {
        $holiday_id = (int)($_POST['holiday_id'] ?? 0);
        if ($holiday_id > 0) {
            $sqlHoliday = $view_all_branches ? "DELETE FROM branch_calendar_holidays WHERE holiday_id=?" : "DELETE FROM branch_calendar_holidays WHERE holiday_id=? AND branch_id=?";
            $stmt = $conn->prepare($sqlHoliday);
            if ($view_all_branches) $stmt->bind_param('i', $holiday_id);
            else $stmt->bind_param('ii', $holiday_id, $branch_id);
            $stmt->execute();
            $stmt->close();
            $toast = ['type' => 'success', 'msg' => 'Local holiday deleted successfully.'];
        }
    } elseif ($action === 'seen_notifications') {
        $stmt = $conn->prepare("UPDATE user_task_assignees a JOIN user_tasks t ON t.task_id=a.task_id SET a.notify_seen=1, a.seen_at=NOW() WHERE a.user_id=? AND t.status NOT IN ('completed','cancelled') AND NOW() >= DATE_SUB(t.due_datetime, INTERVAL t.reminder_days DAY)");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($toast['type'])) {
        $_SESSION['task_flash'] = $toast;
        $redirect_url = 'tasks.php';
        if (!empty($_SERVER['QUERY_STRING'])) {
            $redirect_url .= '?' . $_SERVER['QUERY_STRING'];
        }
        header('Location: ' . $redirect_url);
        exit;
    }
    header('Location: tasks.php');
    exit;
}

if (!empty($_SESSION['task_flash']) && is_array($_SESSION['task_flash'])) {
    $toast = $_SESSION['task_flash'];
    unset($_SESSION['task_flash']);
}
$userWhere = $view_all_branches ? "status='active'" : "status='active' AND branch_id=" . (int)$branch_id;
$users = [];
$res = $conn->query("SELECT user_id, first_name, last_name, role, branch_id FROM users WHERE $userWhere ORDER BY first_name, last_name");
if ($res) while ($r = $res->fetch_assoc()) $users[] = $r;

$where = $view_all_branches ? "1=1" : "(t.branch_id=" . (int)$branch_id . " OR t.created_by=" . (int)$user_id . " OR a.user_id=" . (int)$user_id . ")";
$status_filter = $_GET['status'] ?? 'all';

/*
 * Keep the date filter empty by default so the Task Management tab shows
 * past, current, and future tasks. A date condition is applied only when
 * the user explicitly selects a date.
 */
$date_filter = trim((string)($_GET['date'] ?? ''));

$extra = '';
if (in_array($status_filter, ['pending', 'in_progress', 'completed', 'cancelled'], true)) {
    $extra .= " AND t.status='" . $conn->real_escape_string($status_filter) . "'";
}
if ($date_filter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)) {
    $extra .= " AND t.task_date='" . $conn->real_escape_string($date_filter) . "'";
}
$tasks = [];
$sql = "SELECT DISTINCT t.*, CONCAT(c.first_name,' ',c.last_name) AS creator_name,
      GROUP_CONCAT(DISTINCT CONCAT(u.first_name,' ',u.last_name) ORDER BY u.first_name SEPARATOR ', ') AS tagged_users,
      GROUP_CONCAT(DISTINCT u.user_id ORDER BY u.user_id SEPARATOR ',') AS tagged_ids,
      COUNT(DISTINCT a.id) AS assignee_total,
      SUM(CASE WHEN a.assignee_status='pending' THEN 1 ELSE 0 END) AS assignee_pending,
      SUM(CASE WHEN a.assignee_status='in_progress' THEN 1 ELSE 0 END) AS assignee_in_progress,
      SUM(CASE WHEN a.assignee_status='completed' THEN 1 ELSE 0 END) AS assignee_completed,
      SUM(CASE WHEN a.assignee_status='cancelled' THEN 1 ELSE 0 END) AS assignee_cancelled,
      MAX(CASE WHEN a.user_id=" . (int)$user_id . " THEN a.assignee_status ELSE NULL END) AS current_user_assignee_status
      FROM user_tasks t
      LEFT JOIN user_task_assignees a ON a.task_id=t.task_id
      LEFT JOIN users u ON u.user_id=a.user_id
      LEFT JOIN users c ON c.user_id=t.created_by
      WHERE $where $extra
      GROUP BY t.task_id
      ORDER BY
        CASE
            WHEN SUM(CASE WHEN a.assignee_status IN ('pending','in_progress') THEN 1 ELSE 0 END) > 0
                 AND t.due_datetime < NOW() THEN 0
            WHEN SUM(CASE WHEN a.assignee_status IN ('pending','in_progress') THEN 1 ELSE 0 END) > 0 THEN 1
            ELSE 2
        END ASC,
        CASE
            WHEN SUM(CASE WHEN a.assignee_status IN ('pending','in_progress') THEN 1 ELSE 0 END) > 0
            THEN t.due_datetime
        END ASC,
        CASE
            WHEN SUM(CASE WHEN a.assignee_status IN ('pending','in_progress') THEN 1 ELSE 0 END) = 0
            THEN t.due_datetime
        END DESC";
$res = $conn->query($sql);
if ($res) while ($r = $res->fetch_assoc()) $tasks[] = $r;

$taskDetails = [];
if (!empty($tasks)) {
    $ids = array_map('intval', array_column($tasks, 'task_id'));
    $idList = implode(',', array_filter($ids));
    if ($idList !== '') {
        $detailSql = "SELECT a.task_id, a.user_id, a.assignee_status, COALESCE(a.assignee_note,'') AS assignee_note, a.updated_at,
                     CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS user_name, COALESCE(u.role,'') AS user_role
                     FROM user_task_assignees a
                     LEFT JOIN users u ON u.user_id=a.user_id
                     WHERE a.task_id IN ($idList)
                     ORDER BY a.task_id, u.first_name, u.last_name";
        $dr = $conn->query($detailSql);
        if ($dr) {
            while ($d = $dr->fetch_assoc()) {
                $tid = (int)$d['task_id'];
                if (!isset($taskDetails[$tid])) $taskDetails[$tid] = [];
                $taskDetails[$tid][] = $d;
            }
        }
    }
}


$taskAttachments = [];
if (tableExists($conn, 'user_task_attachments') && !empty($tasks)) {
    $attachmentTaskIds = implode(',', array_map('intval', array_column($tasks, 'task_id')));
    if ($attachmentTaskIds !== '') {
        $attachmentResult = $conn->query("SELECT attachment_id, task_id, original_name, stored_path, mime_type, file_size, created_at FROM user_task_attachments WHERE task_id IN ($attachmentTaskIds) ORDER BY attachment_id ASC");
        if ($attachmentResult) {
            while ($attachmentRow = $attachmentResult->fetch_assoc()) {
                $attachmentTaskId = (int)$attachmentRow['task_id'];
                if (!isset($taskAttachments[$attachmentTaskId])) $taskAttachments[$attachmentTaskId] = [];
                $taskAttachments[$attachmentTaskId][] = $attachmentRow;
            }
        }
    }
}

$calendarDtrDetails = [];
if (tableExists($conn, 'employee_dtr') && tableExists($conn, 'employees')) {
    $dtrCalendarSql = "SELECT d.*, e.employee_name, e.contact_number, b.branch_name
                       FROM employee_dtr d
                       INNER JOIN employees e ON d.employee_id=e.employee_id
                       LEFT JOIN branches b ON d.branch_id=b.branch_id
                       WHERE " . ($view_all_branches ? "1=1" : "d.branch_id=" . (int)$branch_id) . "
                       ORDER BY d.attendance_date DESC, e.employee_name ASC, d.start_time ASC, d.dtr_id ASC
                       LIMIT 1200";
    $dtrCalendarResult = $conn->query($dtrCalendarSql);
    $dtrGrouped = [];
    if ($dtrCalendarResult) {
        while ($row = $dtrCalendarResult->fetch_assoc()) {
            $groupKey = (string)($row['employee_id'] ?? '') . '|' . (string)($row['attendance_date'] ?? '');
            if (!isset($dtrGrouped[$groupKey])) {
                $holidayDate = (string)($row['attendance_date'] ?? '');
                $holidayInfo = $holidayDate !== '' ? getTaskCalendarPhilippineHolidayInfo($holidayDate) : ['type' => 'regular', 'name' => ''];
                $storedHolidayName = trim((string)($row['holiday_name'] ?? ''));
                $storedHolidayType = trim((string)($row['holiday_type'] ?? 'regular'));
                if (($holidayInfo['type'] ?? 'regular') !== 'regular') {
                    $storedHolidayType = (string)$holidayInfo['type'];
                    $storedHolidayName = (string)$holidayInfo['name'];
                }
                $dtrGrouped[$groupKey] = [
                    'employee_id' => (int)($row['employee_id'] ?? 0),
                    'employee_name' => (string)($row['employee_name'] ?? ''),
                    'branch_name' => (string)($row['branch_name'] ?? ''),
                    'attendance_date' => $holidayDate,
                    'slots' => [],
                    'daily_total_minutes' => 0,
                    'has_pending' => false,
                    'holiday_type' => $storedHolidayType,
                    'holiday_name' => $storedHolidayName,
                    'regular_minutes' => 0,
                    'overtime_minutes' => 0,
                    'basic_pay' => 0,
                    'overtime_pay' => 0,
                    'total_pay' => 0
                ];
            }
            $hasEnd = !empty($row['end_time']) && $row['end_time'] !== '00:00:00';
            if (count($dtrGrouped[$groupKey]['slots']) < 3) {
                $dtrGrouped[$groupKey]['slots'][] = [
                    'start_time' => (string)($row['start_time'] ?? ''),
                    'end_time' => $hasEnd ? (string)$row['end_time'] : '',
                    'duration_minutes' => (int)($row['duration_minutes'] ?? 0),
                    'is_open' => !$hasEnd
                ];
            }
            $dtrGrouped[$groupKey]['daily_total_minutes'] += (int)($row['duration_minutes'] ?? 0);
            $dtrGrouped[$groupKey]['regular_minutes'] += (int)($row['regular_minutes'] ?? 0);
            $dtrGrouped[$groupKey]['overtime_minutes'] += (int)($row['overtime_minutes'] ?? 0);
            $dtrGrouped[$groupKey]['basic_pay'] += (float)($row['basic_pay'] ?? 0);
            $dtrGrouped[$groupKey]['overtime_pay'] += (float)($row['overtime_pay'] ?? 0);
            $dtrGrouped[$groupKey]['total_pay'] += (float)($row['total_pay'] ?? 0);
            if (!$hasEnd) $dtrGrouped[$groupKey]['has_pending'] = true;
        }
    }
    foreach ($dtrGrouped as $calendarDtrRow) {
        $calendarDtrRow['basic_pay'] = number_format((float)$calendarDtrRow['basic_pay'], 2);
        $calendarDtrRow['overtime_pay'] = number_format((float)$calendarDtrRow['overtime_pay'], 2);
        $calendarDtrRow['total_pay'] = number_format((float)$calendarDtrRow['total_pay'], 2);
        $calendarDtrDetails[] = $calendarDtrRow;
    }
}

$customCalendarHolidays = [];
$holidayWhere = $view_all_branches ? "1=1" : "h.branch_id=" . (int)$branch_id;
$holidaySql = "SELECT h.*, COALESCE(b.branch_name,'') AS branch_name, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS created_by_name
               FROM branch_calendar_holidays h
               LEFT JOIN branches b ON b.branch_id=h.branch_id
               LEFT JOIN users u ON u.user_id=h.created_by
               WHERE $holidayWhere
               ORDER BY h.holiday_date ASC, h.holiday_name ASC";
$holidayResult = $conn->query($holidaySql);
if ($holidayResult) while ($holidayRow = $holidayResult->fetch_assoc()) $customCalendarHolidays[] = $holidayRow;

$calendarEvents = [];
$calendarYears = [date('Y')];
foreach ($calendarDtrDetails as $row) {
    if (!empty($row['attendance_date'])) $calendarYears[] = substr((string)$row['attendance_date'], 0, 4);
}
$calendarTaskEvents = [];
$calendarSql = "SELECT t.task_id, t.title, t.description, t.task_date, t.task_time, t.due_datetime, t.priority, t.status,
                      CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) AS creator_name,
                      GROUP_CONCAT(DISTINCT CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) ORDER BY u.first_name, u.last_name SEPARATOR ', ') AS assigned_users
               FROM user_tasks t
               LEFT JOIN user_task_assignees a ON a.task_id=t.task_id
               LEFT JOIN users u ON u.user_id=a.user_id
               LEFT JOIN users c ON c.user_id=t.created_by
               WHERE $where
               GROUP BY t.task_id
               ORDER BY t.due_datetime ASC";
$calendarResult = $conn->query($calendarSql);
if ($calendarResult) {
    while ($calendarRow = $calendarResult->fetch_assoc()) {
        $taskEvent = [
            'task_id' => (int)$calendarRow['task_id'],
            'date' => (string)$calendarRow['task_date'],
            'time' => substr((string)$calendarRow['task_time'], 0, 5),
            'type' => 'task',
            'title' => (string)$calendarRow['title'],
            'description' => (string)($calendarRow['description'] ?? ''),
            'priority' => (string)($calendarRow['priority'] ?? 'normal'),
            'status' => (string)($calendarRow['status'] ?? 'pending'),
            'creator' => trim((string)($calendarRow['creator_name'] ?? '')),
            'assigned_users' => trim((string)($calendarRow['assigned_users'] ?? ''))
        ];
        $calendarTaskEvents[] = $taskEvent;
        $calendarEvents[] = $taskEvent;
        if (!empty($taskEvent['date'])) $calendarYears[] = substr($taskEvent['date'], 0, 4);
    }
}
foreach (array_unique($calendarYears) as $calendarYear) {
    $calendarYear = (int)$calendarYear;
    if ($calendarYear <= 0) continue;
    $startTs = strtotime($calendarYear . '-01-01');
    $endTs = strtotime($calendarYear . '-12-31');
    for ($dayTs = $startTs; $dayTs <= $endTs; $dayTs += 86400) {
        $day = date('Y-m-d', $dayTs);
        $holiday = getTaskCalendarPhilippineHolidayInfo($day);
        if (($holiday['type'] ?? 'regular') !== 'regular') {
            $calendarEvents[] = [
                'date' => $day,
                'type' => 'holiday',
                'holiday_type' => $holiday['type'],
                'title' => $holiday['name']
            ];
        }
    }
}
foreach ($customCalendarHolidays as $holidayRow) {
    $calendarEvents[] = [
        'date' => (string)$holidayRow['holiday_date'],
        'type' => 'holiday',
        'holiday_type' => (string)$holidayRow['holiday_type'],
        'title' => (string)$holidayRow['holiday_name'],
        'custom' => true,
        'holiday_id' => (int)$holidayRow['holiday_id'],
        'branch_id' => (int)($holidayRow['branch_id'] ?? 0),
        'branch_name' => (string)($holidayRow['branch_name'] ?? ''),
        'location_name' => (string)($holidayRow['location_name'] ?? ''),
        'notes' => (string)($holidayRow['notes'] ?? ''),
        'created_by_name' => trim((string)($holidayRow['created_by_name'] ?? ''))
    ];
    if (!empty($holidayRow['holiday_date'])) $calendarYears[] = substr((string)$holidayRow['holiday_date'], 0, 4);
}
foreach ($calendarDtrDetails as $row) {
    $calendarEvents[] = [
        'date' => (string)($row['attendance_date'] ?? ''),
        'type' => 'attendance',
        'title' => (string)($row['employee_name'] ?? ''),
        'hours' => taskCalendarFormatDuration((int)($row['daily_total_minutes'] ?? 0)),
        'pending' => !empty($row['has_pending'])
    ];
}

$notif = [];
$stmt = $conn->prepare("SELECT t.task_id, t.title, t.due_datetime, t.reminder_days, t.status FROM user_tasks t JOIN user_task_assignees a ON a.task_id=t.task_id WHERE a.user_id=? AND t.status NOT IN ('completed','cancelled') AND NOW() >= DATE_SUB(t.due_datetime, INTERVAL t.reminder_days DAY) ORDER BY t.due_datetime ASC LIMIT 10");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$nr = $stmt->get_result();
while ($nr && $r = $nr->fetch_assoc()) $notif[] = $r;
$stmt->close();
$notif_count = count($notif);

/*
 * Accurate stat cards:
 * - Count each task only once, regardless of how many assignees it has.
 * - Derive the task status from the assignee records, which is the same
 *   status logic shown in the Task Management table.
 * - Cancelled tasks are excluded from Pending/In Progress/Completed cards,
 *   but they are still included in Total Tasks for complete monitoring.
 */
$stats = [
    'total' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0
];

$statsSql = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN derived_status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN derived_status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN derived_status = 'completed' THEN 1 ELSE 0 END) AS completed
    FROM (
        SELECT
            t.task_id,
            CASE
                WHEN COUNT(a.id) > 0
                     AND SUM(CASE WHEN a.assignee_status = 'completed' THEN 1 ELSE 0 END) = COUNT(a.id)
                    THEN 'completed'
                WHEN COUNT(a.id) > 0
                     AND SUM(CASE WHEN a.assignee_status = 'cancelled' THEN 1 ELSE 0 END) = COUNT(a.id)
                    THEN 'cancelled'
                WHEN SUM(CASE WHEN a.assignee_status = 'in_progress' THEN 1 ELSE 0 END) > 0
                     OR (
                         SUM(CASE WHEN a.assignee_status = 'completed' THEN 1 ELSE 0 END) > 0
                         AND SUM(CASE WHEN a.assignee_status IN ('pending','in_progress') THEN 1 ELSE 0 END) > 0
                     )
                    THEN 'in_progress'
                ELSE 'pending'
            END AS derived_status
        FROM user_tasks t
        LEFT JOIN user_task_assignees a ON a.task_id = t.task_id
        WHERE $where
        GROUP BY t.task_id
    ) task_status_summary
";

$statsResult = $conn->query($statsSql);
if ($statsResult && ($statsRow = $statsResult->fetch_assoc())) {
    $stats['total'] = (int)($statsRow['total'] ?? 0);
    $stats['pending'] = (int)($statsRow['pending'] ?? 0);
    $stats['in_progress'] = (int)($statsRow['in_progress'] ?? 0);
    $stats['completed'] = (int)($statsRow['completed'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tasks - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <style>
        /* Tasks page custom styles aligned with existing Branch Admin UI */
    

        #appPage {
            min-height: 100vh;
        }

        .task-sidebar-badge {
            margin-left: auto;
            background: #ef4444;
            color: #fff;
            border-radius: 999px;
            font-size: .7rem;
            padding: 2px 7px;
            font-weight: 800;
        }

        .sidebar.collapsed .task-sidebar-badge {
            position: absolute;
            top: 5px;
            right: 8px;
            padding: 1px 5px;
            font-size: .65rem;
        }

        .btn-payroll-green,
        .btn-amgc {
            background: linear-gradient(135deg, #047857, #44D34E) !important;
            color: #fff !important;
            border: none !important;
            border-radius: 10px !important;
            padding: .6rem 1.2rem !important;
            font-weight: 700 !important;
            font-size: .88rem !important;
            box-shadow: 0 4px 10px rgba(4, 120, 87, .22) !important;
            transition: .2s ease !important;
        }

        .btn-payroll-green:hover,
        .btn-amgc:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(4, 120, 87, .32) !important;
            color: #fff !important;
        }

        .stat-card-row {
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
            border-radius: 10px !important;
            box-shadow: 0 4px 10px rgba(0, 0, 0, .08) !important;
            min-height: 120px !important;
            padding: .95rem 1.1rem !important;
            display: flex !important;
            align-items: flex-start !important;
            gap: .85rem !important;
            color: #fff !important;
            transition: .2s ease !important;
        }

        .stat-card:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 15px rgba(0, 0, 0, .15) !important;
        }

        .stat-card .stat-icon {
            font-size: 1.65rem !important;
            color: rgba(255, 255, 255, .85) !important;
            background: transparent !important;
            width: auto !important;
            height: auto !important;
            line-height: 1 !important;
            margin-top: .15rem;
        }

        .stat-card .stat-content {
            background: transparent !important;
            color: #fff !important;
        }

        .stat-card .stat-value {
            font-size: 1.45rem !important;
            line-height: 1.1 !important;
            color: #fff !important;
        }

        .stat-card .stat-label {
            font-size: .78rem !important;
            color: #fff !important;
            margin-top: 2px;
        }

        .dashboard-tabs {
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 0;
            gap: .5rem;
        }

        .dashboard-tabs .nav-link {
            color: #047857 !important;
            font-weight: 500 !important;
            border-radius: 10px 10px 0 0 !important;
            border: none !important;
            padding: .75rem 1.05rem !important;
            background: transparent !important;
        }

        .dashboard-tabs .nav-link.active {
            background: #047857 !important;
            color: #fff !important;
            border-color: #047857 !important;
        }

        .tab-card {
            background: #fff;
            border-radius: 0 14px 14px 14px;
            padding: 18px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
        }

        .task-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: .9rem;
            flex-wrap: wrap;
        }

        .task-filter-wrap {
            display: flex;
            align-items: center;
            gap: .65rem;
            flex-wrap: nowrap;
            min-width: 0;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: thin;
        }

        .task-filter-wrap > * {
            flex-shrink: 0;
        }

        .task-search-wrap,
        .task-date-wrap,
        .task-status-wrap {
            position: relative;
            width: auto;
            max-width: none;
        }

        .task-search-wrap {
            width: clamp(220px, 28vw, 420px);
            flex: 0 1 420px;
            min-width: 220px;
        }

        .task-date-wrap {
            width: 180px;
        }

        .task-status-wrap {
            width: 175px;
        }

        .task-filter-wrap .btn {
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }

        .task-search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            z-index: 1;
        }

        .task-search-wrap input {
            padding-left: 40px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            height: 42px;
            font-size: .88rem;
        }

        .task-date-wrap input,
        .task-status-wrap select {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            height: 42px;
            font-size: .88rem;
            min-width: 170px;
        }

        .task-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            margin-bottom: 0;
        }

        .task-table thead th {
            background-color: #f8f9fa !important;
            color: #495057 !important;
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 14px 8px;
            border-bottom: 2px solid #dee2e6;
            text-align: center;
            white-space: nowrap;
        }

        .task-table tbody td {
            padding: 12px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
            text-align: center;
            word-wrap: break-word;
        }

        .task-table tbody td:first-child {
            text-align: left;
            min-width: 260px;
        }

        .task-table tbody tr:hover {
            background: #f8f9fa;
        }

        .task-table tbody tr[data-task] {
            cursor: pointer;
        }

        .task-title {
            font-weight: 700;
            color: #052A47;
        }

        .task-desc {
            font-size: .82rem;
            color: #6b7280;
            max-width: 420px;
            white-space: normal;
        }

        .badge-soft {
            display: inline-block;
            border-radius: 20px;
            padding: 4px 10px;
            font-weight: 700;
            font-size: 11px;
            min-width: 78px;
            text-align: center;
        }

        .status-pending {
            background: #fff7ed;
            color: #c2410c
        }

        .status-in_progress {
            background: #e7f1ff;
            color: #0d6efd
        }

        .status-completed {
            background: #d4edda;
            color: #155724
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24
        }

        .priority-low {
            background: #f3f4f6;
            color: #374151
        }

        .priority-normal {
            background: #d4edda;
            color: #155724
        }

        .priority-high {
            background: #fff7ed;
            color: #c2410c
        }

        .priority-urgent {
            background: #f8d7da;
            color: #721c24
        }

        .table-btn {
            background: none;
            border: 1px solid transparent;
            padding: 6px 8px;
            border-radius: 4px;
            transition: .2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
            margin: 0 1px;
        }

        .btn-edit {
            color: #198754;
            border-color: #198754
        }

        .btn-edit:hover {
            background: #198754;
            color: #fff
        }

        .btn-view {
            color: #0d6efd;
            border-color: #0d6efd
        }

        .btn-view:hover {
            background: #0d6efd;
            color: #fff
        }

        .btn-delete {
            color: #dc3545;
            border-color: #dc3545
        }

        .btn-delete:hover {
            background: #dc3545;
            color: #fff
        }

        .modal .modal-content {
            border: none;
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
            overflow: hidden;
        }

        .modal .modal-header {
            background: linear-gradient(135deg, #047857, #059669) !important;
            color: #fff !important;
            border-bottom: none;
            padding: 1rem 1.25rem;
        }

        .modal .modal-title {
            font-weight: 800;
            color: #fff !important;
        }

        .modal .btn-close {
            filter: brightness(0) invert(1);
            opacity: .9;
        }

        .modal .modal-body {
            padding: 1.25rem;
            background: #fff;
        }

        .modal .modal-footer {
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
        }

        .form-control,
        .form-select {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            min-height: 42px;
            font-size: .9rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #44D34E;
            box-shadow: 0 0 0 .2rem rgba(68, 211, 78, .18);
        }

        .assignee-picker {
            position: relative;
        }

        .assignee-recipient-field {
            min-height: 46px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 6px 9px;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            cursor: text;
            transition: border-color .2s ease, box-shadow .2s ease;
        }

        .assignee-recipient-field:focus-within {
            border-color: #44D34E;
            box-shadow: 0 0 0 .2rem rgba(68, 211, 78, .18);
        }

        .assignee-chips {
            display: contents;
        }

        .assignee-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            max-width: 100%;
            padding: 5px 8px;
            border-radius: 999px;
            background: #e9f9ef;
            color: #047857;
            border: 1px solid rgba(4, 120, 87, .16);
            font-size: .78rem;
            font-weight: 800;
            line-height: 1;
        }

        .assignee-chip-name {
            max-width: 190px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .assignee-chip-role {
            font-size: .66rem;
            color: #5f6b76;
            font-weight: 700;
        }

        .assignee-chip-remove {
            border: 0;
            background: transparent;
            color: #047857;
            padding: 0;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            line-height: 1;
        }

        .assignee-chip-remove:hover {
            background: rgba(4, 120, 87, .12);
        }

        .assignee-search-input {
            border: 0 !important;
            outline: 0 !important;
            box-shadow: none !important;
            min-width: 160px;
            flex: 1 1 160px;
            height: 30px;
            padding: 2px 3px !important;
            font-size: .86rem;
            background: transparent !important;
        }

        .assignee-dropdown {
            position: absolute;
            z-index: 1085;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            max-height: 230px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 14px 35px rgba(15, 23, 42, .16);
            display: none;
            padding: 6px;
        }

        .assignee-dropdown.show {
            display: block;
        }

        .assignee-option {
            width: 100%;
            border: 0;
            background: #fff;
            border-radius: 9px;
            padding: 9px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            text-align: left;
            color: #1f2937;
        }

        .assignee-option:hover,
        .assignee-option.active {
            background: #eefbf6;
            color: #047857;
        }

        .assignee-option-name {
            font-size: .84rem;
            font-weight: 800;
            display: block;
        }

        .assignee-option-meta {
            font-size: .72rem;
            color: #7b8794;
            display: block;
            margin-top: 2px;
        }

        .assignee-option-check {
            color: #047857;
            font-size: 1rem;
            visibility: hidden;
        }

        .assignee-option.selected .assignee-option-check {
            visibility: visible;
        }

        .assignee-picker-help {
            font-size: .75rem;
            color: #6b7280;
            margin-top: 6px;
        }

        .task-attachment-list { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
        .task-attachment-link { display:inline-flex; align-items:center; gap:7px; padding:8px 10px; border:1px solid #dbe5ef; border-radius:10px; background:#f8fafc; color:#047857; font-size:.78rem; font-weight:700; text-decoration:none; max-width:100%; }
        .task-attachment-link:hover { background:#eefbf6; color:#047857; border-color:rgba(4,120,87,.25); }
        .task-attachment-link span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:260px; }
        .task-upload-help { font-size:.75rem; color:#6b7280; margin-top:6px; }
        .existing-task-files { margin-top:10px; padding:10px 12px; border:1px solid #e5e7eb; border-radius:12px; background:#f8fafc; display:none; }

        .empty-state-table {
            text-align: center;
            padding: 40px 20px;
            background: #fff;
            border-radius: 8px;
            color: #6b7280;
        }

        .empty-state-table i {
            font-size: 42px;
            color: #adb5bd;
            margin-bottom: 10px;
            display: block;
        }

        .mobile-menu-btn {
            display: none;
        }

        /* Calendar tab copied from Employee Attendance */
        .calendar-shell {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr);
            min-height: 720px;
            background: #fff;
            border: 1px solid #eef1f4;
            border-radius: 18px;
            overflow: hidden;
        }

        .calendar-sidebar-panel {
            background: #fff;
            border-right: 1px solid #eef1f4;
            padding: 18px 16px;
        }

        .calendar-top-controls {
            display: flex;
            gap: 8px;
            background: #fafbfc;
            border: 1px solid #eef1f4;
            border-radius: 10px;
            padding: 5px;
            margin-bottom: 22px;
        }

        .calendar-view-btn {
            flex: 1;
            border: 0;
            background: transparent;
            color: #88929d;
            border-radius: 7px;
            padding: 8px 8px;
            font-weight: 600;
            font-size: 12px;
        }

        .calendar-view-btn.active {
            background: #fff;
            color: #1f2937;
            box-shadow: 0 1px 8px rgba(15, 23, 42, .06);
        }

        .mini-calendar-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .mini-calendar-title {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
        }

        .mini-nav-btn {
            width: 30px;
            height: 30px;
            border: 1px solid #eef1f4;
            background: #fff;
            color: #07a91e;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .mini-calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
            padding-bottom: 18px;
            border-bottom: 1px solid #eef1f4;
        }

        .mini-holiday-dropdown {
            margin-top: 14px;
        }

        .mini-holiday-toggle {
            width: 100%;
            border: 1px solid #eef1f4;
            background: #ffffff;
            color: #1f2937;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(15, 23, 42, .04);
            transition: all .2s ease;
        }

        .mini-holiday-toggle:hover {
            border-color: rgba(7, 216, 38, .35);
            background: #fbfffc;
            color: #047857;
        }

        .mini-holiday-toggle[aria-expanded="true"] {
            background: linear-gradient(135deg, rgba(4, 120, 87, .08), rgba(68, 211, 78, .10));
            border-color: rgba(7, 216, 38, .35);
            color: #047857;
        }

        .mini-holiday-chevron {
            transition: transform .2s ease;
        }

        .mini-holiday-toggle[aria-expanded="true"] .mini-holiday-chevron {
            transform: rotate(180deg);
        }

        .mini-holiday-list {
            margin-top: 10px;
            border: 1px solid #eef1f4;
            border-radius: 12px;
            background: #fff;
            overflow: hidden;
        }

        .mini-holiday-item {
            display: flex;
            gap: 10px;
            padding: 10px 12px;
            border-bottom: 1px solid #f1f3f5;
            cursor: pointer;
            transition: background .2s ease;
        }

        .mini-holiday-item:last-child {
            border-bottom: 0;
        }

        .mini-holiday-item:hover {
            background: #fbfffc;
        }

        .mini-holiday-date {
            min-width: 42px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            color: #047857;
            font-weight: 700;
            line-height: 1;
        }

        .mini-holiday-date span:first-child {
            font-size: 11px;
            text-transform: uppercase;
        }

        .mini-holiday-date span:last-child {
            font-size: 15px;
        }

        .mini-holiday-info {
            min-width: 0;
            flex: 1;
        }

        .mini-holiday-name {
            font-size: 12px;
            font-weight: 700;
            color: #1f2937;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mini-holiday-type {
            display: inline-flex;
            margin-top: 4px;
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
        }

        .mini-holiday-type.regular_holiday {
            background: #fff8df;
            color: #8a5a00;
        }

        .mini-holiday-type.special_non_working {
            background: #eaf3ff;
            color: #0d6efd;
        }

        .mini-holiday-empty {
            padding: 14px 12px;
            color: #6c757d;
            font-size: 12px;
            font-weight: 700;
            text-align: center;
        }

        .mini-task-dropdown {
            margin-top: 12px;
        }

        .mini-task-time {
            display: inline-flex;
            margin-top: 4px;
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            background: #e9f9ef;
            color: #047857;
        }

        .mini-task-date {
            background: #e9f9ef;
            color: #047857;
        }

        .mini-day-name {
            color: #9aa3ad;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            padding-bottom: 4px;
        }

        .mini-date {
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            color: #1f2937;
            position: relative;
            cursor: pointer;
        }

        .mini-date.muted {
            color: #c4cbd2;
        }

        .mini-date.active {
            background: #07d826;
            color: #fff;
            box-shadow: 0 8px 18px rgba(7, 216, 38, .25);
        }

        .mini-date.has-event::after {
            content: '';
            position: absolute;
            width: 4px;
            height: 4px;
            border-radius: 50%;
            bottom: 2px;
            background: #ff9f1c;
        }

        .mini-date.active.has-event::after {
            background: #fff;
        }

        .calendar-main-panel {
            background: #fff;
            padding: 18px 20px 22px;
            min-width: 0;
        }

        .calendar-search-row {
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #eef1f4;
            padding: 0 0 18px;
            margin-bottom: 18px;
        }

        .calendar-search-box {
            position: relative;
            max-width: 380px;
            width: 100%;
        }

        .calendar-search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa3ad;
        }

        .calendar-search-box input {
            width: 100%;
            border: 0;
            outline: 0;
            background: #fff;
            padding: 10px 12px 10px 42px;
            font-size: 14px;
            color: #1f2937;
        }

        .calendar-main-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .calendar-main-title {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
            color: #1f2937;
        }

        .calendar-today-btn {
            border: 1px solid #eef1f4;
            background: #fff;
            color: #1f2937;
            border-radius: 10px;
            padding: 10px 22px;
            font-weight: 800;
            box-shadow: 0 2px 10px rgba(15, 23, 42, .04);
        }

        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 12px;
            color: #5f6b76;
            margin-bottom: 14px;
        }

        .legend-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
            background: #07d826;
        }

        .legend-dot.regular_holiday {
            background: #ff6b35;
        }

        .legend-dot.special_non_working {
            background: #4d96ff;
        }

        .legend-dot.task {
            background: #047857;
        }

        .calendar-event.task {
            background: #e9f9ef;
            color: #047857;
            border-left-color: #047857;
        }

        .attendance-calendar {
            width: 100%;
            overflow: auto;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(120px, 1fr));
            border-left: 1px solid #edf0f2;
            border-top: 1px solid #edf0f2;
            min-width: 840px;
        }

        .calendar-days {
            border-radius: 12px 12px 0 0;
            overflow: hidden;
        }

        .calendar-days div {
            background: #fff;
            padding: 14px 10px;
            text-align: center;
            font-weight: 700;
            font-size: 12px;
            color: #3f4852;
            border-right: 1px solid #edf0f2;
            border-bottom: 1px solid #edf0f2;
        }

        .calendar-cell {
            min-height: 132px;
            background: #fff;
            padding: 10px 8px;
            overflow: hidden;
            cursor: pointer;
            transition: background .15s ease, box-shadow .15s ease;
            border-right: 1px solid #edf0f2;
            border-bottom: 1px solid #edf0f2;
        }

        .calendar-cell:not(.muted):hover {
            background: #fbfffc;
            box-shadow: inset 0 0 0 2px rgba(7, 216, 38, .18);
        }

        .calendar-cell.muted {
            background: #fcfdfe;
        }

        .calendar-date-num {
            font-weight: 700;
            font-size: 13px;
            color: #1f2937;
            margin-bottom: 8px;
            text-align: center;
        }

        .calendar-cell.muted .calendar-date-num {
            color: #c4cbd2;
        }

        .calendar-cell.selected {
            outline: none;
            background: rgba(7, 216, 38, 0.06);
        }

        .calendar-cell.selected .calendar-date-num {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            margin: 0 auto 8px;
            border-radius: 50%;
            background: #07d826;
            color: #fff;
            box-shadow: 0 8px 18px rgba(7, 216, 38, .22);
        }

        .calendar-cell.today .calendar-date-num {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            margin: 0 auto 8px;
            border-radius: 50%;
            background: #07d826;
            color: #fff;
        }

        .calendar-event {
            position: relative;
            display: block;
            padding: 2px 6px 2px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            background: transparent !important;
            color: #475569;
            line-height: 1.35;
        }

        .calendar-event::before {
            content: '';
            position: absolute;
            left: 0;
            top: 2px;
            bottom: 2px;
            width: 4px;
            border-radius: 6px;
            background: #07d826;
        }

        .calendar-event.attendance.pending::before {
            background: #ffb020;
        }

        .calendar-event.holiday.regular_holiday::before {
            background: #ff6b35;
        }

        .calendar-event.holiday.special_non_working::before {
            background: #4d96ff;
        }

        .calendar-event.attendance {
            color: #334155;
        }

        .calendar-event.attendance.pending {
            color: #8a5a00;
        }

        .calendar-event.holiday.regular_holiday {
            color: #8a3b00;
        }

        .calendar-event.holiday.special_non_working {
            color: #0d4b9b;
        }

        .calendar-more {
            font-size: 11px;
            font-weight: 800;
            color: #6c757d;
            padding-left: 10px;
        }

        /* Polished Day View */
        .calendar-day-view {
            border: 1px solid #e8eef2;
            border-radius: 18px;
            background: #ffffff;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
        }

        .calendar-day-view-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 20px;
            background: linear-gradient(135deg, rgba(4, 120, 87, .08), rgba(68, 211, 78, .10));
            border-bottom: 1px solid #e5eee9;
        }

        .calendar-day-view-heading {
            min-width: 0;
        }

        .calendar-day-view-header h6 {
            margin: 0;
            color: #052A47;
            font-size: 18px;
            font-weight: 800;
        }

        .calendar-day-view-subtitle {
            margin-top: 4px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 600;
        }

        .calendar-day-view-body {
            display: grid;
            gap: 12px;
            padding: 18px;
            background: #fbfdfc;
        }

        .calendar-result-card {
            position: relative;
            display: grid;
            grid-template-columns: 48px minmax(0, 1fr) auto;
            align-items: center;
            gap: 14px;
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #ffffff;
            color: #052A47;
            text-align: left;
            cursor: pointer;
            transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
            overflow: hidden;
        }

        .calendar-result-card::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 5px;
            background: #047857;
        }

        .calendar-result-card:hover {
            transform: translateY(-2px);
            border-color: rgba(4, 120, 87, .28);
            box-shadow: 0 10px 24px rgba(15, 23, 42, .09);
        }

        .calendar-result-card.task-card::before {
            background: #047857;
        }

        .calendar-result-card.attendance-card::before {
            background: #07d826;
        }

        .calendar-result-card.attendance-card.pending::before {
            background: #ffb020;
        }

        .calendar-result-card.regular-holiday-card::before {
            background: #ff6b35;
        }

        .calendar-result-card.special-holiday-card::before {
            background: #4d96ff;
        }

        .calendar-result-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #e9f9ef;
            color: #047857;
            font-size: 19px;
        }

        .attendance-card .calendar-result-icon {
            background: #ecfdf3;
            color: #07a91e;
        }

        .attendance-card.pending .calendar-result-icon {
            background: #fff7e6;
            color: #c77700;
        }

        .regular-holiday-card .calendar-result-icon {
            background: #fff1eb;
            color: #d94d15;
        }

        .special-holiday-card .calendar-result-icon {
            background: #eaf3ff;
            color: #0d6efd;
        }

        .calendar-result-content {
            min-width: 0;
        }

        .calendar-result-title {
            color: #052A47;
            font-size: 14px;
            font-weight: 800;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .calendar-result-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 6px;
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
        }

        .calendar-result-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .calendar-result-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .25px;
            white-space: nowrap;
        }

        .calendar-result-badge.task {
            background: #e9f9ef;
            color: #047857;
        }

        .calendar-result-badge.attendance {
            background: #ecfdf3;
            color: #15803d;
        }

        .calendar-result-badge.pending {
            background: #fff7e6;
            color: #b45309;
        }

        .calendar-result-badge.regular_holiday {
            background: #fff1eb;
            color: #c2410c;
        }

        .calendar-result-badge.special_non_working {
            background: #eaf3ff;
            color: #0d6efd;
        }

        .calendar-result-action {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #64748b;
            font-size: 16px;
        }

        .calendar-result-card:hover .calendar-result-action {
            background: #e9f9ef;
            color: #047857;
        }

        .calendar-empty-state {
            padding: 52px 18px;
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            background: #ffffff;
            color: #64748b;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .calendar-day-view-header {
                align-items: flex-start;
                flex-direction: column;
                padding: 16px;
            }

            .calendar-day-view-header .calendar-today-btn {
                width: 100%;
            }

            .calendar-day-view-body {
                padding: 12px;
            }

            .calendar-result-card {
                grid-template-columns: 42px minmax(0, 1fr);
                padding: 13px 12px;
            }

            .calendar-result-action {
                display: none;
            }
        }

        .calendar-detail-section {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 12px;
            background: #fff;
        }

        .calendar-detail-section h6 {
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 10px;
        }

        .calendar-detail-table {
            width: 100%;
            border-collapse: collapse;
        }

        .calendar-detail-table th,
        .calendar-detail-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f3f5;
            font-size: 13px;
            vertical-align: top;
        }

        .calendar-detail-table th {
            color: #495057;
            width: 34%;
            font-weight: 800;
        }

        .calendar-detail-empty {
            text-align: center;
            padding: 26px 10px;
            color: #6c757d;
            font-weight: 700;
        }

        .calendar-slot-list {
            margin: 0;
            padding-left: 18px;
        }

        .calendar-slot-list li {
            margin-bottom: 4px;
        }

        /* Calendar date details table - no horizontal scrollbar */
        #calendarDateModal .modal-body,
        #calendarDateModalBody {
            overflow-x: hidden !important;
            max-width: 100% !important;
        }

        .calendar-detail-table-wrapper {
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: hidden !important;
        }

        .calendar-detail-table.calendar-day-table {
            width: 100% !important;
            max-width: 100% !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
            margin: 0 !important;
        }

        .calendar-detail-table.calendar-day-table thead th {
            background: #f8fafc !important;
            color: #6b7280 !important;
            font-size: 11px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: .35px !important;
            padding: 9px 7px !important;
            border: 1px solid #e5e7eb !important;
            white-space: normal !important;
            word-break: break-word !important;
            text-align: left !important;
        }

        .calendar-detail-table.calendar-day-table tbody td {
            padding: 9px 7px !important;
            border: 1px solid #e5e7eb !important;
            font-size: 12px !important;
            vertical-align: middle !important;
            white-space: normal !important;
            word-break: break-word !important;
            overflow-wrap: anywhere !important;
        }

        /* Keep attendance detail rows plain white to avoid confusing row highlights. */
        .calendar-detail-table.calendar-day-table tbody tr td {
            background: #ffffff !important;
        }

        .calendar-detail-table.calendar-day-table .money-cell,
        .calendar-detail-table.calendar-day-table .number-cell {
            text-align: right !important;
            font-variant-numeric: tabular-nums !important;
        }

        .calendar-detail-table.calendar-day-table .employee-cell {
            width: 18% !important;
        }

        .calendar-detail-table.calendar-day-table .branch-cell {
            width: 13% !important;
        }

        .calendar-detail-table.calendar-day-table .time-cell {
            width: 11% !important;
        }

        .calendar-detail-table.calendar-day-table .duration-cell {
            width: 9% !important;
        }

        .calendar-detail-table.calendar-day-table .holiday-cell {
            width: 14% !important;
        }

        .calendar-detail-table.calendar-day-table .money-cell {
            width: 10% !important;
        }

        /* Wider Attendance Details modal so the table does not look compressed */
        #calendarDateModal .calendar-date-wide-modal {
            width: 96vw !important;
            max-width: 1380px !important;
            margin: 1rem auto !important;
        }

        #calendarDateModal .modal-content {
            min-height: 78vh !important;
        }

        #calendarDateModal .modal-body {
            padding: 24px !important;
        }

        #calendarDateModal .modal-footer {
            padding: 18px 28px !important;
        }

        #calendarDateModal .calendar-detail-section {
            padding: 18px !important;
        }

        #calendarDateModal .calendar-detail-table-wrapper {
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: hidden !important;
        }

        #calendarDateModal .calendar-detail-table.calendar-day-table {
            width: 100% !important;
            table-layout: auto !important;
        }

        #calendarDateModal .calendar-detail-table.calendar-day-table thead th {
            padding: 12px 10px !important;
            font-size: 12px !important;
            text-align: center !important;
        }

        #calendarDateModal .calendar-detail-table.calendar-day-table tbody td {
            padding: 13px 10px !important;
            font-size: 13px !important;
            line-height: 1.35 !important;
        }

        #calendarDateModal .calendar-detail-table.calendar-day-table .employee-cell {
            width: 18% !important;
            min-width: 190px !important;
        }

        #calendarDateModal .calendar-detail-table.calendar-day-table .branch-cell {
            width: 12% !important;
            min-width: 125px !important;
        }

        #calendarDateModal .calendar-detail-table.calendar-day-table .time-cell {
            width: 8% !important;
            min-width: 90px !important;
        }

        #calendarDateModal .calendar-detail-table.calendar-day-table .duration-cell {
            width: 7% !important;
            min-width: 80px !important;
        }

        #calendarDateModal .calendar-detail-table.calendar-day-table .holiday-cell {
            width: 13% !important;
            min-width: 150px !important;
        }

        #calendarDateModal .calendar-detail-table.calendar-day-table .money-cell {
            width: 8% !important;
            min-width: 105px !important;
        }

        @media (max-width: 1200px) {
            #calendarDateModal .calendar-date-wide-modal {
                width: 98vw !important;
                max-width: 98vw !important;
            }

            #calendarDateModal .calendar-detail-table.calendar-day-table thead th,
            #calendarDateModal .calendar-detail-table.calendar-day-table tbody td {
                padding: 9px 6px !important;
                font-size: 11px !important;
            }

            #calendarDateModal .calendar-detail-table.calendar-day-table .employee-cell {
                min-width: 150px !important;
            }

            #calendarDateModal .calendar-detail-table.calendar-day-table .branch-cell,
            #calendarDateModal .calendar-detail-table.calendar-day-table .holiday-cell {
                min-width: 100px !important;
            }

            #calendarDateModal .calendar-detail-table.calendar-day-table .money-cell,
            #calendarDateModal .calendar-detail-table.calendar-day-table .time-cell,
            #calendarDateModal .calendar-detail-table.calendar-day-table .duration-cell {
                min-width: 72px !important;
            }
        }

        @media (max-width: 768px) {
            #calendarDateModal .calendar-date-wide-modal {
                width: calc(100vw - 16px) !important;
                max-width: calc(100vw - 16px) !important;
                margin: .5rem auto !important;
            }

            #calendarDateModal .modal-body {
                padding: 12px !important;
            }

            .calendar-detail-table.calendar-day-table thead th,
            .calendar-detail-table.calendar-day-table tbody td {
                padding: 7px 5px !important;

                @media(max-width:992px) {
                    .mobile-menu-btn {
                        display: flex !important;
                        align-items: center;
                        justify-content: center
                    }

                    .task-toolbar {
                        align-items: stretch
                    }

                    .task-filter-wrap {
                        width: 100%;
                        flex-wrap: nowrap;
                        overflow-x: auto;
                    }

                    .task-search-wrap {
                        width: 240px;
                        min-width: 240px;
                        flex: 0 0 240px;
                    }

                    .task-date-wrap {
                        width: 175px;
                        min-width: 175px;
                        flex: 0 0 175px;
                    }

                    .task-status-wrap {
                        width: 170px;
                        min-width: 170px;
                        flex: 0 0 170px;
                    }

                    .stat-card-row .col {
                        flex: 0 0 50%;
                        max-width: 50%;
                    }

                    .task-table thead {
                        display: none
                    }

                    .task-table tbody tr {
                        display: block;
                        margin-bottom: 12px;
                        border: 1px solid #e9ecef;
                        border-radius: 12px;
                        overflow: hidden
                    }

                    .task-table tbody td {
                        display: flex;
                        justify-content: space-between;
                        text-align: right;
                        border-bottom: 1px solid #eef2f7
                    }

                    .task-table tbody td:first-child {
                        text-align: left;
                        display: block
                    }

                    .task-table tbody td:before {
                        content: attr(data-label);
                        font-weight: 700;
                        color: #052A47;
                        margin-right: 1rem
                    }

                    .task-table tbody td:first-child:before {
                        display: none
                    }
                }

                @media(max-width:576px) {
                    .stat-card-row .col {
                        flex: 0 0 100%;
                        max-width: 100%;
                    }

                    .tab-card {
                        padding: 12px
                    }

                    .dashboard-tabs .nav-link {
                        width: 100%;
                        border-radius: 12px !important
                    }

                    .dashboard-tabs .nav-item {
                        width: 100%;
                    }
                }
    </style>
</head>

<body>
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>

                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon">

                    <span class="nav-text">Branch Admin</span>
                </h3>
            </div>

            <div class="sidebar-content">
                <div class="sidebar-menu">
                    <ul class="nav flex-column">
                        <!-- Dashboard -->
                        <li class="nav-item">
                            <a class="nav-link" href="branchdashboard.php">
                                <i class="bi bi-speedometer2"></i>
                                <span class="nav-text">Dashboard</span>
                            </a>
                        </li>

                        <!-- Vendor Dropdown -->
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
                                <i class="bi bi-building"></i>
                                <span class="nav-text">Vendor</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>

                            <div class="collapse" id="supplierMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item">
                                        <a class="nav-link" href="purchase_order.php">
                                            <i class="bi bi-file-earmark-text"></i>
                                            <span class="nav-text">Enter Bills</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="paybills.php">
                                            <i class="bi bi-currency-dollar"></i>
                                            <span class="nav-text">Pay Bills</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="supplier.php">
                                            <i class="bi bi-shop"></i>
                                            <span class="nav-text">Vendor List</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- Customer Dropdown -->
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                                <i class="bi bi-people-fill"></i>
                                <span class="nav-text">Customers</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>

                            <div class="collapse" id="customerMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item">
                                        <a class="nav-link" href="orderproduct.php">
                                            <i class="bi bi-receipt"></i>
                                            <span class="nav-text">Create Invoice</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="collections.php">
                                            <i class="bi bi-cash-stack"></i>
                                            <span class="nav-text">Receive Payment</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="customer_list.php">
                                            <i class="bi bi-person-badge"></i>
                                            <span class="nav-text">Customer List</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- Employees Dropdown -->
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'employeesMenu')">
                                <i class="bi bi-briefcase"></i>
                                <span class="nav-text">Employees</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>

                            <div class="collapse" id="employeesMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item">
                                        <a class="nav-link" href="employeelist.php">
                                            <i class="bi bi-person-badge"></i>
                                            <span class="nav-text">Employee List</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="employee.php">
                                            <i class="bi bi-clock-history"></i>
                                            <span class="nav-text">Enter Time</span>
                                        </a>
                                    </li>
                                    <!-- Tasks -->
                        <li class="nav-item">
                            <a class="nav-link active" href="tasks.php">
                                <i class="bi bi-calendar-check"></i>
                                <span class="nav-text">Tasks</span>
                                <?php if ($notif_count > 0): ?>
                                    <span class="task-sidebar-badge"><?php echo $notif_count; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                                </ul>
                            </div>
                        </li>

                        <!-- Banking Dropdown -->
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'bankingMenu')">
                                <i class="bi bi-bank2"></i>
                                <span class="nav-text">Banking</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>

                            <div class="collapse" id="bankingMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item">
                                        <a class="nav-link" href="deposit.php">
                                            <i class="bi bi-bank"></i>
                                            <span class="nav-text">Record Deposit</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="Withdrawal.php">
                                            <i class="bi bi-journal-check"></i>
                                            <span class="nav-text">Write Checks</span>
                                        </a>
                                    </li>

                                    <li class="nav-item active">
                                        <a class="nav-link" href="transferfunds.php">
                                            <i class="bi bi-arrow-left-right"></i>
                                            <span class="nav-text">Transfer Funds</span>
                                        </a>
                                    </li>

                                    <li class="nav-item" hidden>
                                        <a class="nav-link" href="bank_statement.php">
                                            <i class="bi bi-receipt"></i>
                                            <span class="nav-text">Bank Statement</span>
                                        </a>
                                    </li>

                                    <li class="nav-item" hidden>
                                        <a class="nav-link" href="expenses.php">
                                            <i class="bi bi-cash-stack"></i>
                                            <span class="nav-text">Expenses</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- Company Dropdown -->
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
                                <i class="bi bi-building"></i>
                                <span class="nav-text">Company</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>

                            <div class="collapse" id="warehouseMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item">
                                        <a class="nav-link" href="current_inventory.php">
                                            <i class="bi bi-box"></i>
                                            <span class="nav-text">Items</span>
                                        </a>
                                    </li>
                                     
                                    <li class="nav-item">
                                        <a class="nav-link" href="fixed_assets.php">
                                        <i class="bi bi-building"></i>
                                        <span class="nav-text">Fixed Assets</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="warehouses.php">
                                            <i class="bi bi-shop"></i>
                                            <span class="nav-text">Warehouses</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="chartofaccounts.php">
                                            <i class="bi bi-graph-up"></i>
                                            <span class="nav-text">Chart of Accounts</span>
                                        </a>
                                    </li>

                                    <li class="nav-item" hidden>
                                        <a class="nav-link" href="trip_tickets.php">
                                            <i class="bi bi-ticket-perforated"></i>
                                            <span class="nav-text">Trip Tickets</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="motorpool.php">
                                            <i class="bi bi-truck"></i>
                                            <span class="nav-text">Motorpool</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="central_warehouse.php">
                                            <i class="bi bi-box-seam"></i>
                                            <span class="nav-text">Central Warehouse</span>
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link" href="drivers.php">
                                            <i class="bi bi-people-fill"></i>
                                            <span class="nav-text">Users</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <!-- Accounting Dropdown -->
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'accountingMenu')">
                                <i class="bi bi-graph-up"></i>
                                <span class="nav-text">Accounting</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>

                            <div class="collapse" id="accountingMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item">
                                        <a class="nav-link" href="journal_entries.php">
                                            <i class="bi bi-journal"></i>
                                            <span class="nav-text">Journal Entries</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="batch_transaction.php">
                                            <i class="bi bi-collection"></i>
                                            <span class="nav-text">Batch Transaction</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="item_adjustment.php">
                                            <i class="bi bi-sliders"></i>
                                            <span class="nav-text">Item Adjusment</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="it_request.php">
                                <i class="bi bi-headset"></i>
                                <span class="nav-text">IT Requests</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar">
                        <?php echo htmlspecialchars($user_initials); ?>
                    </div>

                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar">
                            <?php echo htmlspecialchars($user_name); ?>
                        </span>

                        <span class="user-role-sidebar">
                            <?php echo htmlspecialchars(ucfirst($user_role)); ?>
                        </span>
                    </div>
                </div>

                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>


        <div class="main-content" id="mainContent">
            <div class="navbar-top">
                <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
                <div class="page-title">
                    <h2>Tasks</h2>
                    <p>Manage task schedules, reminders, team assignments, and status updates</p>
                </div>
            </div>

            <?php if ($notif_count > 0): ?>
                <div class="alert alert-warning d-flex justify-content-between align-items-center rounded-4 shadow-sm">
                    <div><strong><i class="bi bi-bell-fill me-1"></i><?php echo $notif_count; ?> task reminder(s):</strong> You have upcoming tasks or schedules.</div>
                    <button class="btn btn-sm btn-outline-dark" onclick="showNotifications()">View</button>
                </div>
            <?php endif; ?>

            <div class="row stat-card-row g-1 g-sm-2 mb-4">
                <div class="col">
                    <div class="stat-card total"><i class="bi bi-list-task stat-icon"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Tasks</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card pending"><i class="bi bi-hourglass-split stat-icon"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card complete"><i class="bi bi-arrow-repeat stat-icon"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card delivery"><i class="bi bi-check2-circle stat-icon"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['completed']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <ul class="nav nav-tabs dashboard-tabs mb-0" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="task-management-tab" data-bs-toggle="tab" data-bs-target="#taskManagementPane" type="button" role="tab"><i class="bi bi-calendar-check me-1"></i>Task Management</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="task-calendar-tab" data-bs-toggle="tab" data-bs-target="#taskCalendarPane" type="button" role="tab"><i class="bi bi-calendar3 me-1"></i>Calendar</button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="taskManagementPane" role="tabpanel">
                        <div class="tab-card">
                            <form class="task-toolbar" method="GET">
                                <div class="task-filter-wrap">
                                    <div class="task-search-wrap">
                                        <i class="bi bi-search"></i>
                                        <input type="text" class="form-control" id="taskSearchInput" placeholder="Search task..." oninput="filterTaskRows()">
                                    </div>
                                    <div class="task-date-wrap"><input type="date" name="date" class="form-control" value="<?php echo h($date_filter); ?>"></div>
                                    <div class="task-status-wrap"><select name="status" class="form-select">
                                            <option value="all">All Status</option><?php foreach (['pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $status_filter === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                                        </select></div>
                                    <button class="btn btn-outline-secondary rounded-3"><i class="bi bi-funnel me-1"></i>Filter</button>
                                    <a href="tasks.php" class="btn btn-outline-secondary rounded-3">Reset</a>
                                </div>
                                <button type="button" class="btn btn-payroll-green" data-bs-toggle="modal" data-bs-target="#taskModal" onclick="openAddTask()"><i class="bi bi-plus-circle me-1"></i>Add Task</button>
                            </form>
                            <div class="table-responsive">
                                <table class="task-table" id="taskTable">
                                    <thead>
                                        <tr>
                                            <th>Task</th>
                                            <th>Date & Time</th>
                                            <th>Reminder</th>
                                            <th>Tagged Users</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($tasks)): ?><tr>
                                                <td colspan="7" class="empty-state-table"><i class="bi bi-calendar-x"></i>
                                                    <h5>No Tasks Found</h5>
                                                    <p>Create a task schedule to start assigning work.</p>
                                                </td>
                                            </tr><?php endif; ?>
                                        <?php foreach ($tasks as $t): ?>
                                            <tr data-task='<?php echo h(json_encode($t)); ?>' onclick="openTaskDetails(this)">
                                                <td data-label="Task">
                                                    <div class="task-title"><?php echo h($t['title']); ?></div>
                                                    <div class="task-desc"><?php echo nl2br(h($t['description'])); ?></div>
                                                    <div class="small text-muted mt-1">Created by: <?php echo h($t['creator_name'] ?: 'N/A'); ?></div><?php if (!empty($t['is_recurring'])): ?><div class="small text-success mt-1"><i class="bi bi-arrow-repeat"></i> Every <?php echo (int)$t['recurrence_interval']; ?> <?php echo h($t['recurrence_unit']); ?>(s) until <?php echo h($t['recurrence_until']); ?></div><?php endif; ?>
                                                </td>
                                                <td data-label="Date & Time">
                                                    <strong><?php echo date('M d, Y', strtotime($t['task_date'])); ?></strong><br>
                                                    <span class="text-muted"><?php echo date('h:i A', strtotime($t['task_time'])); ?></span>
                                                    <?php
                                                    $unfinishedAssignees = (int)($t['assignee_pending'] ?? 0) + (int)($t['assignee_in_progress'] ?? 0);
                                                    $isOverdueTask = $unfinishedAssignees > 0
                                                        && !empty($t['due_datetime'])
                                                        && strtotime((string)$t['due_datetime']) < time();
                                                    ?>
                                                    <?php if ($isOverdueTask): ?>
                                                        <div class="mt-1">
                                                            <span class="badge-soft status-cancelled">
                                                                <i class="bi bi-exclamation-triangle-fill me-1"></i>Overdue
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Reminder"><?php echo (int)$t['reminder_days']; ?> day(s) prior</td>
                                                <td data-label="Tagged Users" style="max-width:260px;white-space:normal"><?php echo h($t['tagged_users'] ?: '—'); ?></td>
                                                <td data-label="Priority"><span class="badge-soft priority-<?php echo h($t['priority']); ?>"><?php echo ucfirst(h($t['priority'])); ?></span></td>
                                                <td data-label="Status">
                                                    <?php
                                                    $totalAssignees = (int)($t['assignee_total'] ?? 0);
                                                    $completedAssignees = (int)($t['assignee_completed'] ?? 0);
                                                    $progressLabel = $totalAssignees > 0 ? ($completedAssignees . '/' . $totalAssignees . ' Completed') : 'No Assignees';
                                                    $displayStatus = ($totalAssignees > 0 && $completedAssignees === $totalAssignees) ? 'completed' : ((int)($t['assignee_in_progress'] ?? 0) > 0 ? 'in_progress' : 'pending');
                                                    ?>
                                                    <span class="badge-soft status-<?php echo h($displayStatus); ?>"><?php echo h($progressLabel); ?></span>
                                                    <div class="small text-muted mt-1">Pending: <?php echo (int)($t['assignee_pending'] ?? 0); ?> | In Progress: <?php echo (int)($t['assignee_in_progress'] ?? 0); ?> | Cancelled: <?php echo (int)($t['assignee_cancelled'] ?? 0); ?></div>
                                                </td>
                                                <td data-label="Action" class="text-end task-action-cell" onclick="event.stopPropagation();">
                                                    <?php $myStatus = $t['current_user_assignee_status'] ?? null; ?>
                                                    <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                        <?php if ($myStatus && !in_array($myStatus, ['completed', 'cancelled'], true)): ?>
                                                            <?php if ($myStatus !== 'in_progress'): ?>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="action" value="status">
                                                                    <input type="hidden" name="task_id" value="<?php echo (int)$t['task_id']; ?>">
                                                                    <input type="hidden" name="assignee_status" value="in_progress">
                                                                    <button type="submit" class="table-btn btn-edit" title="Mark as In Progress"><i class="bi bi-play-circle"></i></button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="action" value="status">
                                                                <input type="hidden" name="task_id" value="<?php echo (int)$t['task_id']; ?>">
                                                                <input type="hidden" name="assignee_status" value="completed">
                                                                <button type="submit" class="table-btn btn-success" title="Mark as Completed"><i class="bi bi-check2-circle"></i></button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <button class="table-btn btn-edit" onclick="editTask(this)" title="Edit"><i class="bi bi-pencil-square"></i></button>
                                                        <button class="table-btn btn-delete" onclick="deleteTask(<?php echo (int)$t['task_id']; ?>)" title="Delete"><i class="bi bi-trash"></i></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="taskCalendarPane" role="tabpanel">
                        <div class="calendar-shell">
                            <aside class="calendar-sidebar-panel">
                                <div class="calendar-top-controls">
                                    <button type="button" class="calendar-view-btn" data-calendar-view="day" onclick="setCalendarView('day')">Day</button>
                                    <button type="button" class="calendar-view-btn" data-calendar-view="week" onclick="setCalendarView('week')">Week</button>
                                    <button type="button" class="calendar-view-btn active" data-calendar-view="month" onclick="setCalendarView('month')">Month</button>
                                </div>
                                <div class="mini-calendar-head">
                                    <div id="miniCalendarTitle" class="mini-calendar-title">Calendar</div>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="mini-nav-btn" onclick="changeCalendarMonth(-1)"><i class="bi bi-chevron-left"></i></button>
                                        <button type="button" class="mini-nav-btn" onclick="changeCalendarMonth(1)"><i class="bi bi-chevron-right"></i></button>
                                    </div>
                                </div>
                                <div id="miniAttendanceCalendar" class="mini-calendar-grid"></div>
                                <div class="mini-holiday-dropdown">
                                    <button type="button"
                                        class="mini-holiday-toggle"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#miniHolidayListCollapse"
                                        aria-expanded="false"
                                        aria-controls="miniHolidayListCollapse">
                                        <span><i class="bi bi-calendar-event me-1"></i> Holidays</span>
                                        <i class="bi bi-chevron-down mini-holiday-chevron"></i>
                                    </button>
                                    <div class="collapse" id="miniHolidayListCollapse">
                                        <div id="miniHolidayList" class="mini-holiday-list">
                                            <div class="mini-holiday-empty">No holidays this month.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mini-holiday-dropdown mini-task-dropdown">
                                    <button type="button"
                                        class="mini-holiday-toggle"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#miniTaskListCollapse"
                                        aria-expanded="false"
                                        aria-controls="miniTaskListCollapse">
                                        <span><i class="bi bi-list-check me-1"></i> Tasks</span>
                                        <i class="bi bi-chevron-down mini-holiday-chevron"></i>
                                    </button>
                                    <div class="collapse" id="miniTaskListCollapse">
                                        <div id="miniTaskList" class="mini-holiday-list">
                                            <div class="mini-holiday-empty">No tasks for the selected date.</div>
                                        </div>
                                    </div>
                                </div>
                            </aside>
                            <section class="calendar-main-panel">
                                <div class="calendar-main-header">
                                    <h5 id="calendarTitle" class="calendar-main-title">Attendance Calendar</h5>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="button" class="calendar-today-btn" onclick="openHolidayModal()"><i class="bi bi-calendar-plus me-1"></i>Add Local Holiday</button>
                                        <button type="button" class="calendar-today-btn" onclick="goToCalendarToday()">Today</button>
                                    </div>
                                </div>
                                <div class="calendar-legend">
                                    <span><i class="legend-dot attendance"></i> Attendance</span>
                                    <span><i class="legend-dot regular_holiday"></i> Regular Holiday</span>
                                    <span><i class="legend-dot special_non_working"></i> Special Non-working Day</span>
                                    <span><i class="legend-dot task"></i> Task</span>
                                </div>
                                <div class="attendance-calendar" id="attendanceCalendar"></div>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="calendarHolidayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" id="calendarHolidayForm">
                    <input type="hidden" name="action" value="save_calendar_holiday">
                    <input type="hidden" name="holiday_id" id="calendarHolidayId" value="0">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i><span id="calendarHolidayModalTitle">Add Local Holiday</span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label fw-semibold">Holiday Date <span class="text-danger">*</span></label><input type="date" class="form-control" name="holiday_date" id="calendarHolidayDate" required></div>
                            <div class="col-md-6"><label class="form-label fw-semibold">Holiday Type <span class="text-danger">*</span></label><select class="form-select" name="holiday_type" id="calendarHolidayType" required>
                                    <option value="special_non_working">Special Non-working Day</option>
                                    <option value="regular_holiday">Regular Holiday</option>
                                </select></div>
                            <div class="col-12"><label class="form-label fw-semibold">Holiday Name <span class="text-danger">*</span></label><input type="text" class="form-control" name="holiday_name" id="calendarHolidayName" maxlength="180" required placeholder="Example: City Foundation Day"></div>
                            <div class="col-md-6"><label class="form-label fw-semibold">City / Municipality</label><input type="text" class="form-control" name="location_name" id="calendarHolidayLocation" maxlength="180" placeholder="Example: Lipa City"></div>
                            <?php if ($view_all_branches): ?><div class="col-md-6"><label class="form-label fw-semibold">Branch</label><select class="form-select" name="holiday_branch_id" id="calendarHolidayBranch"><?php $br = $conn->query("SELECT branch_id,branch_name FROM branches ORDER BY branch_name");
                                                                                                                                                                                                                        if ($br) while ($b = $br->fetch_assoc()): ?><option value="<?php echo (int)$b['branch_id']; ?>"><?php echo h($b['branch_name']); ?></option><?php endwhile; ?></select></div><?php endif; ?>
                            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea class="form-control" name="holiday_notes" id="calendarHolidayNotes" rows="3" placeholder="Optional proclamation or local announcement details"></textarea>
                                <div class="form-text">This holiday will appear only in the selected branch calendar and will not affect all branches.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary action-footer-btn" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-amgc action-footer-btn"><i class="bi bi-check2-circle me-1"></i>Save Holiday</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="calendarDateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog calendar-date-wide-modal modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-check me-2"></i><span id="calendarDateModalTitle">Date Details</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="calendarDateModalBody">
                    <div class="calendar-detail-empty">No details found for this date.</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary action-footer-btn" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="taskDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-card-checklist me-2"></i>Task Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-lg-8">
                            <div class="p-3 rounded-4 border bg-light">
                                <div class="small text-muted fw-semibold mb-1">Task Title</div>
                                <h5 class="mb-2" id="detailTaskTitle">-</h5>
                                <div class="small text-muted fw-semibold mb-1">Description</div>
                                <div id="detailTaskDescription" class="text-muted">-</div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="p-3 rounded-4 border h-100">
                                <div class="small text-muted">Due Date & Time</div>
                                <div class="fw-bold mb-2" id="detailTaskDue">-</div>
                                <div class="small text-muted">Priority</div>
                                <div class="fw-bold mb-2" id="detailTaskPriority">-</div>
                                <div class="small text-muted">Created By</div>
                                <div class="fw-bold" id="detailTaskCreator">-</div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="small text-muted fw-bold mb-1">Attachments</div>
                        <div id="detailTaskAttachments" class="task-attachment-list"><span class="text-muted small">No attachments.</span></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Assigned User</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Status Note</th>
                                    <th>Last Updated</th>
                                </tr>
                            </thead>
                            <tbody id="detailAssigneeBody">
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No assignee details found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="POST" enctype="multipart/form-data" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i><span id="taskModalTitle">Add Task</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="taskAction" value="add_task"><input type="hidden" name="task_id" id="taskId">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label fw-bold">Task Title</label><input type="text" name="title" id="taskTitle" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Priority</label><select name="priority" id="taskPriority" class="form-select">
                                <option value="low">Low</option>
                                <option value="normal" selected>Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select></div>
                        <div class="col-12">
                            <label class="form-label fw-bold" for="assigneeSearchInput">Assigned Users</label>
                            <div class="assignee-picker" id="assigneePicker">
                                <div class="assignee-recipient-field" id="assigneeRecipientField" onclick="focusAssigneeSearch()">
                                    <div class="assignee-chips" id="assigneeChips"></div>
                                    <input type="text" id="assigneeSearchInput" class="assignee-search-input" placeholder="Type a name or role..." autocomplete="off" aria-label="Search users">
                                </div>
                                <div class="assignee-dropdown" id="assigneeDropdown" role="listbox"></div>
                                <div id="assigneeHiddenInputs"></div>
                            </div>
                            <div class="assignee-picker-help">You are selected by default. Search and add more users like adding email recipients.</div>
                        </div>
                        <div class="col-md-4"><label class="form-label fw-bold">Date</label><input type="date" name="task_date" id="taskDate" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Time</label><input type="time" name="task_time" id="taskTime" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Alarm / Reminder</label><select name="reminder_days" id="reminderDays" class="form-select">
                                <option value="0">On the day</option>
                                <option value="1" selected>1 day prior</option>
                                <option value="2">2 days prior</option>
                                <option value="3">3 days prior</option>
                                <option value="7">7 days prior</option>
                                <option value="14">14 days prior</option>
                                <option value="30">30 days prior</option>
                            </select></div>
                        <div class="col-12">
                            <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="is_recurring" id="isRecurring" value="1" onchange="toggleRecurringFields()"><label class="form-check-label fw-bold" for="isRecurring">Recurring task / schedule</label></div>
                        </div>
                        <div class="col-12" id="recurringFields" style="display:none">
                            <div class="row g-3 align-items-end p-3 rounded-4" style="background:#f8fafc;border:1px solid #e5e7eb">
                                <div class="col-md-3"><label class="form-label fw-bold">Every</label><input type="number" name="recurrence_interval" id="recurrenceInterval" class="form-control" min="1" value="1"></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Period</label><select name="recurrence_unit" id="recurrenceUnit" class="form-select">
                                        <option value="day">Day(s)</option>
                                        <option value="week">Week(s)</option>
                                        <option value="month" selected>Month(s)</option>
                                        <option value="year">Year(s)</option>
                                    </select></div>
                                <div class="col-md-5"><label class="form-label fw-bold">Until Date</label><input type="date" name="recurrence_until" id="recurrenceUntil" class="form-control"></div>
                                <div class="col-12 small text-muted">Example: Every 1 month until Dec 31, 2026 for recurring expense or invoice reminders.</div>
                            </div>
                        </div>
                        <div class="col-12"><label class="form-label fw-bold">Description</label><textarea name="description" id="taskDescription" class="form-control" rows="3"></textarea></div>
                        <div class="col-12">
                            <label class="form-label fw-bold" for="taskAttachments">Attachments</label>
                            <input type="file" name="task_attachments[]" id="taskAttachments" class="form-control" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.webp">
                            <div class="task-upload-help">You may attach documents, spreadsheets, text files, or images. Maximum 10 MB per file.</div>
                            <div class="existing-task-files" id="existingTaskFiles"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Cancel</button><button class="btn btn-amgc"><i class="bi bi-save me-1"></i>Save Task</button></div>
            </form>
        </div>
    </div>


    <form method="POST" id="deleteForm" style="display:none"><input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" id="deleteTaskId"></form>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const notifications = <?php echo json_encode($notif); ?>;

        function setSidebarArrowState(arrow, isOpen) {
            if (!arrow) return;
            arrow.style.transform = isOpen ? 'rotate(180deg)' : 'rotate(0deg)';
        }

        function toggleSidebarDropdown(event, id) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            const target = document.getElementById(id);
            const parentLink = event?.currentTarget || document.querySelector(`[onclick*="${id}"]`);
            const arrow = parentLink?.querySelector('.dropdown-arrow');
            const sidebar = document.getElementById('sidebar');
            if (!target) return false;

            if (sidebar && window.innerWidth > 992 && sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
                localStorage.setItem('sidebarCollapsed', 'false');
            }

            const willOpen = !target.classList.contains('show');

            document.querySelectorAll('.sidebar .collapse.show').forEach(openMenu => {
                if (openMenu.id !== id) {
                    openMenu.classList.remove('show');
                    const otherParent = document.querySelector(`[onclick*="${openMenu.id}"]`);
                    setSidebarArrowState(otherParent?.querySelector('.dropdown-arrow'), false);
                }
            });

            target.classList.toggle('show', willOpen);
            setSidebarArrowState(arrow, willOpen);
            return false;
        }

        function setActiveSidebarItem() {
            const currentPage = window.location.pathname.split('/').pop().split('?')[0];
            let activeChild = null;

            document.querySelectorAll('.sidebar .nav-link[href]').forEach(link => {
                const rawHref = link.getAttribute('href') || '';
                if (!rawHref || rawHref === '#') return;

                const hrefPage = rawHref.split('/').pop().split('?')[0];
                const isCurrent = hrefPage === currentPage;
                link.classList.toggle('active', isCurrent);
                if (isCurrent) activeChild = link;
            });

            document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdown => {
                const parentLink = dropdown.querySelector(':scope > .nav-link');
                const collapse = dropdown.querySelector(':scope > .collapse');
                const activeLink = collapse?.querySelector('.nav-link.active');
                const shouldOpen = !!activeLink;

                if (collapse) collapse.classList.toggle('show', shouldOpen);
                if (parentLink) parentLink.classList.remove('active');
                setSidebarArrowState(parentLink?.querySelector('.dropdown-arrow'), shouldOpen);
            });

            return activeChild;
        }

        const sidebar = document.getElementById('sidebar');
        if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar?.classList.add('collapsed');

        document.getElementById('desktopToggleBtn')?.addEventListener('click', () => {
            sidebar?.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar?.classList.contains('collapsed') ? 'true' : 'false');

            if (!sidebar?.classList.contains('collapsed')) {
                setActiveSidebarItem();
            }
        });

        document.getElementById('mobileMenuBtn')?.addEventListener('click', () => sidebar?.classList.toggle('active'));

        setActiveSidebarItem();

        function toggleRecurringFields() {
            const on = document.getElementById('isRecurring')?.checked;
            const box = document.getElementById('recurringFields');
            if (box) box.style.display = on ? 'block' : 'none';
        }
        const taskAssigneeDetails = <?php echo json_encode($taskDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const taskAttachmentDetails = <?php echo json_encode($taskAttachments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        function escapeHtml(v) {
            return String(v ?? '').replace(/[&<>'"]/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#039;',
                '"': '&quot;'
            } [c]));
        }

        function formatStatusLabel(status) {
            const map = {
                pending: 'Pending',
                in_progress: 'In Progress',
                completed: 'Completed',
                cancelled: 'Cancelled'
            };
            return map[status] || status || 'Pending';
        }

        function formatDateTime(value) {
            if (!value) return '-';
            const d = new Date(String(value).replace(' ', 'T'));
            if (isNaN(d.getTime())) return value;
            return d.toLocaleString([], {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function openTaskDetails(row) {
            const data = JSON.parse(row.dataset.task || '{}');
            document.getElementById('detailTaskTitle').textContent = data.title || '-';
            document.getElementById('detailTaskDescription').innerHTML = escapeHtml(data.description || 'No description provided.').replace(/\n/g, '<br>');
            document.getElementById('detailTaskDue').textContent = ((data.task_date || '-') + ' ' + ((data.task_time || '').substring(0, 5) || ''));
            document.getElementById('detailTaskPriority').textContent = data.priority ? data.priority.charAt(0).toUpperCase() + data.priority.slice(1) : '-';
            document.getElementById('detailTaskCreator').textContent = data.creator_name || 'N/A';
            const attachmentsBox = document.getElementById('detailTaskAttachments');
            const attachments = taskAttachmentDetails[String(data.task_id)] || taskAttachmentDetails[data.task_id] || [];
            attachmentsBox.innerHTML = attachments.length ? attachments.map(file => '<a class="task-attachment-link" href="' + escapeHtml(file.stored_path) + '" target="_blank" rel="noopener" download><i class="bi bi-paperclip"></i><span>' + escapeHtml(file.original_name) + '</span></a>').join('') : '<span class="text-muted small">No attachments.</span>';
            const tbody = document.getElementById('detailAssigneeBody');
            const list = taskAssigneeDetails[String(data.task_id)] || taskAssigneeDetails[data.task_id] || [];
            if (!list.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No assignee details found.</td></tr>';
            } else {
                tbody.innerHTML = list.map(a => {
                    const status = a.assignee_status || 'pending';
                    const note = (a.assignee_note || '').trim();
                    return '<tr>' +
                        '<td class="fw-semibold">' + escapeHtml((a.user_name || '').trim() || 'Unknown User') + '</td>' +
                        '<td>' + escapeHtml(a.user_role || '-') + '</td>' +
                        '<td><span class="badge-soft status-' + escapeHtml(status) + '">' + escapeHtml(formatStatusLabel(status)) + '</span></td>' +
                        '<td style="min-width:260px;white-space:normal">' + (note ? escapeHtml(note).replace(/\n/g, '<br>') : '<span class="text-muted">No status note.</span>') + '</td>' +
                        '<td>' + escapeHtml(formatDateTime(a.updated_at)) + '</td>' +
                        '</tr>';
                }).join('');
            }
            new bootstrap.Modal(document.getElementById('taskDetailsModal')).show();
        }
        const assigneeUsers = <?php echo json_encode(array_map(static function ($u) {
                                    return [
                                        'id' => (int)($u['user_id'] ?? 0),
                                        'name' => trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? '')),
                                        'role' => (string)($u['role'] ?? ''),
                                        'branch_id' => (int)($u['branch_id'] ?? 0)
                                    ];
                                }, $users), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const currentTaskUserId = <?php echo (int)$user_id; ?>;
        let selectedAssigneeIds = [];
        let assigneeActiveIndex = -1;

        function getAssigneeUser(id) {
            return assigneeUsers.find(u => Number(u.id) === Number(id)) || null;
        }

        function focusAssigneeSearch() {
            document.getElementById('assigneeSearchInput')?.focus();
        }

        function setSelectedAssignees(ids) {
            selectedAssigneeIds = [...new Set((ids || []).map(Number).filter(id => id > 0 && getAssigneeUser(id)))];
            renderAssigneePicker();
        }

        function addAssignee(id) {
            id = Number(id);
            if (id > 0 && getAssigneeUser(id) && !selectedAssigneeIds.includes(id)) selectedAssigneeIds.push(id);
            const input = document.getElementById('assigneeSearchInput');
            if (input) input.value = '';
            assigneeActiveIndex = -1;
            renderAssigneePicker();
            input?.focus();
        }

        function removeAssignee(id) {
            selectedAssigneeIds = selectedAssigneeIds.filter(item => Number(item) !== Number(id));
            renderAssigneePicker();
            document.getElementById('assigneeSearchInput')?.focus();
        }

        function getFilteredAssigneeUsers() {
            const q = (document.getElementById('assigneeSearchInput')?.value || '').trim().toLowerCase();
            return assigneeUsers.filter(u => {
                const haystack = ((u.name || '') + ' ' + (u.role || '')).toLowerCase();
                return !q || haystack.includes(q);
            });
        }

        function renderAssigneePicker() {
            const chips = document.getElementById('assigneeChips');
            const hidden = document.getElementById('assigneeHiddenInputs');
            const dropdown = document.getElementById('assigneeDropdown');
            if (chips) {
                chips.innerHTML = selectedAssigneeIds.map(id => {
                    const u = getAssigneeUser(id);
                    if (!u) return '';
                    return `<span class="assignee-chip"><span class="assignee-chip-name" title="${escapeHtml(u.name)}">${escapeHtml(u.name || 'User')}</span><span class="assignee-chip-role">${escapeHtml(u.role || '')}</span><button type="button" class="assignee-chip-remove" aria-label="Remove ${escapeHtml(u.name)}" onclick="event.stopPropagation();removeAssignee(${Number(u.id)})">&times;</button></span>`;
                }).join('');
            }
            if (hidden) {
                hidden.innerHTML = selectedAssigneeIds.map(id => `<input type="hidden" name="assignees[]" value="${Number(id)}">`).join('');
            }
            if (dropdown && dropdown.classList.contains('show')) renderAssigneeDropdown();
        }

        function renderAssigneeDropdown() {
            const dropdown = document.getElementById('assigneeDropdown');
            if (!dropdown) return;
            const list = getFilteredAssigneeUsers();
            if (assigneeActiveIndex >= list.length) assigneeActiveIndex = list.length - 1;
            dropdown.innerHTML = list.length ? list.map((u, index) => {
                const selected = selectedAssigneeIds.includes(Number(u.id));
                return `<button type="button" class="assignee-option${selected?' selected':''}${index===assigneeActiveIndex?' active':''}" role="option" aria-selected="${selected?'true':'false'}" data-user-id="${Number(u.id)}" onclick="addAssignee(${Number(u.id)})"><span><span class="assignee-option-name">${escapeHtml(u.name || 'User')}</span><span class="assignee-option-meta">${escapeHtml(u.role || 'User')}</span></span><i class="bi bi-check2 assignee-option-check"></i></div>`;
            }).join('') : '<div class="px-3 py-3 text-center text-muted small">No users found.</div>';
        }

        function openAssigneeDropdown() {
            const dropdown = document.getElementById('assigneeDropdown');
            if (!dropdown) return;
            dropdown.classList.add('show');
            renderAssigneeDropdown();
        }

        function closeAssigneeDropdown() {
            document.getElementById('assigneeDropdown')?.classList.remove('show');
            assigneeActiveIndex = -1;
        }

        const assigneeSearchInput = document.getElementById('assigneeSearchInput');
        assigneeSearchInput?.addEventListener('focus', openAssigneeDropdown);
        assigneeSearchInput?.addEventListener('input', () => {
            assigneeActiveIndex = -1;
            openAssigneeDropdown();
        });
        assigneeSearchInput?.addEventListener('keydown', event => {
            const list = getFilteredAssigneeUsers();
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                assigneeActiveIndex = Math.min(assigneeActiveIndex + 1, list.length - 1);
                renderAssigneeDropdown();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                assigneeActiveIndex = Math.max(assigneeActiveIndex - 1, 0);
                renderAssigneeDropdown();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const target = list[assigneeActiveIndex >= 0 ? assigneeActiveIndex : 0];
                if (target) addAssignee(target.id);
            } else if (event.key === 'Backspace' && !assigneeSearchInput.value && selectedAssigneeIds.length) {
                removeAssignee(selectedAssigneeIds[selectedAssigneeIds.length - 1]);
            } else if (event.key === 'Escape') {
                closeAssigneeDropdown();
            }
        });
        document.addEventListener('click', event => {
            if (!document.getElementById('assigneePicker')?.contains(event.target)) closeAssigneeDropdown();
        });
        document.getElementById('taskModal')?.addEventListener('hidden.bs.modal', closeAssigneeDropdown);

        document.querySelector('#taskModal form')?.addEventListener('submit', event => {
            if (!selectedAssigneeIds.length) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Assigned User Required',
                    text: 'Please select at least one assigned user.',
                    confirmButtonColor: '#047857'
                }).then(() => focusAssigneeSearch());
            }
        });

        function renderExistingTaskFiles(taskId) {
            const box = document.getElementById('existingTaskFiles');
            if (!box) return;
            const files = taskAttachmentDetails[String(taskId)] || taskAttachmentDetails[taskId] || [];
            if (!files.length) {
                box.style.display = 'none';
                box.innerHTML = '';
                return;
            }
            box.style.display = 'block';
            box.innerHTML = '<div class="small fw-bold text-muted mb-2">Existing Attachments</div><div class="task-attachment-list">' + files.map(file => '<a class="task-attachment-link" href="' + escapeHtml(file.stored_path) + '" target="_blank" rel="noopener" download><i class="bi bi-paperclip"></i><span>' + escapeHtml(file.original_name) + '</span></a>').join('') + '</div>';
        }

        function openAddTask() {
            document.getElementById('taskModalTitle').textContent = 'Add Task';
            document.getElementById('taskAction').value = 'add_task';
            document.getElementById('taskId').value = '';
            document.querySelector('#taskModal form').reset();
            document.getElementById('taskDate').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('isRecurring').checked = false;
            toggleRecurringFields();
            setSelectedAssignees(getAssigneeUser(currentTaskUserId) ? [currentTaskUserId] : []);
            document.getElementById('taskAttachments').value = '';
            renderExistingTaskFiles(0);
        }

        function editTask(btn) {
            const data = JSON.parse(btn.closest('tr').dataset.task);
            document.getElementById('taskModalTitle').textContent = 'Edit Task';
            document.getElementById('taskAction').value = 'update_task';
            document.getElementById('taskId').value = data.task_id;
            document.getElementById('taskTitle').value = data.title || '';
            document.getElementById('taskPriority').value = data.priority || 'normal';
            document.getElementById('taskDate').value = data.task_date || '';
            document.getElementById('taskTime').value = (data.task_time || '').substring(0, 5);
            document.getElementById('reminderDays').value = data.reminder_days || 1;
            document.getElementById('taskDescription').value = data.description || '';
            document.getElementById('isRecurring').checked = (String(data.is_recurring) === '1');
            document.getElementById('recurrenceInterval').value = data.recurrence_interval || 1;
            document.getElementById('recurrenceUnit').value = data.recurrence_unit || 'month';
            document.getElementById('recurrenceUntil').value = data.recurrence_until || '';
            toggleRecurringFields();
            setSelectedAssignees(String(data.tagged_ids || '').split(',').map(Number).filter(Boolean));
            document.getElementById('taskAttachments').value = '';
            renderExistingTaskFiles(data.task_id);
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }

        function filterTaskRows() {
            const q = (document.getElementById('taskSearchInput')?.value || '').toLowerCase();
            document.querySelectorAll('#taskTable tbody tr[data-task]').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        function deleteTask(id) {
            Swal.fire({
                title: 'Delete task?',
                text: 'This task cannot be restored after deletion.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#0d7c66',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, delete'
            }).then(r => {
                if (r.isConfirmed) {
                    document.getElementById('deleteTaskId').value = id;
                    document.getElementById('deleteForm').submit();
                }
            });
        }

        function showNotifications() {
            if (!notifications.length) return;
            const html = notifications.map(n => `<div class="text-start border-bottom py-2"><strong>${n.title}</strong><br><small>Due: ${new Date(n.due_datetime.replace(' ','T')).toLocaleString()}</small></div>`).join('');
            Swal.fire({
                title: 'Task Reminders',
                html,
                icon: 'warning',
                confirmButtonColor: '#0d7c66'
            });
            fetch('tasks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=seen_notifications'
            });
        }
        if (notifications.length) {
            setTimeout(() => {
                showNotifications();
                if ('Notification' in window) {
                    if (Notification.permission === 'granted') new Notification('Task Reminder', {
                        body: `You have ${notifications.length} upcoming task(s).`
                    });
                    else if (Notification.permission !== 'denied') Notification.requestPermission();
                }
            }, 600);
        }

        const APP_TODAY = <?php echo json_encode(date('Y-m-d')); ?>;
        const calendarEvents = <?php echo json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const calendarDtrDetails = <?php echo json_encode($calendarDtrDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        let calendarCurrentView = 'month';
        let calendarCursor = new Date();
        let calendarSelectedDate = new Date();

        function escapeHtml(value) {
            const el = document.createElement('div');
            el.textContent = value == null ? '' : String(value);
            return el.innerHTML;
        }

        function displayValue(value) {
            return value == null || value === '' ? '-' : escapeHtml(value);
        }

        function setCalendarView(view) {
            calendarCurrentView = ['day', 'week', 'month'].includes(view) ? view : 'month';
            document.querySelectorAll('[data-calendar-view]').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.calendarView === calendarCurrentView);
            });
            renderAttendanceCalendar();
        }

        function changeCalendarMonth(delta) {
            if (calendarCurrentView === 'day') {
                calendarSelectedDate.setDate(calendarSelectedDate.getDate() + delta);
                calendarCursor = new Date(calendarSelectedDate.getFullYear(), calendarSelectedDate.getMonth(), 1);
            } else if (calendarCurrentView === 'week') {
                calendarSelectedDate.setDate(calendarSelectedDate.getDate() + (delta * 7));
                calendarCursor = new Date(calendarSelectedDate.getFullYear(), calendarSelectedDate.getMonth(), 1);
            } else {
                calendarCursor.setMonth(calendarCursor.getMonth() + delta);
                calendarCursor = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth(), 1);
            }
            renderAttendanceCalendar();
        }

        function goToCalendarToday() {
            calendarSelectedDate = new Date();
            calendarSelectedDate.setHours(0, 0, 0, 0);
            calendarCursor = new Date(calendarSelectedDate.getFullYear(), calendarSelectedDate.getMonth(), 1);
            renderAttendanceCalendar();
        }

        function setCalendarDate(dateKey, openDetails = false) {
            const nextDate = new Date(dateKey + 'T00:00:00');
            if (Number.isNaN(nextDate.getTime())) return;
            calendarSelectedDate = nextDate;
            calendarSelectedDate.setHours(0, 0, 0, 0);
            calendarCursor = new Date(nextDate.getFullYear(), nextDate.getMonth(), 1);
            renderAttendanceCalendar();
            if (openDetails) setTimeout(() => openCalendarDateDetails(dateKey), 30);
        }

        function dateToKey(d) {
            return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        }

        function getEasterDate(year) {
            const a = year % 19,
                b = Math.floor(year / 100),
                c = year % 100,
                d = Math.floor(b / 4),
                e = b % 4;
            const f = Math.floor((b + 8) / 25),
                g = Math.floor((b - f + 1) / 3),
                h = (19 * a + b - d - g + 15) % 30;
            const i = Math.floor(c / 4),
                k = c % 4,
                l = (32 + 2 * e + 2 * i - h - k) % 7,
                m = Math.floor((a + 11 * h + 22 * l) / 451);
            const month = Math.floor((h + l - 7 * m + 114) / 31),
                day = ((h + l - 7 * m + 114) % 31) + 1;
            return new Date(year, month - 1, day);
        }

        function addDays(date, days) {
            const d = new Date(date);
            d.setDate(d.getDate() + days);
            return d;
        }

        function lastMondayOfAugust(year) {
            const d = new Date(year, 7, 31);
            while (d.getDay() !== 1) d.setDate(d.getDate() - 1);
            return dateToKey(d);
        }

        function getPhilippineHolidayInfoJS(dateKey) {
            const [yearStr, monthStr, dayStr] = dateKey.split('-');
            const year = parseInt(yearStr, 10),
                md = `${monthStr}-${dayStr}`;
            const fixed = {
                '01-01': {
                    type: 'regular_holiday',
                    name: "New Year's Day"
                },
                '02-25': {
                    type: 'special_non_working',
                    name: 'EDSA People Power Revolution Anniversary'
                },
                '04-09': {
                    type: 'regular_holiday',
                    name: 'Araw ng Kagitingan'
                },
                '05-01': {
                    type: 'regular_holiday',
                    name: 'Labor Day'
                },
                '06-12': {
                    type: 'regular_holiday',
                    name: 'Independence Day'
                },
                '08-21': {
                    type: 'special_non_working',
                    name: 'Ninoy Aquino Day'
                },
                '11-01': {
                    type: 'special_non_working',
                    name: "All Saints' Day"
                },
                '11-30': {
                    type: 'regular_holiday',
                    name: 'Bonifacio Day'
                },
                '12-08': {
                    type: 'special_non_working',
                    name: 'Feast of Immaculate Conception of Mary'
                },
                '12-24': {
                    type: 'special_non_working',
                    name: 'Additional Special Non-Working Day'
                },
                '12-25': {
                    type: 'regular_holiday',
                    name: 'Christmas Day'
                },
                '12-30': {
                    type: 'regular_holiday',
                    name: 'Rizal Day'
                },
                '12-31': {
                    type: 'special_non_working',
                    name: 'Last Day of the Year'
                }
            };
            if (fixed[md]) return fixed[md];
            if (dateKey === lastMondayOfAugust(year)) return {
                type: 'regular_holiday',
                name: 'National Heroes Day'
            };
            const easter = getEasterDate(year);
            if (dateKey === dateToKey(addDays(easter, -3))) return {
                type: 'regular_holiday',
                name: 'Maundy Thursday'
            };
            if (dateKey === dateToKey(addDays(easter, -2))) return {
                type: 'regular_holiday',
                name: 'Good Friday'
            };
            if (dateKey === dateToKey(addDays(easter, -1))) return {
                type: 'special_non_working',
                name: 'Black Saturday'
            };
            const movable = {
                2024: {
                    '2024-02-10': {
                        type: 'special_non_working',
                        name: 'Chinese New Year'
                    },
                    '2024-04-10': {
                        type: 'regular_holiday',
                        name: "Eid'l Fitr"
                    },
                    '2024-06-17': {
                        type: 'regular_holiday',
                        name: "Eid'l Adha"
                    }
                },
                2025: {
                    '2025-01-29': {
                        type: 'special_non_working',
                        name: 'Chinese New Year'
                    },
                    '2025-03-31': {
                        type: 'regular_holiday',
                        name: "Eid'l Fitr"
                    },
                    '2025-06-06': {
                        type: 'regular_holiday',
                        name: "Eid'l Adha"
                    }
                },
                2026: {
                    '2026-02-17': {
                        type: 'special_non_working',
                        name: 'Chinese New Year'
                    },
                    '2026-03-20': {
                        type: 'regular_holiday',
                        name: "Eid'l Fitr"
                    },
                    '2026-05-27': {
                        type: 'regular_holiday',
                        name: "Eid'l Adha"
                    }
                },
                2027: {
                    '2027-02-06': {
                        type: 'special_non_working',
                        name: 'Chinese New Year'
                    },
                    '2027-03-10': {
                        type: 'regular_holiday',
                        name: "Eid'l Fitr"
                    },
                    '2027-05-17': {
                        type: 'regular_holiday',
                        name: "Eid'l Adha"
                    }
                },
                2028: {
                    '2028-01-26': {
                        type: 'special_non_working',
                        name: 'Chinese New Year'
                    },
                    '2028-02-27': {
                        type: 'regular_holiday',
                        name: "Eid'l Fitr"
                    },
                    '2028-05-05': {
                        type: 'regular_holiday',
                        name: "Eid'l Adha"
                    }
                },
                2029: {
                    '2029-02-13': {
                        type: 'special_non_working',
                        name: 'Chinese New Year'
                    },
                    '2029-02-15': {
                        type: 'regular_holiday',
                        name: "Eid'l Fitr"
                    },
                    '2029-04-24': {
                        type: 'regular_holiday',
                        name: "Eid'l Adha"
                    }
                },
                2030: {
                    '2030-02-03': {
                        type: 'special_non_working',
                        name: 'Chinese New Year'
                    },
                    '2030-02-05': {
                        type: 'regular_holiday',
                        name: "Eid'l Fitr"
                    },
                    '2030-04-14': {
                        type: 'regular_holiday',
                        name: "Eid'l Adha"
                    }
                }
            };
            return movable[year] && movable[year][dateKey] ? movable[year][dateKey] : null;
        }

        function buildCalendarEventMap() {
            const byDate = {};
            (calendarEvents || []).forEach(ev => {
                if (ev.date)(byDate[ev.date] ||= []).push(ev);
            });
            return byDate;
        }

        function getCalendarEventsForDate(dateKey, query = '') {
            const byDate = buildCalendarEventMap();
            let events = [...(byDate[dateKey] || [])];
            if (!events.some(ev => ev.type === 'holiday')) {
                const generated = getPhilippineHolidayInfoJS(dateKey);
                if (generated) events.unshift({
                    date: dateKey,
                    type: 'holiday',
                    holiday_type: generated.type,
                    title: generated.name
                });
            }
            if (query) {
                const rows = (calendarDtrDetails || []).filter(row => row.attendance_date === dateKey);
                events = events.filter(ev => {
                    const attendanceText = rows.map(row => `${row.employee_name||''} ${row.branch_name||''} ${row.holiday_name||''}`).join(' ');
                    const taskText = `${ev.title||''} ${ev.description||''} ${ev.assigned_users||''} ${ev.creator||''} ${ev.priority||''} ${ev.status||''}`;
                    return `${taskText} ${ev.holiday_type||''} ${ev.type||''} ${attendanceText}`.toLowerCase().includes(query);
                });
            }
            return events;
        }

        function renderMiniCalendar(year, month, todayKey, selectedKey, query) {
            const miniTitle = document.getElementById('miniCalendarTitle');
            const miniWrap = document.getElementById('miniAttendanceCalendar');
            if (!miniWrap) return;
            if (miniTitle) miniTitle.textContent = new Date(year, month, 1).toLocaleString('en-US', {
                month: 'long',
                year: 'numeric'
            });
            const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const startBlank = new Date(year, month, 1).getDay();
            let html = dayNames.map(d => `<div class="mini-day-name">${d}</div>`).join('');
            const miniStart = new Date(year, month, 1 - startBlank);
            for (let i = 0; i < 42; i++) {
                const d = new Date(miniStart);
                d.setDate(miniStart.getDate() + i);
                const key = dateToKey(d),
                    cls = ['mini-date'];
                if (d.getMonth() !== month) cls.push('muted');
                if (key === todayKey) cls.push('today');
                if (key === selectedKey) cls.push('selected');
                if (getCalendarEventsForDate(key, query).length) cls.push('has-event');
                html += `<div class="${cls.join(' ')}" role="button" tabindex="0" onclick="setCalendarDate('${key}',false)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();setCalendarDate('${key}',false);}">${d.getDate()}</div>`;
            }
            miniWrap.innerHTML = html;
        }

        function renderMiniHolidayList(year, month, query = '') {
            const list = document.getElementById('miniHolidayList');
            if (!list) return;
            const holidays = [],
                lastDate = new Date(year, month + 1, 0).getDate();
            for (let day = 1; day <= lastDate; day++) {
                const key = dateToKey(new Date(year, month, day));
                let holiday = getCalendarEventsForDate(key, '').find(ev => ev.type === 'holiday');
                if (holiday) holiday = {
                    type: holiday.holiday_type || '',
                    name: holiday.title || ''
                };
                else holiday = getPhilippineHolidayInfoJS(key);
                if (!holiday || !holiday.name) continue;
                if (query && !`${holiday.name} ${holiday.type}`.toLowerCase().includes(query)) continue;
                holidays.push({
                    dateKey: key,
                    day,
                    monthText: new Date(year, month, day).toLocaleString('en-US', {
                        month: 'short'
                    }),
                    ...holiday
                });
            }
            if (!holidays.length) {
                list.innerHTML = '<div class="mini-holiday-empty">No holidays this month.</div>';
                return;
            }
            list.innerHTML = holidays.map(h => {
                const label = h.type === 'regular_holiday' ? 'Regular Holiday' : 'Special Non-working Day';
                return `<div class="mini-holiday-item" role="button" tabindex="0" onclick="setCalendarDate('${h.dateKey}',true)">
            <div class="mini-holiday-date"><span>${escapeHtml(h.monthText)}</span><span>${h.day}</span></div>
            <div class="mini-holiday-info"><div class="mini-holiday-name" title="${escapeHtml(h.name)}">${escapeHtml(h.name)}</div><span class="mini-holiday-type ${escapeHtml(h.type)}">${label}</span></div>
        </div>`;
            }).join('');
        }

        function renderMiniTaskList(dateKey, query = '') {
            const list = document.getElementById('miniTaskList');
            if (!list) return;

            const selectedDate = new Date(dateKey + 'T00:00:00');
            const tasks = getCalendarEventsForDate(dateKey, '').filter(ev => {
                if (ev.type !== 'task') return false;
                if (!query) return true;
                return `${ev.title||''} ${ev.description||''} ${ev.assigned_users||''} ${ev.creator||''} ${ev.priority||''} ${ev.status||''}`
                    .toLowerCase().includes(query);
            });

            if (!tasks.length) {
                list.innerHTML = '<div class="mini-holiday-empty">No tasks for the selected date.</div>';
                return;
            }

            const monthText = selectedDate.toLocaleString('en-US', { month: 'short' });
            const day = selectedDate.getDate();
            list.innerHTML = tasks.map(task => {
                const timeLabel = task.time
                    ? new Date(`2000-01-01T${task.time}:00`).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
                    : 'No time';
                const statusLabel = String(task.status || 'pending').replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());
                return `<div class="mini-holiday-item" role="button" tabindex="0" onclick="openCalendarDateDetails('${dateKey}')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openCalendarDateDetails('${dateKey}');}">
                    <div class="mini-holiday-date mini-task-date"><span>${escapeHtml(monthText)}</span><span>${day}</span></div>
                    <div class="mini-holiday-info">
                        <div class="mini-holiday-name" title="${escapeHtml(task.title || 'Task')}">${escapeHtml(task.title || 'Task')}</div>
                        <span class="mini-task-time">${escapeHtml(timeLabel)} · ${escapeHtml(statusLabel)}</span>
                    </div>
                </div>`;
            }).join('');
        }

        function renderCalendarEventItem(ev) {
            if (ev.type === 'holiday') return `<div class="calendar-event holiday ${ev.holiday_type||''}" title="${escapeHtml(ev.title||'')}">${escapeHtml(ev.title||'')}</div>`;
            if (ev.type === 'task') {
                const assignees = ev.assigned_users ? ` · ${ev.assigned_users}` : '';
                return `<div class="calendar-event task" title="${escapeHtml((ev.title||'Task')+assignees)}">${escapeHtml(ev.time||'')} · ${escapeHtml(ev.title||'Task')}${escapeHtml(assignees)}</div>`;
            }
            const timeText = ev.hours ? `${ev.hours}h` : 'Attendance';
            return `<div class="calendar-event attendance${ev.pending?' pending':''}" title="${escapeHtml(ev.title||'')}">${escapeHtml(timeText)} · ${escapeHtml(ev.title||'')}${ev.pending?' · Pending':''}</div>`;
        }

        function renderMonthCalendar(year, month, query, todayKey) {
            const first = new Date(year, month, 1),
                last = new Date(year, month + 1, 0),
                blank = first.getDay(),
                total = Math.ceil((blank + last.getDate()) / 7) * 7;
            const names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            let html = '<div class="calendar-grid calendar-days">' + names.map(d => `<div>${d.toUpperCase()}</div>`).join('') + '</div><div class="calendar-grid">';
            const start = new Date(year, month, 1 - blank);
            for (let i = 0; i < total; i++) {
                const d = new Date(start);
                d.setDate(start.getDate() + i);
                const key = dateToKey(d),
                    events = getCalendarEventsForDate(key, query),
                    cls = ['calendar-cell'];
                if (d.getMonth() !== month) cls.push('muted');
                if (key === todayKey) cls.push('today');
                if (key === dateToKey(calendarSelectedDate)) cls.push('selected');
                html += `<div class="${cls.join(' ')}" data-calendar-date="${key}" role="button" tabindex="0" onclick="setCalendarDate('${key}',true)"><div class="calendar-date-num">${d.getDate()}</div>`;
                events.slice(0, 4).forEach(ev => html += renderCalendarEventItem(ev));
                if (events.length > 4) html += `<div class="calendar-more">+${events.length-4} more</div>`;
                html += '</div>';
            }
            return html + '</div>';
        }

        function getWeekStart(date) {
            const d = new Date(date);
            d.setDate(d.getDate() - d.getDay());
            d.setHours(0, 0, 0, 0);
            return d;
        }

        function renderWeekCalendar(query, todayKey) {
            const names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                start = getWeekStart(calendarSelectedDate);
            let html = '<div class="calendar-grid calendar-days week-grid">';
            for (let i = 0; i < 7; i++) {
                const d = addDays(start, i);
                html += `<div>${names[i].toUpperCase()}<br><span style="font-size:11px;color:#9aa3ad;">${d.toLocaleDateString('en-US',{month:'short',day:'numeric'})}</span></div>`;
            }
            html += '</div><div class="calendar-grid week-grid">';
            for (let i = 0; i < 7; i++) {
                const d = addDays(start, i),
                    key = dateToKey(d),
                    events = getCalendarEventsForDate(key, query),
                    cls = ['calendar-cell', 'week-cell'];
                if (key === todayKey) cls.push('today');
                if (key === dateToKey(calendarSelectedDate)) cls.push('selected');
                html += `<div class="${cls.join(' ')}" data-calendar-date="${key}" role="button" tabindex="0" onclick="setCalendarDate('${key}',true)"><div class="calendar-date-num">${d.getDate()}</div>`;
                events.forEach(ev => html += renderCalendarEventItem(ev));
                if (!events.length) html += '<div class="calendar-detail-empty" style="padding:18px 4px;font-size:12px;">No entries</div>';
                html += '</div>';
            }
            return html + '</div>';
        }

        function renderDayCalendar(query) {
            const key = dateToKey(calendarSelectedDate);
            const events = getCalendarEventsForDate(key, query);
            const label = calendarSelectedDate.toLocaleDateString('en-US', {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });

            const taskCount = events.filter(ev => ev.type === 'task').length;
            const attendanceCount = events.filter(ev => ev.type === 'attendance').length;
            const holidayCount = events.filter(ev => ev.type === 'holiday').length;

            const summaryParts = [];
            if (taskCount) summaryParts.push(`${taskCount} task${taskCount === 1 ? '' : 's'}`);
            if (attendanceCount) summaryParts.push(`${attendanceCount} attendance record${attendanceCount === 1 ? '' : 's'}`);
            if (holidayCount) summaryParts.push(`${holidayCount} holiday${holidayCount === 1 ? '' : 's'}`);

            let html = `
                <div class="calendar-day-view" data-calendar-date="${key}" tabindex="0">
                    <div class="calendar-day-view-header">
                        <div class="calendar-day-view-heading">
                            <h6>${escapeHtml(label)}</h6>
                            <div class="calendar-day-view-subtitle">
                                ${summaryParts.length ? escapeHtml(summaryParts.join(' · ')) : 'No scheduled records for this date'}
                            </div>
                        </div>
                        <button type="button" class="calendar-today-btn" onclick="setCalendarDate('${key}',true)">
                            <i class="bi bi-eye me-1"></i> View Details
                        </button>
                    </div>
                    <div class="calendar-day-view-body">`;

            if (events.length) {
                events.forEach(ev => {
                    let cardClass = '';
                    let iconClass = '';
                    let typeLabel = '';
                    let badgeClass = '';
                    let metaItems = [];

                    if (ev.type === 'task') {
                        cardClass = 'task-card';
                        iconClass = 'bi-check2-square';
                        typeLabel = 'Task';
                        badgeClass = 'task';

                        if (ev.time) {
                            metaItems.push(`<span class="calendar-result-meta-item"><i class="bi bi-clock"></i>${escapeHtml(ev.time)}</span>`);
                        }
                        if (ev.assigned_users) {
                            metaItems.push(`<span class="calendar-result-meta-item"><i class="bi bi-people"></i>${escapeHtml(ev.assigned_users)}</span>`);
                        } else {
                            metaItems.push(`<span class="calendar-result-meta-item"><i class="bi bi-person-x"></i>No assignee</span>`);
                        }
                    } else if (ev.type === 'holiday') {
                        const isRegular = ev.holiday_type === 'regular_holiday';
                        cardClass = isRegular ? 'regular-holiday-card' : 'special-holiday-card';
                        iconClass = 'bi-calendar-event';
                        typeLabel = isRegular ? 'Regular Holiday' : 'Special Holiday';
                        badgeClass = isRegular ? 'regular_holiday' : 'special_non_working';

                        if (ev.location_name) {
                            metaItems.push(`<span class="calendar-result-meta-item"><i class="bi bi-geo-alt"></i>${escapeHtml(ev.location_name)}</span>`);
                        }
                        if (ev.branch_name) {
                            metaItems.push(`<span class="calendar-result-meta-item"><i class="bi bi-building"></i>${escapeHtml(ev.branch_name)}</span>`);
                        }
                    } else {
                        const isPending = !!ev.pending;
                        cardClass = `attendance-card${isPending ? ' pending' : ''}`;
                        iconClass = isPending ? 'bi-hourglass-split' : 'bi-person-check';
                        typeLabel = isPending ? 'Attendance Pending' : 'Attendance';
                        badgeClass = isPending ? 'pending' : 'attendance';

                        if (ev.hours) {
                            metaItems.push(`<span class="calendar-result-meta-item"><i class="bi bi-clock-history"></i>${escapeHtml(ev.hours)}h</span>`);
                        }
                    }

                    html += `
                        <div class="calendar-result-card ${cardClass}">
                            <span class="calendar-result-icon"><i class="bi ${iconClass}"></i></span>
                            <span class="calendar-result-content">
                                <span class="calendar-result-title">${escapeHtml(ev.title || 'Entry')}</span>
                                <span class="calendar-result-meta">
                                    <span class="calendar-result-badge ${badgeClass}">${escapeHtml(typeLabel)}</span>
                                    ${metaItems.join('')}
                                </span>
                            </span>
                        </div>`;
                });
            } else {
                html += `
                    <div class="calendar-empty-state">
                        <i class="bi bi-calendar-x d-block mb-2" style="font-size:32px;"></i>
                        No attendance, holiday, or task details found for this date.
                    </div>`;
            }

            return html + '</div></div>';
        }

        function renderAttendanceCalendar() {
            const wrap = document.getElementById('attendanceCalendar'),
                title = document.getElementById('calendarTitle');
            if (!wrap) return;
            const query = '',
                today = new Date(),
                todayKey = dateToKey(today),
                selectedKey = dateToKey(calendarSelectedDate),
                year = calendarCursor.getFullYear(),
                month = calendarCursor.getMonth();
            renderMiniCalendar(year, month, todayKey, selectedKey, query);
            renderMiniHolidayList(year, month, query);
            renderMiniTaskList(selectedKey, query);
            if (calendarCurrentView === 'day') {
                if (title) title.textContent = calendarSelectedDate.toLocaleDateString('en-US', {
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric'
                });
                wrap.innerHTML = renderDayCalendar(query);
            } else if (calendarCurrentView === 'week') {
                const start = getWeekStart(calendarSelectedDate),
                    end = addDays(start, 6);
                if (title) title.textContent = `${start.toLocaleDateString('en-US',{month:'short',day:'numeric'})} - ${end.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}`;
                wrap.innerHTML = renderWeekCalendar(query, todayKey);
            } else {
                if (title) title.textContent = calendarCursor.toLocaleString('en-US', {
                    month: 'long',
                    year: 'numeric'
                });
                wrap.innerHTML = renderMonthCalendar(year, month, query, todayKey);
            }
        }

        function formatDurationDecimal(minutes) {
            return (parseInt(minutes || 0, 10) / 60).toFixed(2);
        }

        function formatSlotDurationDecimal(slot) {
            return formatDurationDecimal(slot && slot.duration_minutes ? slot.duration_minutes : 0);
        }

        function formatTimeText(value) {
            if (!value) return 'Pending';
            const parts = String(value).split(':');
            let hour = parseInt(parts[0] || '0', 10);
            const minute = parts[1] || '00',
                ampm = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12 || 12;
            return `${hour}:${minute} ${ampm}`;
        }

        function openHolidayModal(dateKey = '', holiday = null) {
            const form = document.getElementById('calendarHolidayForm');
            if (form) form.reset();
            document.getElementById('calendarHolidayId').value = holiday && holiday.holiday_id ? holiday.holiday_id : 0;
            document.getElementById('calendarHolidayDate').value = dateKey || (holiday && holiday.date) || dateToKey(calendarSelectedDate);
            document.getElementById('calendarHolidayName').value = holiday && holiday.title ? holiday.title : '';
            document.getElementById('calendarHolidayType').value = holiday && holiday.holiday_type ? holiday.holiday_type : 'special_non_working';
            document.getElementById('calendarHolidayLocation').value = holiday && holiday.location_name ? holiday.location_name : '';
            document.getElementById('calendarHolidayNotes').value = holiday && holiday.notes ? holiday.notes : '';
            const branch = document.getElementById('calendarHolidayBranch');
            if (branch && holiday && holiday.branch_id) branch.value = holiday.branch_id;
            const title = document.getElementById('calendarHolidayModalTitle');
            if (title) title.textContent = holiday && holiday.holiday_id ? 'Edit Local Holiday' : 'Add Local Holiday';
            const el = document.getElementById('calendarHolidayModal');
            (bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el)).show();
        }

        function deleteCalendarHoliday(id) {
            Swal.fire({
                title: 'Delete Local Holiday?',
                text: 'This holiday will be removed from the branch calendar.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Delete'
            }).then(r => {
                if (!r.isConfirmed) return;
                const f = document.createElement('form');
                f.method = 'POST';
                f.innerHTML = `<input type="hidden" name="action" value="delete_calendar_holiday"><input type="hidden" name="holiday_id" value="${parseInt(id,10)}">`;
                document.body.appendChild(f);
                f.submit();
            });
        }

        function openCalendarDateDetails(dateKey) {
            const modalEl = document.getElementById('calendarDateModal'),
                titleEl = document.getElementById('calendarDateModalTitle'),
                bodyEl = document.getElementById('calendarDateModalBody');
            if (!modalEl || !bodyEl) return;
            const dateObj = new Date(dateKey + 'T00:00:00');
            if (titleEl) titleEl.textContent = dateObj.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const eventsForDate = (calendarEvents || []).filter(ev => ev.date === dateKey);
            const holidayRows = eventsForDate.filter(ev => ev.type === 'holiday');
            const attendanceRows = (calendarDtrDetails || []).filter(row => row.attendance_date === dateKey);
            const taskRows = eventsForDate.filter(ev => ev.type === 'task');
            const tabs = [];

            if (attendanceRows.length) {
                let content = `<div class="calendar-detail-table-wrapper"><table class="calendar-detail-table calendar-day-table"><thead><tr><th class="employee-cell">Employee</th><th class="branch-cell">Branch</th><th class="time-cell">Time In</th><th class="time-cell">Time Out</th><th class="duration-cell number-cell">Duration</th><th class="duration-cell number-cell">Regular</th><th class="duration-cell number-cell">OT</th><th class="holiday-cell">Date Classification</th><th class="money-cell">Basic Pay</th><th class="money-cell">OT Pay</th><th class="money-cell">Total Pay</th></tr></thead><tbody>`;
                attendanceRows.forEach(row => {
                    const slots = row.slots && row.slots.length ? row.slots : [{
                        start_time: '',
                        end_time: '',
                        duration_minutes: row.daily_total_minutes || 0,
                        is_open: row.has_pending
                    }];
                    slots.forEach((slot, index) => {
                        const first = index === 0,
                            count = slots.length,
                            status = row.has_pending ? ' <span class="badge bg-warning text-dark">Pending</span>' : '';
                        content += '<tr>';
                        if (first) content += `<td class="employee-cell" rowspan="${count}"><strong>${escapeHtml(row.employee_name||'Employee')}</strong>${status}</td><td class="branch-cell" rowspan="${count}">${displayValue(row.branch_name)}</td>`;
                        content += `<td class="time-cell">${formatTimeText(slot.start_time)}</td><td class="time-cell">${slot.is_open?'Pending':formatTimeText(slot.end_time)}</td><td class="duration-cell number-cell">${formatSlotDurationDecimal(slot)}</td>`;
                        if (first) {
                            const label = row.holiday_name ? (row.holiday_type === 'regular_holiday' ? 'Regular Holiday' : 'Special Holiday') : 'Regular Workday';
                            const classification = row.holiday_name ? `<span class="holiday-chip ${escapeHtml(row.holiday_type||'')}">${escapeHtml(label)}</span>` : '<span class="workday-chip">Regular Workday</span>';
                            content += `<td class="duration-cell number-cell" rowspan="${count}">${formatDurationDecimal(row.regular_minutes||0)}</td><td class="duration-cell number-cell" rowspan="${count}">${formatDurationDecimal(row.overtime_minutes||0)}</td><td class="holiday-cell" rowspan="${count}">${classification}</td><td class="money-cell" rowspan="${count}">₱${escapeHtml(row.basic_pay||'0.00')}</td><td class="money-cell" rowspan="${count}">₱${escapeHtml(row.overtime_pay||'0.00')}</td><td class="money-cell" rowspan="${count}"><strong>₱${escapeHtml(row.total_pay||'0.00')}</strong></td>`;
                        }
                        content += '</tr>';
                    });
                });
                content += '</tbody></table></div>';
                tabs.push({
                    id: 'attendance',
                    label: 'Attendance Details',
                    icon: 'bi-person-check',
                    content
                });
            }
            if (taskRows.length) {
                let content = `<div class="calendar-detail-table-wrapper"><table class="calendar-detail-table calendar-day-table"><thead><tr><th class="employee-cell">Task</th><th class="time-cell">Time</th><th class="branch-cell">Assigned Users</th><th class="holiday-cell">Priority</th><th class="holiday-cell">Status</th><th class="employee-cell">Created By</th></tr></thead><tbody>`;
                taskRows.forEach(ev => {
                    content += `<tr><td class="employee-cell"><strong>${escapeHtml(ev.title||'Task')}</strong><div class="small text-muted">${escapeHtml(ev.description||'')}</div></td><td class="time-cell">${escapeHtml(ev.time||'-')}</td><td class="branch-cell">${escapeHtml(ev.assigned_users||'No assignee')}</td><td class="holiday-cell">${escapeHtml(ev.priority||'Normal')}</td><td class="holiday-cell">${escapeHtml(ev.status||'Pending')}</td><td class="employee-cell">${escapeHtml(ev.creator||'-')}</td></tr>`;
                });
                content += '</tbody></table></div>';
                tabs.push({
                    id: 'tasks',
                    label: 'Task Details',
                    icon: 'bi-calendar-check',
                    content
                });
            }
            if (holidayRows.length) {
                let content = '<div class="row g-3">';
                holidayRows.forEach(h => {
                    const label = h.holiday_type === 'regular_holiday' ? 'Regular Holiday' : 'Special Non-working Day';
                    content += `<div class="col-12"><div class="border rounded-4 p-3"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h6 class="mb-1">${escapeHtml(h.title||'-')}</h6><span class="holiday-chip ${escapeHtml(h.holiday_type||'')}">${label}</span>${h.custom?'<span class="badge bg-success ms-2">Local Holiday</span>':'<span class="badge bg-secondary ms-2">National Holiday</span>'}</div>${h.custom?`<div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-success" onclick='openHolidayModal(${JSON.stringify(dateKey)},${JSON.stringify(h)})'><i class="bi bi-pencil-square me-1"></i>Edit</button><button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCalendarHoliday(${parseInt(h.holiday_id||0,10)})"><i class="bi bi-trash me-1"></i>Delete</button></div>`:''}</div>${h.location_name?`<div class="small text-muted mt-2"><i class="bi bi-geo-alt me-1"></i>${escapeHtml(h.location_name)}</div>`:''}${h.branch_name?`<div class="small text-muted"><i class="bi bi-building me-1"></i>${escapeHtml(h.branch_name)}</div>`:''}${h.notes?`<div class="mt-2">${escapeHtml(h.notes)}</div>`:''}</div></div>`;
                });
                content += '</div><div class="mt-3"><button type="button" class="btn btn-amgc" onclick="openHolidayModal(\'' + dateKey + '\')"><i class="bi bi-calendar-plus me-1"></i>Add Another Local Holiday</button></div>';
                tabs.push({
                    id: 'holiday',
                    label: 'Holiday Details',
                    icon: 'bi-star-fill',
                    content
                });
            }
            if (!tabs.length) {
                bodyEl.innerHTML = `<div class="calendar-detail-empty"><i class="bi bi-calendar-x d-block mb-2" style="font-size:28px;"></i>No attendance, holiday, or task details found for this date.<div class="mt-3"><button type="button" class="btn btn-amgc" onclick="openHolidayModal('${dateKey}')"><i class="bi bi-calendar-plus me-1"></i>Add Local Holiday</button></div></div>`;
            } else {
                const nav = tabs.map((t, i) => `<li class="nav-item" role="presentation"><button class="nav-link ${i===0?'active':''}" id="calendar-${t.id}-tab" data-bs-toggle="tab" data-bs-target="#calendar-${t.id}-pane" type="button" role="tab"><i class="bi ${t.icon} me-1"></i>${t.label}</button></li>`).join('');
                const panes = tabs.map((t, i) => `<div class="tab-pane fade ${i===0?'show active':''}" id="calendar-${t.id}-pane" role="tabpanel">${t.content}</div>`).join('');
                bodyEl.innerHTML = `<ul class="nav nav-tabs dashboard-tabs mb-3" role="tablist">${nav}</ul><div class="tab-content">${panes}</div>`;
            }
            (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).show();
        }
        document.getElementById('task-calendar-tab')?.addEventListener('shown.bs.tab', renderAttendanceCalendar);
        renderAttendanceCalendar();

        <?php if ($toast['type']): ?>Swal.fire({
            icon: '<?php echo $toast['type']; ?>',
            title: '<?php echo $toast['type'] === 'success' ? 'Success' : 'Error'; ?>',
            text: '<?php echo h($toast['msg']); ?>',
            confirmButtonColor: '#0d7c66'
        });
        <?php endif; ?>
        
        
        function confirmLogout() {
    const modalEl = document.getElementById('profileModal');

    if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    }

    localStorage.removeItem('sidebarCollapsed');

    if (typeof Swal !== 'undefined') {
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
                window.location.href = '../logout.php';
            }
        });
    } else {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = '../logout.php';
        }
    }
}

function logout() {
    confirmLogout();
}
    </script>
</body>

</html>