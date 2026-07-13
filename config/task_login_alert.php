<?php
/**
 * AMGC Task Modal + SweetAlert Notification
 * Placement: paste this before </body> on user landing pages:
 * <?php require_once __DIR__ . '/../config/task_login_alert.php'; ?>
 */
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    return;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

$__task_user_id = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['employee_id'] ?? 0);
if ($__task_user_id <= 0) {
    return;
}

if (!function_exists('amgc_task_modal_table_exists')) {
    function amgc_task_modal_table_exists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $res = @$conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}
if (!amgc_task_modal_table_exists($conn, 'user_tasks') || !amgc_task_modal_table_exists($conn, 'user_task_assignees')) {
    return;
}

$__tasks = [];
$__active_task_count = 0;
$__reminder_task_count = 0;
try {
    $sql = "SELECT t.task_id, t.title, t.description, t.due_datetime, t.reminder_days, t.priority, t.status,
                   a.notify_seen, a.assignee_status, a.assignee_note
            FROM user_tasks t
            INNER JOIN user_task_assignees a ON a.task_id = t.task_id
            WHERE a.user_id = ?
              AND t.status NOT IN ('completed','cancelled')
              AND a.assignee_status NOT IN ('completed','cancelled')
            ORDER BY FIELD(a.assignee_status, 'pending', 'in_progress'), t.due_datetime ASC
            LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $__task_user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $due = strtotime((string) $row['due_datetime']);
        $reminderDays = (int) ($row['reminder_days'] ?? 0);
        $isReminderDue = $due ? (time() >= strtotime('-' . $reminderDays . ' day', $due)) : false;
        if ($isReminderDue && !in_array((string) $row['assignee_status'], ['completed', 'cancelled'], true)) {
            $__reminder_task_count++;
        }
        $__tasks[] = [
            'task_id' => (int) $row['task_id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'due_datetime' => (string) $row['due_datetime'],
            'priority' => (string) $row['priority'],
            'status' => (string) $row['status'],
            'assignee_status' => (string) $row['assignee_status'],
            'assignee_note' => (string) ($row['assignee_note'] ?? ''),
            'notify_seen' => (int) $row['notify_seen']
        ];
    }
    $stmt->close();
} catch (Throwable $e) {
    return;
}
$__task_ids = array_values(array_filter(array_map(static function ($task) {
    return (int) ($task['task_id'] ?? 0);
}, $__tasks)));
$__task_attachments = [];
if (!empty($__task_ids) && amgc_task_modal_table_exists($conn, 'user_task_attachments')) {
    $idList = implode(',', array_map('intval', $__task_ids));
    $attachmentSql = "SELECT attachment_id, task_id, original_name, mime_type, file_size
                      FROM user_task_attachments
                      WHERE task_id IN ($idList)
                      ORDER BY attachment_id ASC";
    $attachmentResult = @$conn->query($attachmentSql);
    if ($attachmentResult) {
        while ($attachment = $attachmentResult->fetch_assoc()) {
            $taskId = (int) $attachment['task_id'];
            if (!isset($__task_attachments[$taskId]))
                $__task_attachments[$taskId] = [];
            $__task_attachments[$taskId][] = [
                'attachment_id' => (int) $attachment['attachment_id'],
                'original_name' => (string) $attachment['original_name'],
                'mime_type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
                'file_size' => (int) ($attachment['file_size'] ?? 0)
            ];
        }
    }
}
foreach ($__tasks as &$__task_row) {
    $taskId = (int) ($__task_row['task_id'] ?? 0);
    $__task_row['attachments'] = $__task_attachments[$taskId] ?? [];
}
unset($__task_row);

$__active_task_count = count($__tasks);
if ($__active_task_count === 0) {
    return;
}

