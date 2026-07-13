<?php
if (isset($_POST['action'])) {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

require_once '../config/database.php';
require_once '../config/session_handler.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = strtolower(trim((string)($_SESSION['role'] ?? '')));

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($user_role !== 'motorpool') {
    if ($user_role === 'branch_admin') {
        header('Location: ../Branch_Admin/branchdashboard.php');
    } elseif ($user_role === 'admin') {
        header('Location: ../Admin/dashboard.php');
    } else {
        header('Location: ../login.php');
    }
    exit;
}

$user_name = isset($_SESSION['first_name'])
    ? trim((string)$_SESSION['first_name'] . ' ' . (string)($_SESSION['last_name'] ?? ''))
    : 'Motorpool Account';

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1));
}
if ($user_initials === '') $user_initials = 'MA';

function employeeTableExists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safeTable'");
    return ($res && $res->num_rows > 0);
}

function ensureMotorpoolBranchContext(mysqli $conn): array {
    $branchName = 'Motorpool';

    if (employeeTableExists($conn, 'branches')) {
        $stmt = $conn->prepare("SELECT branch_id, branch_name FROM branches WHERE LOWER(TRIM(branch_name)) = 'motorpool' OR LOWER(TRIM(branch_name)) LIKE '%motorpool%' ORDER BY CASE WHEN LOWER(TRIM(branch_name)) = 'motorpool' THEN 0 ELSE 1 END, branch_id ASC LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return [(int)$row['branch_id'], trim((string)$row['branch_name']) ?: 'Motorpool'];
        }

        $stmt = $conn->prepare("INSERT INTO branches (branch_name) VALUES (?)");
        if ($stmt) {
            $stmt->bind_param('s', $branchName);
            if (@$stmt->execute()) {
                $newId = (int)$conn->insert_id;
                $stmt->close();
                return [$newId, $branchName];
            }
            $stmt->close();
        }
    }

    return [0, $branchName];
}

[$branch_id, $branch_name] = ensureMotorpoolBranchContext($conn);
$view_all_branches = false;
$_SESSION['branch_id'] = $branch_id;
$_SESSION['view_all_branches'] = false;

function employeeColumnExists(mysqli $conn, string $table, string $column): bool {
    $result = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "` LIKE '" . $conn->real_escape_string($column) . "'");
    return $result && $result->num_rows > 0;
}
function employeeAddColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    if (!employeeColumnExists($conn, $table, $column)) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensureEmployeeTables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS employees (
        employee_id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NULL,
        employee_name VARCHAR(150) NOT NULL,
        contact_number VARCHAR(50) NULL,
        email VARCHAR(150) NULL,
        birthday DATE NULL,
        tin VARCHAR(50) NULL,
        philhealth VARCHAR(50) NULL,
        sss VARCHAR(50) NULL,
        pagibig VARCHAR(50) NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_employee_branch (branch_id),
        INDEX idx_employee_name (employee_name),
        INDEX idx_employee_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS employee_dtr (
        dtr_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        branch_id INT NULL,
        attendance_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NULL,
        duration_minutes INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_dtr_employee_date (employee_id, attendance_date),
        INDEX idx_dtr_branch_date (branch_id, attendance_date),
        CONSTRAINT fk_employee_dtr_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $employeeColumns = [
        'source_account' => "VARCHAR(50) NULL DEFAULT NULL AFTER status",
        'source_user_id' => "INT NULL DEFAULT NULL AFTER source_account",
        'basic_pay' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER start_date",
        'pay_classification' => "VARCHAR(100) NULL AFTER basic_pay",
        'start_of_work' => "TIME NULL AFTER account_number",
        'end_of_work' => "TIME NULL AFTER start_of_work",
        'total_work_hours' => "DECIMAL(8,2) NOT NULL DEFAULT 8 AFTER end_of_work",
        'total_workdays_per_month' => "DECIMAL(8,2) NOT NULL DEFAULT 26 AFTER total_rest_days"
    ];
    foreach ($employeeColumns as $column => $definition) employeeAddColumnIfMissing($conn, 'employees', $column, $definition);

    $dtrColumns = [
        'source_account' => "VARCHAR(50) NULL DEFAULT NULL AFTER updated_at",
        'source_user_id' => "INT NULL DEFAULT NULL AFTER source_account",
        'holiday_type' => "VARCHAR(40) NOT NULL DEFAULT 'regular' AFTER duration_minutes",
        'holiday_name' => "VARCHAR(180) NULL AFTER holiday_type",
        'scheduled_work_minutes' => "INT NOT NULL DEFAULT 480 AFTER holiday_name",
        'regular_minutes' => "INT NOT NULL DEFAULT 0 AFTER scheduled_work_minutes",
        'overtime_minutes' => "INT NOT NULL DEFAULT 0 AFTER regular_minutes",
        'monthly_rate' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER overtime_minutes",
        'daily_rate' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER monthly_rate",
        'hourly_rate' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER daily_rate",
        'basic_pay' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER hourly_rate",
        'overtime_pay' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER basic_pay",
        'total_pay' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER overtime_pay"
    ];
    foreach ($dtrColumns as $column => $definition) employeeAddColumnIfMissing($conn, 'employee_dtr', $column, $definition);

    // Make old employee_dtr tables compatible with start-only records.
    $endColumn = $conn->query("SHOW COLUMNS FROM employee_dtr LIKE 'end_time'");
    if ($endColumn && ($col = $endColumn->fetch_assoc())) {
        if (stripos($col['Null'] ?? '', 'NO') !== false) {
            @$conn->query("ALTER TABLE employee_dtr MODIFY end_time TIME NULL");
        }
    }

    $submitColumns = [
        'payroll_submitted' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER total_pay",
        'payroll_submit_id' => "INT NULL AFTER payroll_submitted",
        'payroll_submitted_at' => "DATETIME NULL AFTER payroll_submit_id"
    ];
    foreach ($submitColumns as $column => $definition) employeeAddColumnIfMissing($conn, 'employee_dtr', $column, $definition);

    $otApprovalColumns = [
        'ot_requested_minutes' => "INT NOT NULL DEFAULT 0 AFTER overtime_minutes",
        'ot_approved_minutes' => "INT NOT NULL DEFAULT 0 AFTER ot_requested_minutes",
        'ot_approval_status' => "ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER ot_approved_minutes",
        'ot_approved_by' => "INT NULL AFTER ot_approval_status",
        'ot_approved_by_name' => "VARCHAR(150) NULL AFTER ot_approved_by",
        'ot_approved_at' => "DATETIME NULL AFTER ot_approved_by_name",
        'ot_approval_remarks' => "TEXT NULL AFTER ot_approved_at",
        'ot_approval_attachments' => "TEXT NULL AFTER ot_approval_remarks"
    ];
    foreach ($otApprovalColumns as $column => $definition) employeeAddColumnIfMissing($conn, 'employee_dtr', $column, $definition);

    $conn->query("CREATE TABLE IF NOT EXISTS employee_payroll_submissions (
        submit_id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NULL,
        submitted_by INT NULL,
        submitted_by_name VARCHAR(150) NULL,
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes VARCHAR(255) NULL,
        source_account VARCHAR(50) NULL DEFAULT NULL,
        source_user_id INT NULL DEFAULT NULL,
        INDEX idx_payroll_submit_branch_date (branch_id, submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $submissionColumns = [
        'source_account' => "VARCHAR(50) NULL DEFAULT NULL AFTER notes",
        'source_user_id' => "INT NULL DEFAULT NULL AFTER source_account"
    ];
    foreach ($submissionColumns as $column => $definition) employeeAddColumnIfMissing($conn, 'employee_payroll_submissions', $column, $definition);
}
ensureEmployeeTables($conn);
@$conn->query("UPDATE employees SET source_account='motorpool', source_user_id=$user_id WHERE branch_id=" . (int)$branch_id . " AND TRIM(COALESCE(source_account,'')) = ''");
@$conn->query("UPDATE employee_dtr SET source_account='motorpool', source_user_id=$user_id WHERE branch_id=" . (int)$branch_id . " AND TRIM(COALESCE(source_account,'')) = ''");

function minutesBetweenTimes(string $start, string $end): int {
    $s = strtotime('2000-01-01 ' . $start);
    $e = strtotime('2000-01-01 ' . $end);
    if ($s === false || $e === false) return 0;
    if ($e < $s) $e += 86400;

    $total = max(0, (int)(($e - $s) / 60));

    // Fixed lunch break: 12:00 PM to 1:00 PM must not be counted.
    // Deduct only the overlapped minutes, not always the full 1 hour.
    $lunchStart = strtotime('2000-01-01 12:00:00');
    $lunchEnd = strtotime('2000-01-01 13:00:00');
    $overlapStart = max($s, $lunchStart);
    $overlapEnd = min($e, $lunchEnd);
    if ($overlapEnd > $overlapStart) {
        $total -= (int)(($overlapEnd - $overlapStart) / 60);
    }

    return max(0, $total);
}
function formatDuration(?int $minutes): string {
    $hours = round(max(0, (int)$minutes) / 60, 2);
    return number_format($hours, 2, '.', '');
}
function formatPeso($amount): string {
    return '₱' . number_format((float)$amount, 2);
}
function formatMoneyPlain($amount): string {
    return number_format((float)$amount, 2);
}
function normalizeOtAttachmentList($value): array {
    if (is_array($value)) return array_values(array_filter(array_map('strval', $value)));
    $raw = trim((string)$value);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return array_values(array_filter(array_map('strval', $decoded)));
    return [$raw];
}
function saveOtApprovalAttachments(string $fieldName = 'ot_attachments'): array {
    if (empty($_FILES[$fieldName])) return [];
    $files = $_FILES[$fieldName];
    $uploadRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'ot_approval';
    if (!is_dir($uploadRoot)) {
        @mkdir($uploadRoot, 0775, true);
    }
    if (!is_dir($uploadRoot) || !is_writable($uploadRoot)) {
        throw new Exception('OT attachment upload folder is not writable. Please create uploads/ot_approval and allow write permission.');
    }

    $allowedExtensions = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx'];
    $saved = [];

    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];

    foreach ($names as $index => $originalName) {
        if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($errors[$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new Exception('Failed to upload one of the OT attachments.');
        if (($sizes[$index] ?? 0) > 10 * 1024 * 1024) throw new Exception('Each OT attachment must be 10MB or smaller.');

        $extension = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) throw new Exception('Invalid OT attachment file type.');

        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo((string)$originalName, PATHINFO_FILENAME));
        if ($safeBase === '') $safeBase = 'ot_attachment';
        $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $extension;
        $target = $uploadRoot . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file((string)$tmpNames[$index], $target)) throw new Exception('Failed to save OT attachment.');
        $saved[] = '../uploads/ot_approval/' . $fileName;
    }
    return $saved;
}
function getPhilippineHolidayInfo(string $date): array {
    $ts = strtotime($date);
    if ($ts === false) return ['type' => 'regular', 'name' => ''];

    $year = (int)date('Y', $ts);
    $md = date('m-d', $ts);

    // Philippine holidays are computed yearly instead of being locked to 2026.
    // Fixed-date holidays are added for all years. Movable holidays that can be
    // calculated reliably (Holy Week and National Heroes Day) are generated by year.
    // Lunar/Islamic holidays below are kept as yearly official/scheduled overrides
    // because their exact dates depend on proclamation/calendar confirmation.
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

    $lastMondayAugust = date('Y-m-d', strtotime('last monday of august ' . $year));
    if ($date === $lastMondayAugust) return ['type' => 'regular_holiday', 'name' => 'National Heroes Day'];

    // Holy Week based on Gregorian Easter Sunday.
    $easterTs = easter_date($year);
    $holyWeek = [
        date('Y-m-d', strtotime('-3 days', $easterTs)) => ['type' => 'regular_holiday', 'name' => 'Maundy Thursday'],
        date('Y-m-d', strtotime('-2 days', $easterTs)) => ['type' => 'regular_holiday', 'name' => 'Good Friday'],
        date('Y-m-d', strtotime('-1 day', $easterTs)) => ['type' => 'special_non_working', 'name' => 'Black Saturday']
    ];
    if (isset($holyWeek[$date])) return $holyWeek[$date];

    // Year-specific Philippine calendar holidays. Extend this list whenever
    // Malacañang/NCMF issues new proclamations for Eid or additional special days.
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
        ],
        2031 => [
            '2031-01-23' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2031-01-25' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2031-04-03' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2032 => [
            '2032-02-11' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2032-01-14' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2032-03-22' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2033 => [
            '2033-01-31' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2033-01-03' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2033-03-11' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2034 => [
            '2034-02-19' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2034-12-23' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2034-03-01' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ],
        2035 => [
            '2035-02-08' => ['type' => 'special_non_working', 'name' => 'Chinese New Year'],
            '2035-12-12' => ['type' => 'regular_holiday', 'name' => "Eid'l Fitr"],
            '2035-02-18' => ['type' => 'regular_holiday', 'name' => "Eid'l Adha"]
        ]
    ];

    return $movable[$year][$date] ?? ['type' => 'regular', 'name' => ''];
}

function computeEmployeeDayPay(array $emp, int $workedMinutes, string $holidayType): array {
    // Payroll rule: only the first 8 worked hours are Basic Pay.
    // Any excess of at least 1 full hour is paid as OT Pay.
    // Example: 9 worked hours = 8 hours Basic Pay + 1 hour OT Pay.
    //
    // Rate rule:
    // - If pay classification is monthly, employees.basic_pay is treated as Monthly Rate.
    // - If pay classification is daily, employees.basic_pay is treated as Daily Rate,
    //   and Monthly Rate is computed as Daily Rate x total workdays per month.
    // This fixes the issue where Monthly Rate was appearing the same as Daily Rate.
    $scheduledMinutes = 8 * 60;
    $baseRate = (float)($emp['basic_pay'] ?? 0);
    $workdays = (float)($emp['total_workdays_per_month'] ?? 26);
    if ($workdays <= 0) $workdays = 26;

    $payClass = strtolower(trim((string)($emp['pay_classification'] ?? 'daily')));
    $isMonthly = (
        strpos($payClass, 'month') !== false ||
        strpos($payClass, 'monthly') !== false ||
        $payClass === 'salary' ||
        $payClass === 'salaried'
    );

    if ($isMonthly) {
        $monthlyRate = $baseRate;
        $dailyRate = $workdays > 0 ? ($monthlyRate / $workdays) : 0;
    } else {
        $dailyRate = $baseRate;
        $monthlyRate = $dailyRate * $workdays;
    }

    $hourlyRate = $dailyRate / 8;

    $regularMinutes = min($workedMinutes, $scheduledMinutes);
    $rawOtMinutes = max(0, $workedMinutes - $scheduledMinutes);
    $overtimeMinutes = $rawOtMinutes >= 60 ? $rawOtMinutes : 0;

    $basicMultiplier = 1.0;
    $otMultiplier = 1.25;
    if ($holidayType === 'regular_holiday') {
        $basicMultiplier = 2.0;
        $otMultiplier = 2.6;
    } elseif ($holidayType === 'special_non_working') {
        $basicMultiplier = 1.3;
        $otMultiplier = 1.69;
    }

    $basicPay = ($regularMinutes / 60) * $hourlyRate * $basicMultiplier;
    $overtimePay = ($overtimeMinutes / 60) * $hourlyRate * $otMultiplier;
    return [
        'scheduled_minutes' => $scheduledMinutes,
        'regular_minutes' => $regularMinutes,
        'raw_overtime_minutes' => $rawOtMinutes,
        'overtime_minutes' => $overtimeMinutes,
        'monthly_rate' => $monthlyRate,
        'daily_rate' => $dailyRate,
        'hourly_rate' => $hourlyRate,
        'basic_pay' => $basicPay,
        'overtime_pay' => $overtimePay,
        'total_pay' => $basicPay + $overtimePay
    ];
}

function jsonResponse(array $response): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        if ($action === 'save_employee') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $employee_name = trim($_POST['employee_name'] ?? '');
            $contact_number = trim($_POST['contact_number'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $birthday = trim($_POST['birthday'] ?? '');
            $tin = trim($_POST['tin'] ?? '');
            $philhealth = trim($_POST['philhealth'] ?? '');
            $sss = trim($_POST['sss'] ?? '');
            $pagibig = trim($_POST['pagibig'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($employee_name === '') throw new Exception('Employee name is required');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address');
            if (!in_array($status, ['active','inactive'], true)) $status = 'active';
            $birthday_db = $birthday !== '' ? $birthday : null;

            if ($employee_id > 0) {
                $sql = "UPDATE employees SET employee_name=?, contact_number=?, email=?, birthday=?, tin=?, philhealth=?, sss=?, pagibig=?, status=?, source_account='motorpool', source_user_id=$user_id, updated_at=NOW() WHERE employee_id=?";
                if (!$view_all_branches) $sql .= " AND branch_id=" . (int)$branch_id . " AND COALESCE(source_account, '') = 'motorpool'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sssssssssi', $employee_name, $contact_number, $email, $birthday_db, $tin, $philhealth, $sss, $pagibig, $status, $employee_id);
                if (!$stmt->execute()) throw new Exception('Failed to update employee');
                jsonResponse(['success'=>true,'message'=>'Employee updated successfully']);
            } else {
                $stmt = $conn->prepare("INSERT INTO employees (branch_id, employee_name, contact_number, email, birthday, tin, philhealth, sss, pagibig, status, source_account, source_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'motorpool', $user_id, NOW(), NOW())");
                $stmt->bind_param('isssssssss', $branch_id, $employee_name, $contact_number, $email, $birthday_db, $tin, $philhealth, $sss, $pagibig, $status);
                if (!$stmt->execute()) throw new Exception('Failed to add employee');
                jsonResponse(['success'=>true,'message'=>'Employee added successfully']);
            }
        }

        if ($action === 'delete_employee') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            if ($employee_id <= 0) throw new Exception('Invalid employee');
            $sql = "DELETE FROM employees WHERE employee_id=?";
            if (!$view_all_branches) $sql .= " AND branch_id=" . (int)$branch_id . " AND COALESCE(source_account, '') = 'motorpool'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $employee_id);
            if (!$stmt->execute()) throw new Exception('Failed to delete employee');
            jsonResponse(['success'=>true,'message'=>'Employee deleted successfully']);
        }

        if ($action === 'save_dtr') {
            $dtrEntries = [];
            if (isset($_POST['employee_ids']) && is_array($_POST['employee_ids'])) {
                foreach ($_POST['employee_ids'] as $rowKey => $employeeIdValue) {
                    $employeeIdValue = (int)$employeeIdValue;
                    if ($employeeIdValue > 0) {
                        $dtrEntries[] = [
                            'row_key' => (string)$rowKey,
                            'employee_id' => $employeeIdValue
                        ];
                    }
                }
            }

            $single_employee_id = (int)($_POST['employee_id'] ?? 0);
            if (empty($dtrEntries) && $single_employee_id > 0) {
                $dtrEntries[] = [
                    'row_key' => (string)$single_employee_id,
                    'employee_id' => $single_employee_id
                ];
            }

            $attendance_dates = (isset($_POST['attendance_dates']) && is_array($_POST['attendance_dates'])) ? $_POST['attendance_dates'] : [];
            $timeIns = (isset($_POST['time_ins']) && is_array($_POST['time_ins'])) ? $_POST['time_ins'] : [];
            $timeOuts = (isset($_POST['time_outs']) && is_array($_POST['time_outs'])) ? $_POST['time_outs'] : [];
            $oldStartTimes = (isset($_POST['start_times']) && is_array($_POST['start_times'])) ? $_POST['start_times'] : [];
            $oldEndTimes = (isset($_POST['end_times']) && is_array($_POST['end_times'])) ? $_POST['end_times'] : [];
            $default_attendance_date = trim($_POST['attendance_date'] ?? date('Y-m-d'));

            if (empty($dtrEntries)) throw new Exception('Select at least one employee');

            $savedDays = 0;
            $savedPairs = 0;
            $pendingPairs = 0;
            $skippedCount = 0;
            $invalidCount = 0;

            foreach ($dtrEntries as $entry) {
                $employee_id = (int)($entry['employee_id'] ?? 0);
                $rowKey = (string)($entry['row_key'] ?? $employee_id);
                if ($employee_id <= 0) {
                    $skippedCount++;
                    continue;
                }

                $attendance_date = trim((string)($attendance_dates[$rowKey] ?? $attendance_dates[$employee_id] ?? $default_attendance_date));
                if ($attendance_date === '') {
                    $invalidCount++;
                    continue;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date)) {
                    $invalidCount++;
                    continue;
                }
                $serverToday = date('Y-m-d');
                if (strtotime($attendance_date) > strtotime($serverToday)) {
                    throw new Exception('Future dates are not allowed for attendance. Please select today or a past date only.');
                }

                $checkSql = "SELECT employee_id, branch_id, basic_pay, pay_classification, total_work_hours, total_workdays_per_month FROM employees WHERE employee_id=?";
                if (!$view_all_branches) $checkSql .= " AND branch_id=" . (int)$branch_id . " AND COALESCE(source_account, '') = 'motorpool'";
                $check = $conn->prepare($checkSql);
                $check->bind_param('i', $employee_id);
                $check->execute();
                $emp = $check->get_result()->fetch_assoc();
                $check->close();
                if (!$emp) {
                    $skippedCount++;
                    continue;
                }
                $empBranch = (int)($emp['branch_id'] ?? $branch_id);

                $rowIns = (isset($timeIns[$rowKey]) && is_array($timeIns[$rowKey])) ? $timeIns[$rowKey] : [];
                $rowOuts = (isset($timeOuts[$rowKey]) && is_array($timeOuts[$rowKey])) ? $timeOuts[$rowKey] : [];

                // Backward compatibility for the old one-time-in/one-time-out form names.
                if (empty($rowIns) && (isset($oldStartTimes[$rowKey]) || isset($oldStartTimes[$employee_id]))) {
                    $rowIns = [1 => ($oldStartTimes[$rowKey] ?? $oldStartTimes[$employee_id] ?? '')];
                    $rowOuts = [1 => ($oldEndTimes[$rowKey] ?? $oldEndTimes[$employee_id] ?? '')];
                }

                $pairs = [];
                for ($slot = 1; $slot <= 3; $slot++) {
                    $in = trim((string)($rowIns[$slot] ?? ''));
                    $out = trim((string)($rowOuts[$slot] ?? ''));
                    if ($in === '' && $out === '') continue;
                    if ($in === '') {
                        $invalidCount++;
                        continue;
                    }
                    $duration = 0;
                    if ($out !== '') {
                        $duration = minutesBetweenTimes($in, $out);
                        if ($duration <= 0) {
                            $invalidCount++;
                            continue;
                        }
                    }
                    $pairs[] = [
                        'in' => $in,
                        'out' => $out,
                        'duration' => $duration
                    ];
                }

                if (empty($pairs)) {
                    $invalidCount++;
                    continue;
                }

                $preservedOtStatus = 'none';
                $preservedOtApprovedBy = 0;
                $preservedOtApprovedByName = '';
                $preservedOtApprovedAt = '';
                $preservedOtRemarks = '';
                $preservedOtAttachments = '';
                $preserveSql = "SELECT ot_approval_status, ot_approved_by, ot_approved_by_name, ot_approved_at, ot_approval_remarks, ot_approval_attachments
                                FROM employee_dtr
                                WHERE employee_id=? AND attendance_date=?
                                  AND COALESCE(payroll_submitted,0)=0
                                  AND COALESCE(ot_requested_minutes,0)>0
                                  AND ot_approval_status IN ('approved','rejected')";
                if (!$view_all_branches) $preserveSql .= " AND branch_id=" . (int)$branch_id . " AND COALESCE(source_account, '') = 'motorpool'";
                $preserveSql .= " ORDER BY FIELD(ot_approval_status,'approved','rejected'), ot_approved_at DESC, dtr_id DESC LIMIT 1";
                $preserveStmt = $conn->prepare($preserveSql);
                $preserveStmt->bind_param('is', $employee_id, $attendance_date);
                $preserveStmt->execute();
                $preservedOtRow = $preserveStmt->get_result()->fetch_assoc();
                $preserveStmt->close();
                if ($preservedOtRow) {
                    $preservedOtStatus = (string)($preservedOtRow['ot_approval_status'] ?? 'none');
                    $preservedOtApprovedBy = (int)($preservedOtRow['ot_approved_by'] ?? 0);
                    $preservedOtApprovedByName = trim((string)($preservedOtRow['ot_approved_by_name'] ?? ''));
                    $preservedOtApprovedAt = trim((string)($preservedOtRow['ot_approved_at'] ?? ''));
                    $preservedOtRemarks = trim((string)($preservedOtRow['ot_approval_remarks'] ?? ''));
                    $preservedOtAttachments = trim((string)($preservedOtRow['ot_approval_attachments'] ?? ''));
                    if ($preservedOtApprovedBy <= 0) $preservedOtApprovedBy = (int)$user_id;
                    if ($preservedOtApprovedByName === '') $preservedOtApprovedByName = $user_name;
                    if ($preservedOtApprovedAt === '') $preservedOtApprovedAt = date('Y-m-d H:i:s');
                }

                $deleteSql = "DELETE FROM employee_dtr WHERE employee_id=? AND attendance_date=? AND COALESCE(payroll_submitted,0)=0";
                if (!$view_all_branches) $deleteSql .= " AND branch_id=" . (int)$branch_id . " AND COALESCE(source_account, '') = 'motorpool'";
                $deleteStmt = $conn->prepare($deleteSql);
                $deleteStmt->bind_param('is', $employee_id, $attendance_date);
                if (!$deleteStmt->execute()) throw new Exception('Failed to update existing DTR rows');
                $deleteStmt->close();

                $holiday = getPhilippineHolidayInfo($attendance_date);
                $completedTotalMinutes = 0;
                foreach ($pairs as $p) {
                    if ($p['out'] !== '') $completedTotalMinutes += (int)$p['duration'];
                }
                $dayPay = computeEmployeeDayPay($emp, $completedTotalMinutes, $holiday['type']);
                $remainingRegularMinutes = (int)$dayPay['regular_minutes'];
                $remainingOvertimeMinutes = (int)$dayPay['overtime_minutes'];
                $hourlyRate = (float)$dayPay['hourly_rate'];
                $basicMultiplier = ($holiday['type'] === 'regular_holiday') ? 2.0 : (($holiday['type'] === 'special_non_working') ? 1.3 : 1.0);
                $otMultiplier = ($holiday['type'] === 'regular_holiday') ? 2.6 : (($holiday['type'] === 'special_non_working') ? 1.69 : 1.25);

                foreach ($pairs as $pair) {
                    if ($pair['out'] === '') {
                        $pay = computeEmployeeDayPay($emp, 0, $holiday['type']);
                        $stmt = $conn->prepare("INSERT INTO employee_dtr (employee_id, branch_id, attendance_date, start_time, end_time, duration_minutes, holiday_type, holiday_name, scheduled_work_minutes, regular_minutes, overtime_minutes, monthly_rate, daily_rate, hourly_rate, basic_pay, overtime_pay, total_pay, source_account, source_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, 0, ?, ?, ?, 0, 0, ?, ?, ?, 0, 0, 0, 'motorpool', $user_id, NOW(), NOW())");
                        $stmt->bind_param('iissssiddd', $employee_id, $empBranch, $attendance_date, $pair['in'], $holiday['type'], $holiday['name'], $pay['scheduled_minutes'], $pay['monthly_rate'], $pay['daily_rate'], $pay['hourly_rate']);
                        $pendingPairs++;
                    } else {
                        $pairMinutes = (int)$pair['duration'];
                        $pairRegularMinutes = min($pairMinutes, $remainingRegularMinutes);
                        $remainingRegularMinutes -= $pairRegularMinutes;
                        $candidateOtMinutes = max(0, $pairMinutes - $pairRegularMinutes);
                        $pairRequestedOvertimeMinutes = min($candidateOtMinutes, $remainingOvertimeMinutes);
                        $remainingOvertimeMinutes -= $pairRequestedOvertimeMinutes;
                        $pairApprovedOvertimeMinutes = 0;
                        $pairBasicPay = ($pairRegularMinutes / 60) * $hourlyRate * $basicMultiplier;
                        $pairOvertimePay = 0;
                        $pairTotalPay = $pairBasicPay;
                        $pairOtStatus = $pairRequestedOvertimeMinutes > 0 ? 'pending' : 'none';
                        $stmt = $conn->prepare("INSERT INTO employee_dtr (employee_id, branch_id, attendance_date, start_time, end_time, duration_minutes, holiday_type, holiday_name, scheduled_work_minutes, regular_minutes, overtime_minutes, ot_requested_minutes, ot_approved_minutes, ot_approval_status, monthly_rate, daily_rate, hourly_rate, basic_pay, overtime_pay, total_pay, source_account, source_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'motorpool', $user_id, NOW(), NOW())");
                        $stmt->bind_param('iisssissiiiiisdddddd', $employee_id, $empBranch, $attendance_date, $pair['in'], $pair['out'], $pair['duration'], $holiday['type'], $holiday['name'], $dayPay['scheduled_minutes'], $pairRegularMinutes, $pairApprovedOvertimeMinutes, $pairRequestedOvertimeMinutes, $pairApprovedOvertimeMinutes, $pairOtStatus, $dayPay['monthly_rate'], $dayPay['daily_rate'], $dayPay['hourly_rate'], $pairBasicPay, $pairOvertimePay, $pairTotalPay);
                    }
                    if (!$stmt->execute()) throw new Exception('Failed to save DTR');
                    $stmt->close();
                    $savedPairs++;
                }

                if (in_array($preservedOtStatus, ['approved', 'rejected'], true)) {
                    $newOtSql = "SELECT dtr_id, ot_requested_minutes, hourly_rate, basic_pay, holiday_type
                                 FROM employee_dtr
                                 WHERE employee_id=? AND attendance_date=?
                                   AND COALESCE(payroll_submitted,0)=0
                                   AND COALESCE(ot_requested_minutes,0)>0";
                    if (!$view_all_branches) $newOtSql .= " AND branch_id=" . (int)$branch_id . " AND COALESCE(source_account, '') = 'motorpool'";
                    $newOtSql .= " ORDER BY start_time ASC, dtr_id ASC";
                    $newOtStmt = $conn->prepare($newOtSql);
                    $newOtStmt->bind_param('is', $employee_id, $attendance_date);
                    $newOtStmt->execute();
                    $newOtRows = $newOtStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $newOtStmt->close();

                    foreach ($newOtRows as $newOtRow) {
                        $requestedMinutes = (int)($newOtRow['ot_requested_minutes'] ?? 0);
                        $approvedMinutes = ($preservedOtStatus === 'approved') ? $requestedMinutes : 0;
                        $rowHolidayType = (string)($newOtRow['holiday_type'] ?? 'regular');
                        $rowOtMultiplier = ($rowHolidayType === 'regular_holiday') ? 2.6 : (($rowHolidayType === 'special_non_working') ? 1.69 : 1.25);
                        $rowHourlyRate = (float)($newOtRow['hourly_rate'] ?? 0);
                        $rowBasicPay = (float)($newOtRow['basic_pay'] ?? 0);
                        $rowOtPay = ($approvedMinutes / 60) * $rowHourlyRate * $rowOtMultiplier;
                        $rowTotalPay = $rowBasicPay + $rowOtPay;
                        $newDtrId = (int)($newOtRow['dtr_id'] ?? 0);

                        $restoreStmt = $conn->prepare("UPDATE employee_dtr
                                                       SET overtime_minutes=?, ot_approved_minutes=?, ot_approval_status=?, ot_approved_by=?, ot_approved_by_name=?, ot_approved_at=?, ot_approval_remarks=?, ot_approval_attachments=?, overtime_pay=?, total_pay=?, updated_at=NOW()
                                                       WHERE dtr_id=? AND COALESCE(payroll_submitted,0)=0");
                        $restoreStmt->bind_param('iisissssddi', $approvedMinutes, $approvedMinutes, $preservedOtStatus, $preservedOtApprovedBy, $preservedOtApprovedByName, $preservedOtApprovedAt, $preservedOtRemarks, $preservedOtAttachments, $rowOtPay, $rowTotalPay, $newDtrId);
                        if (!$restoreStmt->execute()) throw new Exception('Failed to preserve OT approval.');
                        $restoreStmt->close();
                    }
                }
                $savedDays++;
            }

            if ($savedDays === 0) {
                throw new Exception('No DTR record was saved. Make sure each row has an employee, date, and valid Time In/Time Out values.');
            }

            $parts = [];
            $parts[] = $savedDays . ' employee day row(s) saved';
            $parts[] = $savedPairs . ' time pair(s) saved';
            if ($pendingPairs > 0) $parts[] = $pendingPairs . ' pending time out';
            if ($invalidCount > 0) $parts[] = $invalidCount . ' invalid/skipped';
            if ($skippedCount > 0) $parts[] = $skippedCount . ' skipped';
            jsonResponse(['success'=>true,'message'=>'DTR processed: ' . implode(', ', $parts) . '. Salary, basic pay, holidays, and OT requests were computed. OT below 1 hour is not counted. OT pay will be added only after approval. Lunch break 12:00 PM to 1:00 PM was excluded from completed durations.']);
        }


        if ($action === 'approve_ot') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $attendance_date = trim((string)($_POST['attendance_date'] ?? ''));
            $decision = trim((string)($_POST['decision'] ?? 'approve'));
            if ($employee_id <= 0 || $attendance_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date)) throw new Exception('Invalid OT approval request.');
            if (!in_array($decision, ['approve','reject'], true)) $decision = 'approve';
            $otRemarks = trim((string)($_POST['ot_remarks'] ?? ''));
            if ($otRemarks === '') {
                throw new Exception('Remarks is required before submitting OT approval.');
            }
            $hasUploadedOtAttachment = false;
            if (isset($_FILES['ot_attachments']) && is_array($_FILES['ot_attachments']['name'] ?? null)) {
                foreach ($_FILES['ot_attachments']['name'] as $fileIndex => $fileName) {
                    $fileError = $_FILES['ot_attachments']['error'][$fileIndex] ?? UPLOAD_ERR_NO_FILE;
                    if ($fileError === UPLOAD_ERR_OK && trim((string)$fileName) !== '') {
                        $hasUploadedOtAttachment = true;
                        break;
                    }
                }
            }
            if (!$hasUploadedOtAttachment) {
                throw new Exception('Attachment is required before submitting OT approval.');
            }
            $newOtAttachments = saveOtApprovalAttachments('ot_attachments');
            $otAttachmentsJson = json_encode($newOtAttachments, JSON_UNESCAPED_SLASHES);

            $fetchSql = "SELECT dtr_id, duration_minutes, regular_minutes, ot_requested_minutes, monthly_rate, daily_rate, hourly_rate, basic_pay, holiday_type
                         FROM employee_dtr
                         WHERE employee_id=? AND attendance_date=? AND COALESCE(payroll_submitted,0)=0";
            if (!$view_all_branches) $fetchSql .= " AND branch_id=" . (int)$branch_id . " AND COALESCE(source_account, '') = 'motorpool'";
            $fetchSql .= " ORDER BY start_time ASC, dtr_id ASC";
            $fetchStmt = $conn->prepare($fetchSql);
            $fetchStmt->bind_param('is', $employee_id, $attendance_date);
            $fetchStmt->execute();
            $rows = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $fetchStmt->close();
            if (empty($rows)) throw new Exception('No open DTR row found for OT approval.');

            $requestedTotal = 0;
            foreach ($rows as $r) $requestedTotal += (int)($r['ot_requested_minutes'] ?? 0);
            if ($requestedTotal <= 0) throw new Exception('This DTR has no OT request to approve.');

            $conn->begin_transaction();
            try {
                $remainingApproval = ($decision === 'approve') ? $requestedTotal : 0;
                $status = ($decision === 'approve') ? 'approved' : 'rejected';
                foreach ($rows as $r) {
                    $requested = (int)($r['ot_requested_minutes'] ?? 0);
                    $approved = min($requested, $remainingApproval);
                    $remainingApproval -= $approved;
                    $holidayType = (string)($r['holiday_type'] ?? 'regular');
                    $otMultiplier = ($holidayType === 'regular_holiday') ? 2.6 : (($holidayType === 'special_non_working') ? 1.69 : 1.25);
                    $hourlyRate = (float)($r['hourly_rate'] ?? 0);
                    $basicPay = (float)($r['basic_pay'] ?? 0);
                    $otPay = ($approved / 60) * $hourlyRate * $otMultiplier;
                    $totalPay = $basicPay + $otPay;
                    $update = $conn->prepare("UPDATE employee_dtr
                                             SET overtime_minutes=?, ot_approved_minutes=?, ot_approval_status=?, ot_approved_by=?, ot_approved_by_name=?, ot_approved_at=NOW(), ot_approval_remarks=?, ot_approval_attachments=?, overtime_pay=?, total_pay=?, updated_at=NOW()
                                             WHERE dtr_id=? AND COALESCE(payroll_submitted,0)=0");
                    $dtrId = (int)$r['dtr_id'];
                    $update->bind_param('iisisssddi', $approved, $approved, $status, $user_id, $user_name, $otRemarks, $otAttachmentsJson, $otPay, $totalPay, $dtrId);
                    if (!$update->execute()) throw new Exception('Failed to update OT approval.');
                    $update->close();
                }
                $conn->commit();
                $approvedText = ($decision === 'approve') ? formatDuration($requestedTotal) . ' hour(s) approved' : 'OT request rejected';
                jsonResponse(['success'=>true,'message'=>'OT approval updated successfully. ' . $approvedText . '.']);
            } catch (Exception $inner) {
                $conn->rollback();
                throw $inner;
            }
        }

        if ($action === 'submit_for_payroll') {
            $dtrEntries = [];
            if (isset($_POST['employee_ids']) && is_array($_POST['employee_ids'])) {
                foreach ($_POST['employee_ids'] as $rowKey => $employeeIdValue) {
                    $employeeIdValue = (int)$employeeIdValue;
                    if ($employeeIdValue > 0) {
                        $dtrEntries[] = [
                            'row_key' => (string)$rowKey,
                            'employee_id' => $employeeIdValue
                        ];
                    }
                }
            }

            $attendance_dates = (isset($_POST['attendance_dates']) && is_array($_POST['attendance_dates'])) ? $_POST['attendance_dates'] : [];
            if (empty($dtrEntries)) throw new Exception('Select at least one completed DTR row to submit.');

            $targets = [];
            foreach ($dtrEntries as $entry) {
                $employee_id = (int)($entry['employee_id'] ?? 0);
                $rowKey = (string)($entry['row_key'] ?? $employee_id);
                $attendance_date = trim((string)($attendance_dates[$rowKey] ?? $attendance_dates[$employee_id] ?? ''));
                if ($employee_id <= 0 || $attendance_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date)) continue;
                $targets[$employee_id . '|' . $attendance_date] = ['employee_id' => $employee_id, 'attendance_date' => $attendance_date];
            }
            if (empty($targets)) throw new Exception('No valid DTR row was selected for payroll submission.');

            $conn->begin_transaction();
            try {
                $insert = $conn->prepare("INSERT INTO employee_payroll_submissions (branch_id, submitted_by, submitted_by_name, submitted_at, notes, source_account, source_user_id) VALUES (?, ?, ?, NOW(), ?, 'motorpool', $user_id)");
                $notes = 'DTR submitted for payroll';
                $insert->bind_param('iiss', $branch_id, $user_id, $user_name, $notes);
                if (!$insert->execute()) throw new Exception('Failed to create payroll submission history.');
                $submit_id = (int)$conn->insert_id;
                $insert->close();

                $submittedGroups = 0;
                $submittedRows = 0;
                foreach ($targets as $target) {
                    $employee_id = (int)$target['employee_id'];
                    $attendance_date = (string)$target['attendance_date'];

                    $checkSql = "SELECT COUNT(*) AS total_rows,
                                       SUM(CASE WHEN end_time IS NULL OR end_time = '00:00:00' THEN 1 ELSE 0 END) AS pending_rows,
                                       SUM(CASE WHEN COALESCE(payroll_submitted,0) = 0 THEN 1 ELSE 0 END) AS open_rows,
                                       SUM(CASE WHEN COALESCE(ot_requested_minutes,0) > 0 AND ot_approval_status = 'pending' THEN 1 ELSE 0 END) AS pending_ot_rows
                                FROM employee_dtr
                                WHERE employee_id=? AND attendance_date=?";
                    if (!$view_all_branches) $checkSql .= " AND branch_id=" . (int)$branch_id . " AND COALESCE(source_account, '') = 'motorpool'";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->bind_param('is', $employee_id, $attendance_date);
                    $checkStmt->execute();
                    $checkRow = $checkStmt->get_result()->fetch_assoc();
                    $checkStmt->close();

                    $totalRows = (int)($checkRow['total_rows'] ?? 0);
                    $pendingRows = (int)($checkRow['pending_rows'] ?? 0);
                    $openRows = (int)($checkRow['open_rows'] ?? 0);
                    $pendingOtRows = (int)($checkRow['pending_ot_rows'] ?? 0);
                    if ($totalRows <= 0 || $openRows <= 0) continue;
                    if ($pendingRows > 0) throw new Exception('Complete all Time Out fields before submitting DTR to payroll.');
                    if ($pendingOtRows > 0) throw new Exception('Approve or reject pending OT before submitting DTR to payroll.');

                    $updateSql = "UPDATE employee_dtr SET payroll_submitted=1, payroll_submit_id=?, payroll_submitted_at=NOW()
                                  WHERE employee_id=? AND attendance_date=? AND COALESCE(payroll_submitted,0)=0";
                    if (!$view_all_branches) $updateSql .= " AND branch_id=" . (int)$branch_id . " AND COALESCE(source_account, '') = 'motorpool'";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param('iis', $submit_id, $employee_id, $attendance_date);
                    if (!$updateStmt->execute()) throw new Exception('Failed to submit DTR rows for payroll.');
                    if ($updateStmt->affected_rows > 0) {
                        $submittedGroups++;
                        $submittedRows += (int)$updateStmt->affected_rows;
                    }
                    $updateStmt->close();
                }

                if ($submittedGroups <= 0) throw new Exception('No completed and unsubmitted DTR rows were found. Save the DTR first, then submit it for payroll.');
                $conn->commit();
                jsonResponse(['success'=>true,'message'=>'DTR submitted for payroll successfully. ' . $submittedGroups . ' employee day row(s) and ' . $submittedRows . ' time pair(s) were added to history.']);
            } catch (Exception $inner) {
                $conn->rollback();
                throw $inner;
            }
        }

        throw new Exception('Invalid action');
    } catch (Exception $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()]);
    }
}

$motorpoolEmployeeScope = "e.branch_id = " . (int)$branch_id . " AND COALESCE(e.source_account, '') = 'motorpool'";
$motorpoolDtrScope = "d.branch_id = " . (int)$branch_id . " AND COALESCE(d.source_account, '') = 'motorpool'";
$where = $motorpoolEmployeeScope;
$employees = [];
$res = $conn->query("SELECT e.*, b.branch_name FROM employees e LEFT JOIN branches b ON e.branch_id=b.branch_id WHERE $where ORDER BY e.employee_name ASC");
if ($res) while ($row = $res->fetch_assoc()) $employees[] = $row;

$employeeById = [];
foreach ($employees as $empRow) {
    $employeeById[(int)($empRow['employee_id'] ?? 0)] = $empRow;
}

$dtrRowsRaw = [];
$dtrGroupedRows = [];
$dtrSql = "SELECT d.*, e.employee_name, e.contact_number, b.branch_name
          FROM employee_dtr d
          INNER JOIN employees e ON d.employee_id=e.employee_id
          LEFT JOIN branches b ON d.branch_id=b.branch_id
          WHERE " . $motorpoolDtrScope . "
          ORDER BY d.attendance_date DESC, e.employee_name ASC, d.start_time ASC, d.dtr_id ASC LIMIT 1200";
$res = $conn->query($dtrSql);
if ($res) while ($row = $res->fetch_assoc()) $dtrRowsRaw[] = $row;

foreach ($dtrRowsRaw as $row) {
    $groupKey = (string)($row['employee_id'] ?? '') . '|' . (string)($row['attendance_date'] ?? '');
    if (!isset($dtrGroupedRows[$groupKey])) {
        $dtrGroupedRows[$groupKey] = [
            'employee_id' => (int)($row['employee_id'] ?? 0),
            'employee_name' => (string)($row['employee_name'] ?? ''),
            'contact_number' => (string)($row['contact_number'] ?? ''),
            'branch_name' => (string)($row['branch_name'] ?? ''),
            'branch_id' => (int)($row['branch_id'] ?? 0),
            'attendance_date' => (string)($row['attendance_date'] ?? ''),
            'slots' => [],
            'daily_total_minutes' => 0,
            'has_pending' => false,
            'holiday_type' => (string)($row['holiday_type'] ?? 'regular'),
            'holiday_name' => (string)($row['holiday_name'] ?? ''),
            'regular_minutes' => 0,
            'overtime_minutes' => 0,
            'ot_requested_minutes' => 0,
            'ot_approved_minutes' => 0,
            'ot_approval_status' => 'none',
            'ot_approved_by_name' => '',
            'ot_approved_at' => '',
            'ot_approval_remarks' => '',
            'ot_approval_attachments' => '',
            'basic_pay' => 0,
            'overtime_pay' => 0,
            'total_pay' => 0,
            'monthly_rate' => 0,
            'daily_rate' => 0,
            'hourly_rate' => 0,
            'scheduled_minutes' => 480,
            'payroll_submitted' => (int)($row['payroll_submitted'] ?? 0),
            'payroll_submit_id' => (int)($row['payroll_submit_id'] ?? 0),
            'payroll_submitted_at' => (string)($row['payroll_submitted_at'] ?? '')
        ];
    }

    if ((int)($row['payroll_submitted'] ?? 0) === 1) {
        $dtrGroupedRows[$groupKey]['payroll_submitted'] = 1;
        if (!empty($row['payroll_submit_id'])) $dtrGroupedRows[$groupKey]['payroll_submit_id'] = (int)$row['payroll_submit_id'];
        if (!empty($row['payroll_submitted_at'])) $dtrGroupedRows[$groupKey]['payroll_submitted_at'] = (string)$row['payroll_submitted_at'];
    }

    if (count($dtrGroupedRows[$groupKey]['slots']) < 3) {
        $hasEnd = !empty($row['end_time']) && $row['end_time'] !== '00:00:00';
        $dtrGroupedRows[$groupKey]['slots'][] = [
            'dtr_id' => (int)($row['dtr_id'] ?? 0),
            'start_time' => (string)($row['start_time'] ?? ''),
            'end_time' => $hasEnd ? (string)$row['end_time'] : '',
            'duration_minutes' => (int)($row['duration_minutes'] ?? 0),
            'is_open' => !$hasEnd
        ];
    }

    $dtrGroupedRows[$groupKey]['daily_total_minutes'] += (int)($row['duration_minutes'] ?? 0);
    $dtrGroupedRows[$groupKey]['regular_minutes'] += (int)($row['regular_minutes'] ?? 0);
    $dtrGroupedRows[$groupKey]['overtime_minutes'] += (int)($row['overtime_minutes'] ?? 0);
    $dtrGroupedRows[$groupKey]['ot_requested_minutes'] += (int)($row['ot_requested_minutes'] ?? 0);
    $dtrGroupedRows[$groupKey]['ot_approved_minutes'] += (int)($row['ot_approved_minutes'] ?? 0);
    if ((int)($row['ot_requested_minutes'] ?? 0) > 0) {
        $rowStatus = (string)($row['ot_approval_status'] ?? 'none');
        if ($dtrGroupedRows[$groupKey]['ot_approval_status'] !== 'pending') $dtrGroupedRows[$groupKey]['ot_approval_status'] = $rowStatus;
        if ($rowStatus === 'pending') $dtrGroupedRows[$groupKey]['ot_approval_status'] = 'pending';
        if (!empty($row['ot_approved_by_name'])) $dtrGroupedRows[$groupKey]['ot_approved_by_name'] = (string)$row['ot_approved_by_name'];
        if (!empty($row['ot_approved_at'])) $dtrGroupedRows[$groupKey]['ot_approved_at'] = (string)$row['ot_approved_at'];
        if (!empty($row['ot_approval_remarks'])) $dtrGroupedRows[$groupKey]['ot_approval_remarks'] = (string)$row['ot_approval_remarks'];
        if (!empty($row['ot_approval_attachments'])) $dtrGroupedRows[$groupKey]['ot_approval_attachments'] = (string)$row['ot_approval_attachments'];
    }
    $dtrGroupedRows[$groupKey]['basic_pay'] += (float)($row['basic_pay'] ?? 0);
    $dtrGroupedRows[$groupKey]['overtime_pay'] += (float)($row['overtime_pay'] ?? 0);
    $dtrGroupedRows[$groupKey]['total_pay'] += (float)($row['total_pay'] ?? 0);
    if (empty($row['end_time']) || $row['end_time'] === '00:00:00') {
        $dtrGroupedRows[$groupKey]['has_pending'] = true;
    }
}
foreach ($dtrGroupedRows as &$groupRow) {
    $dateForHoliday = (string)($groupRow['attendance_date'] ?? '');
    $generatedHoliday = $dateForHoliday !== '' ? getPhilippineHolidayInfo($dateForHoliday) : ['type' => 'regular', 'name' => ''];
    if (($generatedHoliday['type'] ?? 'regular') !== 'regular') {
        $groupRow['holiday_type'] = $generatedHoliday['type'];
        $groupRow['holiday_name'] = $generatedHoliday['name'];
    } elseif (empty($groupRow['holiday_name'])) {
        $groupRow['holiday_type'] = 'regular';
        $groupRow['holiday_name'] = '';
    }

    if ((int)($groupRow['daily_total_minutes'] ?? 0) > 0 && isset($employeeById[(int)$groupRow['employee_id']])) {
        // Always recompute grouped display from the full daily total so Basic Pay does not grow beyond 8 hours.
        $computedPay = computeEmployeeDayPay($employeeById[(int)$groupRow['employee_id']], (int)$groupRow['daily_total_minutes'], (string)$groupRow['holiday_type']);
        $groupRow['regular_minutes'] = (int)$computedPay['regular_minutes'];
        $approvedOtMinutes = (int)($groupRow['ot_approved_minutes'] ?? 0);
        $groupRow['overtime_minutes'] = $approvedOtMinutes;
        $basicMultiplier = ($groupRow['holiday_type'] === 'regular_holiday') ? 2.0 : (($groupRow['holiday_type'] === 'special_non_working') ? 1.3 : 1.0);
        $otMultiplier = ($groupRow['holiday_type'] === 'regular_holiday') ? 2.6 : (($groupRow['holiday_type'] === 'special_non_working') ? 1.69 : 1.25);
        $groupRow['basic_pay'] = ((int)$computedPay['regular_minutes'] / 60) * (float)$computedPay['hourly_rate'] * $basicMultiplier;
        $groupRow['overtime_pay'] = ($approvedOtMinutes / 60) * (float)$computedPay['hourly_rate'] * $otMultiplier;
        $groupRow['total_pay'] = (float)$groupRow['basic_pay'] + (float)$groupRow['overtime_pay'];
        $groupRow['monthly_rate'] = (float)$computedPay['monthly_rate'];
        $groupRow['daily_rate'] = (float)$computedPay['daily_rate'];
        $groupRow['hourly_rate'] = (float)$computedPay['hourly_rate'];
        $groupRow['scheduled_minutes'] = (int)$computedPay['scheduled_minutes'];
    } elseif (isset($employeeById[(int)$groupRow['employee_id']])) {
        $computedPay = computeEmployeeDayPay($employeeById[(int)$groupRow['employee_id']], 0, (string)$groupRow['holiday_type']);
        $groupRow['monthly_rate'] = (float)$computedPay['monthly_rate'];
        $groupRow['daily_rate'] = (float)$computedPay['daily_rate'];
        $groupRow['hourly_rate'] = (float)$computedPay['hourly_rate'];
        $groupRow['scheduled_minutes'] = (int)$computedPay['scheduled_minutes'];
    }
}
unset($groupRow);
$dtrRows = array_values($dtrGroupedRows);

$payrollHistoryRaw = [];
$payrollHistoryGrouped = [];
$payrollHistorySql = "SELECT d.*, e.employee_name, e.contact_number, b.branch_name, ps.submitted_at, ps.submitted_by_name
                     FROM employee_dtr d
                     INNER JOIN employees e ON d.employee_id=e.employee_id
                     LEFT JOIN branches b ON d.branch_id=b.branch_id
                     LEFT JOIN employee_payroll_submissions ps ON d.payroll_submit_id=ps.submit_id
                     WHERE " . $motorpoolDtrScope . "
                       AND COALESCE(d.payroll_submitted,0)=1
                     ORDER BY COALESCE(ps.submitted_at, d.payroll_submitted_at) DESC, d.attendance_date DESC, e.employee_name ASC, d.start_time ASC, d.dtr_id ASC LIMIT 1200";
$res = $conn->query($payrollHistorySql);
if ($res) while ($row = $res->fetch_assoc()) $payrollHistoryRaw[] = $row;

foreach ($payrollHistoryRaw as $row) {
    $groupKey = (string)($row['payroll_submit_id'] ?? 0) . '|' . (string)($row['employee_id'] ?? '') . '|' . (string)($row['attendance_date'] ?? '');
    if (!isset($payrollHistoryGrouped[$groupKey])) {
        $payrollHistoryGrouped[$groupKey] = [
            'submit_id' => (int)($row['payroll_submit_id'] ?? 0),
            'submitted_at' => (string)($row['submitted_at'] ?? $row['payroll_submitted_at'] ?? ''),
            'submitted_by_name' => (string)($row['submitted_by_name'] ?? ''),
            'employee_id' => (int)($row['employee_id'] ?? 0),
            'employee_name' => (string)($row['employee_name'] ?? ''),
            'contact_number' => (string)($row['contact_number'] ?? ''),
            'branch_name' => (string)($row['branch_name'] ?? ''),
            'attendance_date' => (string)($row['attendance_date'] ?? ''),
            'slots' => [],
            'daily_total_minutes' => 0,
            'holiday_type' => (string)($row['holiday_type'] ?? 'regular'),
            'holiday_name' => (string)($row['holiday_name'] ?? ''),
            'regular_minutes' => 0,
            'overtime_minutes' => 0,
            'ot_requested_minutes' => 0,
            'ot_approved_minutes' => 0,
            'ot_approval_status' => 'none',
            'ot_approved_by_name' => '',
            'ot_approved_at' => '',
            'ot_approval_remarks' => '',
            'ot_approval_attachments' => '',
            'basic_pay' => 0,
            'overtime_pay' => 0,
            'total_pay' => 0,
            'monthly_rate' => 0,
            'daily_rate' => 0,
            'hourly_rate' => 0
        ];
    }
    if (count($payrollHistoryGrouped[$groupKey]['slots']) < 3) {
        $payrollHistoryGrouped[$groupKey]['slots'][] = [
            'start_time' => (string)($row['start_time'] ?? ''),
            'end_time' => (!empty($row['end_time']) && $row['end_time'] !== '00:00:00') ? (string)$row['end_time'] : '',
            'duration_minutes' => (int)($row['duration_minutes'] ?? 0)
        ];
    }
    $payrollHistoryGrouped[$groupKey]['daily_total_minutes'] += (int)($row['duration_minutes'] ?? 0);
    $payrollHistoryGrouped[$groupKey]['regular_minutes'] += (int)($row['regular_minutes'] ?? 0);
    $payrollHistoryGrouped[$groupKey]['overtime_minutes'] += (int)($row['overtime_minutes'] ?? 0);
    $payrollHistoryGrouped[$groupKey]['ot_requested_minutes'] += (int)($row['ot_requested_minutes'] ?? 0);
    $payrollHistoryGrouped[$groupKey]['ot_approved_minutes'] += (int)($row['ot_approved_minutes'] ?? 0);
    if ((int)($row['ot_requested_minutes'] ?? 0) > 0) {
        $rowStatus = (string)($row['ot_approval_status'] ?? 'none');
        if ($payrollHistoryGrouped[$groupKey]['ot_approval_status'] !== 'pending') $payrollHistoryGrouped[$groupKey]['ot_approval_status'] = $rowStatus;
        if ($rowStatus === 'pending') $payrollHistoryGrouped[$groupKey]['ot_approval_status'] = 'pending';
        if (!empty($row['ot_approved_by_name'])) $payrollHistoryGrouped[$groupKey]['ot_approved_by_name'] = (string)$row['ot_approved_by_name'];
        if (!empty($row['ot_approved_at'])) $payrollHistoryGrouped[$groupKey]['ot_approved_at'] = (string)$row['ot_approved_at'];
        if (!empty($row['ot_approval_remarks'])) $payrollHistoryGrouped[$groupKey]['ot_approval_remarks'] = (string)$row['ot_approval_remarks'];
        if (!empty($row['ot_approval_attachments'])) $payrollHistoryGrouped[$groupKey]['ot_approval_attachments'] = (string)$row['ot_approval_attachments'];
    }
    $payrollHistoryGrouped[$groupKey]['basic_pay'] += (float)($row['basic_pay'] ?? 0);
    $payrollHistoryGrouped[$groupKey]['overtime_pay'] += (float)($row['overtime_pay'] ?? 0);
    $payrollHistoryGrouped[$groupKey]['total_pay'] += (float)($row['total_pay'] ?? 0);
    if ((float)($row['monthly_rate'] ?? 0) > 0) $payrollHistoryGrouped[$groupKey]['monthly_rate'] = (float)$row['monthly_rate'];
    if ((float)($row['daily_rate'] ?? 0) > 0) $payrollHistoryGrouped[$groupKey]['daily_rate'] = (float)$row['daily_rate'];
    if ((float)($row['hourly_rate'] ?? 0) > 0) $payrollHistoryGrouped[$groupKey]['hourly_rate'] = (float)$row['hourly_rate'];
}
$payrollHistoryRows = array_values($payrollHistoryGrouped);

$totalEmployees = count($employees);
$activeEmployees = count(array_filter($employees, fn($e) => ($e['status'] ?? '') === 'active'));
$today = date('Y-m-d');
$todayMinutes = 0;
$todayEmployees = [];
$dtrRecordMap = [];
foreach ($dtrRows as $r) {
    $mapEmployeeId = (string)($r['employee_id'] ?? '');
    $mapDate = (string)($r['attendance_date'] ?? '');
    if ($mapEmployeeId !== '' && $mapDate !== '' && (int)($r['payroll_submitted'] ?? 0) === 0) {
        if (!isset($dtrRecordMap[$mapEmployeeId])) $dtrRecordMap[$mapEmployeeId] = [];
        $dtrRecordMap[$mapEmployeeId][$mapDate] = [
            'employee_id' => (int)($r['employee_id'] ?? 0),
            'attendance_date' => $mapDate,
            'slots' => $r['slots'] ?? [],
            'is_open' => !empty($r['has_pending'])
        ];
    }
    if (($r['attendance_date'] ?? '') === $today) {
        $todayMinutes += (int)($r['daily_total_minutes'] ?? 0);
        $todayEmployees[$r['employee_id']] = true;
    }
}