$__task_payload = json_encode($__tasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$__api_path = '../config/task_modal_api.php';
?>
<style>
    .amgc-task-floating-btn {
        position: fixed;
        right: 22px;
        bottom: 22px;
        z-index: 1050;
        border: 0;
        border-radius: 999px;
        background: linear-gradient(135deg, #0d7c66, #22c55e);
        color: #fff;
        padding: 13px 18px;
        font-weight: 600;
        box-shadow: 0 12px 30px rgba(13, 124, 102, .32);
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer
    }

    .amgc-task-floating-btn .task-count {
        background: #ef4444;
        color: #fff;
        border-radius: 999px;
        min-width: 22px;
        height: 22px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px
    }

    .amgc-task-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(2, 18, 31, .58);
        z-index: 3000;
        align-items: center;
        justify-content: center;
        padding: 16px;
        overscroll-behavior: contain
    }

    .swal2-container {
        z-index: 999999 !important
    }

    .amgc-task-modal.show {
        display: flex
    }

    .amgc-task-dialog {
        width: 100%;
        max-width: 920px;
        max-height: 90vh;
        background: #fff;
        border-radius: 15px;
        box-shadow: 0 25px 80px rgba(0, 0, 0, .30);
        overflow: hidden;
        display: flex;
        flex-direction: column
    }

    .amgc-task-header {
        padding: 18px 22px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #fbfffd
    }

    .amgc-task-title {
        font-size: 22px;
        font-weight: 700;
        color: #062f4f;
        margin: 0
    }

    .amgc-task-subtitle {
        font-size: 13px;
        color: #64748b;
        margin-top: 3px
    }

    .amgc-task-close {
        border: 0;
        background: transparent;
        color: #64748b;
        font-size: 25px;
        cursor: pointer
    }

    .amgc-task-body {
        padding: 20px;
        overflow: auto;
        -webkit-overflow-scrolling: touch
    }

    .amgc-task-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 14px
    }

    .amgc-task-card {
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background: #fff;
        padding: 16px;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .05)
    }

    .amgc-task-card-head {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start
    }

    .amgc-task-name {
        font-weight: 600;
        color: #062f4f;
        font-size: 16px
    }

    .amgc-task-desc {
        color: #64748b;
        font-size: 13px;
        line-height: 1.45;
        margin-top: 7px
    }

    .amgc-task-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px
    }

    .amgc-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 600
    }

    .pill-due {
        background: #eff6ff;
        color: #1d4ed8
    }

    .pill-low {
        background: #f0fdf4;
        color: #15803d
    }

    .pill-normal {
        background: #ecfdf5;
        color: #0d7c66
    }

    .pill-high {
        background: #fff7ed;
        color: #c2410c
    }

    .pill-urgent {
        background: #fef2f2;
        color: #b91c1c
    }

    .pill-pending {
        background: #f8fafc;
        color: #475569
    }

    .pill-in_progress {
        background: #eff6ff;
        color: #1d4ed8
    }

    .pill-completed {
        background: #dcfce7;
        color: #15803d
    }

    .pill-cancelled {
        background: #fee2e2;
        color: #b91c1c
    }

    .amgc-task-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 14px
    }

    .amgc-btn {
        border: 0;
        border-radius: 10px;
        padding: 10px 14px;
        font-weight: 600;
        cursor: pointer
    }

    .amgc-btn-primary {
        background: linear-gradient(135deg, #0d7c66, #22c55e);
        color: #fff
    }

    .amgc-btn-light {
        background: #f1f5f9;
        color: #334155
    }

    .amgc-task-form {
        display: none;
        margin-top: 14px;
        border-top: 1px solid #e5e7eb;
        padding-top: 14px
    }

    .amgc-task-form.show {
        display: block
    }

    .amgc-form-row {
        display: grid;
        grid-template-columns: 220px 1fr auto;
        gap: 10px;
        align-items: end
    }

    .amgc-control {
        width: 100%;
        border: 1px solid #dbe5ef;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 14px;
        outline: none
    }

    .amgc-control:focus {
        border-color: #94e4ba;
        box-shadow: 0 0 0 3px rgba(34, 197, 94, .12)
    }

    .amgc-label {
        font-size: 12px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 6px;
        display: block
    }

    .amgc-empty {
        text-align: center;
        padding: 30px;
        color: #64748b
    }

    .amgc-hidden {
        display: none !important
    }

    .amgc-task-attachments {
        margin-top: 14px;
        padding-top: 13px;
        border-top: 1px solid #e5e7eb
    }

    .amgc-attachment-title {
        font-size: 12px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 8px
    }

    .amgc-attachment-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px
    }

    .amgc-attachment-btn {
        border: 1px solid #dbe5ef;
        background: #f8fafc;
        color: #0d7c66;
        border-radius: 10px;
        padding: 8px 11px;
        font-size: 12px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        max-width: 100%;
        cursor: pointer
    }

    .amgc-attachment-btn:hover {
        background: #ecfdf5;
        border-color: #94e4ba
    }

    .amgc-attachment-name {
        max-width: 250px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap
    }

    /* Attachment Preview - same centered fullscreen style/function as motorpool(5).php */
    .amgc-preview-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 999999;
        padding: 0;
        background: rgba(0, 0, 0, .78);
        align-items: center;
        justify-content: center;
        overflow: hidden
    }

    .amgc-preview-modal.show {
        display: flex
    }

    .amgc-preview-dialog {
        position: fixed;
        inset: 0;
        width: 100vw;
        height: 100vh;
        max-width: none;
        max-height: none;
        margin: 0;
        padding: 0;
        background: transparent;
        border: 0;
        box-shadow: none;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: visible
    }

    .amgc-preview-body {
        width: 100%;
        height: 100%;
        padding: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden
    }

    .amgc-preview-wrapper {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        max-width: 92vw;
        max-height: 92vh;
        line-height: 0
    }

    .amgc-preview-content {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        max-width: 92vw;
        max-height: 92vh;
        line-height: 0
    }

    .amgc-preview-image {
        display: block;
        width: auto;
        height: auto;
        max-width: 92vw;
        max-height: 92vh;
        object-fit: contain;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 4px 30px rgba(0, 0, 0, .35)
    }

    .amgc-preview-frame {
        display: block;
        width: 92vw;
        height: 92vh;
        max-width: 1200px;
        border: 0;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 4px 30px rgba(0, 0, 0, .35)
    }

    .amgc-preview-close,
    .amgc-preview-download {
        position: absolute;
        right: 10px;
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 50%;
        background: rgba(0, 0, 0, .70);
        color: #fff;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        text-decoration: none;
        transition: transform .2s ease, background-color .2s ease;
        padding: 0;
        margin: 0;
        font-size: 17px;
        line-height: 1
    }

    .amgc-preview-close {
        top: 10px
    }

    .amgc-preview-download {
        bottom: 10px
    }

    .amgc-preview-close:hover,
    .amgc-preview-download:hover {
        background: rgba(0, 0, 0, .90);
        color: #fff;
        transform: scale(1.05)
    }

    .amgc-preview-message {
        max-width: 500px;
        padding: 22px;
        border-radius: 10px;
        background: #fff;
        color: #475569;
        text-align: center;
        line-height: 1.45;
        box-shadow: 0 4px 30px rgba(0, 0, 0, .30)
    }

    .amgc-preview-message strong {
        display: block;
        margin-bottom: 7px;
        color: #062f4f;
        font-size: 17px
    }

    .amgc-preview-loading {
        width: 38px;
        height: 38px;
        border: 4px solid rgba(255,255,255,.35);
        border-top-color: #fff;
        border-radius: 50%;
        animation: amgcPreviewSpin .75s linear infinite
    }

    @keyframes amgcPreviewSpin {
        to { transform: rotate(360deg) }
    }

    @media(max-width:991px) {
        .amgc-preview-body { padding: 10px }
        .amgc-preview-wrapper,
        .amgc-preview-content { max-width: calc(100vw - 20px); max-height: calc(100dvh - 20px) }
        .amgc-preview-image { max-width: calc(100vw - 20px); max-height: calc(100dvh - 20px) }
        .amgc-preview-frame { width: calc(100vw - 20px); height: calc(100dvh - 20px) }
    }

    @media(max-width:380px) {
        .amgc-preview-close,
        .amgc-preview-download { width: 28px; height: 28px; font-size: 14px }
    }

    @media(max-width:700px) {
        .amgc-task-dialog {
            max-height: 94vh
        }

        .amgc-task-card-head {
            display: block
        }

        .amgc-form-row {
            grid-template-columns: 1fr
        }

        .amgc-task-floating-btn {
            right: 14px;
            bottom: 82px;
            padding: 12px 18px;
            font-size: 15px;
            font-weight: 600;
            box-shadow: 0 8px 22px rgba(13, 124, 102, .22)
        }

        .amgc-task-floating-btn .task-count {
            min-width: 20px;
            height: 20px;
            font-size: 11px;
            font-weight: 600
        }
    }