$calendarEvents = [];
$calendarHolidayMap = [];
$calendarYears = array_unique(array_map(function($r){ return substr((string)($r['attendance_date'] ?? date('Y-m-d')), 0, 4); }, $dtrRows));
$calendarYears[] = date('Y');
foreach (array_unique($calendarYears) as $calYear) {
    $calYear = (int)$calYear;
    if ($calYear <= 0) continue;
    $startTs = strtotime($calYear . '-01-01');
    $endTs = strtotime($calYear . '-12-31');
    for ($dayTs = $startTs; $dayTs <= $endTs; $dayTs += 86400) {
        $day = date('Y-m-d', $dayTs);
        $h = getPhilippineHolidayInfo($day);
        if (($h['type'] ?? 'regular') !== 'regular') {
            $calendarHolidayMap[$day] = $h;
            $calendarEvents[] = [
                'date' => $day,
                'type' => 'holiday',
                'holiday_type' => $h['type'],
                'title' => $h['name']
            ];
        }
    }
}
foreach ($dtrRows as $r) {
    $calendarEvents[] = [
        'date' => (string)($r['attendance_date'] ?? ''),
        'type' => 'attendance',
        'title' => (string)($r['employee_name'] ?? ''),
        'hours' => formatDuration((int)($r['daily_total_minutes'] ?? 0)),
        'pay' => number_format((float)($r['total_pay'] ?? 0), 2),
        'pending' => !empty($r['has_pending'])
    ];
}
ksort($calendarHolidayMap);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Motorpool Employee Attendance</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
<link rel="shortcut icon" href="../Pictures/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
<link rel="manifest" href="../Pictures/site.webmanifest" />
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../js/session-checker.js"></script>
<style>
        /* Branch badge styling */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Table styles */
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            table-layout: fixed;
        }
        
        .user-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 14px 8px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: center;
        }
        
        .user-table tbody td {
            padding: 12px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
            text-align: center;
            word-wrap: break-word;
        }
        
        .user-table tbody td:first-child {
            text-align: left;
        }
        
        .user-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Role-specific row styling */
        .role-delivery {
            border-left: 4px solid #0d6efd;
        }
        
        .role-warehouse {
            border-left: 4px solid #198754;
        }
        
        .role-sales {
            border-left: 4px solid #ffc107;
        }
        
        /* Column widths */
        .col-name { width: 24%; }
        .col-role { width: 13%; }
        .col-details { width: 25%; }
        .col-contact { width: 15%; }
        .col-status { width: 12%; }
        .col-branch { width: 11%; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 70px;
        }
        
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        
        .role-badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 20px;
            text-align: center;
            min-width: 90px;
        }
        
        .role-badge i {
            margin-right: 4px;
        }
        
        .empty-state-table {
            text-align: center;
            padding: 40px 20px;
            background-color: white;
            border-radius: 8px;
        }
        
        .empty-state-table i {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 16px;
        }
        
        .empty-state-table h5 {
            color: #495057;
            margin-bottom: 8px;
        }
        
        .empty-state-table p {
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
            align-items: center;
            flex-wrap: nowrap;
        }
        
        .table-btn {
            background: none;
            border: none;
            padding: 6px 8px;
            border-radius: 4px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
        }
        
        .table-btn i {
            margin-right: 3px;
        }
        
        .table-btn:hover {
            background-color: #e9ecef;
        }
        
        .btn-view { 
            color: #0d6efd;
            border: 1px solid #0d6efd;
        }
        .btn-edit { 
            color: #198754;
            border: 1px solid #198754;
        }
        .btn-delete { 
            color: #dc3545;
            border: 1px solid #dc3545;
        }
        
        .btn-view:hover { 
            background-color: #0d6efd;
            color: white;
        }
        .btn-edit:hover { 
            background-color: #198754;
            color: white;
        }
        .btn-delete:hover { 
            background-color: #dc3545;
            color: white;
        }
        
        /* Add User Buttons - Outside Filter */
        .add-user-buttons-wrapper {
            margin-bottom: 1.25rem;
            text-align: right;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .btn-add-driver,
        .btn-add-warehouse,
        .btn-add-sales,
        .btn-outline-success {
            background: linear-gradient(135deg, #047857, #059669) !important;
            color: white !important;
            border: none !important;
            border-radius: 10px;
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(4, 120, 87, 0.22);
            cursor: pointer;
        }
        
        .btn-add-driver:hover,
        .btn-add-warehouse:hover,
        .btn-add-sales:hover,
        .btn-outline-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(4, 120, 87, 0.32);
            background: linear-gradient(135deg, #059669, #44D34E) !important;
            color: white !important;
        }
        
        .btn-outline-success i,
        .btn-add-driver i,
        .btn-add-warehouse i,
        .btn-add-sales i {
            color: white !important;
        }


        /* ===== USERS SECTION ===== */
        .management-tabs {
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1.25rem;
            gap: 0.5rem;
        }
        .management-tabs .nav-link {
            border: none !important;
            border-radius: 12px 12px 0 0 !important;
            color: #052A47 !important;
            font-weight: 700 !important;
            padding: 0.8rem 1.1rem !important;
            background: #f8fafc !important;
        }
        .management-tabs .nav-link.active {
            background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
            color: #ffffff !important;
            box-shadow: 0 8px 18px rgba(4, 120, 87, 0.18) !important;
        }
        @media (max-width: 576px) {
            .management-tabs .nav-link {
                width: 100%;
                border-radius: 12px !important;
                text-align: center;
            }
        }
        
        @media (max-width: 768px) {
            .add-user-buttons-wrapper {
                justify-content: center;
                margin-bottom: 1rem;
                gap: 0.5rem;
            }
            
            .btn-add-driver,
            .btn-add-warehouse,
            .btn-add-sales,
            .btn-outline-success {
                padding: 0.5rem 0.8rem;
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 576px) {
            .add-user-buttons-wrapper {
                flex-wrap: wrap;
            }
            
            .btn-add-driver,
            .btn-add-warehouse,
            .btn-add-sales,
            .btn-outline-success {
                flex: 1;
                min-width: calc(50% - 0.5rem);
                text-align: center;
            }
        }
        
        /* ===== STAT CARDS - MATCHING BOTTOM LAYOUT ===== */
.stat-card-row {
    margin-bottom: 1.5rem;
}

.stat-card {
    background: transparent !important;
    border: none !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
    min-height: auto !important;
    height: auto !important;
    padding: 0.8rem !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
    cursor: default !important;
}

/* Gradient backgrounds for each type */
.stat-card.total {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.pending {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.complete {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.delivery {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

/* Force text colors to white */
.stat-card .stat-value,
.stat-card .stat-label,
.stat-card .stat-content,
.stat-card small,
.stat-card small i,
.stat-card .badge {
    color: white !important;
}

/* Remove any white background from children */
.stat-card .stat-content,
.stat-card .stat-icon {
    background: transparent !important;
}

/* Hover effect */
.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}

/* ===== MOBILE: SQUARE CARDS WITH CENTERED ICON ===== */
@media (max-width: 991px) {
    .stat-card {
        aspect-ratio: 1 / 1 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        align-items: center !important;
        text-align: center !important;
        padding: 0.5rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        display: block !important;
        text-align: center !important;
        margin: 0 auto 0.3rem auto !important;
        font-size: 1.6rem !important;
        width: auto !important;
        float: none !important;
        position: static !important;
    }
    
    .stat-card .stat-value {
        display: block !important;
        text-align: center !important;
        font-size: 1.2rem !important;
        font-weight: bold !important;
        line-height: 1.2 !important;
        margin: 0.2rem 0 !important;
        width: 100% !important;
    }
    
    .stat-card .stat-label {
        display: block !important;
        text-align: center !important;
        font-size: 0.7rem !important;
        font-weight: 500 !important;
        width: 100% !important;
    }
    
    .stat-card small {
        display: none !important;
    }
    
    .stat-card .badge {
        display: inline-block !important;
        font-size: 0.5rem !important;
        padding: 0.2rem 0.4rem !important;
        margin-top: 0.2rem !important;
        text-align: center !important;
    }
}

/* ===== DESKTOP: HORIZONTAL LAYOUT ===== */
@media (min-width: 992px) {
    .stat-card {
        align-items: flex-start !important;
        text-align: left !important;
        padding: 1rem !important;
        aspect-ratio: auto !important;
        min-height: 120px !important;
        max-height: 130px !important;
        display: flex !important;
        flex-direction: row !important;
        justify-content: flex-start !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        align-self: flex-start !important;
        margin: 0 0.75rem 0 0 !important;
        font-size: 1.6rem !important;
        display: inline-block !important;
        text-align: left !important;
    }
    
    .stat-card .stat-content {
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        text-align: left !important;
        flex: 1 !important;
    }
    
    .stat-card .stat-value {
        align-self: flex-start !important;
        margin: 0 0 0.05rem 0 !important;
        font-size: 1.4rem !important;
        line-height: 1.2 !important;
        text-align: left !important;
    }
    
    .stat-card .stat-label {
        align-self: flex-start !important;
        margin-top: 0.1rem !important;
        font-size: 0.75rem !important;
        font-weight: 500 !important;
        text-align: left !important;
    }
    
    .stat-card small {
        align-self: flex-start !important;
        margin-top: 0.2rem !important;
        display: block !important;
        font-size: 0.65rem !important;
        opacity: 0.9 !important;
        text-align: left !important;
    }
}

/* ===== TABLET (768px - 991px) ===== */
@media (min-width: 768px) and (max-width: 991px) {
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.4rem !important;
        margin-bottom: 0.25rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 1rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.6rem !important;
    }
}

/* ===== EXTRA SMALL MOBILE (below 400px) ===== */
@media (max-width: 399px) {
    .stat-card {
        padding: 0.3rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.2rem !important;
        margin-bottom: 0.2rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.9rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.55rem !important;
    }
}

/* ===== LANDSCAPE MODE ===== */
@media (max-height: 500px) and (orientation: landscape) {
    .stat-card {
        aspect-ratio: auto !important;
        min-height: 55px !important;
        max-height: 70px !important;
        padding: 0.3rem !important;
        flex-direction: row !important;
        align-items: center !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1rem !important;
        margin: 0 0.5rem 0 0 !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.75rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.5rem !important;
    }
    
    .stat-card small {
        display: none !important;
    }
}
/* ===== FILTER SECTION - COLLAPSIBLE DESIGN ===== */
.form-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.filter-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1.25rem;
    border-bottom: 1px solid #e9ecef;
    cursor: pointer;
}

.filter-header h5 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: #333;
}

.filter-header h5 i {
    margin-right: 0.5rem;
    color: #047857;
}

.filter-toggle-btn {
    background: none;
    border: none;
    color: #6c757d;
    font-size: 1.2rem;
    padding: 0;
    cursor: pointer;
    transition: transform 0.3s ease;
}

.filter-toggle-btn:focus {
    outline: none;
}

.filter-content {
    max-height: 500px;
    overflow: hidden;
    transition: max-height 0.3s ease, padding 0.3s ease;
    padding: 1rem 1.25rem;
}

.filter-content.collapsed {
    max-height: 0;
    padding: 0 1.25rem;
}

/* Remove large white space - compact spacing */
.filter-content .row {
    margin-left: 0;
    margin-right: 0;
}

.filter-content .col-12 {
    padding-left: 0.5rem;
    padding-right: 0.5rem;
}

.filter-content .form-label {
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: #495057;
    display: block;
}

.filter-content .form-label i {
    margin-right: 0.25rem;
    font-size: 0.7rem;
}

.filter-content .form-select,
.filter-content .form-control {
    font-size: 0.85rem;
    border-radius: 8px;
    border: 1px solid #dee2e6;
    padding: 0.4rem 0.75rem;
    height: auto;
}

.filter-content .form-select:focus,
.filter-content .form-control:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
}

/* Search box styling */
.search-box {
    position: relative;
}

.search-box input {
    padding-left: 2rem;
    padding-right: 0.75rem;
}

.search-box i {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 0.9rem;
    z-index: 1;
}

/* ===== USERS TABLE - MOBILE CARD VIEW ===== */
@media (max-width: 768px) {
    /* Hide the table header */
    .user-table thead {
        display: none;
    }
    
    /* Display each row as a block */
    .user-table tbody,
    .user-table tr,
    .user-table td {
        display: block;
        width: 100%;
    }
    
    /* Card style for each user */
    .user-table tbody tr {
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        margin-bottom: 1rem;
        padding: 1rem;
        position: relative;
        border: none;
        cursor: pointer;
    }
    
    /* Remove all borders */
    .user-table tbody td {
        padding: 0;
        border: none !important;
        background: transparent;
    }
    
    /* Name cell */
    .user-table tbody td:first-child {
        text-align: left !important;
        padding-right: 80px;
    }
    
    .user-table tbody td:first-child strong {
        font-size: 1rem;
        color: #212529;
        font-weight: 600;
        display: block;
    }
    
    .user-table tbody td:first-child small {
        font-size: 0.7rem;
        color: #6c757d;
        display: block;
        margin-top: 0.25rem;
    }
    
    /* Role badge */
    .user-table tbody td:nth-child(2) {
        margin-top: 0.5rem;
        margin-bottom: 0;
        text-align: left !important;
        border: none !important;
    }
    
    .user-table tbody td:nth-child(2) span {
        display: inline-block;
        padding: 4px 16px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        border: none;
    }
    
    /* Role badge colors */
    .user-table tbody tr.role-delivery td:nth-child(2) span {
        background: #e3f2fd;
        color: #0d6efd;
    }
    
    .user-table tbody tr.role-warehouse td:nth-child(2) span {
        background: #e8f5e9;
        color: #198754;
    }
    
    .user-table tbody tr.role-sales td:nth-child(2) span {
        background: #fff3e0;
        color: #f59e0b;
    }
    
    /* Hide the DETAILS column */
    .user-table tbody td:nth-child(3) {
        display: none !important;
    }
    
    /* Status badge */
    .user-table tbody td:nth-child(4) {
        position: absolute !important;
        top: 1rem !important;
        right: 1rem !important;
        left: auto !important;
        padding: 0 !important;
        margin: 0 !important;
        width: auto !important;
        display: block !important;
        text-align: right !important;
        border: none !important;
    }
    
    .user-table tbody td:nth-child(4) .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 500;
        min-width: auto;
        border: none;
    }
    
    .status-active {
        background: #e8f5e9;
        color: #198754;
    }
    
    .status-inactive {
        background: #ffebee;
        color: #dc3545;
    }
    
    /* Hide the BRANCH column on mobile */
    .user-table tbody td:nth-child(5) {
        display: none !important;
    }
    
    /* Hide the action buttons cell */
    .user-table tbody td:last-child {
        display: none !important;
    }
    
    /* Tap-to-view label */
    .user-table tbody tr::after {
    content: "tap to view" !important;
    position: absolute !important;
    bottom: 12px !important;
    right: 12px !important;
    font-size: 0.65rem !important;
    color: #9ca3af !important;
    background: transparent !important;
    padding: 2px 8px !important;
    border-radius: 20px !important;
    pointer-events: auto !important;
    cursor: pointer !important;
    z-index: 5 !important;
}
.user-table tbody tr::after:hover {
    color: #0d6efd !important;
    text-decoration: underline !important;
}
}

/* ===== DESKTOP - normal table view ===== */
@media (min-width: 769px) {
    .user-table .btn-edit,
    .user-table .btn-delete {
        display: none !important;
    }
    
    .action-buttons {
        display: flex;
        justify-content: center;
    }
    
    /* Hide the tap-to-view text on desktop */
    .user-table tbody tr::after {
        display: none !important;
    }
    
    /* Display the action buttons cell on desktop */
    .user-table tbody td:last-child {
        display: table-cell !important;
    }
}

/* ===== EXTRA SMALL MOBILE (below 400px) ===== */
@media (max-width: 399px) {
    .user-table tbody tr {
        padding: 0.75rem;
    }
    
    .user-table tbody td:first-child strong {
        font-size: 0.9rem;
    }
    
    .user-table tbody td:first-child small {
        font-size: 0.65rem;
    }
    
    .user-table tbody td:nth-child(2) span,
    .user-table tbody td:nth-child(4) .status-badge {
        padding: 3px 12px;
        font-size: 0.7rem;
    }
    
    .user-table tbody tr::after {
        font-size: 0.6rem !important;
        bottom: 8px !important;
        right: 8px !important;
    }
}

/* ===== LANDSCAPE MODE ===== */
@media (max-height: 500px) and (orientation: landscape) {
    .user-table tbody tr {
        padding: 0.5rem;
        margin-bottom: 0.5rem;
    }
    
    .user-table tbody td:first-child strong {
        font-size: 0.8rem;
    }
    
    .user-table tbody td:first-child small {
        font-size: 0.6rem;
    }
    
    .user-table tbody tr::after {
        font-size: 0.55rem !important;
        bottom: 6px !important;
        right: 6px !important;
    }
}

/* ===== EMPTY STATE MOBILE STYLES ===== */
@media (max-width: 768px) {
    .user-table tbody td.empty-state-table {
        display: block;
        text-align: center;
        padding: 2rem 1rem;
    }
    
    .user-table tbody td.empty-state-table i {
        font-size: 3rem;
        margin-bottom: 1rem;
    }
    
    .user-table tbody td.empty-state-table h5 {
        font-size: 1rem;
        margin-bottom: 0.5rem;
    }
    
    .user-table tbody td.empty-state-table p {
        font-size: 0.8rem;
    }
}
/* ===== MODAL STYLES - CONSISTENT WITH OTHER PAGES ===== */

/* Modal Container */
.modal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Modal Header - Green Gradient (same as other pages) */
.modal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.5rem !important;
    flex-shrink: 0 !important;
}

.modal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

.modal .modal-header .modal-title i {
    font-size: 1.2rem !important;
}

.modal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
    background: transparent !important;
    transition: all 0.2s ease !important;
}

.modal .modal-header .btn-close:hover {
    opacity: 1 !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
.modal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 !important;
    background: #f8fafc !important;
}

/* Modal Body Scrollbar */
.modal .modal-body::-webkit-scrollbar {
    width: 6px;
}

.modal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.modal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

.modal .modal-body::-webkit-scrollbar-thumb:hover {
    background: #047857;
}

/* Modal Footer */
.modal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
}

.modal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

.modal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

.modal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

.modal .modal-footer .btn-primary {
    background: linear-gradient(135deg, #047857, #44D34E) !important;
    border: none !important;
    color: white !important;
}

.modal .modal-footer .btn-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(68, 211, 78, 0.3) !important;
}

.modal .modal-footer .btn-success {
    background: linear-gradient(135deg, #059669, #10b981) !important;
    border: none !important;
    color: white !important;
}

.modal .modal-footer .btn-success:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

/* ===== FORM SECTIONS INSIDE MODAL ===== */
.modal .form-section {
    background: white !important;
    border-radius: 12px !important;
    padding: 1.25rem !important;
    margin-bottom: 1.25rem !important;
    border: 1px solid #e9ecef !important;
    transition: all 0.2s ease !important;
}

.modal .form-section:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
}

.modal .form-section-title {
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    margin-bottom: 1rem !important;
    padding-bottom: 0.5rem !important;
    border-bottom: 2px solid #44D34E !important;
    color: #047857 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

.modal .form-section-title i {
    font-size: 1rem !important;
    color: #44D34E !important;
}

/* Form Labels */
.modal .form-label {
    font-size: 0.75rem !important;
    font-weight: 600 !important;
    color: #374151 !important;
    margin-bottom: 0.35rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.35rem !important;
}

.modal .form-label i {
    color: #047857 !important;
    font-size: 0.8rem !important;
}

.modal .form-label .text-danger {
    color: #dc3545 !important;
    font-size: 0.7rem !important;
}

/* Form Controls */
.modal .form-control,
.modal .form-select {
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    padding: 0.6rem 0.85rem !important;
    font-size: 0.85rem !important;
    transition: all 0.2s ease !important;
    background-color: #ffffff !important;
    width: 100% !important;
}

.modal .form-control:focus,
.modal .form-select:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15) !important;
    outline: none !important;
}

.modal .form-control:read-only,
.modal .form-control[readonly] {
    background-color: #f1f5f9 !important;
    cursor: not-allowed !important;
    color: #475569 !important;
}

/* Password Note */
.modal .password-note {
    background-color: #fef3c7 !important;
    border-left: 4px solid #f59e0b !important;
    padding: 0.5rem 0.75rem !important;
    border-radius: 8px !important;
    font-size: 0.7rem !important;
    margin-top: 0.5rem !important;
    color: #92400e !important;
}

.modal .password-note i {
    color: #f59e0b !important;
    margin-right: 0.25rem !important;
}

/* ===== RESPONSIVE MODAL STYLES ===== */
@media (max-width: 768px) {
    .modal .modal-dialog {
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
    }
    
    .modal .modal-header {
        padding: 0.875rem 1rem !important;
    }
    
    .modal .modal-header .modal-title {
        font-size: 1rem !important;
    }
    
    .modal .modal-body {
        padding: 1rem !important;
    }
    
    .modal .modal-footer {
        padding: 0.75rem 1rem !important;
    }
    
    .modal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.8rem !important;
    }
    
    .modal .form-section {
        padding: 1rem !important;
        margin-bottom: 1rem !important;
    }
    
    .modal .form-section-title {
        font-size: 0.85rem !important;
        margin-bottom: 0.75rem !important;
    }
    
    .modal .form-label {
        font-size: 0.7rem !important;
    }
    
    .modal .form-control,
    .modal .form-select {
        padding: 0.5rem 0.7rem !important;
        font-size: 0.8rem !important;
    }
}

@media (max-width: 576px) {
    .modal .modal-header {
        padding: 0.75rem !important;
    }
    
    .modal .modal-body {
        padding: 0.75rem !important;
    }
    
    .modal .modal-footer {
        padding: 0.6rem 0.75rem !important;
        gap: 0.5rem !important;
    }
    
    .modal .modal-footer .btn {
        padding: 0.4rem 0.4rem !important;
        font-size: 0.75rem !important;
    }
    
    .modal .form-section {
        padding: 0.75rem !important;
    }
    
    .modal .form-section-title {
        font-size: 0.8rem !important;
    }
    
    .modal .form-label {
        font-size: 0.65rem !important;
    }
    
    .modal .form-control,
    .modal .form-select {
        padding: 0.45rem 0.6rem !important;
        font-size: 0.75rem !important;
    }
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    .modal .modal-body {
        max-height: calc(100vh - 120px) !important;
    }
    
    .modal .form-section {
        padding: 0.75rem !important;
        margin-bottom: 0.75rem !important;
    }
    
    .modal .form-section-title {
        font-size: 0.8rem !important;
        margin-bottom: 0.5rem !important;
    }
}
    
/* ===== USERS SECTION ===== */
.management-tabs {
    border-bottom: 2px solid #e5e7eb !important;
    margin-top: 0.5rem !important;
}

.management-tabs .nav-link {
    color: #052A47 !important;
    font-weight: 700 !important;
    border: none !important;
    border-bottom: 3px solid transparent !important;
    border-radius: 10px 10px 0 0 !important;
    padding: 0.75rem 1.2rem !important;
}

.management-tabs .nav-link.active {
    color: #047857 !important;
    background: #ecfdf5 !important;
    border-bottom-color: #44D34E !important;
}

.management-pane.d-none {
    display: none !important;
}

    
        .profile-picture-preview {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e9ecef;
            background: #f8f9fa;
        }

        /* Optional Profile Picture Upload UI - circle, top-left, with live preview */
        .row > [class*="col-"]:has(.profile-upload-wrapper) {
            order: -999;
            flex: 0 0 100% !important;
            max-width: 100% !important;
            width: 100% !important;
        }
        .profile-upload-wrapper {
            width: 100%;
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            margin-bottom: 10px;
        }
        .profile-upload-box {
            width: 112px;
            height: 112px;
            border-radius: 50%;
            border: 3px solid #d1fae5;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            padding: 0;
            transition: all 0.2s ease;
            cursor: pointer !important;
            margin: 0;
            box-shadow: 0 4px 12px rgba(4, 120, 87, 0.12);
        }
        .profile-upload-box:hover {
            border-color: #44D34E;
            box-shadow: 0 8px 18px rgba(4, 120, 87, 0.20);
            transform: translateY(-1px) scale(1.02);
        }
        .profile-upload-box * {
            cursor: pointer !important;
        }
        .profile-picture-input {
            display: none !important;
        }
        .profile-upload-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            background: #f8f9fa;
        }
        .profile-upload-overlay {
            position: absolute;
            inset: auto 0 0 0;
            height: 40%;
            background: rgba(5, 42, 71, 0.72);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .profile-upload-box:hover .profile-upload-overlay {
            opacity: 1;
        }
        .profile-upload-caption {
            margin-left: 14px;
            padding-top: 20px;
            min-width: 0;
        }
        .profile-upload-text {
            color: #052A47;
            font-weight: 700;
            font-size: 0.9rem;
            line-height: 1.2;
            display: block;
        }
        .profile-upload-hint {
            color: #6b7280;
            font-size: 0.76rem;
            line-height: 1.2;
            display: block;
            margin-top: 3px;
        }
        .profile-upload-filename {
            max-width: 240px;
            color: #047857;
            background: #d1fae5;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 7px;
            display: inline-block;
        }
        @media (max-width: 576px) {
            .profile-upload-box {
                width: 96px;
                height: 96px;
            }
            .profile-upload-caption {
                padding-top: 12px;
                margin-left: 12px;
            }
            .profile-upload-filename {
                max-width: 170px;
            }
        }

        .profile-picture-placeholder {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #047857, #44D34E);
            color: #fff;
            font-size: 2rem;
            border: 3px solid #e9ecef;
        }
        .profile-upload-box::before {
            content: "\F4D7";
            font-family: "bootstrap-icons";
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.2rem;
            color: #94a3b8;
            background: #e5e7eb;
            z-index: 0;
        }
        .profile-upload-preview {
            position: relative;
            z-index: 1;
        }
        .profile-upload-preview[src=""],
        .profile-upload-preview:not([src]) {
            opacity: 0;
        }
        .profile-upload-overlay {
            z-index: 2;
        }
        .user-details-profile-top-left {
            order: -999;
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            gap: 18px;
            margin-bottom: 18px;
            text-align: left !important;
        }
        .user-details-profile-top-left .profile-picture-preview,
        .user-details-profile-top-left .profile-picture-placeholder {
            width: 150px;
            height: 150px;
            flex: 0 0 150px;
            font-size: 4rem;
            border: 4px solid #d1fae5;
            box-shadow: 0 8px 20px rgba(4, 120, 87, 0.16);
        }
        .user-details-profile-meta {
            padding-top: 18px;
        }
        @media (max-width: 576px) {
            .user-details-profile-top-left .profile-picture-preview,
            .user-details-profile-top-left .profile-picture-placeholder {
                width: 118px;
                height: 118px;
                flex-basis: 118px;
                font-size: 3rem;
            }
            .user-details-profile-meta { padding-top: 8px; }
        }


        /* Match Global/drivers.php add-user modal header colors */
        #driverModal .modal-header { 
            background-color: #0d6efd !important; 
            color: #ffffff !important; 
        }
        #warehouseModal .modal-header {
            background-color: #198754 !important; 
            color: #ffffff !important;
        }
        #salesModal .modal-header { 
            background-color: #ffc107 !important; 
            color: #212529 !important;
        }
        #driverModal .modal-title, 
        #warehouseModal .modal-title { 
            color: #ffffff !important;
        }
        #salesModal .modal-title { 
            color: #212529 !important;
        }
        #driverModal .btn-close, 
        #warehouseModal .btn-close { 
            filter: brightness(0) invert(1) !important;
        }
        #salesModal .btn-close { 
            filter: none !important; 
        }
        
/* Action Bar with Search */
.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}

.action-buttons-group {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.search-bar-wrapper {
    width: 360px;  /* Changed from 280px to 340px */
    flex-shrink: 0;
}

.search-bar-wrapper .input-group {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.search-bar-wrapper .input-group-text {
    background: #f8fafc;
    border: none;
    color: #94a3b8;
    padding: 0.4rem 0.6rem;
    font-size: 0.8rem;
}

.search-bar-wrapper .form-control {
    border: none;
    padding: 0.4rem 0.6rem;
    font-size: 0.8rem;
    height: 34px;
    background: #f8fafc;
}

.search-bar-wrapper .form-control:focus {
    border: none;
    outline: none;
    box-shadow: none;
    background: white;
}

.search-bar-wrapper .form-control::placeholder {
    color: #94a3b8;
    font-size: 0.75rem;
}

@media (max-width: 768px) {
    .action-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .action-buttons-group {
        justify-content: center;
    }
    .search-bar-wrapper {
        width: 100%;
    }
}
/* ===== MOBILE BOTTOM NAVIGATION - FIXED DROPDOWN ===== */
.mobile-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: white;
    border-top: 1px solid #e5e7eb;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
    z-index: 9999;
    display: none;
    padding: 8px 0 12px 0;
    overflow: visible !important;
}


.calendar-grid.week-grid {
    grid-template-columns:repeat(7, minmax(145px, 1fr));
}
.calendar-cell.week-cell {
    min-height:440px;
}
.calendar-day-view {
    border:1px solid #edf0f2;
    border-radius:14px;
    min-height:460px;
    background:#fff;
    overflow:hidden;
}
.calendar-day-view-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:18px 20px;
    border-bottom:1px solid #edf0f2;
    background:#fbfffc;
}
.calendar-day-view-header h6 {
    margin:0;
    font-size:18px;
    font-weight:900;
    color:#1f2937;
}
.calendar-day-view-body {
    padding:18px 20px;
}
.calendar-result-card {
    border:1px solid #eef1f4;
    border-radius:12px;
    padding:12px 14px;
    margin-bottom:10px;
    cursor:pointer;
    transition:background .15s ease, box-shadow .15s ease;
}
.calendar-result-card:hover {
    background:#fbfffc;
    box-shadow:0 8px 22px rgba(15,23,42,.06);
}
.calendar-result-title {
    font-weight:900;
    color:#1f2937;
    margin-bottom:4px;
}
.calendar-result-meta {
    font-size:12px;
    font-weight:700;
    color:#64748b;
}
.calendar-empty-state {
    text-align:center;
    color:#6c757d;
    font-weight:800;
    padding:42px 10px;
}
.mini-date.selected {
    background:#07d826;
    color:#fff;
    box-shadow:0 8px 18px rgba(7,216,38,.25);
}
.mini-date.today:not(.selected) {
    box-shadow:inset 0 0 0 2px rgba(7,216,38,.35);
    color:#07a91e;
}

@media (max-width: 992px) {
    .mobile-nav {
        display: block;
    }

    .main-content {
        padding-bottom: 80px !important;
    }
}

.mobile-nav .nav {
    display: flex;
    justify-content: space-around;
    align-items: center;
    margin: 0;
    padding: 0;
    list-style: none;
    overflow: visible !important;
    scrollbar-width: none;
}

.mobile-nav .nav::-webkit-scrollbar {
    display: none;
}

.mobile-nav .nav-item {
    position: relative;
    flex-shrink: 0;
    text-align: center;
    overflow: visible !important;
}

.mobile-nav .nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    color: #9ca3af;
    font-size: 0.7rem;
    text-decoration: none;
    border-radius: 12px;
    gap: 4px;
    white-space: nowrap;
    background: transparent;
    border: none;
    cursor: pointer;
}

.mobile-nav .nav-link i {
    font-size: 1.3rem;
    margin: 0;
}

.mobile-nav .nav-link span {
    font-size: 0.65rem;
    font-weight: 500;
}

.mobile-nav .nav-link.active {
    color: #059669;
    background: rgba(5, 150, 105, 0.1);
}

.mobile-nav .more-dropdown {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%) translateY(8px);
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    border: 1px solid #e5e7eb;
    min-width: 180px;
    z-index: 10000;
    display: none !important;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.mobile-nav .more-dropdown.show {
    display: block !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
    transform: translateX(-50%) translateY(0) !important;
}

.mobile-nav .more-dropdown::before {
    content: '';
    position: absolute;
    bottom: -6px;
    left: 50%;
    transform: translateX(-50%) rotate(45deg);
    width: 12px;
    height: 12px;
    background: white;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
}

.mobile-nav .dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #374151;
    text-decoration: none;
    transition: background 0.2s ease;
    border-bottom: 1px solid #f3f4f6;
    font-size: 0.85rem;
    background: white;
    width: 100%;
    text-align: left;
    cursor: pointer;
}

.mobile-nav .dropdown-item:last-child {
    border-bottom: none;
}

.mobile-nav .dropdown-item:hover {
    background: #f9fafb;
}

.mobile-nav .dropdown-item.active {
    background: rgba(5, 150, 105, 0.1);
    color: #059669;
}

.mobile-nav .dropdown-item i {
    width: 20px;
    font-size: 1rem;
    color: #6b7280;
}

.mobile-nav .dropdown-item.active i {
    color: #059669;
}

@media (max-width: 480px) {
    .mobile-nav .nav-link {
        padding: 4px 8px;
    }

    .mobile-nav .nav-link i {
        font-size: 1.1rem;
    }

    .mobile-nav .nav-link span {
        font-size: 0.55rem;
    }

    .mobile-nav .more-dropdown {
        min-width: 160px;
    }

    .mobile-nav .dropdown-item {
        padding: 10px 12px;
        font-size: 0.75rem;
    }
}
    

.employee-toolbar{
    display:flex;
    gap:10px;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    margin-bottom:18px;
}
.toolbar-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}
.btn-primary-green{
    background:linear-gradient(135deg,#44D34E,#047857);
    border:0;
    color:#fff;
    border-radius:12px;
    padding:10px 16px;
    font-weight:700;
    box-shadow:0 4px 14px rgba(68,211,78,.25);
}
.btn-primary-green:hover{
    color:#fff;
    transform:translateY(-1px);
}
.employee-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    table-layout:fixed;
}
.employee-table thead th{
    background:#052A47;
    color:#fff;
    font-weight:700;
    font-size:.82rem;
    padding:14px 12px;
    border:0;
}
.employee-table thead th:first-child{
    border-top-left-radius:14px;
}
.employee-table thead th:last-child{
    border-top-right-radius:14px;
}
.employee-table tbody td{
    padding:14px 12px;
    border-bottom:1px solid #eef2f7;
    vertical-align:middle;
    color:#263238;
    font-size:.9rem;
}
.employee-table tbody tr:hover{
    background:#f8fbff;
}
.employee-name-cell strong{
    display:block;
    color:#052A47;
    font-weight:800;
}
.employee-name-cell small{
    display:block;
    color:#64748b;
}
.duration-badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:#ecfdf5;
    color:#047857;
    border:1px solid #bbf7d0;
    border-radius:999px;
    padding:6px 10px;
    font-weight:800;
}

.dtr-employee-select-table .form-control-sm {
    min-width: 125px;
    border-radius: 8px;
    font-size: .82rem;
}
.dtr-entry-row.table-active {
    background: #ecfdf5 !important;
}


/* QuickBooks-style DTR entry table */
.dtr-qb-sheet {
    border: 1px solid #b7efb3;
    border-radius: 6px;
    background: #ffffff;
    overflow: visible;
}
.dtr-qb-toolbar {
    padding: 10px 12px;
    border-bottom: 1px solid #d8dde3;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}
.dtr-qb-add-row {
    border: 1px solid #047857 !important;
    color: #047857 !important;
    background: #ffffff !important;
    border-radius: 6px !important;
    padding: 7px 14px !important;
    font-weight: 700 !important;
    box-shadow: none !important;
}
.dtr-qb-add-row:hover {
    background: #ecfdf5 !important;
    color: #047857 !important;
}
.dtr-qb-grid-wrap {
    max-height: 345px;
    overflow-y: auto;
    overflow-x: auto;
    background: #ffffff;
}
.dtr-qb-grid-wrap::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}
.dtr-qb-grid-wrap::-webkit-scrollbar-track {
    background: #f1f5f9;
}
.dtr-qb-grid-wrap::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 999px;
}
.dtr-qb-grid-wrap::-webkit-scrollbar-thumb:hover {
    background: #047857;
}
.dtr-qb-grid {
    width: 100%;
    min-width: 900px;
    border-collapse: collapse;
    table-layout: fixed;
    margin: 0;
}
.dtr-qb-grid thead th {
    height: 34px;
    background: #ffffff;
    color: #667085;
    text-transform: uppercase;
    font-size: 13px;
    font-weight: 700;
    border-right: 1px solid #d8dde3;
    border-bottom: 1px solid #9ca3af;
    padding: 5px 7px;
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 4;
}
.dtr-qb-grid thead th:last-child,
.dtr-qb-grid tbody td:last-child {
    border-right: 0;
}
.dtr-qb-grid tbody tr:nth-child(odd) {
    background: #ffffff;
}
.dtr-qb-grid tbody tr:nth-child(even) {
    background: #e8ffe7;
}
.dtr-qb-grid tbody td {
    height: 28px;
    border-right: 1px solid #d8dde3;
    border-bottom: 1px solid rgba(216, 221, 227, 0.45);
    padding: 0;
    vertical-align: middle;
    position: relative;
}
.dtr-qb-grid input {
    width: 100%;
    height: 28px;
    border: 0 !important;
    background: transparent !important;
    border-radius: 0 !important;
    padding: 2px 7px !important;
    font-size: 13px !important;
    color: #111827 !important;
    outline: none !important;
    box-shadow: none !important;
}
.dtr-qb-grid input:focus {
    background: #ffffff !important;
    box-shadow: inset 0 0 0 1px #44D34E !important;
}
.dtr-employee-picker {
    position: relative;
    width: 100%;
}
.dtr-employee-name-input {
    padding-right: 24px !important;
}
.dtr-employee-picker::after {
    content: "\F282";
    font-family: "bootstrap-icons";
    position: absolute;
    right: 7px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 12px;
    color: #94a3b8;
    pointer-events: none;
}
.dtr-employee-dropdown-menu {
    display: none;
    position: fixed;
    z-index: 20000;
    width: 360px;
    max-height: 260px;
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid #44D34E;
    border-radius: 0 0 4px 4px;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.14);
}
.dtr-employee-dropdown-menu.show {
    display: block;
}
.dtr-employee-dropdown-menu::-webkit-scrollbar {
    width: 8px;
}
.dtr-employee-dropdown-menu::-webkit-scrollbar-track {
    background: #e8ffe7;
}
.dtr-employee-dropdown-menu::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 999px;
}
.dtr-employee-option {
    display: grid;
    grid-template-columns: 1fr 120px;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-bottom: 1px solid #eef2f7;
    cursor: pointer;
    font-size: 13px;
    color: #052A47;
    background: #ffffff;
}
.dtr-employee-option:hover,
.dtr-employee-option.active {
    background: #ecfdf5;
}
.dtr-employee-option-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dtr-employee-option-type {
    text-align: right;
    color: #052A47;
    font-size: 12px;
}
.dtr-employee-empty-option {
    padding: 10px 12px;
    color: #64748b;
    font-size: 13px;
}
.dtr-qb-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 82px;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    margin-left: 7px;
}
.dtr-qb-status.ready {
    background: #dcfce7;
    color: #166534;
}
.dtr-qb-status.pending {
    background: #fde68a;
    color: #92400e;
}
.dtr-qb-status.recorded {
    background: #dbeafe;
    color: #1d4ed8;
}
.dtr-qb-status.error {
    background: #fee2e2;
    color: #b91c1c;
}
.dtr-qb-note {
    color: #6b7280;
    font-size: 0.84rem;
    padding: 12px 2px 0;
}

/* Purchase-order style Attendance History table */
.attendance-history-sheet {
    border-radius: 4px;
    overflow: hidden;
    box-shadow: none;
}
.attendance-history-scroll {
    max-height: 430px;
}
.attendance-history-grid {
    min-width: 820px;
}
.attendance-history-grid thead th {
    height: 34px;
    background: #ffffff !important;
    color: #667085 !important;
    font-size: 13px !important;
    text-transform: uppercase;
    letter-spacing: 0;
    text-align: left !important;
    padding: 6px 8px !important;
    border-right: 1px solid #d8dde3 !important;
    border-bottom: 1px solid #9ca3af !important;
}
.attendance-history-grid tbody td {
    height: 28px;
    padding: 4px 8px !important;
    border-right: 1px solid #d8dde3 !important;
    border-bottom: 1px solid rgba(216, 221, 227, 0.45) !important;
    color: #111827 !important;
    font-size: 13px !important;
    text-align: left !important;
    vertical-align: middle !important;
}
.attendance-history-grid tbody tr:nth-child(odd) {
    background: #ffffff !important;
}
.attendance-history-grid tbody tr:nth-child(even) {
    background: #e8ffe7 !important;
}
.attendance-history-grid tbody tr:hover {
    background: #ecfdf5 !important;
}
.attendance-employee-name {
    color: #111827;
    font-weight: 500;
    line-height: 1.2;
}
.attendance-employee-total {
    color: #6b7280;
    font-size: 11px;
    line-height: 1.2;
    margin-top: 2px;
}
.attendance-history-empty {
    height: 44px !important;
    text-align: center !important;
    color: #6b7280 !important;
    background: #ffffff !important;
    font-weight: 600;
}

.attendance-history-grid .dtr-history-clickable {
    cursor: pointer;
}
.attendance-history-grid .dtr-history-clickable:hover td {
    background: #f3fff2 !important;
}
.holiday-chip.regular_workday {
    background: #f3f4f6 !important;
    color: #374151 !important;
    border: 1px solid #e5e7eb !important;
}

/* ===== OT Approval Action Buttons ===== */
#otApprovalTable th:last-child,
#otApprovalTable td:last-child {
    text-align: center !important;
    vertical-align: middle !important;
}

.ot-action-cell {
    text-align: center !important;
    vertical-align: middle !important;
}

.ot-action-buttons {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex-wrap: nowrap;
    width: 100%;
}

.ot-action-btn,
.ot-action-done {
    width: 36px;
    height: 36px;
    min-width: 36px;
    min-height: 36px;
    padding: 0;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 800;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
    transition: all 0.22s ease;
    box-shadow: 0 4px 10px rgba(15, 23, 42, 0.08);
}

.ot-action-btn i,
.ot-action-done i {
    margin: 0 !important;
    font-size: 16px;
    line-height: 1;
}

.ot-action-btn span,
.ot-action-done span {
    display: none !important;
}

.ot-action-approve {
    border: 1px solid rgba(4, 120, 87, 0.18) !important;
    background: linear-gradient(135deg, #047857, #44D34E) !important;
    color: #ffffff !important;
}

.ot-action-approve:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(4, 120, 87, 0.30);
    background: linear-gradient(135deg, #059669, #44D34E) !important;
    color: #ffffff !important;
}

.ot-action-reject {
    border: 1px solid #fecaca !important;
    background: #ffffff !important;
    color: #dc3545 !important;
}

.ot-action-reject:hover {
    transform: translateY(-2px);
    background: #dc3545 !important;
    color: #ffffff !important;
    border-color: #dc3545 !important;
    box-shadow: 0 8px 16px rgba(220, 53, 69, 0.22);
}

.ot-action-done {
    background: #f8fafc !important;
    color: #047857 !important;
    border: 1px solid #d1fae5 !important;
    box-shadow: none;
    cursor: default;
}

@media (max-width: 768px) {
    .ot-action-buttons {
        justify-content: flex-start;
        flex-wrap: wrap;
    }

    .ot-action-btn,
    .ot-action-done {
        min-height: 32px;
    }
}

.rate-details-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 16px;
}
.rate-details-title {
    font-size: 0.82rem;
    font-weight: 800;
    color: #047857;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 12px;
}
.rate-detail-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 9px 0;
    border-bottom: 1px solid #eef2f7;
    font-size: 0.9rem;
}
.rate-detail-row:last-child {
    border-bottom: none;
}
.rate-detail-label {
    color: #6b7280;
    font-weight: 600;
}
.rate-detail-value {
    color: #111827;
    font-weight: 800;
    text-align: right;
}
.rate-detail-total {
    background: #f0fdf4;
    border-radius: 10px;
    padding: 10px 12px;
    margin-top: 8px;
}

.rate-employee-line {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 18px;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 12px;
    color: #374151;
    font-size: 0.9rem;
}
.rate-employee-line strong {
    color: #111827;
    font-weight: 800;
}
.rate-mini-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    margin-bottom: 12px;
    color: #374151;
    font-size: 0.84rem;
}
.rate-mini-summary strong {
    color: #111827;
}
.rate-table-wrap {
    width: 100%;
    overflow-x: auto;
}
.rate-computation-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 0.9rem;
    color: #111827;
}
.rate-computation-table th,
.rate-computation-table td {
    border: 1px solid #d1d5db;
    padding: 2px 7px;
    line-height: 1.2;
}
.rate-computation-table th {
    font-weight: 800;
    text-align: right;
}
.rate-computation-table th:first-child,
.rate-computation-table td:first-child {
    text-align: left;
    width: 43%;
}
.rate-computation-table td:not(:first-child) {
    text-align: right;
}
.rate-subtotal-row td,
.rate-total-row td {
    font-weight: 800;
}
.rate-total-row td {
    border-top: 2px solid #111827;
}

@media (max-width: 768px) {
    .dtr-qb-grid-wrap { max-height: 360px; }
    .dtr-qb-grid { min-width: 760px; }
    .dtr-employee-dropdown-menu { width: 320px; }
}

.summary-card{
    border:1px solid #eef2f7;
    border-radius:16px;
    padding:14px;
    background:#fff;
    box-shadow:0 4px 16px rgba(15,23,42,.06);
}
.summary-card .label{
    font-size:.78rem;
    color:#64748b;
    font-weight:700;
    text-transform:uppercase;
}
.summary-card .value{
    font-size:1.35rem;
    font-weight:900;
    color:#052A47;
}
.profile-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
}
.profile-item{
    background:#f8fafc;
    border:1px solid #edf2f7;
    border-radius:12px;
    padding:11px;
}
.profile-item span{
    display:block;
    font-size:.76rem;
    color:#64748b;
    font-weight:700;
}
.profile-item strong{
    display:block;
    color:#052A47;
    font-size:.93rem;
    word-break:break-word;
}
@media(max-width:768px){
    .profile-grid{
        grid-template-columns:1fr;
    }
    .employee-table{
        table-layout:auto;
    }
    .employee-table thead{
        display:none;
    }
    .employee-table tbody tr{
        display:block;
        margin-bottom:12px;
        border:1px solid #e5e7eb;
        border-radius:16px;
        padding:10px;
        background:#fff;
    }
    .employee-table tbody td{
        display:flex;
        justify-content:space-between;
        gap:12px;
        border:0;
        padding:8px 4px;
    }
    .employee-table tbody td:before{
        content:attr(data-label);
        font-weight:800;
        color:#052A47;
    }
    .employee-table tbody td:first-child{
        display:block;
    }
    .employee-table tbody td:first-child:before{
        display:none;
    }
    .toolbar-actions,
    .employee-toolbar{
        width:100%;
    }
    .toolbar-actions .btn,
    .employee-toolbar .input-group{
        width:100%;
    }
}

/* ===== EMPLOYEE PAGE UI ALIGNMENT WITH DRIVERS/CURRENT INVENTORY ===== */
.page-content.active {
    display: block;
}
.form-card { 
    background: #ffffff; 
    border: 1px solid var(--light-green); 
    border-radius: 14px; 
    padding: 1.25rem; 
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.employee-toolbar .input-group-text { 
    background: #ffffff; 
    border-color: #d1d5db; 
    color: var(--dark-green); 
}
.employee-toolbar .form-control { 
    border-color: #d1d5db; 
    border-radius: 0 10px 10px 0; 
}
.employee-toolbar .form-control:focus, 
.modal .form-control:focus, 
.modal .form-select:focus { 
    border-color: var(--primary-green); 
    box-shadow: 0 0 0 0.2rem rgba(68,211,78,.18); 
}
.btn-primary-green { 
    background: linear-gradient(135deg, var(--dark-green), var(--primary-green)) !important; 
    color: #fff !important; 
    border: none !important; 
    border-radius: 10px !important; 
    padding: 0.6rem 1.1rem !important; 
    font-weight: 700 !important; 
    box-shadow: 0 4px 10px rgba(4,120,87,.22) !important; 
}
.btn-primary-green:hover { 
    background: linear-gradient(135deg, #059669, var(--primary-green)) !important; 
    color:#fff !important; 
    transform: translateY(-2px); 
}
.employee-table { 
    width: 100%; 
    border-collapse: collapse; 
    table-layout: fixed; 
    margin-bottom: 0; 
}
.employee-table thead th { 
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
.employee-table thead th:first-child, 
.employee-table thead th:last-child { 
    border-radius: 0 !important; 
}
.employee-table tbody td { 
    padding: 12px 8px; 
    vertical-align: middle; 
    border-bottom: 1px solid #e9ecef; 
    font-size: 13px; 
    text-align: center; 
    word-wrap: break-word; 
}
.employee-table tbody td:first-child { 
    text-align: left; 
}
.employee-table tbody tr:hover { 
    background-color: #f8f9fa; 
}
.employee-name-cell strong { 
    color: var(--dark-color); 
    font-weight: 800; 
}
.employee-name-cell small { 
    color: #6b7280; 
}
.status-badge.active, 
.status-active { 
    background-color: #d4edda; 
    color: #155724; 
}
.status-badge.inactive, 
.status-inactive { 
    background-color: #f8d7da; 
    color: #721c24; 
}
.duration-badge { 
    background: #d4edda; 
    color: #155724; 
    border: 0; 
    border-radius: 20px; 
    padding: 4px 8px; 
    font-size: 11px; 
    font-weight: 700; 
}
.status-pending { 
    background-color: #fff3cd; 
    color: #856404; 
}
.modal-content { 
    border: none; 
    border-radius: 14px; 
    overflow: hidden; 
    box-shadow: 0 20px 60px rgba(0,0,0,.15); 
}
.modal-header { 
    background: linear-gradient(135deg, var(--dark-green), var(--primary-green)); 
    color: #fff; 
    border-bottom: none; 
}
.modal-header .btn-close { 
    filter: brightness(0) invert(1); 
    opacity: .9; 
}
.modal-footer { 
    border-top: 1px solid #e5e7eb; 
    background: #f9fafb; 
}
.mobile-nav .logout-link { 
    color: #dc3545 !important; 
}
.mobile-nav .logout-link i { 
    color: #dc3545 !important; 
}

.calendar-grid.week-grid {
    grid-template-columns:repeat(7, minmax(145px, 1fr));
}
.calendar-cell.week-cell {
    min-height:440px;
}
.calendar-day-view {
    border:1px solid #edf0f2;
    border-radius:14px;
    min-height:460px;
    background:#fff;
    overflow:hidden;
}
.calendar-day-view-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:18px 20px;
    border-bottom:1px solid #edf0f2;
    background:#fbfffc;
}
.calendar-day-view-header h6 {
    margin:0;
    font-size:18px;
    font-weight:900;
    color:#1f2937;
}
.calendar-day-view-body {
    padding:18px 20px;
}
.calendar-result-card {
    border:1px solid #eef1f4;
    border-radius:12px;
    padding:12px 14px;
    margin-bottom:10px;
    cursor:pointer;
    transition:background .15s ease, box-shadow .15s ease;
}
.calendar-result-card:hover {
    background:#fbfffc;
    box-shadow:0 8px 22px rgba(15,23,42,.06);
}
.calendar-result-title {
    font-weight:900;
    color:#1f2937;
    margin-bottom:4px;
}
.calendar-result-meta {
    font-size:12px;
    font-weight:700;
    color:#64748b;
}
.calendar-empty-state {
    text-align:center;
    color:#6c757d;
    font-weight:800;
    padding:42px 10px;
}
.mini-date.selected {
    background:#07d826;
    color:#fff;
    box-shadow:0 8px 18px rgba(7,216,38,.25);
}
.mini-date.today:not(.selected) {
    box-shadow:inset 0 0 0 2px rgba(7,216,38,.35);
    color:#07a91e;
}

@media (max-width: 992px) { 
    .main-content { 
        margin-left: 0 !important; 
        padding-bottom: 80px !important; 
    } 
    .employee-toolbar { 
        gap: .75rem; 
    }
}


.btn-outline-primary-green {
    border: 1px solid var(--primary-green);
    color: var(--dark-green);
    background: white;
    font-weight: 600;
}
.btn-outline-primary-green:hover {
    background: var(--primary-green);
    border-color: var(--primary-green);
    color: white;
}


.action-footer-btn {
    min-width: 132px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}

@media (max-width: 576px) {
    .modal-footer .action-footer-btn {
        flex: 1 1 100%;
        width: 100%;
    }
}

.employee-attendance-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.employee-attendance-header h5 {
    margin: 0;
}



.employee-profile-row {
    cursor: pointer;
}
.employee-profile-row:hover {
    background: #f0fdf4 !important;
}
.employee-attendance-header {
    gap: 12px;
    flex-wrap: wrap;
}
.employee-attendance-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}
.employee-attendance-actions .btn {
    white-space: nowrap;
}
.employee-details-modal .profile-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
@media (max-width: 576px) {
    .employee-attendance-header {
        align-items: flex-start;
    }
    .employee-attendance-actions {
        width: 100%;
        justify-content: flex-start;
    }
    .employee-details-modal .profile-grid {
        grid-template-columns: 1fr;
    }
}


.dashboard-tabs .nav-link {
    color: #047857;
    font-weight: 700;
    border-radius: 10px 10px 0 0;
}
.dashboard-tabs .nav-link.active {
    background: #047857;
    color: #fff;
    border-color: #047857;
}
.tab-card {
    background: #fff;
    border-radius: 0 14px 14px 14px;
    padding: 18px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
}
.employee-toolbar {
    background: #fff;
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
    margin-bottom: 18px;
}
.attendance-tab-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 14px;
    box-shadow: none;
    border: 1px solid #eef1f4;
    background: #fbfffc;
    padding: 14px;
}
.attendance-dtr-search {
    max-width: 420px;
    min-width: 260px;
    flex: 1 1 320px;
}
.attendance-tab-toolbar .toolbar-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex: 0 0 auto;
}
@media (max-width: 768px) {
    .attendance-tab-toolbar {
        align-items: stretch;
    }
    .attendance-dtr-search,
    .attendance-tab-toolbar .toolbar-actions,
    .attendance-tab-toolbar .toolbar-actions .btn {
        width: 100%;
        max-width: 100%;
    }
}
@media (max-width: 576px) {

    .employee-page-tabs {
        padding: 0 0.75rem;
        gap: 6px;
    }
    .employee-page-tabs .nav-item {
        flex: 1 1 100%;
    }
    .employee-page-tabs .nav-link {
        width: 100%;
        border-radius: 12px !important;
        text-align: center;
    }
    .employee-tab-content {
        padding: 0.75rem;
    }
}


.holiday-chip {
    display:inline-block;
    padding:3px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    background:#eef2ff;
    color:#1f3a8a;
    max-width:150px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.holiday-chip.regular_holiday { background:#fff8df; color:#8a5a00; }
.holiday-chip.special_non_working { background:#eaf3ff; color:#0d6efd; }
.calendar-shell {
    display:grid;
    grid-template-columns:250px minmax(0, 1fr);
    min-height:720px;
    background:#fff;
    border:1px solid #eef1f4;
    border-radius:18px;
    overflow:hidden;
}
.calendar-sidebar-panel {
    background:#fff;
    border-right:1px solid #eef1f4;
    padding:18px 16px;
}
.calendar-top-controls {
    display:flex;
    gap:8px;
    background:#fafbfc;
    border:1px solid #eef1f4;
    border-radius:10px;
    padding:5px;
    margin-bottom:22px;
}
.calendar-view-btn {
    flex:1;
    border:0;
    background:transparent;
    color:#88929d;
    border-radius:7px;
    padding:8px 8px;
    font-weight:800;
    font-size:12px;
}
.calendar-view-btn.active {
    background:#fff;
    color:#1f2937;
    box-shadow:0 1px 8px rgba(15,23,42,.06);
}
.mini-calendar-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:14px;
}
.mini-calendar-title {
    font-size:14px;
    font-weight:900;
    color:#1f2937;
}
.mini-nav-btn {
    width:30px;
    height:30px;
    border:1px solid #eef1f4;
    background:#fff;
    color:#07a91e;
    border-radius:9px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}
.mini-calendar-grid {
    display:grid;
    grid-template-columns:repeat(7, 1fr);
    gap:6px;
    padding-bottom:18px;
    border-bottom:1px solid #eef1f4;
}

.mini-holiday-dropdown {
    margin-top:14px;
}
.mini-holiday-toggle {
    width:100%;
    border:1px solid #eef1f4;
    background:#ffffff;
    color:#1f2937;
    border-radius:12px;
    padding:10px 12px;
    font-size:12px;
    font-weight:900;
    display:flex;
    align-items:center;
    justify-content:space-between;
    box-shadow:0 2px 10px rgba(15,23,42,.04);
    transition:all .2s ease;
}
.mini-holiday-toggle:hover {
    border-color:rgba(7,216,38,.35);
    background:#fbfffc;
    color:#047857;
}
.mini-holiday-toggle[aria-expanded="true"] {
    background:linear-gradient(135deg, rgba(4,120,87,.08), rgba(68,211,78,.10));
    border-color:rgba(7,216,38,.35);
    color:#047857;
}
.mini-holiday-chevron {
    transition:transform .2s ease;
}
.mini-holiday-toggle[aria-expanded="true"] .mini-holiday-chevron {
    transform:rotate(180deg);
}
.mini-holiday-list {
    margin-top:10px;
    border:1px solid #eef1f4;
    border-radius:12px;
    background:#fff;
    overflow:hidden;
}
.mini-holiday-item {
    display:flex;
    gap:10px;
    padding:10px 12px;
    border-bottom:1px solid #f1f3f5;
    cursor:pointer;
    transition:background .2s ease;
}
.mini-holiday-item:last-child {
    border-bottom:0;
}
.mini-holiday-item:hover {
    background:#fbfffc;
}
.mini-holiday-date {
    min-width:42px;
    height:38px;
    border-radius:10px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    background:#f8fafc;
    color:#047857;
    font-weight:900;
    line-height:1;
}
.mini-holiday-date span:first-child {
    font-size:11px;
    text-transform:uppercase;
}
.mini-holiday-date span:last-child {
    font-size:15px;
}
.mini-holiday-info {
    min-width:0;
    flex:1;
}
.mini-holiday-name {
    font-size:12px;
    font-weight:900;
    color:#1f2937;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.mini-holiday-type {
    display:inline-flex;
    margin-top:4px;
    padding:2px 7px;
    border-radius:999px;
    font-size:10px;
    font-weight:800;
}
.mini-holiday-type.regular_holiday {
    background:#fff8df;
    color:#8a5a00;
}
.mini-holiday-type.special_non_working {
    background:#eaf3ff;
    color:#0d6efd;
}
.mini-holiday-empty {
    padding:14px 12px;
    color:#6c757d;
    font-size:12px;
    font-weight:700;
    text-align:center;
}
.mini-day-name {
    color:#9aa3ad;
    font-size:11px;
    font-weight:800;
    text-align:center;
    padding-bottom:4px;
}
.mini-date {
    height:28px;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:800;
    color:#1f2937;
    position:relative;
    cursor:pointer;
}
.mini-date.muted { color:#c4cbd2; }
.mini-date.active {
    background:#07d826;
    color:#fff;
    box-shadow:0 8px 18px rgba(7,216,38,.25);
}
.mini-date.has-event::after {
    content:'';
    position:absolute;
    width:4px;
    height:4px;
    border-radius:50%;
    bottom:2px;
    background:#ff9f1c;
}
.mini-date.active.has-event::after { background:#fff; }
.calendar-main-panel {
    background:#fff;
    padding:18px 20px 22px;
    min-width:0;
}
.calendar-search-row {
    display:flex;
    align-items:center;
    gap:12px;
    border-bottom:1px solid #eef1f4;
    padding:0 0 18px;
    margin-bottom:18px;
}
.calendar-search-box {
    position:relative;
    max-width:380px;
    width:100%;
}
.calendar-search-box i {
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#9aa3ad;
}
.calendar-search-box input {
    width:100%;
    border:0;
    outline:0;
    background:#fff;
    padding:10px 12px 10px 42px;
    font-size:14px;
    color:#1f2937;
}
.calendar-main-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:18px;
}
.calendar-main-title {
    margin:0;
    font-size:26px;
    font-weight:900;
    color:#1f2937;
}
.calendar-today-btn {
    border:1px solid #eef1f4;
    background:#fff;
    color:#1f2937;
    border-radius:10px;
    padding:10px 22px;
    font-weight:800;
    box-shadow:0 2px 10px rgba(15,23,42,.04);
}
.calendar-legend {
    display:flex;
    flex-wrap:wrap;
    gap:14px;
    font-size:12px;
    color:#5f6b76;
    margin-bottom:14px;
}
.legend-dot {
    display:inline-block;
    width:10px;
    height:10px;
    border-radius:50%;
    margin-right:5px;
    background:#07d826;
}
.legend-dot.regular_holiday { background:#ff6b35; }
.legend-dot.special_non_working { background:#4d96ff; }
.attendance-calendar { width:100%; overflow:auto; }
.calendar-grid {
    display:grid;
    grid-template-columns:repeat(7, minmax(120px, 1fr));
    border-left:1px solid #edf0f2;
    border-top:1px solid #edf0f2;
    min-width:840px;
}
.calendar-days {
    border-radius:12px 12px 0 0;
    overflow:hidden;
}
.calendar-days div {
    background:#fff;
    padding:14px 10px;
    text-align:center;
    font-weight:900;
    font-size:12px;
    color:#3f4852;
    border-right:1px solid #edf0f2;
    border-bottom:1px solid #edf0f2;
}
.calendar-cell {
    min-height:132px;
    background:#fff;
    padding:10px 8px;
    overflow:hidden;
    cursor:pointer;
    transition:background .15s ease, box-shadow .15s ease;
    border-right:1px solid #edf0f2;
    border-bottom:1px solid #edf0f2;
}
.calendar-cell:not(.muted):hover {
    background:#fbfffc;
    box-shadow:inset 0 0 0 2px rgba(7,216,38,.18);
}
.calendar-cell.muted { background:#fcfdfe; }
.calendar-date-num {
    font-weight:900;
    font-size:13px;
    color:#1f2937;
    margin-bottom:8px;
    text-align:center;
}
.calendar-cell.muted .calendar-date-num { color:#c4cbd2; }

.calendar-cell.selected {
    outline: none;
    background: rgba(7, 216, 38, 0.06);
}
.calendar-cell.selected .calendar-date-num {
    display:flex;
    align-items:center;
    justify-content:center;
    width:28px;
    height:28px;
    margin:0 auto 8px;
    border-radius:50%;
    background:#07d826;
    color:#fff;
    box-shadow:0 8px 18px rgba(7,216,38,.22);
}
.calendar-cell.today .calendar-date-num {
    display:flex;
    align-items:center;
    justify-content:center;
    width:24px;
    height:24px;
    margin:0 auto 8px;
    border-radius:50%;
    background:#07d826;
    color:#fff;
}
.calendar-event {
    position:relative;
    display:block;
    padding:2px 6px 2px 10px;
    border-radius:4px;
    font-size:11px;
    font-weight:800;
    margin-bottom:6px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    background:transparent !important;
    color:#475569;
    line-height:1.35;
}
.calendar-event::before {
    content:'';
    position:absolute;
    left:0;
    top:2px;
    bottom:2px;
    width:4px;
    border-radius:6px;
    background:#07d826;
}
.calendar-event.attendance.pending::before { background:#ffb020; }
.calendar-event.holiday.regular_holiday::before { background:#ff6b35; }
.calendar-event.holiday.special_non_working::before { background:#4d96ff; }
.calendar-event.attendance { color:#334155; }
.calendar-event.attendance.pending { color:#8a5a00; }
.calendar-event.holiday.regular_holiday { color:#8a3b00; }
.calendar-event.holiday.special_non_working { color:#0d4b9b; }
.calendar-more { font-size:11px; font-weight:800; color:#6c757d; padding-left:10px; }
.calendar-detail-section {
    border:1px solid #e9ecef;
    border-radius:12px;
    padding:14px;
    margin-bottom:12px;
    background:#fff;
}
.calendar-detail-section h6 {
    font-weight:800;
    color:#1f2937;
    margin-bottom:10px;
}
.calendar-detail-table { width:100%; border-collapse:collapse; }
.calendar-detail-table th, .calendar-detail-table td {
    padding:8px 10px;
    border-bottom:1px solid #f1f3f5;
    font-size:13px;
    vertical-align:top;
}
.calendar-detail-table th { color:#495057; width:34%; font-weight:800; }
.calendar-detail-empty {
    text-align:center;
    padding:26px 10px;
    color:#6c757d;
    font-weight:700;
}
.calendar-slot-list { margin:0; padding-left:18px; }
.calendar-slot-list li { margin-bottom:4px; }

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
.calendar-detail-table.calendar-day-table .employee-cell { width: 18% !important; }
.calendar-detail-table.calendar-day-table .branch-cell { width: 13% !important; }
.calendar-detail-table.calendar-day-table .time-cell { width: 11% !important; }
.calendar-detail-table.calendar-day-table .duration-cell { width: 9% !important; }
.calendar-detail-table.calendar-day-table .holiday-cell { width: 14% !important; }
.calendar-detail-table.calendar-day-table .money-cell { width: 10% !important; }

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
        font-size: 10.5px !important;
    }
    .calendar-detail-table.calendar-day-table .branch-cell,
    .calendar-detail-table.calendar-day-table .holiday-cell {
        display: none !important;
    }
}


.calendar-grid.week-grid {
    grid-template-columns:repeat(7, minmax(145px, 1fr));
}
.calendar-cell.week-cell {
    min-height:440px;
}
.calendar-day-view {
    border:1px solid #edf0f2;
    border-radius:14px;
    min-height:460px;
    background:#fff;
    overflow:hidden;
}
.calendar-day-view-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:18px 20px;
    border-bottom:1px solid #edf0f2;
    background:#fbfffc;
}
.calendar-day-view-header h6 {
    margin:0;
    font-size:18px;
    font-weight:900;
    color:#1f2937;
}
.calendar-day-view-body {
    padding:18px 20px;
}
.calendar-result-card {
    border:1px solid #eef1f4;
    border-radius:12px;
    padding:12px 14px;
    margin-bottom:10px;
    cursor:pointer;
    transition:background .15s ease, box-shadow .15s ease;
}
.calendar-result-card:hover {
    background:#fbfffc;
    box-shadow:0 8px 22px rgba(15,23,42,.06);
}
.calendar-result-title {
    font-weight:900;
    color:#1f2937;
    margin-bottom:4px;
}
.calendar-result-meta {
    font-size:12px;
    font-weight:700;
    color:#64748b;
}
.calendar-empty-state {
    text-align:center;
    color:#6c757d;
    font-weight:800;
    padding:42px 10px;
}
.mini-date.selected {
    background:#07d826;
    color:#fff;
    box-shadow:0 8px 18px rgba(7,216,38,.25);
}
.mini-date.today:not(.selected) {
    box-shadow:inset 0 0 0 2px rgba(7,216,38,.35);
    color:#07a91e;
}

@media (max-width: 992px) {
    .calendar-shell { grid-template-columns:1fr; }
    .calendar-sidebar-panel { border-right:0; border-bottom:1px solid #eef1f4; }
    .calendar-main-title { font-size:22px; }
    .calendar-grid { grid-template-columns:repeat(7, minmax(105px, 1fr)); }
}


/* ===== ATTENDANCE DETAILS MODAL TABLE WIDTH FIX =====
   Keeps the existing green UI palette while giving the calendar day
   details table enough room so table headers stay on one line. */
#calendarDateModal .calendar-date-wide-modal{
    width: 99vw !important;
    max-width: 1680px !important;
    margin: 0.5rem auto !important;
}

#calendarDateModal .modal-content{
    min-height: 82vh !important;
}

#calendarDateModal .modal-body{
    padding: 24px !important;
}

#calendarDateModal .calendar-detail-section{
    padding: 18px !important;
    overflow: hidden !important;
}

#calendarDateModal .calendar-detail-table-wrapper{
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
}

#calendarDateModal .calendar-detail-table.calendar-day-table{
    width: 100% !important;
    max-width: 100% !important;
    table-layout: auto !important;
    border-collapse: collapse !important;
}

#calendarDateModal .calendar-detail-table.calendar-day-table thead th{
    white-space: nowrap !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
    line-height: 1.1 !important;
    text-align: center !important;
    vertical-align: middle !important;
    padding: 13px 9px !important;
    font-size: 12px !important;
}

#calendarDateModal .calendar-detail-table.calendar-day-table tbody td{
    padding: 12px 9px !important;
    font-size: 12.5px !important;
    vertical-align: middle !important;
}

#calendarDateModal .calendar-detail-table.calendar-day-table .employee-cell{
    width: 17% !important;
    min-width: 210px !important;
}

#calendarDateModal .calendar-detail-table.calendar-day-table .branch-cell{
    width: 11% !important;
    min-width: 130px !important;
}

#calendarDateModal .calendar-detail-table.calendar-day-table .time-cell{
    width: 8% !important;
    min-width: 95px !important;
}

#calendarDateModal .calendar-detail-table.calendar-day-table .duration-cell{
    width: 7% !important;
    min-width: 88px !important;
}

#calendarDateModal .calendar-detail-table.calendar-day-table .holiday-cell{
    width: 13% !important;
    min-width: 150px !important;
}

#calendarDateModal .calendar-detail-table.calendar-day-table .money-cell{
    width: 9% !important;
    min-width: 110px !important;
}