</style>

<button type="button" class="amgc-task-floating-btn" onclick="amgcOpenTaskModal()">
    <span>My Task</span>
    <?php if ($__active_task_count > 0): ?><span
            class="task-count"><?php echo (int) $__active_task_count; ?></span><?php endif; ?>
</button>

<div class="amgc-task-modal" id="amgcTaskModal" aria-hidden="true">
    <div class="amgc-task-dialog">
        <div class="amgc-task-header">
            <div>
                <h3 class="amgc-task-title">My Task</h3>
                <div class="amgc-task-subtitle">View assigned tasks and update your progress.</div>
            </div>
            <button class="amgc-task-close" type="button" onclick="amgcCloseTaskModal()">&times;</button>
        </div>
        <div class="amgc-task-body">
            <div class="amgc-task-grid" id="amgcTaskList"></div>
        </div>
    </div>
</div>

<div class="amgc-preview-modal" id="amgcAttachmentPreviewModal" aria-hidden="true">
    <div class="amgc-preview-dialog" role="dialog" aria-modal="true" aria-label="Attachment Preview">
        <div class="amgc-preview-body">
            <div class="amgc-preview-wrapper">
                <button class="amgc-preview-close" type="button" onclick="amgcCloseAttachmentPreview()" aria-label="Close">&#10005;</button>
                <a class="amgc-preview-download" id="amgcAttachmentDownload" href="#" download aria-label="Download" title="Download">&#8681;</a>
                <div class="amgc-preview-content" id="amgcAttachmentPreviewBody"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    (function () {
        window.AMGC_TASKS = <?php echo $__task_payload ?: '[]'; ?>;
        window.AMGC_TASK_API = <?php echo json_encode($__api_path); ?>;
        window.AMGC_TASK_ACTIVE_COUNT = <?php echo (int) $__active_task_count; ?>;
        window.AMGC_TASK_REMINDER_COUNT = <?php echo (int) $__reminder_task_count; ?>;

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]; });
        }
        function statusText(value) { return String(value || 'pending').replace('_', ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
        function dueText(value) {
            if (!value) return '';
            var date = new Date(String(value).replace(' ', 'T'));
            return isNaN(date.getTime()) ? value : date.toLocaleString();
        }
        function formatFileSize(bytes) {
            var size = Number(bytes || 0);
            if (size < 1024) return size + ' B';
            if (size < 1048576) return (size / 1024).toFixed(1) + ' KB';
            return (size / 1048576).toFixed(1) + ' MB';
        }
        function attachmentMarkup(task) {
            var files = Array.isArray(task.attachments) ? task.attachments : [];
            if (!files.length) return '';
            return '<div class="amgc-task-attachments"><div class="amgc-attachment-title">Attachments</div><div class="amgc-attachment-list">'
                + files.map(function (file) {
                    var id = Number(file.attachment_id || 0);
                    var name = escapeHtml(file.original_name || 'Attachment');
                    var mime = escapeHtml(file.mime_type || 'application/octet-stream');
                    var size = escapeHtml(formatFileSize(file.file_size));
                    return '<button type="button" class="amgc-attachment-btn" data-attachment-id="' + id + '" data-attachment-name="' + name + '" data-attachment-mime="' + mime + '" title="' + name + '">'
                        + '<span aria-hidden="true">&#128206;</span><span class="amgc-attachment-name">' + name + '</span><small>(' + size + ')</small></button>';
                }).join('') + '</div></div>';
        }
        function renderTasks() {
            var container = document.getElementById('amgcTaskList');
            if (!container) return;
            var tasks = window.AMGC_TASKS || [];
            if (!tasks.length) { container.innerHTML = '<div class="amgc-empty">No assigned tasks.</div>'; return; }
            container.innerHTML = tasks.map(function (task) {
                var id = Number(task.task_id || 0);
                var p = escapeHtml(task.priority || 'normal');
                var s = escapeHtml(task.assignee_status || 'pending');
                return '<div class="amgc-task-card" data-task-card="' + id + '">'
                    + '<div class="amgc-task-card-head"><div><div class="amgc-task-name">' + escapeHtml(task.title) + '</div>'
                    + (task.description ? '<div class="amgc-task-desc">' + escapeHtml(task.description) + '</div>' : '')
                    + '</div><span class="amgc-pill pill-' + s + '">' + statusText(s) + '</span></div>'
                    + '<div class="amgc-task-meta"><span class="amgc-pill pill-due">Due: ' + escapeHtml(dueText(task.due_datetime)) + '</span><span class="amgc-pill pill-' + p + '">' + statusText(p) + '</span></div>'
                    + (task.assignee_note ? '<div class="amgc-task-desc"><strong>Latest Note:</strong> ' + escapeHtml(task.assignee_note) + '</div>' : '')
                    + attachmentMarkup(task)
                    + '<div class="amgc-task-actions"><button type="button" class="amgc-btn amgc-btn-primary" onclick="amgcToggleTaskForm(' + id + ')">Update Status</button></div>'
                    + '<form class="amgc-task-form" id="amgcTaskForm' + id + '" onsubmit="return amgcSubmitTaskStatus(event,' + id + ')">'
                    + '<div class="amgc-form-row"><div><label class="amgc-label">Status</label><select class="amgc-control" name="status"><option value="pending" ' + (s === 'pending' ? 'selected' : '') + '>Pending</option><option value="in_progress" ' + (s === 'in_progress' ? 'selected' : '') + '>In Progress</option><option value="completed" ' + (s === 'completed' ? 'selected' : '') + '>Completed</option><option value="cancelled" ' + (s === 'cancelled' ? 'selected' : '') + '>Cancelled</option></select></div>'
                    + '<div><label class="amgc-label">Status Note</label><input class="amgc-control" name="assignee_note" value="' + escapeHtml(task.assignee_note) + '" placeholder="Add a status note or completion comment..."></div>'
                    + '<button class="amgc-btn amgc-btn-primary" type="submit">Save Update</button></div></form>'
                    + '</div>';
            }).join('');
        }
        window.amgcAttachmentPreviewPreviousOverflow = '';
        window.amgcOpenAttachmentPreview = function (id, name, mime) {
            id = Number(id || 0);
            if (!id) return;
            var modal = document.getElementById('amgcAttachmentPreviewModal');
            var body = document.getElementById('amgcAttachmentPreviewBody');
            var download = document.getElementById('amgcAttachmentDownload');
            if (!modal || !body) return;
            var previewUrl = window.AMGC_TASK_API + '?action=view_attachment&attachment_id=' + encodeURIComponent(id);
            var downloadUrl = window.AMGC_TASK_API + '?action=download_attachment&attachment_id=' + encodeURIComponent(id);
            download.href = downloadUrl;
            download.setAttribute('download', name || 'attachment');
            body.innerHTML = '<div class="amgc-preview-loading" role="status" aria-label="Loading"></div>';
            window.amgcAttachmentPreviewPreviousOverflow = document.body.style.overflow || '';
            document.body.style.overflow = 'hidden';
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');

            var normalizedMime = String(mime || '').toLowerCase();
            window.setTimeout(function () {
                if (normalizedMime.indexOf('image/') === 0) {
                    var img = document.createElement('img');
                    img.className = 'amgc-preview-image';
                    img.alt = name || 'Attachment';
                    img.style.opacity = '0';
                    img.onload = function () { img.style.opacity = '1'; };
                    img.onerror = function () {
                        body.innerHTML = '<div class="amgc-preview-message"><strong>Unable to load this image.</strong><span>Please use the download button.</span></div>';
                    };
                    img.src = previewUrl;
                    body.innerHTML = '';
                    body.appendChild(img);
                } else if (normalizedMime === 'application/pdf') {
                    var frame = document.createElement('embed');
                    frame.className = 'amgc-preview-frame';
                    frame.type = 'application/pdf';
                    frame.src = previewUrl;
                    body.innerHTML = '';
                    body.appendChild(frame);
                } else if (normalizedMime.indexOf('text/') === 0 || normalizedMime === 'text/csv') {
                    var textFrame = document.createElement('iframe');
                    textFrame.className = 'amgc-preview-frame';
                    textFrame.title = name || 'Attachment Preview';
                    textFrame.src = previewUrl;
                    body.innerHTML = '';
                    body.appendChild(textFrame);
                } else {
                    body.innerHTML = '<div class="amgc-preview-message"><strong>This file type cannot be previewed directly.</strong><span>Please use the download button to view the attachment.</span></div>';
                }
            }, 80);
        };
        window.amgcCloseAttachmentPreview = function () {
            var modal = document.getElementById('amgcAttachmentPreviewModal');
            var body = document.getElementById('amgcAttachmentPreviewBody');
            if (modal) { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }
            if (body) body.innerHTML = '';
            document.body.style.overflow = window.amgcAttachmentPreviewPreviousOverflow || 'hidden';
        };
        window.amgcTaskPreviousBodyOverflow = '';
        window.amgcOpenTaskModal = function () {
            var modal = document.getElementById('amgcTaskModal');
            if (modal) {
                window.amgcTaskPreviousBodyOverflow = document.body.style.overflow || '';
                document.body.style.overflow = 'hidden';
                modal.classList.add('show');
                modal.setAttribute('aria-hidden', 'false');
            }
            // The task alert remains active until the assigned user completes or cancels the task.
            // Opening the modal does not clear the reminder.

        };
        window.amgcCloseTaskModal = function () {
            var modal = document.getElementById('amgcTaskModal');
            if (modal) { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }
            document.body.style.overflow = window.amgcTaskPreviousBodyOverflow || '';
        };
        window.amgcToggleTaskForm = function (id) {
            var form = document.getElementById('amgcTaskForm' + id);
            if (form) form.classList.toggle('show');
        };
        window.amgcSubmitTaskStatus = function (event, id) {
            event.preventDefault();
            var form = event.target;
            var data = new URLSearchParams(new FormData(form));
            data.append('action', 'update_status');
            data.append('task_id', id);
            fetch(window.AMGC_TASK_API, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: data.toString() })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (!json.success) { throw new Error(json.message || 'Unable to update task.'); }
                    var openForm = document.getElementById('amgcTaskForm' + id);
                    if (openForm) { openForm.classList.remove('show'); openForm.reset(); }
                    amgcCloseTaskModal();
                    Swal.fire({
                        icon: 'success',
                        title: 'Task Updated',
                        text: 'Task status updated successfully.',
                        confirmButtonColor: '#0d7c66',
                        allowOutsideClick: false
                    }).then(function () { location.reload(); });
                })
                .catch(function (err) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Update Failed',
                        text: err.message || 'Unable to update task.',
                        confirmButtonColor: '#0d7c66'
                    });
                });
            return false;
        };
        document.addEventListener('DOMContentLoaded', function () {
            renderTasks();
            var modal = document.getElementById('amgcTaskModal');
            if (modal) { modal.addEventListener('click', function (e) { if (e.target === modal) amgcCloseTaskModal(); }); }
            var previewModal = document.getElementById('amgcAttachmentPreviewModal');
            if (previewModal) { previewModal.addEventListener('click', function (e) { if (e.target === previewModal) amgcCloseAttachmentPreview(); }); }
            document.addEventListener('click', function (e) {
                var button = e.target.closest('.amgc-attachment-btn');
                if (!button) return;
                e.preventDefault();
                amgcOpenAttachmentPreview(button.dataset.attachmentId, button.dataset.attachmentName, button.dataset.attachmentMime);
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    var attachmentModal = document.getElementById('amgcAttachmentPreviewModal');
                    if (attachmentModal && attachmentModal.classList.contains('show')) amgcCloseAttachmentPreview();
                }
            });
            if (window.AMGC_TASK_ACTIVE_COUNT > 0) {
                var activeCount = Number(window.AMGC_TASK_ACTIVE_COUNT || 0);
                var reminderCount = Number(window.AMGC_TASK_REMINDER_COUNT || 0);
                var alertText = activeCount === 1
                    ? 'You have 1 active assigned task that is not yet completed.'
                    : 'You have ' + activeCount + ' active assigned tasks that are not yet completed.';

                if (reminderCount > 0) {
                    alertText += ' ' + reminderCount + ' ' +
                        (reminderCount === 1 ? 'task is' : 'tasks are') +
                        ' already within the reminder period.';
                }

                Swal.fire({
                    icon: 'info',
                    title: activeCount + ' Active Task' + (activeCount > 1 ? 's' : ''),
                    text: alertText,
                    showCancelButton: true,
                    confirmButtonText: 'View Tasks',
                    cancelButtonText: 'Later',
                    confirmButtonColor: '#0d7c66'
                }).then(function (result) { if (result.isConfirmed) { amgcOpenTaskModal(); } });
            }
        });
    })();
</script>