@media (max-width: 1200px){
    #calendarDateModal .calendar-date-wide-modal{
        width: 99vw !important;
        max-width: 99vw !important;
    }
    #calendarDateModal .calendar-detail-table.calendar-day-table thead th{
        font-size: 11px !important;
        padding: 11px 6px !important;
    }
    #calendarDateModal .calendar-detail-table.calendar-day-table tbody td{
        font-size: 11.5px !important;
        padding: 10px 6px !important;
    }
    #calendarDateModal .calendar-detail-table.calendar-day-table .employee-cell{min-width: 170px !important;}
    #calendarDateModal .calendar-detail-table.calendar-day-table .branch-cell{min-width: 105px !important;}
    #calendarDateModal .calendar-detail-table.calendar-day-table .time-cell{min-width: 78px !important;}
    #calendarDateModal .calendar-detail-table.calendar-day-table .duration-cell{min-width: 70px !important;}
    #calendarDateModal .calendar-detail-table.calendar-day-table .holiday-cell{min-width: 120px !important;}
    #calendarDateModal .calendar-detail-table.calendar-day-table .money-cell{min-width: 92px !important;}
}


        .dtr-locked-field {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            cursor: not-allowed !important;
        }

.time-stack {
    display: flex;
    flex-direction: column;
    gap: 2px;
    text-align: left;
}
.time-stack-item {
    display: block;
    white-space: nowrap;
    font-size: 12px;
}
.payroll-history-clickable {
    cursor: pointer;
}
.dtr-qb-status.danger {
    background: #f8d7da !important;
    color: #721c24 !important;
}
.payroll-history-clickable:hover {
    background-color: #f0fdf4 !important;
}

/* ===== OT Approval Details / Approval Modal ===== */
#otApprovalTable .ot-approval-row { cursor: pointer; }
#otApprovalTable .ot-approval-row:hover td { background: #f0fdf4 !important; }
.ot-approval-remarks-box {
    width: 100%;
    min-height: 90px;
    border: 1px solid #d1fae5;
    border-radius: 12px;
    padding: 0.75rem 0.85rem;
    resize: vertical;
    font-size: 0.9rem;
    color: #052A47;
    outline: none;
}
.ot-approval-remarks-box:focus {
    border-color: #059669;
    box-shadow: 0 0 0 0.2rem rgba(5, 150, 105, 0.12);
}
.ot-approval-file-input {
    border: 1px dashed #86efac;
    background: #f0fdf4;
    border-radius: 12px;
    padding: 0.75rem;
}
.ot-attachment-list {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    align-items: flex-end;
}
.ot-attachment-link {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.6rem;
    border-radius: 999px;
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
}
.ot-attachment-link:hover {
    background: #047857;
    color: #fff;
}
.attachment-preview-frame {
    width: 100%;
    height: 70vh;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #f8fafc;
}
.attachment-preview-empty {
    min-height: 260px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 0.5rem;
    color: #64748b;
    background: #f8fafc;
    border: 1px dashed #a7f3d0;
    border-radius: 14px;
    font-weight: 700;
}
.attachment-preview-empty i {
    font-size: 2rem;
    color: #047857;
}


.employee-tabs .nav-link,
.nav-tabs .nav-link,
.tab-btn {
    font-weight: 700 !important;
}

/* =========================================================
   MOBILE MODAL INTERNAL SCROLL FIX
   Applies to all Bootstrap modals EXCEPT #profileModal.
   Mobile preview only:
   - page behind modal will not scroll
   - modal shell will not scroll outside
   - only modal-body scrolls
   - modal height stays above the mobile bottom navigation
   ========================================================= */
@media (max-width: 991.98px) {
    :root {
        --amgc-mobile-nav-height: 82px;
        --amgc-modal-safe-gap: 10px;
    }

    body.modal-open {
        overflow: hidden !important;
        padding-right: 0 !important;
        touch-action: none;
    }

    body.modal-open .main-content {
        overflow: hidden !important;
    }

    .modal:not(#profileModal) {
        z-index: 20050 !important;
        padding: var(--amgc-modal-safe-gap) var(--amgc-modal-safe-gap)
                 calc(var(--amgc-mobile-nav-height) + var(--amgc-modal-safe-gap))
                 var(--amgc-modal-safe-gap) !important;
        overflow: hidden !important;
    }

    .modal-backdrop {
        z-index: 20040 !important;
    }

    .mobile-nav {
        z-index: 9999 !important;
    }

    .modal:not(#profileModal) .modal-dialog {
        width: auto !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        height: calc(100dvh - var(--amgc-mobile-nav-height) - (var(--amgc-modal-safe-gap) * 2)) !important;
        max-height: calc(100dvh - var(--amgc-mobile-nav-height) - (var(--amgc-modal-safe-gap) * 2)) !important;
        display: flex !important;
        align-items: stretch !important;
    }

    .modal:not(#profileModal) .modal-content {
        width: 100% !important;
        height: 100% !important;
        max-height: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
        border-radius: 16px !important;
    }

    .modal:not(#profileModal) .modal-header,
    .modal:not(#profileModal) .modal-footer {
        flex: 0 0 auto !important;
        position: relative !important;
        z-index: 2 !important;
    }

    .modal:not(#profileModal) .modal-body {
        flex: 1 1 auto !important;
        min-height: 0 !important;
        max-height: none !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        -webkit-overflow-scrolling: touch !important;
        overscroll-behavior: contain !important;
    }

    .modal:not(#profileModal) .modal-footer {
        gap: 8px !important;
        flex-wrap: wrap !important;
    }

    .modal:not(#profileModal) .modal-footer .btn,
    .modal:not(#profileModal) .modal-footer .action-footer-btn {
        min-height: 42px !important;
    }
}

@media (max-width: 576px) {
    .modal:not(#profileModal) {
        padding: 8px 8px calc(var(--amgc-mobile-nav-height) + 8px) 8px !important;
    }

    .modal:not(#profileModal) .modal-dialog {
        height: calc(100dvh - var(--amgc-mobile-nav-height) - 16px) !important;
        max-height: calc(100dvh - var(--amgc-mobile-nav-height) - 16px) !important;
    }

    .modal:not(#profileModal) .modal-header {
        padding: 12px 14px !important;
    }

    .modal:not(#profileModal) .modal-body {
        padding: 14px !important;
    }

    .modal:not(#profileModal) .modal-footer {
        padding: 12px 14px !important;
    }

    .modal:not(#profileModal) .modal-footer .btn,
    .modal:not(#profileModal) .modal-footer .action-footer-btn {
        flex: 1 1 100% !important;
        width: 100% !important;
    }
}


/* =========================================================
   MOBILE MODAL FOOTER CUT-OFF FIX - DO NOT APPLY TO profileModal
   Fixes DTR and other modals where the <form> wraps modal-body + modal-footer.
   The form becomes the scroll container layout, so the footer stays visible
   above the mobile bottom navigation and only the modal-body scrolls.
   ========================================================= */
@media (max-width: 991.98px) {
    .modal:not(#profileModal) .modal-content > form,
    .modal:not(#profileModal) form.modal-form-lock {
        flex: 1 1 auto !important;
        min-height: 0 !important;
        height: 100% !important;
        max-height: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }

    .modal:not(#profileModal) .modal-content > form > .modal-body {
        flex: 1 1 auto !important;
        min-height: 0 !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        -webkit-overflow-scrolling: touch !important;
        overscroll-behavior: contain !important;
    }

    .modal:not(#profileModal) .modal-content > form > .modal-footer {
        flex: 0 0 auto !important;
        margin-top: 0 !important;
        position: relative !important;
        bottom: auto !important;
        z-index: 3 !important;
        background: #ffffff !important;
    }

    #dtrModal .modal-dialog {
        height: calc(var(--amgc-real-vh, 100dvh) - var(--amgc-mobile-nav-height) - 20px) !important;
        max-height: calc(var(--amgc-real-vh, 100dvh) - var(--amgc-mobile-nav-height) - 20px) !important;
    }

    #dtrModal .modal-content,
    #dtrModal #dtrForm {
        height: 100% !important;
        max-height: 100% !important;
        min-height: 0 !important;
        overflow: hidden !important;
    }

    #dtrModal .modal-footer {
        padding-bottom: 12px !important;
    }

    #dtrModal .dtr-qb-grid-wrap {
        max-width: 100% !important;
        overflow-x: auto !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }
}

@media (max-width: 576px) {
    #dtrModal {
        padding-bottom: calc(var(--amgc-mobile-nav-height) + 10px) !important;
    }

    #dtrModal .modal-dialog {
        height: calc(var(--amgc-real-vh, 100dvh) - var(--amgc-mobile-nav-height) - 18px) !important;
        max-height: calc(var(--amgc-real-vh, 100dvh) - var(--amgc-mobile-nav-height) - 18px) !important;
    }

    #dtrModal .modal-footer {
        padding: 10px 14px 12px !important;
    }

    #dtrModal .modal-footer .btn,
    #dtrModal .modal-footer .action-footer-btn {
        min-height: 44px !important;
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

            <span class="nav-text">Motorpool</span>
        </h3>
    </div>

    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="motorpool_inventory.php">
                        <i class="bi bi-box-seam"></i>
                        <span class="nav-text">Current Inventory</span>
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
                                <a class="nav-link" href="enterbills.php">
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
                                <a class="nav-link" href="vendors.php">
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
                                <a class="nav-link active" href="employee.php">
                                    <i class="bi bi-clock-history"></i>
                                    <span class="nav-text">Enter Time</span>
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
                                <a class="nav-link" href="withdrawal.php">
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
                                <a class="nav-link" href="chartofaccounts.php">
                                    <i class="bi bi-graph-up"></i>
                                    <span class="nav-text">Chart of Accounts</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="request_handler.php">
                                    <i class="bi bi-clipboard"></i>
                                    <span class="nav-text">RIS Monitoring</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="motorpool.php">
                                    <i class="bi bi-truck"></i>
                                    <span class="nav-text">Vehicle Profile</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="central_warehouse.php">
                                    <i class="bi bi-box-seam"></i>
                                    <span class="nav-text">Central Warehouse</span>
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
    <div class="page-content active">
        <div class="navbar-top">
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
            <div class="page-title">
                <h2>Employee Attendance</h2>
                <p>Manage Employee Attendance List and Daily Time Record <?php if (!$view_all_branches): ?>for your branch<?php endif; ?></p>
            </div>
        </div>

        <div class="row stat-card-row g-1 g-sm-2 mb-4">
            <div class="col"><div class="stat-card total"><i class="bi bi-people stat-icon"></i><div class="stat-content"><div class="stat-value"><?= $totalEmployees ?></div><div class="stat-label">Total Employees</div></div></div></div>
            <div class="col"><div class="stat-card complete"><i class="bi bi-person-check stat-icon"></i><div class="stat-content"><div class="stat-value"><?= $activeEmployees ?></div><div class="stat-label">Active</div></div></div></div>
            <div class="col"><div class="stat-card pending"><i class="bi bi-calendar-check stat-icon"></i><div class="stat-content"><div class="stat-value"><?= count($todayEmployees) ?></div><div class="stat-label">DTR Today</div></div></div></div>
            <div class="col"><div class="stat-card delivery"><i class="bi bi-clock-history stat-icon"></i><div class="stat-content"><div class="stat-value"><?= formatDuration($todayMinutes) ?></div><div class="stat-label">Today's Hours</div></div></div></div>
        </div>

        <div class="mb-4">
            <ul class="nav nav-tabs dashboard-tabs mb-0" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendancePane" type="button" role="tab">
                        <i class="bi bi-calendar-week me-1"></i>Employee Attendance List
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="calendar-tab" data-bs-toggle="tab" data-bs-target="#calendarPane" type="button" role="tab">
                        <i class="bi bi-calendar3 me-1"></i>Calendar
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="ot-approval-tab" data-bs-toggle="tab" data-bs-target="#otApprovalPane" type="button" role="tab">
                        <i class="bi bi-check2-square me-1"></i>OT Approval
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="payroll-history-tab" data-bs-toggle="tab" data-bs-target="#payrollHistoryPane" type="button" role="tab">
                        <i class="bi bi-clock-history me-1"></i>Payroll Submit History
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="attendancePane" role="tabpanel">
                    <div class="tab-card">
                    <div class="employee-toolbar attendance-tab-toolbar">
                        <div class="input-group attendance-dtr-search">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search DTR..." oninput="filterTables()">
                        </div>
                        <div class="toolbar-actions">
                            <button class="btn btn-primary-green" onclick="showDtrModal()"><i class="bi bi-clock-history me-1"></i> Daily Time Record (DTR)</button>
                        </div>
                    </div>
                    <div class="filter-header mb-3 employee-attendance-header">
                        <h5><i class="bi bi-clock-history me-2"></i>Attendance History</h5>
                    </div>
                    <div class="dtr-qb-sheet attendance-history-sheet">
                        <div class="dtr-qb-grid-wrap attendance-history-scroll">
                            <table class="dtr-qb-grid attendance-history-grid mb-0" id="dtrTable">
                                <thead>
                                    <tr>
                                        <th style="width:9%;">Date</th>
                                        <th style="width:17%;">Employee Name</th>
                                        <th style="width:9%;">Time In 1</th>
                                        <th style="width:9%;">Time Out 1</th>
                                        <th style="width:9%;">Time In 2</th>
                                        <th style="width:9%;">Time Out 2</th>
                                        <th style="width:9%;">Time In 3</th>
                                        <th style="width:9%;">Time Out 3</th>
                                        <th style="width:8%;">Total Hours</th>
                                        <th style="width:8%;">Regular Hours</th>
                                        <th style="width:7%;">OT Hours</th>
                                        <th style="width:9%;">OT Status</th>
                                        <th style="width:13%;">Date Classification</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($dtrRows)): ?>
                                    <tr><td colspan="13" class="attendance-history-empty"><i class="bi bi-clock-history me-1"></i>No DTR Records Found</td></tr>
                                <?php else: foreach ($dtrRows as $dtr): 
                                    $slots = $dtr['slots'] ?? [];
                                    $classificationLabel = !empty($dtr['holiday_name'])
                                        ? ((($dtr['holiday_type'] ?? '') === 'regular_holiday') ? 'Regular Holiday' : 'Special Holiday')
                                        : 'Regular Workday';
                                    $classificationText = $classificationLabel;
                                    $classificationClass = !empty($dtr['holiday_name']) ? (string)$dtr['holiday_type'] : 'regular_workday';
                                    $regularHours = formatDuration((int)($dtr['regular_minutes'] ?? 0));
                                    $otHours = formatDuration((int)($dtr['overtime_minutes'] ?? 0));
                                    $requestedOtHours = formatDuration((int)($dtr['ot_requested_minutes'] ?? 0));
                                    $approvedOtHours = formatDuration((int)($dtr['ot_approved_minutes'] ?? 0));
                                    $otStatus = (string)($dtr['ot_approval_status'] ?? 'none');
                                    $otStatusText = ((int)($dtr['ot_requested_minutes'] ?? 0) <= 0) ? 'No OT' : ($otStatus === 'approved' ? 'Approved' : ($otStatus === 'rejected' ? 'Rejected' : 'Pending'));
                                    $otStatusClass = $otStatus === 'approved' ? 'recorded' : ($otStatus === 'rejected' ? 'danger' : (((int)($dtr['ot_requested_minutes'] ?? 0) > 0) ? 'pending' : 'ready'));
                                    $totalHours = formatDuration((int)($dtr['daily_total_minutes'] ?? 0));
                                    $regularHoursDecimal = (float)$regularHours;
                                    $otHoursDecimal = (float)$otHours;
                                    $basicPayAmount = (float)($dtr['basic_pay'] ?? 0);
                                    $otPayAmount = (float)($dtr['overtime_pay'] ?? 0);
                                    $subTotalPayAmount = (float)($dtr['total_pay'] ?? 0);
                                    $regularRateAmount = $regularHoursDecimal > 0 ? ($basicPayAmount / $regularHoursDecimal) : 0;
                                    $otRateAmount = $otHoursDecimal > 0 ? ($otPayAmount / $otHoursDecimal) : 0;
                                    $thirteenthPayAmount = $basicPayAmount / 12;
                                    $grandTotalPayAmount = $subTotalPayAmount + $thirteenthPayAmount;
                                    $dtrOtAttachments = normalizeOtAttachmentList($dtr['ot_approval_attachments'] ?? '');
                                    $dtrOtAttachmentsHtml = '-';
                                    if (!empty($dtrOtAttachments)) {
                                        $dtrOtAttachmentLinks = [];
                                        foreach ($dtrOtAttachments as $attachmentPath) {
                                            $attachmentLabel = basename((string)$attachmentPath);
                                            $dtrOtAttachmentLinks[] = '<a class="ot-attachment-link" href="' . htmlspecialchars((string)$attachmentPath, ENT_QUOTES) . '" data-attachment-preview="1" data-attachment-name="' . htmlspecialchars($attachmentLabel, ENT_QUOTES) . '"><i class="bi bi-paperclip"></i>' . htmlspecialchars($attachmentLabel) . '</a>';
                                        }
                                        $dtrOtAttachmentsHtml = '<div class="ot-attachment-list">' . implode('', $dtrOtAttachmentLinks) . '</div>';
                                    }
                                ?>
                                    <tr class="dtr-row dtr-history-clickable"
                                        role="button"
                                        title="Click to view rate details"
                                        data-search="<?= strtolower(htmlspecialchars(($dtr['attendance_date'] ?? '').' '.($dtr['employee_name'] ?? '').' '.$classificationText)) ?>"
                                        data-employee="<?= htmlspecialchars($dtr['employee_name'] ?? '', ENT_QUOTES) ?>"
                                        data-date="<?= htmlspecialchars(date('F d, Y', strtotime($dtr['attendance_date'] ?? date('Y-m-d'))), ENT_QUOTES) ?>"
                                        data-classification="<?= htmlspecialchars($classificationLabel, ENT_QUOTES) ?>"
                                        data-classification-name="<?= htmlspecialchars($classificationText, ENT_QUOTES) ?>"
                                        data-total-hours="<?= htmlspecialchars($totalHours, ENT_QUOTES) ?>"
                                        data-regular-hours="<?= htmlspecialchars($regularHours, ENT_QUOTES) ?>"
                                        data-ot-hours="<?= htmlspecialchars($otHours, ENT_QUOTES) ?>"
                                        data-requested-ot-hours="<?= htmlspecialchars($requestedOtHours, ENT_QUOTES) ?>"
                                        data-approved-ot-hours="<?= htmlspecialchars($approvedOtHours, ENT_QUOTES) ?>"
                                        data-ot-status="<?= htmlspecialchars($otStatusText, ENT_QUOTES) ?>"
                                        data-ot-approved-by="<?= htmlspecialchars($dtr['ot_approved_by_name'] ?? '-', ENT_QUOTES) ?>"
                                        data-ot-approved-at="<?= htmlspecialchars(!empty($dtr['ot_approved_at']) ? date('m/d/Y h:i A', strtotime($dtr['ot_approved_at'])) : '-', ENT_QUOTES) ?>"
                                        data-ot-remarks="<?= htmlspecialchars($dtr['ot_approval_remarks'] ?? '-', ENT_QUOTES) ?>"
                                        data-ot-attachments="<?= htmlspecialchars($dtrOtAttachmentsHtml, ENT_QUOTES) ?>"
                                        data-monthly-rate="<?= htmlspecialchars(formatPeso($dtr['monthly_rate'] ?? 0), ENT_QUOTES) ?>"
                                        data-daily-rate="<?= htmlspecialchars(formatPeso($dtr['daily_rate'] ?? 0), ENT_QUOTES) ?>"
                                        data-hourly-rate="<?= htmlspecialchars(formatPeso($dtr['hourly_rate'] ?? 0), ENT_QUOTES) ?>"
                                        data-basic-pay="<?= htmlspecialchars(formatPeso($dtr['basic_pay'] ?? 0), ENT_QUOTES) ?>"
                                        data-ot-pay="<?= htmlspecialchars(formatPeso($dtr['overtime_pay'] ?? 0), ENT_QUOTES) ?>"
                                        data-total-pay="<?= htmlspecialchars(formatPeso($dtr['total_pay'] ?? 0), ENT_QUOTES) ?>"
                                        data-regular-rate-plain="<?= htmlspecialchars(formatMoneyPlain($regularRateAmount), ENT_QUOTES) ?>"
                                        data-ot-rate-plain="<?= htmlspecialchars(formatMoneyPlain($otRateAmount), ENT_QUOTES) ?>"
                                        data-basic-pay-plain="<?= htmlspecialchars(formatMoneyPlain($basicPayAmount), ENT_QUOTES) ?>"
                                        data-ot-pay-plain="<?= htmlspecialchars(formatMoneyPlain($otPayAmount), ENT_QUOTES) ?>"
                                        data-subtotal-pay-plain="<?= htmlspecialchars(formatMoneyPlain($subTotalPayAmount), ENT_QUOTES) ?>"
                                        data-thirteenth-pay-plain="<?= htmlspecialchars(formatMoneyPlain($thirteenthPayAmount), ENT_QUOTES) ?>"
                                        data-grand-total-pay-plain="<?= htmlspecialchars(formatMoneyPlain($grandTotalPayAmount), ENT_QUOTES) ?>">
                                        <td data-label="Date"><?= date('m/d/Y', strtotime($dtr['attendance_date'])) ?></td>
                                        <td data-label="Employee Name">
                                            <div class="attendance-employee-name"><?= htmlspecialchars($dtr['employee_name']) ?></div>
                                        </td>
                                        <?php for ($slotIndex = 0; $slotIndex < 3; $slotIndex++): $slot = $slots[$slotIndex] ?? null; ?>
                                            <td data-label="Time In <?= $slotIndex + 1 ?>"><?= $slot && !empty($slot['start_time']) ? date('h:i A', strtotime($slot['start_time'])) : '-' ?></td>
                                            <td data-label="Time Out <?= $slotIndex + 1 ?>"><?= $slot && !empty($slot['end_time']) ? date('h:i A', strtotime($slot['end_time'])) : ($slot && !empty($slot['is_open']) ? '<span class="dtr-qb-status pending">Pending</span>' : '-') ?></td>
                                        <?php endfor; ?>
                                        <td data-label="Total Hours"><span class="duration-badge"><?= $totalHours ?></span></td>
                                        <td data-label="Regular Hours"><?= $regularHours ?></td>
                                        <td data-label="OT Hours"><?= $otHours ?></td>
                                        <td data-label="OT Status"><span class="dtr-qb-status <?= htmlspecialchars($otStatusClass) ?>"><?= htmlspecialchars($otStatusText) ?></span></td>
                                        <td data-label="Date Classification">
                                            <span class="holiday-chip <?= htmlspecialchars($classificationClass) ?>"><?= htmlspecialchars($classificationText) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    </div>

                </div>
                <div class="tab-pane fade" id="calendarPane" role="tabpanel">
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
                        </aside>
                        <section class="calendar-main-panel">
                            <div class="calendar-main-header">
                                <h5 id="calendarTitle" class="calendar-main-title">Attendance Calendar</h5>
                                <button type="button" class="calendar-today-btn" onclick="goToCalendarToday()">Today</button>
                            </div>
                            <div class="calendar-legend">
                                <span><i class="legend-dot attendance"></i> Attendance</span>
                                <span><i class="legend-dot regular_holiday"></i> Regular Holiday</span>
                                <span><i class="legend-dot special_non_working"></i> Special Non-working Day</span>
                            </div>
                            <div class="attendance-calendar" id="attendanceCalendar"></div>
                        </section>
                    </div>
                </div>
                <div class="tab-pane fade" id="otApprovalPane" role="tabpanel">
                    <div class="tab-card">
                        <div class="filter-header mb-3 employee-attendance-header">
                            <h5><i class="bi bi-check2-square me-2"></i>OT Approval</h5>
                        </div>
                        <div class="dtr-qb-sheet attendance-history-sheet">
                            <div class="dtr-qb-grid-wrap attendance-history-scroll">
                                <table class="dtr-qb-grid attendance-history-grid mb-0" id="otApprovalTable">
                                    <thead>
                                        <tr>
                                            <th style="width:10%;">Date</th>
                                            <th style="width:20%;">Employee Name</th>
                                            <th style="width:12%;">Total Hours</th>
                                            <th style="width:14%;">Requested OT</th>
                                            <th style="width:14%;">Approved OT</th>
                                            <th style="width:14%;">Status</th>
                                            <th style="width:16%;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                        $otApprovalRows = array_values(array_filter($dtrRows, function($row){ return (int)($row['ot_requested_minutes'] ?? 0) > 0; }));
                                    ?>
                                    <?php if (empty($otApprovalRows)): ?>
                                        <tr><td colspan="7" class="attendance-history-empty"><i class="bi bi-check2-square me-1"></i>No OT Approval Request Found</td></tr>
                                    <?php else: foreach ($otApprovalRows as $otRow):
                                        $otStatus = (string)($otRow['ot_approval_status'] ?? 'none');
                                        $otStatusLabel = $otStatus === 'approved' ? 'Approved' : ($otStatus === 'rejected' ? 'Rejected' : 'Pending');
                                        $otStatusClass = $otStatus === 'approved' ? 'recorded' : ($otStatus === 'rejected' ? 'danger' : 'pending');
                                        $requestedOt = formatDuration((int)($otRow['ot_requested_minutes'] ?? 0));
                                        $approvedOt = formatDuration((int)($otRow['ot_approved_minutes'] ?? 0));
                                        $otDateText = !empty($otRow['attendance_date']) ? date('m/d/Y', strtotime($otRow['attendance_date'])) : '-';
                                    ?>
                                        <?php
                                            $otAttachments = normalizeOtAttachmentList($otRow['ot_approval_attachments'] ?? '');
                                            $otAttachmentsHtml = '-';
                                            if (!empty($otAttachments)) {
                                                $otAttachmentLinks = [];
                                                foreach ($otAttachments as $attachmentPath) {
                                                    $attachmentLabel = basename((string)$attachmentPath);
                                                    $otAttachmentLinks[] = '<a class="ot-attachment-link" href="' . htmlspecialchars((string)$attachmentPath, ENT_QUOTES) . '" data-attachment-preview="1" data-attachment-name="' . htmlspecialchars($attachmentLabel, ENT_QUOTES) . '"><i class="bi bi-paperclip"></i>' . htmlspecialchars($attachmentLabel) . '</a>';
                                                }
                                                $otAttachmentsHtml = '<div class="ot-attachment-list">' . implode('', $otAttachmentLinks) . '</div>';
                                            }
                                        ?>
                                        <tr class="dtr-row ot-approval-row"
                                            role="button"
                                            title="Click to view OT approval details"
                                            data-search="<?= strtolower(htmlspecialchars(($otRow['attendance_date'] ?? '').' '.($otRow['employee_name'] ?? '').' '.$otStatusLabel)) ?>"
                                            data-employee="<?= htmlspecialchars($otRow['employee_name'] ?? '', ENT_QUOTES) ?>"
                                            data-date="<?= htmlspecialchars(!empty($otRow['attendance_date']) ? date('F d, Y', strtotime($otRow['attendance_date'])) : '-', ENT_QUOTES) ?>"
                                            data-classification="OT Approval"
                                            data-total-hours="<?= htmlspecialchars(formatDuration((int)($otRow['daily_total_minutes'] ?? 0)), ENT_QUOTES) ?>"
                                            data-regular-hours="<?= htmlspecialchars(formatDuration((int)($otRow['regular_minutes'] ?? 0)), ENT_QUOTES) ?>"
                                            data-ot-hours="<?= htmlspecialchars(formatDuration((int)($otRow['overtime_minutes'] ?? 0)), ENT_QUOTES) ?>"
                                            data-requested-ot-hours="<?= htmlspecialchars($requestedOt, ENT_QUOTES) ?>"
                                            data-approved-ot-hours="<?= htmlspecialchars($approvedOt, ENT_QUOTES) ?>"
                                            data-ot-status="<?= htmlspecialchars($otStatusLabel, ENT_QUOTES) ?>"
                                            data-monthly-rate="<?= htmlspecialchars(formatPeso($otRow['monthly_rate'] ?? 0), ENT_QUOTES) ?>"
                                            data-daily-rate="<?= htmlspecialchars(formatPeso($otRow['daily_rate'] ?? 0), ENT_QUOTES) ?>"
                                            data-hourly-rate="<?= htmlspecialchars(formatPeso($otRow['hourly_rate'] ?? 0), ENT_QUOTES) ?>"
                                            data-basic-pay="<?= htmlspecialchars(formatPeso($otRow['basic_pay'] ?? 0), ENT_QUOTES) ?>"
                                            data-ot-pay="<?= htmlspecialchars(formatPeso($otRow['overtime_pay'] ?? 0), ENT_QUOTES) ?>"
                                            data-total-pay="<?= htmlspecialchars(formatPeso($otRow['total_pay'] ?? 0), ENT_QUOTES) ?>"
                                            data-ot-approved-by="<?= htmlspecialchars($otRow['ot_approved_by_name'] ?? '-', ENT_QUOTES) ?>"
                                            data-ot-approved-at="<?= htmlspecialchars(!empty($otRow['ot_approved_at']) ? date('m/d/Y h:i A', strtotime($otRow['ot_approved_at'])) : '-', ENT_QUOTES) ?>"
                                            data-ot-remarks="<?= htmlspecialchars($otRow['ot_approval_remarks'] ?? '-', ENT_QUOTES) ?>"
                                            data-ot-attachments="<?= htmlspecialchars($otAttachmentsHtml, ENT_QUOTES) ?>">
                                            <td data-label="Date"><?= htmlspecialchars($otDateText) ?></td>
                                            <td data-label="Employee Name"><div class="attendance-employee-name"><?= htmlspecialchars($otRow['employee_name'] ?? '') ?></div></td>
                                            <td data-label="Total Hours"><span class="duration-badge"><?= formatDuration((int)($otRow['daily_total_minutes'] ?? 0)) ?></span></td>
                                            <td data-label="Requested OT"><?= htmlspecialchars($requestedOt) ?> hr</td>
                                            <td data-label="Approved OT"><?= htmlspecialchars($approvedOt) ?> hr</td>
                                            <td data-label="Status"><span class="dtr-qb-status <?= htmlspecialchars($otStatusClass) ?>"><?= htmlspecialchars($otStatusLabel) ?></span></td>
                                            <td data-label="Action" class="ot-action-cell">
                                                <?php if ($otStatus === 'pending'): ?>
                                                    <div class="ot-action-buttons">
                                                        <button type="button" class="ot-action-btn ot-action-approve" title="Approve OT" aria-label="Approve OT" onclick="event.stopPropagation(); approveOtRequest(<?= (int)$otRow['employee_id'] ?>, '<?= htmlspecialchars($otRow['attendance_date'] ?? '', ENT_QUOTES) ?>', 'approve')">
                                                            <i class="bi bi-check2-circle"></i>
                                                        </button>
                                                        <button type="button" class="ot-action-btn ot-action-reject" title="Reject OT" aria-label="Reject OT" onclick="event.stopPropagation(); approveOtRequest(<?= (int)$otRow['employee_id'] ?>, '<?= htmlspecialchars($otRow['attendance_date'] ?? '', ENT_QUOTES) ?>', 'reject')">
                                                            <i class="bi bi-x-circle"></i>
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="ot-action-done" title="Completed" aria-label="Completed">
                                                        <i class="bi bi-check2-all"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="payrollHistoryPane" role="tabpanel">
                    <div class="tab-card">
                        <div class="filter-header mb-3 employee-attendance-header">
                            <h5><i class="bi bi-clock-history me-2"></i>Payroll Submit History</h5>
                        </div>
                        <div class="dtr-qb-sheet attendance-history-sheet">
                            <div class="dtr-qb-grid-wrap attendance-history-scroll">
                                <table class="dtr-qb-grid attendance-history-grid mb-0" id="payrollHistoryTable">
                                    <thead>
                                        <tr>
                                            <th style="width:10%;">Submitted</th>
                                            <th style="width:9%;">DTR Date</th>
                                            <th style="width:18%;">Employee Name</th>
                                            <th style="width:19%;">Time In</th>
                                            <th style="width:19%;">Time Out</th>
                                            <th style="width:8%;">Total Hours</th>
                                            <th style="width:8%;">Regular Hours</th>
                                            <th style="width:7%;">OT Hours</th>
                                            <th style="width:11%;">Total Pay</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($payrollHistoryRows)): ?>
                                        <tr><td colspan="9" class="attendance-history-empty"><i class="bi bi-clock-history me-1"></i>No Payroll Submit History Found</td></tr>
                                    <?php else: foreach ($payrollHistoryRows as $history):
                                        $slots = $history['slots'] ?? [];
                                        $timeInList = [];
                                        $timeOutList = [];
                                        foreach ($slots as $slotNo => $slot) {
                                            $labelNo = $slotNo + 1;
                                            $timeInList[] = '<span class="time-stack-item"><strong>' . $labelNo . '.</strong> ' . (!empty($slot['start_time']) ? date('h:i A', strtotime($slot['start_time'])) : '-') . '</span>';
                                            $timeOutList[] = '<span class="time-stack-item"><strong>' . $labelNo . '.</strong> ' . (!empty($slot['end_time']) ? date('h:i A', strtotime($slot['end_time'])) : '-') . '</span>';
                                        }
                                        $submittedText = !empty($history['submitted_at']) ? date('m/d/Y h:i A', strtotime($history['submitted_at'])) : '-';
                                        $dtrDateText = !empty($history['attendance_date']) ? date('m/d/Y', strtotime($history['attendance_date'])) : '-';
                                        $historyTotalHours = formatDuration((int)($history['daily_total_minutes'] ?? 0));
                                        $historyRegularHours = formatDuration((int)($history['regular_minutes'] ?? 0));
                                        $historyOtHours = formatDuration((int)($history['overtime_minutes'] ?? 0));
                                        $historyRegularHoursDecimal = (float)$historyRegularHours;
                                        $historyOtHoursDecimal = (float)$historyOtHours;
                                        $historyBasicPayAmount = (float)($history['basic_pay'] ?? 0);
                                        $historyOtPayAmount = (float)($history['overtime_pay'] ?? 0);
                                        $historySubTotalPayAmount = (float)($history['total_pay'] ?? 0);
                                        $historyRegularRateAmount = $historyRegularHoursDecimal > 0 ? ($historyBasicPayAmount / $historyRegularHoursDecimal) : 0;
                                        $historyOtRateAmount = $historyOtHoursDecimal > 0 ? ($historyOtPayAmount / $historyOtHoursDecimal) : 0;
                                        $historyThirteenthPayAmount = $historyBasicPayAmount / 12;
                                        $historyGrandTotalPayAmount = $historySubTotalPayAmount + $historyThirteenthPayAmount;
                                        $historyClassification = !empty($history['holiday_name']) ? ((($history['holiday_type'] ?? '') === 'regular_holiday') ? 'Regular Holiday' : 'Special Holiday') : 'Regular Workday';
                                        $historyOtAttachments = normalizeOtAttachmentList($history['ot_approval_attachments'] ?? '');
                                        $historyOtAttachmentsHtml = '-';
                                        if (!empty($historyOtAttachments)) {
                                            $historyOtAttachmentLinks = [];
                                            foreach ($historyOtAttachments as $attachmentPath) {
                                                $attachmentLabel = basename((string)$attachmentPath);
                                                $historyOtAttachmentLinks[] = '<a class="ot-attachment-link" href="' . htmlspecialchars((string)$attachmentPath, ENT_QUOTES) . '" data-attachment-preview="1" data-attachment-name="' . htmlspecialchars($attachmentLabel, ENT_QUOTES) . '"><i class="bi bi-paperclip"></i>' . htmlspecialchars($attachmentLabel) . '</a>';
                                            }
                                            $historyOtAttachmentsHtml = '<div class="ot-attachment-list">' . implode('', $historyOtAttachmentLinks) . '</div>';
                                        }
                                    ?>
                                        <tr class="dtr-row payroll-history-clickable"
                                            role="button"
                                            title="Click to view submitted DTR details"
                                            data-search="<?= strtolower(htmlspecialchars(($history['submitted_at'] ?? '').' '.($history['attendance_date'] ?? '').' '.($history['employee_name'] ?? '').' '.$historyClassification)) ?>"
                                            data-employee="<?= htmlspecialchars($history['employee_name'] ?? '', ENT_QUOTES) ?>"
                                            data-date="<?= htmlspecialchars(!empty($history['attendance_date']) ? date('F d, Y', strtotime($history['attendance_date'])) : '-', ENT_QUOTES) ?>"
                                            data-submitted-at="<?= htmlspecialchars($submittedText, ENT_QUOTES) ?>"
                                            data-submitted-by="<?= htmlspecialchars($history['submitted_by_name'] ?? '-', ENT_QUOTES) ?>"
                                            data-branch="<?= htmlspecialchars($history['branch_name'] ?? '-', ENT_QUOTES) ?>"
                                            data-classification="<?= htmlspecialchars($historyClassification, ENT_QUOTES) ?>"
                                            data-total-hours="<?= htmlspecialchars($historyTotalHours, ENT_QUOTES) ?>"
                                            data-regular-hours="<?= htmlspecialchars($historyRegularHours, ENT_QUOTES) ?>"
                                            data-ot-hours="<?= htmlspecialchars($historyOtHours, ENT_QUOTES) ?>"
                                            data-requested-ot-hours="<?= htmlspecialchars(formatDuration((int)($history['ot_requested_minutes'] ?? 0)), ENT_QUOTES) ?>"
                                            data-approved-ot-hours="<?= htmlspecialchars(formatDuration((int)($history['ot_approved_minutes'] ?? 0)), ENT_QUOTES) ?>"
                                            data-ot-status="<?= htmlspecialchars(((int)($history['ot_requested_minutes'] ?? 0) <= 0) ? 'No OT' : (((string)($history['ot_approval_status'] ?? 'none') === 'approved') ? 'Approved' : (((string)($history['ot_approval_status'] ?? 'none') === 'rejected') ? 'Rejected' : 'Pending')), ENT_QUOTES) ?>"
                                            data-ot-approved-by="<?= htmlspecialchars($history['ot_approved_by_name'] ?? '-', ENT_QUOTES) ?>"
                                            data-ot-approved-at="<?= htmlspecialchars(!empty($history['ot_approved_at']) ? date('m/d/Y h:i A', strtotime($history['ot_approved_at'])) : '-', ENT_QUOTES) ?>"
                                            data-ot-remarks="<?= htmlspecialchars($history['ot_approval_remarks'] ?? '-', ENT_QUOTES) ?>"
                                            data-ot-attachments="<?= htmlspecialchars($historyOtAttachmentsHtml, ENT_QUOTES) ?>"
                                            data-monthly-rate="<?= htmlspecialchars(formatPeso($history['monthly_rate'] ?? 0), ENT_QUOTES) ?>"
                                            data-daily-rate="<?= htmlspecialchars(formatPeso($history['daily_rate'] ?? 0), ENT_QUOTES) ?>"
                                            data-hourly-rate="<?= htmlspecialchars(formatPeso($history['hourly_rate'] ?? 0), ENT_QUOTES) ?>"
                                            data-basic-pay="<?= htmlspecialchars(formatPeso($history['basic_pay'] ?? 0), ENT_QUOTES) ?>"
                                            data-ot-pay="<?= htmlspecialchars(formatPeso($history['overtime_pay'] ?? 0), ENT_QUOTES) ?>"
                                            data-total-pay="<?= htmlspecialchars(formatPeso($history['total_pay'] ?? 0), ENT_QUOTES) ?>"
                                            data-regular-rate-plain="<?= htmlspecialchars(formatMoneyPlain($historyRegularRateAmount), ENT_QUOTES) ?>"
                                            data-ot-rate-plain="<?= htmlspecialchars(formatMoneyPlain($historyOtRateAmount), ENT_QUOTES) ?>"
                                            data-basic-pay-plain="<?= htmlspecialchars(formatMoneyPlain($historyBasicPayAmount), ENT_QUOTES) ?>"
                                            data-ot-pay-plain="<?= htmlspecialchars(formatMoneyPlain($historyOtPayAmount), ENT_QUOTES) ?>"
                                            data-subtotal-pay-plain="<?= htmlspecialchars(formatMoneyPlain($historySubTotalPayAmount), ENT_QUOTES) ?>"
                                            data-thirteenth-pay-plain="<?= htmlspecialchars(formatMoneyPlain($historyThirteenthPayAmount), ENT_QUOTES) ?>"
                                            data-grand-total-pay-plain="<?= htmlspecialchars(formatMoneyPlain($historyGrandTotalPayAmount), ENT_QUOTES) ?>"
                                            data-time-ins="<?= htmlspecialchars(implode(' | ', array_map('strip_tags', $timeInList)), ENT_QUOTES) ?>"
                                            data-time-outs="<?= htmlspecialchars(implode(' | ', array_map('strip_tags', $timeOutList)), ENT_QUOTES) ?>">
                                            <td data-label="Submitted"><?= htmlspecialchars($submittedText) ?></td>
                                            <td data-label="DTR Date"><?= htmlspecialchars($dtrDateText) ?></td>
                                            <td data-label="Employee Name"><div class="attendance-employee-name"><?= htmlspecialchars($history['employee_name']) ?></div></td>
                                            <td data-label="Time In"><div class="time-stack"><?= implode('', $timeInList) ?: '-' ?></div></td>
                                            <td data-label="Time Out"><div class="time-stack"><?= implode('', $timeOutList) ?: '-' ?></div></td>
                                            <td data-label="Total Hours"><span class="duration-badge"><?= $historyTotalHours ?></span></td>
                                            <td data-label="Regular Hours"><?= $historyRegularHours ?></td>
                                            <td data-label="OT Hours"><?= $historyOtHours ?></td>
                                            <td data-label="Total Pay"><?= formatPeso($history['total_pay'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div><div class="modal fade" id="dtrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Daily Time Record (DTR)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="dtrForm"><div class="modal-body">
            <input type="hidden" name="action" value="save_dtr">
            <input type="hidden" name="employee_id" id="singleDtrEmployee" value="">
            <div class="dtr-qb-sheet">
                <div class="dtr-qb-toolbar">
                    <button type="button" class="btn dtr-qb-add-row" onclick="addDtrBlankRow()"><i class="bi bi-plus-lg me-1"></i>Add Row</button>
                    <button type="button" class="btn btn-warning action-footer-btn" onclick="submitDtrForPayroll()"><i class="bi bi-send-check me-1"></i>Submit for Payroll</button>
                </div>
                <div class="dtr-qb-grid-wrap">
                    <table class="dtr-qb-grid mb-0">
                        <thead>
                            <tr>
                                <th style="width:18%;">Name</th>
                                <th style="width:11%;">Date</th>
                                <th style="width:9%;">Time In 1</th>
                                <th style="width:9%;">Time Out 1</th>
                                <th style="width:9%;">Time In 2</th>
                                <th style="width:9%;">Time Out 2</th>
                                <th style="width:9%;">Time In 3</th>
                                <th style="width:9%;">Time Out 3</th>
                                <th style="width:8%;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="dtrRowsBody"></tbody>
                    </table>
                </div>
            </div>
        </div><div class="modal-footer justify-content-end gap-2 flex-wrap">
            <button type="submit" class="btn btn-outline-primary-green action-footer-btn" data-save-mode="new"><i class="bi bi-save me-1"></i>Save & New</button>
            <button type="submit" class="btn btn-primary-green action-footer-btn" data-save-mode="close"><i class="bi bi-save me-1"></i>Save & Close</button>
            <button type="button" class="btn btn-outline-secondary action-footer-btn" onclick="clearDtrForm()"><i class="bi bi-eraser me-1"></i>Clear</button>
        </div></form>
    </div></div>
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
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary action-footer-btn" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="dtrRateDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i>Rate Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="rate-details-card">
                    <div class="rate-details-title">Rate Computation</div>
                    <div class="rate-employee-line">
                        <span>Employee: <strong id="rateDetailEmployee">-</strong></span>
                        <span>Date: <strong id="rateDetailDate">-</strong></span>
                        <span>Date Classification: <strong id="rateDetailClassification">-</strong></span>
                        <span id="rateDetailSubmittedAtRow" style="display:none;">Submitted At: <strong id="rateDetailSubmittedAt">-</strong></span>
                        <span id="rateDetailSubmittedByRow" style="display:none;">Submitted By: <strong id="rateDetailSubmittedBy">-</strong></span>
                        <span id="rateDetailTimeInRow" style="display:none;">Time In: <strong id="rateDetailTimeIns">-</strong></span>
                        <span id="rateDetailTimeOutRow" style="display:none;">Time Out: <strong id="rateDetailTimeOuts">-</strong></span>
                        <span>OT Status: <strong id="rateDetailOtStatus">No OT</strong></span>
                        <span id="rateDetailOtApprovedByRow" style="display:none;">Approved/Rejected By: <strong id="rateDetailOtApprovedBy">-</strong></span>
                        <span id="rateDetailOtApprovedAtRow" style="display:none;">Approval Date: <strong id="rateDetailOtApprovedAt">-</strong></span>
                        <span id="rateDetailOtRemarksRow" style="display:none;">Remarks: <strong id="rateDetailOtRemarks">-</strong></span>
                        <span id="rateDetailOtAttachmentsRow" style="display:none;">Attachments: <strong id="rateDetailOtAttachments">-</strong></span>
                    </div>
                    <div class="rate-mini-summary">
                        <span>Monthly Rate: <strong id="rateDetailMonthlyRate">₱0.00</strong></span>
                        <span>Daily Rate: <strong id="rateDetailDailyRate">₱0.00</strong></span>
                        <span>Hourly Rate: <strong id="rateDetailHourlyRate">₱0.00</strong></span>
                    </div>
                    <div class="rate-table-wrap">
                        <table class="rate-computation-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Hours</th>
                                    <th>Rate</th>
                                    <th>Pay</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Regular</td>
                                    <td id="rateDetailRegularTableHours">0.00</td>
                                    <td id="rateDetailRegularTableRate">0.00</td>
                                    <td id="rateDetailBasicPay">0.00</td>
                                </tr>
                                <tr>
                                    <td>OT</td>
                                    <td id="rateDetailOtTableHours">0.00</td>
                                    <td id="rateDetailOtTableRate">0.00</td>
                                    <td id="rateDetailOtPay">0.00</td>
                                </tr>
                                <tr class="rate-subtotal-row">
                                    <td>Sub-total</td>
                                    <td id="rateDetailSubTotalHours">0.00</td>
                                    <td></td>
                                    <td id="rateDetailSubTotalPay">0.00</td>
                                </tr>
                                <tr>
                                    <td>13th Month Provision</td>
                                    <td></td>
                                    <td></td>
                                    <td id="rateDetailThirteenthPay">0.00</td>
                                </tr>
                                <tr class="rate-total-row">
                                    <td>Total</td>
                                    <td></td>
                                    <td></td>
                                    <td id="rateDetailTotalPay">0.00</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary action-footer-btn" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="attachmentPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i><span id="attachmentPreviewTitle">Attachment Preview</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="attachmentPreviewEmpty" class="attachment-preview-empty" style="display:none;">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>Preview is not available for this file type.</span>
                </div>
                <iframe id="attachmentPreviewFrame" class="attachment-preview-frame" src="about:blank"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary action-footer-btn" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="motorpool_inventory.php">
                    <i class="bi bi-box-seam"></i>
                    <span>Inventory</span>
                </a>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'vendorMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Vendor</span>
                </a>
                <div class="more-dropdown" id="vendorMobileMenu">
                    <a class="dropdown-item" href="enterbills.php"><i
                            class="bi bi-file-earmark-text"></i><span>Enter Bills</span></a>
                    <a class="dropdown-item" href="paybills.php"><i class="bi bi-currency-dollar"></i><span>Pay
                            Bills</span></a>
                    <a class="dropdown-item" href="vendors.php"><i class="bi bi-shop"></i><span>Vendor List</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                    <i class="bi bi-people-fill"></i>
                    <span>Customer</span>
                </a>
                <div class="more-dropdown" id="customerMobileMenu">
                    <a class="dropdown-item" href="orderproduct.php"><i class="bi bi-receipt"></i><span>Create
                            Invoice</span></a>
                    <a class="dropdown-item" href="collections.php"><i class="bi bi-cash-stack"></i><span>Receive
                            Payment</span></a>
                    <a class="dropdown-item" href="customer_list.php"><i class="bi bi-person-badge"></i><span>Customer
                            List</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn active" href="#" onclick="toggleMobileDropdown(event, 'employeeMobileMenu')">
                    <i class="bi bi-briefcase"></i>
                    <span>Employees</span>
                </a>
                <div class="more-dropdown" id="employeeMobileMenu">
                    <a class="dropdown-item" href="employeelist.php"><i class="bi bi-receipt"></i><span>Employee
                            List</span></a>
                    <a class="dropdown-item active" href="employee.php"><i class="bi bi-cash-stack"></i><span>Enter
                            Time</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                    <i class="bi bi-bank2"></i>
                    <span>Banking</span>
                </a>
                <div class="more-dropdown" id="bankingMobileMenu">
                    <a class="dropdown-item" href="deposit.php"><i class="bi bi-bank"></i><span>Record
                            Deposit</span></a>
                    <a class="dropdown-item" href="withdrawal.php"><i class="bi bi-journal-check"></i><span>Write
                            Checks</span></a>
                    <a class="dropdown-item" href="transferfunds.php"><i
                            class="bi bi-arrow-left-right"></i><span>Transfer Funds</span></a>
                </div>
            </li>


            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'companyMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Company</span>
                </a>
                <div class="more-dropdown" id="companyMobileMenu">
                    <a class="dropdown-item" href="chartofaccounts.php"><i class="bi bi-graph-up"></i><span>Chart of
                            Accounts</span></a>
                    <a class="dropdown-item" href="request_handler.php"><i class="bi bi-clipboard"></i><span>RIS
                            Monitoring</span></a>
                    <a class="dropdown-item" href="motorpool.php"><i class="bi bi-truck"></i><span>Vehicle
                            Profile</span></a>
                    <a class="dropdown-item" href="central_warehouse.php"><i class="bi bi-box-seam"></i><span>Central
                            Warehouse</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'accountingMobileMenu')">
                    <i class="bi bi-graph-up"></i>
                    <span>Accounting</span>
                </a>
                <div class="more-dropdown" id="accountingMobileMenu">
                    <a class="dropdown-item" href="journal_entries.php"><i class="bi bi-journal"></i><span>Journal
                            Entries</span></a>
                    <a class="dropdown-item" href="batch_transaction.php"><i class="bi bi-collection"></i><span>Batch
                            Transactions</span></a>
                    <a class="dropdown-item" href="item_adjustment.php"><i class="bi bi-sliders"></i><span>Item
                            Adjustment</span></a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="it_request.php">
                    <i class="bi bi-headset"></i>
                    <span>IT Request</span>
                </a>
            </li>


            <li class="nav-item" id="profileMobileBtn">
                <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#profileModal">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button
                        type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p><?php if (!$view_all_branches && $branch_id > 0): ?>
                        <div class="branch-info mb-3"><i
                                class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span>
                        </div><?php endif; ?>
                    <button class="btn btn-danger btn-lg w-100"
                        onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const APP_TODAY = '<?= htmlspecialchars($today, ENT_QUOTES) ?>';
const calendarEvents = <?= json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const dtrRecordMap = <?= json_encode($dtrRecordMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const calendarDtrDetails = <?= json_encode(array_values(array_map(function($r){ return ['employee_id'=>(int)($r['employee_id'] ?? 0), 'employee_name'=>(string)($r['employee_name'] ?? ''), 'branch_name'=>(string)($r['branch_name'] ?? ''), 'attendance_date'=>(string)($r['attendance_date'] ?? ''), 'slots'=>$r['slots'] ?? [], 'daily_total_minutes'=>(int)($r['daily_total_minutes'] ?? 0), 'regular_minutes'=>(int)($r['regular_minutes'] ?? 0), 'overtime_minutes'=>(int)($r['overtime_minutes'] ?? 0), 'ot_requested_minutes'=>(int)($r['ot_requested_minutes'] ?? 0), 'ot_approved_minutes'=>(int)($r['ot_approved_minutes'] ?? 0), 'ot_approval_status'=>(string)($r['ot_approval_status'] ?? 'none'), 'ot_approved_by_name'=>(string)($r['ot_approved_by_name'] ?? ''), 'ot_approved_at'=>(string)($r['ot_approved_at'] ?? ''), 'ot_approval_remarks'=>(string)($r['ot_approval_remarks'] ?? ''), 'ot_approval_attachments'=>(string)($r['ot_approval_attachments'] ?? ''), 'basic_pay'=>number_format((float)($r['basic_pay'] ?? 0), 2), 'overtime_pay'=>number_format((float)($r['overtime_pay'] ?? 0), 2), 'total_pay'=>number_format((float)($r['total_pay'] ?? 0), 2), 'has_pending'=>!empty($r['has_pending']), 'holiday_type'=>(string)($r['holiday_type'] ?? ''), 'holiday_name'=>(string)($r['holiday_name'] ?? '')]; }, $dtrRows)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const pendingDtrData = <?= json_encode(array_values(array_filter(array_map(function($dtr){
    $slots = $dtr['slots'] ?? [];
    $slotCount = count($slots);
    $hasOpenSlot = false;
    foreach ($slots as $slot) {
        if (!empty($slot['is_open']) || empty($slot['end_time'])) {
            $hasOpenSlot = true;
            break;
        }
    }
    $requestedOt = (int)($dtr['ot_requested_minutes'] ?? 0);
    $otStatus = (string)($dtr['ot_approval_status'] ?? 'none');
    $isIncomplete = ($hasOpenSlot || $slotCount < 3);
    $hasUnapprovedOt = ($requestedOt > 0 && $otStatus === 'pending');
    if ((int)($dtr['payroll_submitted'] ?? 0) === 1) return null;
    return [
        'employee_id' => (int)($dtr['employee_id'] ?? 0),
        'employee_name' => (string)($dtr['employee_name'] ?? ''),
        'attendance_date' => (string)($dtr['attendance_date'] ?? ''),
        'slots' => $slots,
        'slot_count' => $slotCount,
        'ot_requested_minutes' => $requestedOt,
        'ot_approved_minutes' => (int)($dtr['ot_approved_minutes'] ?? 0),
        'ot_approval_status' => $otStatus,
        'is_incomplete' => $isIncomplete,
        'has_unapproved_ot' => $hasUnapprovedOt,
        'keep_in_dtr_until_payroll' => ($isIncomplete || $hasUnapprovedOt)
    ];
}, $dtrRows), function($dtr){
    return is_array($dtr) && !empty($dtr['keep_in_dtr_until_payroll']);
})), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const employeeData = <?= json_encode(array_values(array_map(function($employee){ return ['employee_id'=>(int)$employee['employee_id'], 'employee_name'=>(string)$employee['employee_name'], 'contact_number'=>(string)($employee['contact_number'] ?? ''), 'branch_name'=>(string)($employee['branch_name'] ?? ''), 'status'=>(string)($employee['status'] ?? '')]; }, array_filter($employees, function($employee){ return ($employee['status'] ?? '') === 'active'; }))), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

let calendarCursor = new Date();
let calendarSelectedDate = new Date();
let calendarCurrentView = 'month';
calendarCursor.setDate(1);
calendarSelectedDate.setHours(0,0,0,0);

function setCalendarView(view){
    calendarCurrentView = ['day','week','month'].includes(view) ? view : 'month';
    document.querySelectorAll('.calendar-view-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.calendarView === calendarCurrentView);
    });
    renderAttendanceCalendar();
}
function changeCalendarMonth(delta){
    if (calendarCurrentView === 'day') {
        calendarSelectedDate.setDate(calendarSelectedDate.getDate() + delta);
        calendarCursor = new Date(calendarSelectedDate.getFullYear(), calendarSelectedDate.getMonth(), 1);
    } else if (calendarCurrentView === 'week') {
        calendarSelectedDate.setDate(calendarSelectedDate.getDate() + (delta * 7));
        calendarCursor = new Date(calendarSelectedDate.getFullYear(), calendarSelectedDate.getMonth(), 1);
    } else {
        calendarCursor.setMonth(calendarCursor.getMonth() + delta);
        calendarSelectedDate = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth(), 1);
    }
    renderAttendanceCalendar();
}
function goToCalendarToday(){
    calendarSelectedDate = new Date();
    calendarSelectedDate.setHours(0,0,0,0);
    calendarCursor = new Date(calendarSelectedDate.getFullYear(), calendarSelectedDate.getMonth(), 1);
    renderAttendanceCalendar();
}
function setCalendarDate(dateKey, openDetails = false){
    const nextDate = new Date(dateKey + 'T00:00:00');
    if (Number.isNaN(nextDate.getTime())) return;
    calendarSelectedDate = nextDate;
    calendarSelectedDate.setHours(0,0,0,0);
    calendarCursor = new Date(nextDate.getFullYear(), nextDate.getMonth(), 1);
    renderAttendanceCalendar();
    if (openDetails) {
        setTimeout(function(){ openCalendarDateDetails(dateKey); }, 30);
    }
}
function focusFirstCalendarResult(){
    const first = document.querySelector('#attendanceCalendar [data-calendar-date]');
    if (first) first.focus();
}
function dateToKey(d){
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}
function getEasterDate(year){
    const a = year % 19;
    const b = Math.floor(year / 100);
    const c = year % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const month = Math.floor((h + l - 7 * m + 114) / 31);
    const day = ((h + l - 7 * m + 114) % 31) + 1;
    return new Date(year, month - 1, day);
}
function addDays(date, days){
    const d = new Date(date);
    d.setDate(d.getDate() + days);
    return d;
}
function lastMondayOfAugust(year){
    const d = new Date(year, 7, 31);
    while (d.getDay() !== 1) d.setDate(d.getDate() - 1);
    return dateToKey(d);
}
function getPhilippineHolidayInfoJS(dateKey){
    const [yearStr, monthStr, dayStr] = dateKey.split('-');
    const year = parseInt(yearStr, 10);
    const md = `${monthStr}-${dayStr}`;
    const fixed = {
        '01-01': {type:'regular_holiday', name:"New Year's Day"},
        '02-25': {type:'special_non_working', name:'EDSA People Power Revolution Anniversary'},
        '04-09': {type:'regular_holiday', name:'Araw ng Kagitingan'},
        '05-01': {type:'regular_holiday', name:'Labor Day'},
        '06-12': {type:'regular_holiday', name:'Independence Day'},
        '08-21': {type:'special_non_working', name:'Ninoy Aquino Day'},
        '11-01': {type:'special_non_working', name:"All Saints' Day"},
        '11-30': {type:'regular_holiday', name:'Bonifacio Day'},
        '12-08': {type:'special_non_working', name:'Feast of Immaculate Conception of Mary'},
        '12-24': {type:'special_non_working', name:'Additional Special Non-Working Day'},
        '12-25': {type:'regular_holiday', name:'Christmas Day'},
        '12-30': {type:'regular_holiday', name:'Rizal Day'},
        '12-31': {type:'special_non_working', name:'Last Day of the Year'}
    };
    if (fixed[md]) return fixed[md];
    if (dateKey === lastMondayOfAugust(year)) return {type:'regular_holiday', name:'National Heroes Day'};
    const easter = getEasterDate(year);
    if (dateKey === dateToKey(addDays(easter, -3))) return {type:'regular_holiday', name:'Maundy Thursday'};
    if (dateKey === dateToKey(addDays(easter, -2))) return {type:'regular_holiday', name:'Good Friday'};
    if (dateKey === dateToKey(addDays(easter, -1))) return {type:'special_non_working', name:'Black Saturday'};
    const movable = {
        2024: {'2024-02-10': {type:'special_non_working', name:'Chinese New Year'}, '2024-04-10': {type:'regular_holiday', name:"Eid'l Fitr"}, '2024-06-17': {type:'regular_holiday', name:"Eid'l Adha"}},
        2025: {'2025-01-29': {type:'special_non_working', name:'Chinese New Year'}, '2025-03-31': {type:'regular_holiday', name:"Eid'l Fitr"}, '2025-06-06': {type:'regular_holiday', name:"Eid'l Adha"}},
        2026: {'2026-02-17': {type:'special_non_working', name:'Chinese New Year'}, '2026-03-20': {type:'regular_holiday', name:"Eid'l Fitr"}, '2026-05-27': {type:'regular_holiday', name:"Eid'l Adha"}},
        2027: {'2027-02-06': {type:'special_non_working', name:'Chinese New Year'}, '2027-03-10': {type:'regular_holiday', name:"Eid'l Fitr"}, '2027-05-17': {type:'regular_holiday', name:"Eid'l Adha"}},
        2028: {'2028-01-26': {type:'special_non_working', name:'Chinese New Year'}, '2028-02-27': {type:'regular_holiday', name:"Eid'l Fitr"}, '2028-05-05': {type:'regular_holiday', name:"Eid'l Adha"}},
        2029: {'2029-02-13': {type:'special_non_working', name:'Chinese New Year'}, '2029-02-15': {type:'regular_holiday', name:"Eid'l Fitr"}, '2029-04-24': {type:'regular_holiday', name:"Eid'l Adha"}},
        2030: {'2030-02-03': {type:'special_non_working', name:'Chinese New Year'}, '2030-02-05': {type:'regular_holiday', name:"Eid'l Fitr"}, '2030-04-14': {type:'regular_holiday', name:"Eid'l Adha"}}
    };
    return (movable[year] && movable[year][dateKey]) ? movable[year][dateKey] : null;
}
function buildCalendarEventMap(){
    const byDate = {};
    (calendarEvents || []).forEach(ev => {
        if (!ev.date) return;
        (byDate[ev.date] ||= []).push(ev);
    });
    return byDate;
}
function getCalendarEventsForDate(dateKey, query = ''){
    const byDate = buildCalendarEventMap();
    let events = [...(byDate[dateKey] || [])];
    if (!events.some(ev => ev.type === 'holiday')) {
        const generatedHoliday = getPhilippineHolidayInfoJS(dateKey);
        if (generatedHoliday) events.unshift({date:dateKey, type:'holiday', holiday_type:generatedHoliday.type, title:generatedHoliday.name});
    }
    if (query) {
        const rows = (calendarDtrDetails || []).filter(row => row.attendance_date === dateKey);
        events = events.filter(ev => {
            const rowText = rows.map(row => `${row.employee_name || ''} ${row.branch_name || ''} ${row.holiday_name || ''} ${row.basic_pay || ''} ${row.overtime_pay || ''} ${row.total_pay || ''}`).join(' ');
            return `${ev.title || ''} ${ev.holiday_type || ''} ${ev.type || ''} ${rowText}`.toLowerCase().includes(query);
        });
    }
    return events;
}
function renderMiniCalendar(year, month, todayKey, selectedKey, query){
    const miniTitle = document.getElementById('miniCalendarTitle');
    const miniWrap = document.getElementById('miniAttendanceCalendar');
    if (!miniWrap) return;
    const monthLabel = new Date(year, month, 1).toLocaleString('en-US', {month:'long', year:'numeric'});
    if (miniTitle) miniTitle.textContent = monthLabel;
    const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const startBlank = new Date(year, month, 1).getDay();
    let miniHtml = dayNames.map(d => `<div class="mini-day-name">${d}</div>`).join('');
    const miniStart = new Date(year, month, 1 - startBlank);
    for (let i = 0; i < 42; i++) {
        const d = new Date(miniStart);
        d.setDate(miniStart.getDate() + i);
        const dateKey = dateToKey(d);
        const hasEvent = getCalendarEventsForDate(dateKey, query).length > 0;
        const cls = ['mini-date'];
        if (d.getMonth() !== month) cls.push('muted');
        if (dateKey === todayKey) cls.push('today');
        if (dateKey === selectedKey) cls.push('selected');
        if (hasEvent) cls.push('has-event');
        miniHtml += `<div class="${cls.join(' ')}" role="button" tabindex="0" onclick="setCalendarDate('${dateKey}', false)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();setCalendarDate('${dateKey}', false);}">${d.getDate()}</div>`;
    }
    miniWrap.innerHTML = miniHtml;
}
function renderMiniHolidayList(year, month, query = ''){
    const list = document.getElementById('miniHolidayList');
    if (!list) return;
    const holidays = [];
    const lastDate = new Date(year, month + 1, 0).getDate();
    for (let day = 1; day <= lastDate; day++) {
        const dateKey = dateToKey(new Date(year, month, day));
        let holiday = null;
        const existing = getCalendarEventsForDate(dateKey, '').find(ev => ev.type === 'holiday');
        if (existing) {
            holiday = {type: existing.holiday_type || '', name: existing.title || ''};
        } else {
            holiday = getPhilippineHolidayInfoJS(dateKey);
        }
        if (!holiday || !holiday.name) continue;
        if (query && !(`${holiday.name} ${holiday.type}`.toLowerCase().includes(query))) continue;
        holidays.push({dateKey, day, monthText: new Date(year, month, day).toLocaleString('en-US', {month:'short'}), ...holiday});
    }
    if (!holidays.length) {
        list.innerHTML = '<div class="mini-holiday-empty">No holidays this month.</div>';
        return;
    }
    list.innerHTML = holidays.map(holiday => {
        const typeLabel = holiday.type === 'regular_holiday' ? 'Regular Holiday' : 'Special Non-working Day';
        return `<div class="mini-holiday-item" role="button" tabindex="0" onclick="setCalendarDate('${holiday.dateKey}', true)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();setCalendarDate('${holiday.dateKey}', true);}">
            <div class="mini-holiday-date"><span>${escapeHtml(holiday.monthText)}</span><span>${holiday.day}</span></div>
            <div class="mini-holiday-info">
                <div class="mini-holiday-name" title="${escapeHtml(holiday.name)}">${escapeHtml(holiday.name)}</div>
                <span class="mini-holiday-type ${escapeHtml(holiday.type)}">${typeLabel}</span>
            </div>
        </div>`;
    }).join('');
}
function renderCalendarEventItem(ev){
    if (ev.type === 'holiday') return `<div class="calendar-event holiday ${ev.holiday_type || ''}" title="${escapeHtml(ev.title || '')}">${escapeHtml(ev.title || '')}</div>`;
    const timeText = ev.hours ? `${ev.hours}h` : 'Attendance';
    return `<div class="calendar-event attendance${ev.pending ? ' pending' : ''}" title="${escapeHtml(ev.title || '')}">${escapeHtml(timeText)} · ${escapeHtml(ev.title || '')}${ev.pending ? ' · Pending' : ''}</div>`;
}
function renderMonthCalendar(year, month, query, todayKey){
    const first = new Date(year, month, 1);
    const last = new Date(year, month + 1, 0);
    const startBlank = first.getDay();
    const totalCells = Math.ceil((startBlank + last.getDate()) / 7) * 7;
    const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    let html = '<div class="calendar-grid calendar-days">' + dayNames.map(d => `<div>${d.toUpperCase()}</div>`).join('') + '</div><div class="calendar-grid">';
    const gridStart = new Date(year, month, 1 - startBlank);
    for (let i = 0; i < totalCells; i++) {
        const d = new Date(gridStart);
        d.setDate(gridStart.getDate() + i);
        const dateKey = dateToKey(d);
        const events = getCalendarEventsForDate(dateKey, query);
        const cellClass = ['calendar-cell'];
        if (d.getMonth() !== month) cellClass.push('muted');
        if (dateKey === todayKey) cellClass.push('today');
        if (dateKey === dateToKey(calendarSelectedDate)) cellClass.push('selected');
        html += `<div class="${cellClass.join(' ')}" data-calendar-date="${dateKey}" role="button" tabindex="0" onclick="setCalendarDate('${dateKey}', true)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();setCalendarDate('${dateKey}', true);}"><div class="calendar-date-num">${d.getDate()}</div>`;
        events.slice(0,4).forEach(ev => html += renderCalendarEventItem(ev));
        if (events.length > 4) html += `<div class="calendar-more">+${events.length - 4} more</div>`;
        html += '</div>';
    }
    html += '</div>';
    return html;
}
function getWeekStart(date){
    const d = new Date(date);
    d.setDate(d.getDate() - d.getDay());
    d.setHours(0,0,0,0);
    return d;
}
function renderWeekCalendar(query, todayKey){
    const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const start = getWeekStart(calendarSelectedDate);
    let html = '<div class="calendar-grid calendar-days week-grid">';
    for (let i = 0; i < 7; i++) {
        const d = addDays(start, i);
        html += `<div>${dayNames[i].toUpperCase()}<br><span style="font-size:11px;color:#9aa3ad;">${d.toLocaleDateString('en-US', {month:'short', day:'numeric'})}</span></div>`;
    }
    html += '</div><div class="calendar-grid week-grid">';
    for (let i = 0; i < 7; i++) {
        const d = addDays(start, i);
        const dateKey = dateToKey(d);
        const events = getCalendarEventsForDate(dateKey, query);
        const cellClass = ['calendar-cell','week-cell'];
        if (dateKey === todayKey) cellClass.push('today');
        if (dateKey === dateToKey(calendarSelectedDate)) cellClass.push('selected');
        html += `<div class="${cellClass.join(' ')}" data-calendar-date="${dateKey}" role="button" tabindex="0" onclick="setCalendarDate('${dateKey}', true)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();setCalendarDate('${dateKey}', true);}"><div class="calendar-date-num">${d.getDate()}</div>`;
        events.forEach(ev => html += renderCalendarEventItem(ev));
        if (!events.length) html += '<div class="calendar-detail-empty" style="padding:18px 4px;font-size:12px;">No entries</div>';
        html += '</div>';
    }
    html += '</div>';
    return html;
}
function renderDayCalendar(query){
    const dateKey = dateToKey(calendarSelectedDate);
    const events = getCalendarEventsForDate(dateKey, query);
    const dateLabel = calendarSelectedDate.toLocaleDateString('en-US', {weekday:'long', month:'long', day:'numeric', year:'numeric'});
    let html = `<div class="calendar-day-view" data-calendar-date="${dateKey}" tabindex="0"><div class="calendar-day-view-header"><h6>${dateLabel}</h6><button type="button" class="calendar-today-btn" onclick="setCalendarDate('${dateKey}', true)">View Details</button></div><div class="calendar-day-view-body">`;
    if (events.length) {
        events.forEach(ev => {
            const meta = ev.type === 'holiday' ? (ev.holiday_type === 'regular_holiday' ? 'Regular Holiday' : 'Special Holiday') : (ev.pending ? 'Attendance · Pending' : 'Attendance');
            html += `<div class="calendar-result-card" onclick="setCalendarDate('${dateKey}', true)"><div class="calendar-result-title">${escapeHtml(ev.title || 'Attendance')}</div><div class="calendar-result-meta">${escapeHtml(meta)}</div></div>`;
        });
    } else {
        html += '<div class="calendar-empty-state"><i class="bi bi-calendar-x d-block mb-2" style="font-size:30px;"></i>No attendance or holiday details found for this date.</div>';
    }
    html += '</div></div>';
    return html;
}
function renderAttendanceCalendar(){
    const wrap = document.getElementById('attendanceCalendar');
    const title = document.getElementById('calendarTitle');
    if (!wrap) return;
    const searchInput = document.getElementById('calendarSearchInput');
    const query = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
    const today = new Date();
    const todayKey = dateToKey(today);
    const selectedKey = dateToKey(calendarSelectedDate);
    const year = calendarCursor.getFullYear();
    const month = calendarCursor.getMonth();
    renderMiniCalendar(year, month, todayKey, selectedKey, query);
    renderMiniHolidayList(year, month, query);
    if (calendarCurrentView === 'day') {
        if (title) title.textContent = calendarSelectedDate.toLocaleDateString('en-US', {month:'long', day:'numeric', year:'numeric'});
        wrap.innerHTML = renderDayCalendar(query);
    } else if (calendarCurrentView === 'week') {
        const weekStart = getWeekStart(calendarSelectedDate);
        const weekEnd = addDays(weekStart, 6);
        if (title) title.textContent = `${weekStart.toLocaleDateString('en-US', {month:'short', day:'numeric'})} - ${weekEnd.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'})}`;
        wrap.innerHTML = renderWeekCalendar(query, todayKey);
    } else {
        const monthLabel = calendarCursor.toLocaleString('en-US', {month:'long', year:'numeric'});
        if (title) title.textContent = monthLabel;
        wrap.innerHTML = renderMonthCalendar(year, month, query, todayKey);
    }
}

function formatMinutesText(minutes){
    minutes = parseInt(minutes || 0, 10);
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h && m) return `${h}h ${m}m`;
    if (h) return `${h}h`;
    return `${m}m`;
}
function formatDurationDecimal(minutes){
    const value = parseInt(minutes || 0, 10) / 60;
    return value.toFixed(2);
}
function formatSlotDurationDecimal(slot){
    return formatDurationDecimal(slot && slot.duration_minutes ? slot.duration_minutes : 0);
}
function formatTimeText(value){
    if (!value) return 'Pending';
    const parts = String(value).split(':');
    let hour = parseInt(parts[0] || '0', 10);
    const minute = parts[1] || '00';
    const ampm = hour >= 12 ? 'PM' : 'AM';
    hour = hour % 12 || 12;
    return `${hour}:${minute} ${ampm}`;
}
function openCalendarDateDetails(dateKey){
    const modalEl = document.getElementById('calendarDateModal');
    const titleEl = document.getElementById('calendarDateModalTitle');
    const bodyEl = document.getElementById('calendarDateModalBody');
    if (!modalEl || !bodyEl) return;

    const dateObj = new Date(dateKey + 'T00:00:00');
    if (titleEl) titleEl.textContent = dateObj.toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'});

    const eventsForDate = (calendarEvents || []).filter(ev => ev.date === dateKey);
    let holiday = eventsForDate.find(ev => ev.type === 'holiday');
    if (!holiday) {
        const generatedHoliday = getPhilippineHolidayInfoJS(dateKey);
        if (generatedHoliday) holiday = {type:'holiday', holiday_type:generatedHoliday.type, title:generatedHoliday.name};
    }
    const attendanceRows = (calendarDtrDetails || []).filter(row => row.attendance_date === dateKey);

    let html = '';
    if (holiday) {
        const typeLabel = holiday.holiday_type === 'regular_holiday' ? 'Regular Holiday' : 'Special Holiday';
        html += `<div class="calendar-detail-section"><h6><i class="bi bi-star-fill me-1"></i>Holiday</h6><div class="calendar-detail-table-wrapper"><table class="calendar-detail-table calendar-day-table"><thead><tr><th class="holiday-cell">Holiday Name</th><th class="holiday-cell">Type</th></tr></thead><tbody><tr><td>${escapeHtml(holiday.title || '-')}</td><td><span class="holiday-chip ${escapeHtml(holiday.holiday_type || '')}">${typeLabel}</span></td></tr></tbody></table></div></div>`;
    }

    if (attendanceRows.length) {
        html += `<div class="calendar-detail-section"><h6><i class="bi bi-person-check me-1"></i>Attendance Details</h6><div class="calendar-detail-table-wrapper"><table class="calendar-detail-table calendar-day-table"><thead><tr><th class="employee-cell">Employee</th><th class="branch-cell">Branch</th><th class="time-cell">Time In</th><th class="time-cell">Time Out</th><th class="duration-cell number-cell">Duration</th><th class="duration-cell number-cell">Regular</th><th class="duration-cell number-cell">OT</th><th class="holiday-cell">Date Classification</th><th class="money-cell">Basic Pay</th><th class="money-cell">OT Pay</th><th class="money-cell">Total Pay</th></tr></thead><tbody>`;
        attendanceRows.forEach(row => {
            const rowSlots = (row.slots && row.slots.length) ? row.slots : [{start_time:'', end_time:'', duration_minutes:row.daily_total_minutes || 0, is_open:row.has_pending}];
            const slotCount = rowSlots.length;
            rowSlots.forEach((slot, index) => {
                const isFirstSlot = index === 0;
                const statusBadge = row.has_pending ? ' <span class="badge bg-warning text-dark">Pending</span>' : '';
                const timeOutText = slot.is_open ? 'Pending' : formatTimeText(slot.end_time);
                html += '<tr>';
                if (isFirstSlot) {
                    html += `<td class="employee-cell" rowspan="${slotCount}"><strong>${escapeHtml(row.employee_name || 'Employee')}</strong>${statusBadge}</td>`;
                    html += `<td class="branch-cell" rowspan="${slotCount}">${displayValue(row.branch_name)}</td>`;
                }
                html += `<td class="time-cell">${formatTimeText(slot.start_time)}</td>`;
                html += `<td class="time-cell">${timeOutText}</td>`;
                html += `<td class="duration-cell number-cell">${formatSlotDurationDecimal(slot)}</td>`;
                if (isFirstSlot) {
                    html += `<td class="duration-cell number-cell" rowspan="${slotCount}">${formatDurationDecimal(row.regular_minutes || 0)}</td>`;
                    html += `<td class="duration-cell number-cell" rowspan="${slotCount}">${formatDurationDecimal(row.overtime_minutes || 0)}</td>`;
                    const dateClassificationLabel = row.holiday_name ? (row.holiday_type === 'regular_holiday' ? 'Regular Holiday' : 'Special Holiday') : 'Regular Workday';
                    const dateClassification = row.holiday_name ? '<span class="holiday-chip ' + escapeHtml(row.holiday_type || '') + '">' + escapeHtml(dateClassificationLabel) + '</span>' : '<span class="workday-chip">Regular Workday</span>';
                    html += `<td class="holiday-cell" rowspan="${slotCount}">${dateClassification}</td>`;
                    html += `<td class="money-cell" rowspan="${slotCount}">₱${escapeHtml(row.basic_pay || '0.00')}</td>`;
                    html += `<td class="money-cell" rowspan="${slotCount}">₱${escapeHtml(row.overtime_pay || '0.00')}</td>`;
                    html += `<td class="money-cell" rowspan="${slotCount}"><strong>₱${escapeHtml(row.total_pay || '0.00')}</strong></td>`;
                }
                html += '</tr>';
            });
        });
        html += '</tbody></table></div></div>';
    }

    if (!html) html = '<div class="calendar-detail-empty"><i class="bi bi-calendar-x d-block mb-2" style="font-size:28px;"></i>No attendance or holiday details found for this date.</div>';
    bodyEl.innerHTML = html;
    const existingModal = bootstrap.Modal.getInstance(modalEl);
    (existingModal || new bootstrap.Modal(modalEl)).show();
}


let activeDtrDropdown = null;
let dtrRowCounter = 0;
const employeeModal = () => new bootstrap.Modal(document.getElementById('employeeModal'));
const dtrModal = () => new bootstrap.Modal(document.getElementById('dtrModal'));
const employeeDetailsModal = () => new bootstrap.Modal(document.getElementById('employeeDetailsModal'));
let currentEmployeeDetails = null;

document.addEventListener('DOMContentLoaded', function(){
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const desktopBtn = document.getElementById('desktopToggleBtn');
    if (mobileBtn) mobileBtn.addEventListener('click', toggleSidebar);
    renderAttendanceCalendar();
    if (desktopBtn) desktopBtn.addEventListener('click', toggleSidebar);
    restoreSidebarState();

    const employeeFormEl = document.getElementById('employeeForm');
    const dtrFormEl = document.getElementById('dtrForm');
    if (employeeFormEl) employeeFormEl.addEventListener('submit', submitForm);
    if (dtrFormEl) dtrFormEl.addEventListener('submit', submitForm);
    document.querySelectorAll('.dtr-row-date, .dtr-time-in, .dtr-time-out').forEach(input => input.addEventListener('change', () => refreshDtrRowState(input.closest('.dtr-entry-row'))));
    document.querySelectorAll('.dtr-history-clickable, .payroll-history-clickable, .ot-approval-row').forEach(row => {
        row.addEventListener('click', function(){
            showDtrRateDetails(this);
        });
    });
    refreshAllDtrRowStates();
});


function showDtrRateDetails(row){
    if (!row) return;
    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value || '-';
    };
    const setHtml = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = value || '-';
    };

    const classification = row.dataset.classification || 'Regular Workday';
    const toNumber = (value) => {
        const parsed = parseFloat(String(value || '0').replace(/[^0-9.-]/g, ''));
        return Number.isFinite(parsed) ? parsed : 0;
    };
    const moneyPlain = (value) => toNumber(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const hoursPlain = (value) => toNumber(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    setText('rateDetailEmployee', row.dataset.employee || '-');
    setText('rateDetailDate', row.dataset.date || '-');
    setText('rateDetailClassification', classification);
    setText('rateDetailTotalHours', row.dataset.totalHours || '0.00');
    setText('rateDetailRegularHours', row.dataset.regularHours || '0.00');
    setText('rateDetailOtHours', row.dataset.otHours || '0.00');
    setText('rateDetailRequestedOt', row.dataset.requestedOtHours || row.dataset.otHours || '0.00');
    setText('rateDetailApprovedOt', row.dataset.approvedOtHours || row.dataset.otHours || '0.00');
    setText('rateDetailOtStatus', row.dataset.otStatus || 'No OT');
    setText('rateDetailOtApprovedBy', row.dataset.otApprovedBy || '-');
    setText('rateDetailOtApprovedAt', row.dataset.otApprovedAt || '-');
    setText('rateDetailOtRemarks', row.dataset.otRemarks || '-');
    setHtml('rateDetailOtAttachments', row.dataset.otAttachments || '-');
    setText('rateDetailMonthlyRate', row.dataset.monthlyRate || '₱0.00');
    setText('rateDetailDailyRate', row.dataset.dailyRate || '₱0.00');
    setText('rateDetailHourlyRate', row.dataset.hourlyRate || '₱0.00');
    const regularHours = toNumber(row.dataset.regularHours);
    const approvedOtHours = toNumber(row.dataset.approvedOtHours || row.dataset.otHours);
    const basicPay = toNumber(row.dataset.basicPayPlain || row.dataset.basicPay);
    const otPay = toNumber(row.dataset.otPayPlain || row.dataset.otPay);
    const subtotalPay = basicPay + otPay;
    const thirteenthPay = basicPay / 12;
    const grandTotalPay = subtotalPay + thirteenthPay;
    const regularRate = regularHours > 0 ? basicPay / regularHours : 0;
    const otRate = approvedOtHours > 0 ? otPay / approvedOtHours : 0;
    const paidHours = regularHours + approvedOtHours;

    setText('rateDetailRegularTableHours', hoursPlain(regularHours));
    setText('rateDetailRegularTableRate', moneyPlain(regularRate));
    setText('rateDetailBasicPay', moneyPlain(basicPay));
    setText('rateDetailOtTableHours', hoursPlain(approvedOtHours));
    setText('rateDetailOtTableRate', moneyPlain(otRate));
    setText('rateDetailOtPay', moneyPlain(otPay));
    setText('rateDetailSubTotalHours', hoursPlain(paidHours));
    setText('rateDetailSubTotalPay', moneyPlain(subtotalPay));
    setText('rateDetailThirteenthPay', moneyPlain(thirteenthPay));
    setText('rateDetailTotalPay', moneyPlain(grandTotalPay));
    setText('rateDetailSubmittedAt', row.dataset.submittedAt || '-');
    setText('rateDetailSubmittedBy', row.dataset.submittedBy || '-');
    setText('rateDetailTimeIns', row.dataset.timeIns || '-');
    setText('rateDetailTimeOuts', row.dataset.timeOuts || '-');

    const isSubmittedHistory = row.classList.contains('payroll-history-clickable');
    const hasOtApprovedBy = (row.dataset.otApprovedBy || '').trim() !== '' && (row.dataset.otApprovedBy || '').trim() !== '-';
    const hasOtApprovedAt = (row.dataset.otApprovedAt || '').trim() !== '' && (row.dataset.otApprovedAt || '').trim() !== '-';
    const hasOtRemarks = (row.dataset.otRemarks || '').trim() !== '' && (row.dataset.otRemarks || '').trim() !== '-';
    const hasOtAttachments = (row.dataset.otAttachments || '').trim() !== '' && (row.dataset.otAttachments || '').trim() !== '-';
    const hasOtApprovalInfo = hasOtApprovedBy || hasOtApprovedAt || hasOtRemarks || hasOtAttachments;
    ['rateDetailSubmittedAtRow','rateDetailSubmittedByRow','rateDetailTimeInRow','rateDetailTimeOutRow'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = isSubmittedHistory ? '' : 'none';
    });
    const approvedByRow = document.getElementById('rateDetailOtApprovedByRow');
    if (approvedByRow) approvedByRow.style.display = hasOtApprovalInfo ? '' : 'none';
    const approvedAtRow = document.getElementById('rateDetailOtApprovedAtRow');
    if (approvedAtRow) approvedAtRow.style.display = hasOtApprovalInfo ? '' : 'none';
    const remarksRow = document.getElementById('rateDetailOtRemarksRow');
    if (remarksRow) remarksRow.style.display = hasOtApprovalInfo ? '' : 'none';
    const attachmentsRow = document.getElementById('rateDetailOtAttachmentsRow');
    if (attachmentsRow) attachmentsRow.style.display = hasOtApprovalInfo ? '' : 'none';

    const modalEl = document.getElementById('dtrRateDetailsModal');
    if (modalEl) {
        const instance = bootstrap.Modal.getInstance(modalEl);
        (instance || new bootstrap.Modal(modalEl)).show();
    }
}

function openAttachmentPreview(link) {
    if (!link) return;
    const href = link.getAttribute('href') || '';
    const fileName = link.getAttribute('data-attachment-name') || link.textContent.trim() || 'Attachment Preview';
    const titleEl = document.getElementById('attachmentPreviewTitle');
    const frameEl = document.getElementById('attachmentPreviewFrame');
    const emptyEl = document.getElementById('attachmentPreviewEmpty');
    const modalEl = document.getElementById('attachmentPreviewModal');
    if (!href || !frameEl || !modalEl) return;

    if (titleEl) titleEl.textContent = fileName;
    const extension = (href.split('?')[0].split('#')[0].split('.').pop() || '').toLowerCase();
    const previewable = ['jpg','jpeg','png','gif','webp','pdf','txt'].includes(extension);

    if (previewable) {
        frameEl.style.display = '';
        frameEl.src = href;
        if (emptyEl) emptyEl.style.display = 'none';
    } else {
        frameEl.style.display = 'none';
        frameEl.src = 'about:blank';
        if (emptyEl) emptyEl.style.display = '';
    }

    const instance = bootstrap.Modal.getInstance(modalEl);
    (instance || new bootstrap.Modal(modalEl)).show();
}

document.addEventListener('click', function(e) {
    const link = e.target.closest('a[data-attachment-preview="1"]');
    if (!link) return;
    e.preventDefault();
    e.stopPropagation();
    openAttachmentPreview(link);
});

function toggleSidebar(){
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    if (window.innerWidth <= 992) {
        sidebar.classList.toggle('active');
    } else {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');
    }
}
function toggleSidebarDropdown(event, targetId) {
    event.preventDefault();
    event.stopPropagation();
    const target = document.getElementById(targetId);
    const btn = event.currentTarget;
    const arrow = btn.querySelector('.dropdown-arrow');
    const sidebar = document.getElementById('sidebar');
    if (!target) return false;
    if (sidebar && sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
    }
    document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
        if (collapse.id !== targetId) {
            collapse.classList.remove('show');
            const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
            const otherArrow = otherBtn ? otherBtn.querySelector('.dropdown-arrow') : null;
            if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
        }
    });
    target.classList.toggle('show');
    if (arrow) arrow.style.transform = target.classList.contains('show') ? 'translateY(-50%) rotate(180deg)' : 'translateY(-50%) rotate(0deg)';
    return false;
}
function restoreSidebarState(){
    const sidebar = document.getElementById('sidebar');
    if (sidebar && window.innerWidth > 992 && localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }
    document.querySelectorAll('.sidebar .nav-link.active').forEach(link => {
        const collapseDiv = link.closest('.collapse');
        if (collapseDiv) {
            collapseDiv.classList.add('show');
            const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
            const arrow = parentBtn ? parentBtn.querySelector('.dropdown-arrow') : null;
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }
    });
}

function escapeHtml(value){
    return String(value ?? '').replace(/[&<>"]|'/g, function(match){
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[match];
    });
}
function displayValue(value){
    return value === null || value === undefined || value === '' ? '-' : escapeHtml(value);
}
function formatDateDisplay(value){
    if (!value) return '-';
    const date = new Date(value + 'T00:00:00');
    if (Number.isNaN(date.getTime())) return displayValue(value);
    return date.toLocaleDateString('en-US', {month:'short', day:'2-digit', year:'numeric'});
}
function showEmployeeDetails(emp){
    currentEmployeeDetails = emp;
    const grid = document.getElementById('employeeDetailsGrid');
    if (!grid) return;
    const status = emp.status ? emp.status.charAt(0).toUpperCase() + emp.status.slice(1) : '-';
    const items = [
        ['Employee Name', emp.employee_name],
        ['Branch', emp.branch_name || '<?= htmlspecialchars($branch_name, ENT_QUOTES) ?>'],
        ['Contact Number', emp.contact_number],
        ['Email', emp.email],
        ['Birthday', formatDateDisplay(emp.birthday)],
        ['TIN', emp.tin],
        ['PhilHealth', emp.philhealth],
        ['SSS', emp.sss],
        ['Pag-IBIG', emp.pagibig],
        ['Status', status]
    ];
    grid.innerHTML = items.map(function(item){
        return '<div class="profile-item"><span>' + escapeHtml(item[0]) + '</span><strong>' + displayValue(item[1]) + '</strong></div>';
    }).join('');
    const editBtn = document.getElementById('employeeDetailsEditBtn');
    if (editBtn) {
        editBtn.onclick = function(){
            bootstrap.Modal.getInstance(document.getElementById('employeeDetailsModal'))?.hide();
            setTimeout(function(){ editEmployee(currentEmployeeDetails); }, 200);
        };
    }
    const deleteBtn = document.getElementById('employeeDetailsDeleteBtn');
    if (deleteBtn) {
        deleteBtn.onclick = function(){
            if (!currentEmployeeDetails || !currentEmployeeDetails.employee_id) return;
            bootstrap.Modal.getInstance(document.getElementById('employeeDetailsModal'))?.hide();
            setTimeout(function(){ deleteEmployee(currentEmployeeDetails.employee_id); }, 200);
        };
    }
    employeeDetailsModal().show();
}

function clearEmployeeForm(){
    const form = document.getElementById('employeeForm');
    form.reset();
    document.getElementById('employeeId').value = '';
    document.getElementById('employeeStatus').value = 'active';
    document.getElementById('employeeModalTitle').innerHTML = '<i class="bi bi-person-plus me-2"></i>Add Employee';
}
function showEmployeeModal(){
    clearEmployeeForm();
    employeeModal().show();
}
function editEmployee(emp){
    document.getElementById('employeeForm').reset();
    document.getElementById('employeeId').value = emp.employee_id || '';
    document.getElementById('employeeName').value = emp.employee_name || '';
    document.getElementById('contactNumber').value = emp.contact_number || '';
    document.getElementById('employeeEmail').value = emp.email || '';
    document.getElementById('birthday').value = emp.birthday || '';
    document.getElementById('tin').value = emp.tin || '';
    document.getElementById('philhealth').value = emp.philhealth || '';
    document.getElementById('sss').value = emp.sss || '';
    document.getElementById('pagibig').value = emp.pagibig || '';
    document.getElementById('employeeStatus').value = emp.status || 'active';
    document.getElementById('employeeModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Employee';
    employeeModal().show();
}

function normalizeText(value){
    return String(value ?? '').trim().replace(/\s+/g, ' ').toLowerCase();
}
function findEmployeeByName(name){
    const target = normalizeText(name);
    if (!target) return null;
    return employeeData.find(emp => normalizeText(emp.employee_name) === target) || null;
}

function formatTimeForInput(value){
    const timeText = String(value || '').trim();
    if (!timeText) return '';
    const match = timeText.match(/^(\d{1,2}):(\d{2})/);
    if (!match) return '';
    return String(match[1]).padStart(2, '0') + ':' + match[2];
}
function getDtrRecord(employeeId, dateValue){
    return employeeId && dateValue && dtrRecordMap[String(employeeId)] ? (dtrRecordMap[String(employeeId)][dateValue] || null) : null;
}
function getDtrRecordForRow(row){
    if (!row) return null;
    const employeeId = row.dataset.employeeId || row.querySelector('.dtr-employee-id-input')?.value || '';
    const dateValue = row.querySelector('.dtr-row-date')?.value || '';
    return getDtrRecord(employeeId, dateValue);
}
function buildEmployeeDropdownOptions(row, keyword){
    const menu = row.querySelector('.dtr-employee-dropdown-menu');
    if (!menu) return;
    const search = normalizeText(keyword);
    const matches = employeeData.filter(emp => {
        const label = normalizeText((emp.employee_name || '') + ' ' + (emp.contact_number || ''));
        return !search || label.includes(search);
    }).slice(0, 80);
    if (matches.length === 0) {
        menu.innerHTML = '<div class="dtr-employee-empty-option">No employee found</div>';
        return;
    }
    menu.innerHTML = matches.map(emp => `
        <div class="dtr-employee-option" data-id="${emp.employee_id}" data-name="${escapeHtml(emp.employee_name)}">
            <span class="dtr-employee-option-name">${escapeHtml(emp.employee_name)}</span>
            <span class="dtr-employee-option-type">Employee</span>
        </div>
    `).join('');
    menu.querySelectorAll('.dtr-employee-option').forEach(option => {
        option.addEventListener('mousedown', function(event){
            event.preventDefault();
            selectEmployeeForDtrRow(row, {
                employee_id: this.dataset.id,
                employee_name: this.dataset.name
            });
        });
    });
}
function positionDtrDropdown(row){
    const input = row.querySelector('.dtr-employee-name-input');
    const menu = row.querySelector('.dtr-employee-dropdown-menu');
    if (!input || !menu) return;
    const rect = input.getBoundingClientRect();
    menu.style.left = rect.left + 'px';
    menu.style.top = (rect.bottom - 1) + 'px';
    menu.style.width = Math.max(rect.width, 360) + 'px';
}
function openDtrEmployeeDropdown(row){
    if (row && row.dataset.lockedPending === '1') return;
    closeDtrEmployeeDropdowns(row);
    const input = row.querySelector('.dtr-employee-name-input');
    const menu = row.querySelector('.dtr-employee-dropdown-menu');
    if (!input || !menu) return;
    buildEmployeeDropdownOptions(row, input.value);
    positionDtrDropdown(row);
    menu.classList.add('show');
    activeDtrDropdown = row;
}
function closeDtrEmployeeDropdowns(exceptRow = null){
    document.querySelectorAll('.dtr-employee-dropdown-menu.show').forEach(menu => {
        if (!exceptRow || !exceptRow.contains(menu)) menu.classList.remove('show');
    });
    if (!exceptRow) activeDtrDropdown = null;
}
function selectEmployeeForDtrRow(row, employee){
    const input = row.querySelector('.dtr-employee-name-input');
    const hidden = row.querySelector('.dtr-employee-id-input');
    if (input) input.value = employee.employee_name || '';
    if (hidden) hidden.value = employee.employee_id || '';
    row.dataset.employeeId = employee.employee_id || '';
    updateDtrRowNames(row);
    closeDtrEmployeeDropdowns();
    refreshDtrRowState(row);
}
function getDtrRowKey(row){
    if (!row.dataset.rowKey) {
        dtrRowCounter += 1;
        row.dataset.rowKey = 'row_' + dtrRowCounter;
    }
    return row.dataset.rowKey;
}
function updateDtrRowNames(row){
    const rowKey = getDtrRowKey(row);
    const employeeId = row.dataset.employeeId || row.querySelector('.dtr-employee-id-input')?.value || '';
    const hidden = row.querySelector('.dtr-employee-id-input');
    const dateInput = row.querySelector('.dtr-row-date');

    if (hidden) {
        hidden.name = `employee_ids[${rowKey}]`;
        hidden.value = employeeId || '';
    }
    if (dateInput) dateInput.name = `attendance_dates[${rowKey}]`;
    row.querySelectorAll('.dtr-time-in').forEach(input => {
        input.name = `time_ins[${rowKey}][${input.dataset.slot}]`;
    });
    row.querySelectorAll('.dtr-time-out').forEach(input => {
        input.name = `time_outs[${rowKey}][${input.dataset.slot}]`;
    });
}
function createDtrBlankRow(){
    const tr = document.createElement('tr');
    tr.className = 'dtr-entry-row';
    tr.dataset.employeeId = '';
    tr.dataset.rowKey = 'row_' + (++dtrRowCounter);
    tr.innerHTML = `
        <td>
            <div class="dtr-employee-picker">
                <input type="text" class="dtr-employee-name-input" autocomplete="off">
                <input type="hidden" class="dtr-employee-id-input" value="">
                <div class="dtr-employee-dropdown-menu"></div>
            </div>
        </td>
        <td><input type="date" class="dtr-row-date" max="<?= htmlspecialchars($today, ENT_QUOTES) ?>"></td>
        <td><input type="time" class="dtr-time-in" data-slot="1"></td>
        <td><input type="time" class="dtr-time-out" data-slot="1"></td>
        <td><input type="time" class="dtr-time-in" data-slot="2"></td>
        <td><input type="time" class="dtr-time-out" data-slot="2"></td>
        <td><input type="time" class="dtr-time-in" data-slot="3"></td>
        <td><input type="time" class="dtr-time-out" data-slot="3"></td>
        <td><span class="dtr-qb-status ready dtr-row-status">Ready</span></td>
    `;
    const nameInput = tr.querySelector('.dtr-employee-name-input');
    nameInput.addEventListener('focus', () => openDtrEmployeeDropdown(tr));
    nameInput.addEventListener('click', () => openDtrEmployeeDropdown(tr));
    nameInput.addEventListener('input', () => {
        const exact = findEmployeeByName(nameInput.value);
        const hidden = tr.querySelector('.dtr-employee-id-input');
        if (exact) {
            hidden.value = exact.employee_id;
            tr.dataset.employeeId = exact.employee_id;
        } else {
            hidden.value = '';
            tr.dataset.employeeId = '';
        }
        updateDtrRowNames(tr);
        buildEmployeeDropdownOptions(tr, nameInput.value);
        positionDtrDropdown(tr);
        tr.querySelector('.dtr-employee-dropdown-menu')?.classList.add('show');
        refreshDtrRowState(tr);
    });
    tr.querySelectorAll('.dtr-row-date, .dtr-time-in, .dtr-time-out').forEach(input => {
        input.addEventListener('input', () => refreshDtrRowState(tr));
        input.addEventListener('change', () => refreshDtrRowState(tr));
    });
    updateDtrRowNames(tr);
    return tr;
}
function addDtrBlankRow(){
    const body = document.getElementById('dtrRowsBody');
    if (!body) return;
    const newRow = createDtrBlankRow();
    newRow.dataset.newlyAdded = '1';
    body.insertBefore(newRow, body.firstChild);
    const firstInput = newRow.querySelector('.dtr-employee-name-input');
    if (firstInput) firstInput.focus();
}
function ensureDtrMinimumRows(){
    const body = document.getElementById('dtrRowsBody');
    if (!body) return;
    while (body.querySelectorAll('.dtr-entry-row').length < 10) {
        body.appendChild(createDtrBlankRow());
    }
}
function applyDtrPendingLock(row){
    if (!row || row.dataset.lockedPending !== '1') return;
    const lockInput = (input, titleText) => {
        if (!input) return;
        input.readOnly = true;
        input.classList.add('dtr-locked-field');
        input.style.pointerEvents = 'none';
        input.title = titleText || 'Locked pending DTR field';
    };
    lockInput(row.querySelector('.dtr-employee-name-input'), 'Locked: pending employee cannot be changed');
    lockInput(row.querySelector('.dtr-row-date'), 'Locked: pending DTR date cannot be changed');
    row.querySelectorAll('.dtr-time-in').forEach(input => {
        if (input.value) lockInput(input, 'Locked: existing Time In cannot be changed');
    });
    row.querySelectorAll('.dtr-time-out').forEach(input => {
        if (input.value) lockInput(input, 'Locked: existing Time Out cannot be changed');
    });
}
function validateDtrDateInput(dateInput){
    if (!dateInput || !dateInput.value) return true;
    if (dateInput.value > APP_TODAY) {
        dateInput.value = APP_TODAY;
        Swal.fire('Invalid Date', 'Future dates are not allowed for attendance. Please select today or a past date only.', 'warning');
        return false;
    }
    return true;
}
function refreshDtrRowState(row){
    if (!row) return;
    const nameInput = row.querySelector('.dtr-employee-name-input');
    const hidden = row.querySelector('.dtr-employee-id-input');
    const dateInput = row.querySelector('.dtr-row-date');
    const statusBadge = row.querySelector('.dtr-row-status');
    const timeInputs = row.querySelectorAll('.dtr-time-in, .dtr-time-out');
    const employee = hidden && hidden.value ? employeeData.find(emp => String(emp.employee_id) === String(hidden.value)) : findEmployeeByName(nameInput ? nameInput.value : '');
    if (employee) {
        hidden.value = employee.employee_id;
        row.dataset.employeeId = employee.employee_id;
    }
    updateDtrRowNames(row);
    const hasTimeValue = Array.from(timeInputs).some(input => input.value);
    const hasAnyValue = (nameInput && nameInput.value.trim()) || (dateInput && dateInput.value) || hasTimeValue;
    if (dateInput) dateInput.max = APP_TODAY;
    if (dateInput && dateInput.value && dateInput.value > APP_TODAY) {
        validateDtrDateInput(dateInput);
    }
    if (row.dataset.lockedPending !== '1') {
        if (nameInput) { nameInput.readOnly = false; nameInput.style.pointerEvents = ''; nameInput.title = ''; nameInput.classList.remove('dtr-locked-field'); }
        if (dateInput) { dateInput.readOnly = false; dateInput.style.pointerEvents = ''; dateInput.title = ''; dateInput.classList.remove('dtr-locked-field'); }
        timeInputs.forEach(input => { input.disabled = false; input.readOnly = false; input.style.pointerEvents = ''; input.title = ''; input.classList.remove('dtr-locked-field'); });
    } else {
        applyDtrPendingLock(row);
    }

    if (!hasAnyValue) {
        if (statusBadge) {
            statusBadge.className = 'dtr-qb-status ready dtr-row-status';
            statusBadge.textContent = 'Ready';
        }
        return;
    }
    if (!employee) {
        if (statusBadge) {
            statusBadge.className = 'dtr-qb-status error dtr-row-status';
            statusBadge.textContent = 'Select Employee';
        }
        return;
    }
    if (!dateInput || !dateInput.value) {
        if (statusBadge) {
            statusBadge.className = 'dtr-qb-status error dtr-row-status';
            statusBadge.textContent = 'Set Date';
        }
        return;
    }

    const record = getDtrRecord(employee.employee_id, dateInput.value);
    if (record && Array.isArray(record.slots)) {
        record.slots.slice(0, 3).forEach((slot, index) => {
            const slotNo = index + 1;
            const timeIn = row.querySelector(`.dtr-time-in[data-slot="${slotNo}"]`);
            const timeOut = row.querySelector(`.dtr-time-out[data-slot="${slotNo}"]`);
            if (timeIn && !timeIn.value) timeIn.value = formatTimeForInput(slot.start_time);
            if (timeOut && !timeOut.value) timeOut.value = formatTimeForInput(slot.end_time);
        });
    }
    if (row.dataset.lockedPending === '1') applyDtrPendingLock(row);

    const completePairs = [1,2,3].filter(slot => {
        const tin = row.querySelector(`.dtr-time-in[data-slot="${slot}"]`)?.value || '';
        const tout = row.querySelector(`.dtr-time-out[data-slot="${slot}"]`)?.value || '';
        return tin && tout;
    }).length;
    const pendingPairs = [1,2,3].filter(slot => {
        const tin = row.querySelector(`.dtr-time-in[data-slot="${slot}"]`)?.value || '';
        const tout = row.querySelector(`.dtr-time-out[data-slot="${slot}"]`)?.value || '';
        return tin && !tout;
    }).length;

    if (statusBadge) {
        if (pendingPairs > 0) {
            statusBadge.className = 'dtr-qb-status pending dtr-row-status';
            statusBadge.textContent = 'Pending';
        } else if (completePairs > 0) {
            statusBadge.className = 'dtr-qb-status recorded dtr-row-status';
            statusBadge.textContent = 'Ready Save';
        } else {
            statusBadge.className = 'dtr-qb-status ready dtr-row-status';
            statusBadge.textContent = 'Ready';
        }
    }
}
function refreshAllDtrRowStates(){
    document.querySelectorAll('.dtr-entry-row').forEach(refreshDtrRowState);
}
function clearDtrForm(){
    const singleDtrEmployee = document.getElementById('singleDtrEmployee');
    if (singleDtrEmployee) singleDtrEmployee.value = '';
    const title = document.querySelector('#dtrModal .modal-title');
    if (title) title.innerHTML = '<i class="bi bi-clock-history me-2"></i>Daily Time Record (DTR)';

    const body = document.getElementById('dtrRowsBody');
    if (body) {
        const rows = Array.from(body.querySelectorAll('.dtr-entry-row'));
        rows.forEach(row => {
            if (row.dataset.lockedPending === '1') {
                applyDtrPendingLock(row);
                refreshDtrRowState(row);
            } else {
                row.remove();
            }
        });
        ensureDtrMinimumRows();
    }

    const saveNewButton = document.querySelector('#dtrForm button[data-save-mode="new"]');
    const saveCloseButton = document.querySelector('#dtrForm button[data-save-mode="close"]');
    if (saveNewButton) saveNewButton.innerHTML = '<i class="bi bi-save me-1"></i>Save & New';
    if (saveCloseButton) saveCloseButton.innerHTML = '<i class="bi bi-save me-1"></i>Save & Close';
}
function resetDtrFormForOpen(){
    const form = document.getElementById('dtrForm');
    if (form) form.reset();
    const singleDtrEmployee = document.getElementById('singleDtrEmployee');
    if (singleDtrEmployee) singleDtrEmployee.value = '';
    const title = document.querySelector('#dtrModal .modal-title');
    if (title) title.innerHTML = '<i class="bi bi-clock-history me-2"></i>Daily Time Record (DTR)';
    const body = document.getElementById('dtrRowsBody');
    if (body) body.innerHTML = '';
    const saveNewButton = document.querySelector('#dtrForm button[data-save-mode="new"]');
    const saveCloseButton = document.querySelector('#dtrForm button[data-save-mode="close"]');
    if (saveNewButton) saveNewButton.innerHTML = '<i class="bi bi-save me-1"></i>Save & New';
    if (saveCloseButton) saveCloseButton.innerHTML = '<i class="bi bi-save me-1"></i>Save & Close';
}
function prefillPendingDtrRows(){
    const body = document.getElementById('dtrRowsBody');
    if (!body) return;

    const todayValue = APP_TODAY;
    const pendingRows = Array.isArray(pendingDtrData) ? pendingDtrData.filter(pending => {
        if (!pending || !pending.employee_id) return false;
        const slots = Array.isArray(pending.slots) ? pending.slots : [];
        const hasOpenSlot = slots.some(slot => {
            return slot && (slot.is_open || !formatTimeForInput(slot.end_time));
        });
        const hasIncompleteTime = Boolean(pending.is_incomplete) || hasOpenSlot || slots.length < 3;
        const hasUnapprovedOt = Boolean(pending.has_unapproved_ot) || (Number(pending.ot_requested_minutes || 0) > 0 && String(pending.ot_approval_status || 'none') === 'pending');
        return hasIncompleteTime || hasUnapprovedOt;
    }) : [];

    if (pendingRows.length === 0) {
        ensureDtrMinimumRows();
        return;
    }

    body.innerHTML = '';

    pendingRows.forEach((pending) => {
        const row = createDtrBlankRow();
        row.dataset.lockedPending = '1';
        body.appendChild(row);

        const employee = employeeData.find(emp => String(emp.employee_id) === String(pending.employee_id));
        if (!employee) return;

        selectEmployeeForDtrRow(row, employee);
        const dateInput = row.querySelector('.dtr-row-date');
        if (dateInput) dateInput.value = pending.attendance_date || todayValue;

        for (let slotNo = 1; slotNo <= 3; slotNo++) {
            const slot = (pending.slots || [])[slotNo - 1] || {};
            const inInput = row.querySelector(`.dtr-time-in[data-slot="${slotNo}"]`);
            const outInput = row.querySelector(`.dtr-time-out[data-slot="${slotNo}"]`);
            if (inInput) inInput.value = formatTimeForInput(slot.start_time);
            if (outInput) outInput.value = formatTimeForInput(slot.end_time);
        }

        applyDtrPendingLock(row);
        refreshDtrRowState(row);
    });

    ensureDtrMinimumRows();
}
function showDtrModal(){
    resetDtrFormForOpen();
    prefillPendingDtrRows();
    dtrModal().show();
}
function prepareDtrRowsForSubmit(){
    let hasFutureDate = false;
    document.querySelectorAll('.dtr-entry-row').forEach(row => {
        const dateInputCheck = row.querySelector('.dtr-row-date');
        if (dateInputCheck && dateInputCheck.value && dateInputCheck.value > APP_TODAY) {
            hasFutureDate = true;
        }
        const nameInput = row.querySelector('.dtr-employee-name-input');
        const hidden = row.querySelector('.dtr-employee-id-input');
        if ((!hidden || !hidden.value) && nameInput && nameInput.value.trim()) {
            const employee = findEmployeeByName(nameInput.value);
            if (employee) {
                hidden.value = employee.employee_id;
                row.dataset.employeeId = employee.employee_id;
            }
        }
        updateDtrRowNames(row);
        if (row.dataset.lockedPending === '1') applyDtrPendingLock(row);
    });
    if (hasFutureDate) {
        throw new Error('Future dates are not allowed for attendance.');
    }
}
function toggleAllDtrEmployees(){ }
function updateDtrSelectionCount(){ }
function clearDtrSelection(){ }
function getCurrentTimeValue(){
    const now = new Date();
    return String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
}
function setEmployeeOut(employeeId, employeeName, attendanceDate, startTime){
    clearDtrForm();
    const firstRow = document.querySelector('#dtrRowsBody .dtr-entry-row');
    const employee = employeeData.find(emp => String(emp.employee_id) === String(employeeId));
    if (firstRow && employee) {
        selectEmployeeForDtrRow(firstRow, employee);
        const dateInput = firstRow.querySelector('.dtr-row-date');
        const startInput = firstRow.querySelector('.dtr-time-in[data-slot="1"]');
        const endInput = firstRow.querySelector('.dtr-time-out[data-slot="1"]');
        if (dateInput) dateInput.value = attendanceDate || new Date().toISOString().slice(0,10);
        if (startInput) startInput.value = formatTimeForInput(startTime);
        firstRow.dataset.lockedPending = '1';
        if (endInput) endInput.value = getCurrentTimeValue();
        applyDtrPendingLock(firstRow);
        refreshDtrRowState(firstRow);
    }
    document.querySelector('#dtrModal .modal-title').innerHTML = '<i class="bi bi-box-arrow-right me-2"></i>Set OUT - ' + employeeName;
    const saveNewButton = document.querySelector('#dtrForm button[data-save-mode="new"]');
    const saveCloseButton = document.querySelector('#dtrForm button[data-save-mode="close"]');
    if (saveNewButton) saveNewButton.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save OUT & New';
    if (saveCloseButton) saveCloseButton.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save OUT & Close';
    dtrModal().show();
}
document.addEventListener('click', function(event){
    if (!event.target.closest('.dtr-employee-picker')) closeDtrEmployeeDropdowns();
});
window.addEventListener('scroll', function(){ if (activeDtrDropdown) positionDtrDropdown(activeDtrDropdown); }, true);
window.addEventListener('resize', function(){ if (activeDtrDropdown) positionDtrDropdown(activeDtrDropdown); });

function approveOtRequest(employeeId, attendanceDate, decision){
    const approve = decision === 'approve';
    Swal.fire({
        title: approve ? 'Approve OT?' : 'Reject OT?',
        html: `
            <div class="text-start">
                <label class="form-label fw-bold">Remarks <span class="text-danger">*</span></label>
                <textarea id="otApprovalRemarksInput" class="ot-approval-remarks-box" required placeholder="Enter remarks for this OT ${approve ? 'approval' : 'rejection'}..."></textarea>
                <label class="form-label fw-bold mt-3">Attachments <span class="text-danger">*</span></label>
                <input type="file" id="otApprovalAttachmentsInput" class="form-control ot-approval-file-input" required multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx">
                <small class="text-muted d-block mt-2">Required. Allowed files: images, PDF, Word, and Excel. Maximum 10MB each.</small>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: approve ? 'Approve OT' : 'Reject OT',
        cancelButtonText: 'Cancel',
        confirmButtonColor: approve ? '#047857' : '#dc3545',
        width: 560
    }).then(result => {
        if (!result.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'approve_ot');
        fd.append('employee_id', employeeId);
        fd.append('attendance_date', attendanceDate);
        fd.append('decision', approve ? 'approve' : 'reject');
        const remarksInput = document.getElementById('otApprovalRemarksInput');
        const filesInput = document.getElementById('otApprovalAttachmentsInput');
        const remarksValue = remarksInput ? remarksInput.value.trim() : '';
        if (remarksValue === '') {
            Swal.fire('Required', 'Remarks is required before submitting OT approval.', 'warning');
            return;
        }
        if (!filesInput || !filesInput.files || filesInput.files.length === 0) {
            Swal.fire('Required', 'Attachment is required before submitting OT approval.', 'warning');
            return;
        }
        fd.append('ot_remarks', remarksValue);
        Array.from(filesInput.files).forEach(file => fd.append('ot_attachments[]', file));
        Swal.fire({title:'Updating OT...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
        fetch(window.location.pathname.split('/').pop() || 'employee.php', {method:'POST', body:fd})
            .then(async r=>{
                const text = await r.text();
                try { return JSON.parse(text); }
                catch (e) { throw new Error(text || 'Invalid server response'); }
            })
            .then(data=>{
                if(!data.success){
                    Swal.fire('Error', data.message || 'Unable to update OT approval.', 'error');
                    return;
                }
                Swal.fire({icon:'success',title:'Updated',text:data.message,timer:1400,showConfirmButton:false}).then(()=>location.reload());
            })
            .catch(err=>Swal.fire('Error', err.message || 'Unable to process request','error'));
    });
}

function submitDtrForPayroll(){
    const form = document.getElementById('dtrForm');
    if (!form) return;
    try {
        prepareDtrRowsForSubmit();
    } catch (err) {
        Swal.fire('Invalid Date', err.message || 'Future dates are not allowed for attendance.', 'warning');
        return;
    }

    const rows = Array.from(document.querySelectorAll('#dtrRowsBody .dtr-entry-row')).filter(row => {
        const employeeId = row.querySelector('.dtr-employee-id-input')?.value || '';
        const dateValue = row.querySelector('.dtr-row-date')?.value || '';
        const completePairs = [1,2,3].filter(slot => {
            const tin = row.querySelector(`.dtr-time-in[data-slot="${slot}"]`)?.value || '';
            const tout = row.querySelector(`.dtr-time-out[data-slot="${slot}"]`)?.value || '';
            return tin && tout;
        }).length;
        const pendingPairs = [1,2,3].filter(slot => {
            const tin = row.querySelector(`.dtr-time-in[data-slot="${slot}"]`)?.value || '';
            const tout = row.querySelector(`.dtr-time-out[data-slot="${slot}"]`)?.value || '';
            return tin && !tout;
        }).length;
        return employeeId && dateValue && completePairs > 0 && pendingPairs === 0;
    });

    if (rows.length === 0) {
        Swal.fire('No Completed DTR', 'Save a completed DTR row first, then submit it for payroll.', 'warning');
        return;
    }

    Swal.fire({
        title: 'Submit DTR for payroll?',
        text: 'Completed rows will be added to Payroll Submit History while Attendance History, Calendar, and OT Approval records remain visible.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Submit for Payroll',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#047857'
    }).then(result => {
        if (!result.isConfirmed) return;
        const fd = new FormData(form);
        fd.set('action', 'submit_for_payroll');
        Swal.fire({title:'Submitting...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
        fetch(window.location.pathname.split('/').pop() || 'employee.php', {method:'POST', body:fd})
            .then(async r=>{
                const text = await r.text();
                try { return JSON.parse(text); }
                catch (e) { throw new Error(text || 'Invalid server response'); }
            })
            .then(data=>{
                if(!data.success){
                    Swal.fire('Error', data.message || 'Unable to submit DTR for payroll.', 'error');
                    return;
                }
                Swal.fire({icon:'success',title:'Submitted',text:data.message,timer:1400,showConfirmButton:false}).then(()=>{
                    bootstrap.Modal.getInstance(document.getElementById('dtrModal'))?.hide();
                    location.reload();
                });
            })
            .catch(err=>Swal.fire('Error', err.message || 'Unable to process request','error'));
    });
}

function submitForm(e){
    e.preventDefault();
    const form = e.target;
    const saveMode = e.submitter?.dataset?.saveMode || 'close';
    const isEmployeeForm = form.id === 'employeeForm';
    const isDtrForm = form.id === 'dtrForm';
    if (isDtrForm) {
        try {
            prepareDtrRowsForSubmit();
        } catch (err) {
            Swal.fire('Invalid Date', err.message || 'Future dates are not allowed for attendance.', 'warning');
            return;
        }
    }
    Swal.fire({title:'Saving...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    fetch(window.location.pathname.split('/').pop() || 'employee.php', {method:'POST', body:new FormData(form)})
        .then(r=>r.json())
        .then(data=>{
            if(!data.success){
                Swal.fire('Error', data.message || 'Something went wrong', 'error');
                return;
            }
            Swal.fire({icon:'success',title:'Success',text:data.message,timer:1200,showConfirmButton:false}).then(()=>{
                if (saveMode === 'new') {
                    if (isEmployeeForm) clearEmployeeForm();
                    if (isDtrForm) clearDtrForm();
                    return;
                }
                if (isEmployeeForm) {
                    bootstrap.Modal.getInstance(document.getElementById('employeeModal'))?.hide();
                }
                if (isDtrForm) {
                    bootstrap.Modal.getInstance(document.getElementById('dtrModal'))?.hide();
                }
                location.reload();
            });
        }).catch(err=>Swal.fire('Error', err.message || 'Unable to process request','error'));
}
function deleteEmployee(id){
    Swal.fire({title:'Delete employee?',text:'This will also delete DTR records of this employee.',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes, delete'}).then(res=>{
        if(!res.isConfirmed) return;
        const fd = new FormData(); fd.append('action','delete_employee'); fd.append('employee_id',id);
        Swal.fire({title:'Deleting...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
        fetch(window.location.pathname.split('/').pop() || 'employee.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
            if(data.success){ Swal.fire({icon:'success',title:'Deleted',text:data.message,timer:1200,showConfirmButton:false}).then(()=>location.reload()); }
            else Swal.fire('Error',data.message || 'Delete failed','error');
        }).catch(err=>Swal.fire('Error', err.message || 'Unable to process request','error'));
    });
}
function filterTables(){
    const q = (document.getElementById('searchInput').value || '').toLowerCase();
    document.querySelectorAll('.employee-profile-row,.dtr-row').forEach(row=>{ row.style.display = (row.dataset.search || row.innerText.toLowerCase()).includes(q) ? '' : 'none'; });
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


function logout() { confirmLogout(); }

 // ========== MOBILE BOTTOM NAVBAR FUNCTIONS ==========
function closeAllMobileDropdowns() {
    document.querySelectorAll('.mobile-nav .more-dropdown.show').forEach(function (dropdown) {
        dropdown.classList.remove('show');
    });
    document.querySelectorAll('.mobile-nav .more-btn.active').forEach(function (btn) {
        const menuId = (btn.getAttribute('onclick') || '').match(/'([^']+)'/);
        const menu = menuId ? document.getElementById(menuId[1]) : null;
        const hasActiveChild = menu && menu.querySelector('.dropdown-item.active');
        if (!hasActiveChild) btn.classList.remove('active');
    });
}

function toggleMobileDropdown(event, dropdownId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const dropdown = document.getElementById(dropdownId);
    const btn = event ? event.currentTarget : document.querySelector(`[onclick*="${dropdownId}"]`);
    if (!dropdown || !btn) return false;

    if (dropdown.classList.contains('show')) {
        dropdown.classList.remove('show');
        if (!dropdown.querySelector('.dropdown-item.active')) btn.classList.remove('active');
    } else {
        closeAllMobileDropdowns();
        dropdown.classList.add('show');
        btn.classList.add('active');
    }
    return false;
}

function setActiveMobileNav() {
    const currentPage = window.location.pathname.split('/').pop() || 'request_handler.php';
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
        link.classList.remove('active');
    });
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
        const href = (link.getAttribute('href') || '').split('?')[0];
        if (href === currentPage) {
            link.classList.add('active');
            const parentDropdown = link.closest('.more-dropdown');
            if (parentDropdown) {
                const parentBtn = document.querySelector(`[onclick*="${parentDropdown.id}"]`);
                if (parentBtn) parentBtn.classList.add('active');
            }
        }
    });
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.mobile-nav .dropdown-more')) closeAllMobileDropdowns();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllMobileDropdowns();
});
</script>

<script>
(function () {
    function syncMobileModalSafeSpace() {
        var nav = document.querySelector('.mobile-nav');
        var navHeight = 82;
        if (nav) {
            var styles = window.getComputedStyle(nav);
            if (styles.display !== 'none' && styles.visibility !== 'hidden') {
                navHeight = Math.ceil(nav.getBoundingClientRect().height || 82);
            }
        }
        document.documentElement.style.setProperty('--amgc-mobile-nav-height', navHeight + 'px');
    }

    function isExcludedProfileModal(target) {
        return target && target.id === 'profileModal';
    }

    document.addEventListener('show.bs.modal', function (event) {
        if (isExcludedProfileModal(event.target)) return;
        syncMobileModalSafeSpace();
        document.body.classList.add('amgc-non-profile-modal-open');
    }, true);

    document.addEventListener('shown.bs.modal', function (event) {
        if (isExcludedProfileModal(event.target)) return;
        syncMobileModalSafeSpace();
    }, true);

    document.addEventListener('hidden.bs.modal', function () {
        if (!document.querySelector('.modal.show:not(#profileModal)')) {
            document.body.classList.remove('amgc-non-profile-modal-open');
        }
    }, true);

    window.addEventListener('resize', syncMobileModalSafeSpace);
    window.addEventListener('orientationchange', syncMobileModalSafeSpace);
    document.addEventListener('DOMContentLoaded', syncMobileModalSafeSpace);
})();
</script>


<script>
(function () {
    function setAmgcRealVhForMobileModals() {
        var height = (window.visualViewport && window.visualViewport.height) ? window.visualViewport.height : window.innerHeight;
        document.documentElement.style.setProperty('--amgc-real-vh', height + 'px');
    }
    setAmgcRealVhForMobileModals();
    window.addEventListener('resize', setAmgcRealVhForMobileModals, { passive: true });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', setAmgcRealVhForMobileModals, { passive: true });
        window.visualViewport.addEventListener('scroll', setAmgcRealVhForMobileModals, { passive: true });
    }
    document.addEventListener('shown.bs.modal', function (event) {
        if (event.target && event.target.id !== 'profileModal') {
            setAmgcRealVhForMobileModals();
            document.body.classList.add('amgc-modal-open-mobile');
        }
    });
    document.addEventListener('hidden.bs.modal', function () {
        if (!document.querySelector('.modal.show:not(#profileModal)')) {
            document.body.classList.remove('amgc-modal-open-mobile');
        }
    });
})();
</script>

</body>
</html>
