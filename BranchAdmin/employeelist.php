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

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1));
}
if ($user_initials === '') $user_initials = 'BA';

$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0) {
    $stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) $branch_name = $row['branch_name'];
        $stmt->close();
    }
}

$task_badge_count = 0;

if (isset($conn) && !empty($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];

    $taskBadgeStmt = $conn->prepare("
        SELECT COUNT(DISTINCT t.task_id) AS total
        FROM user_tasks t
        INNER JOIN user_task_assignees a
            ON a.task_id = t.task_id
        WHERE a.user_id = ?
          AND a.assignee_status NOT IN ('completed', 'cancelled')
          AND NOW() >= DATE_SUB(
              t.due_datetime,
              INTERVAL COALESCE(t.reminder_days, 0) DAY
          )
    ");

    if ($taskBadgeStmt) {
        $taskBadgeStmt->bind_param('i', $uid);
        $taskBadgeStmt->execute();

        $taskBadgeResult = $taskBadgeStmt->get_result();
        $taskBadgeRow = $taskBadgeResult->fetch_assoc();

        $task_badge_count = (int) ($taskBadgeRow['total'] ?? 0);

        $taskBadgeStmt->close();
    }
}

function employeeColumnExists(mysqli $conn, string $table, string $column): bool {
    // SHOW COLUMNS does not work reliably with prepared placeholders on some MariaDB versions.
    // Keep table/column names safe, then escape the LIKE value manually.
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    if ($safeTable === '' || $safeColumn === '') return false;

    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return ($res && $res->num_rows > 0);
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

    $employeeColumns = [
        'first_name' => "VARCHAR(100) NULL AFTER branch_id",
        'middle_name' => "VARCHAR(100) NULL AFTER first_name",
        'last_name' => "VARCHAR(100) NULL AFTER middle_name",
        'phone_number' => "VARCHAR(50) NULL AFTER contact_number",
        'mobile_number' => "VARCHAR(50) NULL AFTER phone_number",
        'employee_id_number' => "VARCHAR(80) NULL AFTER mobile_number",
        'biometrics_id_number' => "VARCHAR(80) NULL AFTER employee_id_number",
        'business_unit' => "VARCHAR(150) NULL AFTER pagibig",
        'department' => "VARCHAR(150) NULL AFTER business_unit",
        'position' => "VARCHAR(150) NULL AFTER department",
        'job_description' => "TEXT NULL AFTER position",
        'employment_classification' => "VARCHAR(100) NULL AFTER job_description",
        'start_date' => "DATE NULL AFTER employment_classification",
        'basic_pay' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER start_date",
        'pay_classification' => "VARCHAR(100) NULL AFTER basic_pay",
        'payment_method' => "VARCHAR(100) NULL AFTER pay_classification",
        'account_number' => "VARCHAR(100) NULL AFTER payment_method",
        'start_of_work' => "TIME NULL AFTER account_number",
        'end_of_work' => "TIME NULL AFTER start_of_work",
        'total_work_hours' => "DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER end_of_work",
        'rest_day' => "VARCHAR(150) NULL AFTER total_work_hours",
        'total_rest_days' => "INT NOT NULL DEFAULT 0 AFTER rest_day",
        'total_workdays_per_month' => "DECIMAL(8,2) NOT NULL DEFAULT 26 AFTER total_rest_days",
        'with_sss' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER total_workdays_per_month",
        'with_philhealth' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER with_sss",
        'with_pagibig' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER with_philhealth",
        'remits_withholding_tax' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER with_pagibig"
    ];
    foreach ($employeeColumns as $column => $definition) employeeAddColumnIfMissing($conn, 'employees', $column, $definition);

    $conn->query("CREATE TABLE IF NOT EXISTS employee_government_registrations (
        registration_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        registration_name VARCHAR(120) NOT NULL,
        registration_number VARCHAR(120) NULL,
        attachment_name VARCHAR(255) NULL,
        attachment_path VARCHAR(500) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_emp_gov_employee (employee_id),
        CONSTRAINT fk_emp_gov_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS employee_allowances (
        allowance_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        allowance_name VARCHAR(150) NOT NULL,
        allowance_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_emp_allowance_employee (employee_id),
        CONSTRAINT fk_emp_allowance_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS employee_job_history (
        job_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        business_unit VARCHAR(150) NULL,
        branch_id INT NULL,
        branch_name_snapshot VARCHAR(150) NULL,
        department VARCHAR(150) NULL,
        position VARCHAR(150) NOT NULL,
        job_description TEXT NULL,
        employment_classification VARCHAR(100) NULL,
        basic_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
        pay_classification VARCHAR(100) NULL,
        payment_method VARCHAR(100) NULL,
        account_number VARCHAR(100) NULL,
        start_of_work TIME NULL,
        end_of_work TIME NULL,
        total_work_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
        rest_day VARCHAR(150) NULL,
        total_rest_days INT NOT NULL DEFAULT 0,
        total_workdays_per_month DECIMAL(8,2) NOT NULL DEFAULT 26,
        is_present TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_emp_job_employee (employee_id),
        INDEX idx_emp_job_present (employee_id, is_present),
        INDEX idx_emp_job_dates (employee_id, start_date, end_date),
        CONSTRAINT fk_emp_job_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $jobHistoryColumns = [
        'branch_name_snapshot' => "VARCHAR(150) NULL AFTER branch_id",
        'basic_pay' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER employment_classification",
        'pay_classification' => "VARCHAR(100) NULL AFTER basic_pay",
        'payment_method' => "VARCHAR(100) NULL AFTER pay_classification",
        'account_number' => "VARCHAR(100) NULL AFTER payment_method",
        'start_of_work' => "TIME NULL AFTER account_number",
        'end_of_work' => "TIME NULL AFTER start_of_work",
        'total_work_hours' => "DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER end_of_work",
        'rest_day' => "VARCHAR(150) NULL AFTER total_work_hours",
        'total_rest_days' => "INT NOT NULL DEFAULT 0 AFTER rest_day",
        'total_workdays_per_month' => "DECIMAL(8,2) NOT NULL DEFAULT 26 AFTER total_rest_days"
    ];
    foreach ($jobHistoryColumns as $column => $definition) employeeAddColumnIfMissing($conn, 'employee_job_history', $column, $definition);


    $conn->query("CREATE TABLE IF NOT EXISTS employee_attachments (
        attachment_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        attachment_type VARCHAR(80) NOT NULL DEFAULT 'General',
        original_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_emp_attachment_employee (employee_id),
        CONSTRAINT fk_emp_attachment_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS employee_13th_month_provisions (
        provision_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        basic_pay_monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
        monthly_provision DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_workdays DECIMAL(8,2) NOT NULL DEFAULT 0,
        daily_provision DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_workhours DECIMAL(8,2) NOT NULL DEFAULT 0,
        hourly_provision DECIMAL(12,2) NOT NULL DEFAULT 0,
        regular_hours_worked DECIMAL(8,2) NOT NULL DEFAULT 0,
        provision_for_day DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_emp_provision (employee_id),
        CONSTRAINT fk_emp_13th_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
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

    $endColumn = $conn->query("SHOW COLUMNS FROM employee_dtr LIKE 'end_time'");
    if ($endColumn && ($col = $endColumn->fetch_assoc())) {
        if (stripos($col['Null'] ?? '', 'NO') !== false) @$conn->query("ALTER TABLE employee_dtr MODIFY end_time TIME NULL");
    }
}
ensureEmployeeTables($conn);

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
    $minutes = max(0, (int)$minutes);
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
}
function employeeUploadDir(): string {
    $dir = __DIR__ . '/../uploads/employee_attachments';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
function employeeUploadPublicPath(string $filename): string {
    return '../uploads/employee_attachments/' . $filename;
}
function saveEmployeeUploadedFile(mysqli $conn, int $employee_id, array $file, string $type): void {
    if ($employee_id <= 0 || empty($file['name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return;
    $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== '' && !in_array($ext, $allowed, true)) return;
    $safeName = preg_replace('/[^A-Za-z0-9_\.-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $stored = 'emp_' . $employee_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
    $target = employeeUploadDir() . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $target)) return;
    $publicPath = employeeUploadPublicPath($stored);
    $original = $safeName . ($ext ? '.' . $ext : '');
    $stmt = $conn->prepare("INSERT INTO employee_attachments (employee_id, attachment_type, original_name, file_path, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param('isss', $employee_id, $type, $original, $publicPath);
        $stmt->execute();
        $stmt->close();
    }
}
function saveEmployeeProvision(mysqli $conn, int $employee_id, float $basic_pay, float $total_workdays, float $total_workhours, float $regular_hours_worked, string $pay_classification = 'Monthly'): void {
    if ($employee_id <= 0) return;
    $total_workdays = $total_workdays > 0 ? $total_workdays : 26.0;
    $total_workhours = $total_workhours > 0 ? $total_workhours : 8.0;
    $regular_hours_worked = $regular_hours_worked > 0 ? $regular_hours_worked : $total_workhours;

    // FIX: Basic Pay can be Daily/Hourly depending on Pay Classification.
    // The 13th Month Provision must always use the equivalent monthly basic pay.
    $classification = strtolower(trim($pay_classification));
    if ($classification === 'daily') {
        $basic_pay_monthly = $basic_pay * $total_workdays;
    } elseif ($classification === 'hourly') {
        $basic_pay_monthly = $basic_pay * $total_workhours * $total_workdays;
    } else {
        $basic_pay_monthly = $basic_pay;
    }

    $monthly = $basic_pay_monthly / 12;
    $daily = $total_workdays > 0 ? $monthly / $total_workdays : 0;
    $hourly = $total_workhours > 0 ? $daily / $total_workhours : 0;
    $dayProvision = $hourly * $regular_hours_worked;
    $stmt = $conn->prepare("INSERT INTO employee_13th_month_provisions
        (employee_id, basic_pay_monthly, monthly_provision, total_workdays, daily_provision, total_workhours, hourly_provision, regular_hours_worked, provision_for_day, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE basic_pay_monthly=VALUES(basic_pay_monthly), monthly_provision=VALUES(monthly_provision), total_workdays=VALUES(total_workdays), daily_provision=VALUES(daily_provision), total_workhours=VALUES(total_workhours), hourly_provision=VALUES(hourly_provision), regular_hours_worked=VALUES(regular_hours_worked), provision_for_day=VALUES(provision_for_day), updated_at=NOW()");
    if ($stmt) {
        $stmt->bind_param('idddddddd', $employee_id, $basic_pay_monthly, $monthly, $total_workdays, $daily, $total_workhours, $hourly, $regular_hours_worked, $dayProvision);
        $stmt->execute();
        $stmt->close();
    }
}
function saveEmployeeJobs(mysqli $conn, int $employee_id, int $default_branch_id, bool $view_all_branches): array {
    $fallback = [
        'start_date' => trim((string)($_POST['start_date'] ?? '')),
        'end_date' => '',
        'business_unit' => trim((string)($_POST['business_unit'] ?? '')),
        'branch_id' => (int)($_POST['branch_id'] ?? $default_branch_id),
        'department' => trim((string)($_POST['department'] ?? '')),
        'position' => trim((string)($_POST['position'] ?? '')),
        'job_description' => trim((string)($_POST['job_description'] ?? '')),
        'employment_classification' => trim((string)($_POST['employment_classification'] ?? '')),
        'basic_pay' => (float)str_replace(',', '', (string)($_POST['basic_pay'] ?? 0)),
        'pay_classification' => trim((string)($_POST['pay_classification'] ?? '')),
        'payment_method' => trim((string)($_POST['payment_method'] ?? '')),
        'account_number' => trim((string)($_POST['account_number'] ?? '')),
        'start_of_work' => trim((string)($_POST['start_of_work'] ?? '')),
        'end_of_work' => trim((string)($_POST['end_of_work'] ?? '')),
        'total_work_hours' => (float)str_replace(',', '', (string)($_POST['total_work_hours'] ?? 0)),
        'rest_day' => trim((string)($_POST['rest_day'] ?? '')),
        'total_rest_days' => (int)($_POST['total_rest_days'] ?? 0),
        'total_workdays_per_month' => (float)str_replace(',', '', (string)($_POST['total_workdays_per_month'] ?? 26)),
        'is_present' => 1
    ];

    $starts = (isset($_POST['job_start_date']) && is_array($_POST['job_start_date'])) ? $_POST['job_start_date'] : [];
    $ends = (isset($_POST['job_end_date']) && is_array($_POST['job_end_date'])) ? $_POST['job_end_date'] : [];
    $businessUnits = (isset($_POST['job_business_unit']) && is_array($_POST['job_business_unit'])) ? $_POST['job_business_unit'] : [];
    $branches = (isset($_POST['job_branch_id']) && is_array($_POST['job_branch_id'])) ? $_POST['job_branch_id'] : [];
    $branchNames = (isset($_POST['job_branch_name']) && is_array($_POST['job_branch_name'])) ? $_POST['job_branch_name'] : [];
    $departments = (isset($_POST['job_department']) && is_array($_POST['job_department'])) ? $_POST['job_department'] : [];
    $positions = (isset($_POST['job_position']) && is_array($_POST['job_position'])) ? $_POST['job_position'] : [];
    $descriptions = (isset($_POST['job_description_history']) && is_array($_POST['job_description_history'])) ? $_POST['job_description_history'] : [];
    $classifications = (isset($_POST['job_employment_classification']) && is_array($_POST['job_employment_classification'])) ? $_POST['job_employment_classification'] : [];
    $basicPays = (isset($_POST['job_basic_pay']) && is_array($_POST['job_basic_pay'])) ? $_POST['job_basic_pay'] : [];
    $payClassifications = (isset($_POST['job_pay_classification']) && is_array($_POST['job_pay_classification'])) ? $_POST['job_pay_classification'] : [];
    $paymentMethods = (isset($_POST['job_payment_method']) && is_array($_POST['job_payment_method'])) ? $_POST['job_payment_method'] : [];
    $accountNumbers = (isset($_POST['job_account_number']) && is_array($_POST['job_account_number'])) ? $_POST['job_account_number'] : [];
    $startOfWorks = (isset($_POST['job_start_of_work']) && is_array($_POST['job_start_of_work'])) ? $_POST['job_start_of_work'] : [];
    $endOfWorks = (isset($_POST['job_end_of_work']) && is_array($_POST['job_end_of_work'])) ? $_POST['job_end_of_work'] : [];
    $totalWorkHoursList = (isset($_POST['job_total_work_hours']) && is_array($_POST['job_total_work_hours'])) ? $_POST['job_total_work_hours'] : [];
    $restDays = (isset($_POST['job_rest_day']) && is_array($_POST['job_rest_day'])) ? $_POST['job_rest_day'] : [];
    $totalRestDaysList = (isset($_POST['job_total_rest_days']) && is_array($_POST['job_total_rest_days'])) ? $_POST['job_total_rest_days'] : [];
    $totalWorkdaysList = (isset($_POST['job_total_workdays_per_month']) && is_array($_POST['job_total_workdays_per_month'])) ? $_POST['job_total_workdays_per_month'] : [];
    $presentKeys = (isset($_POST['job_is_present']) && is_array($_POST['job_is_present'])) ? $_POST['job_is_present'] : [];

    $jobs = [];
    $rowCount = max(count($starts), count($ends), count($businessUnits), count($branches), count($branchNames), count($departments), count($positions), count($descriptions), count($classifications), count($basicPays), count($payClassifications), count($paymentMethods), count($accountNumbers), count($startOfWorks), count($endOfWorks), count($totalWorkHoursList), count($restDays), count($totalRestDaysList), count($totalWorkdaysList));
    for ($i = 0; $i < $rowCount; $i++) {
        $position = trim((string)($positions[$i] ?? ''));
        $department = trim((string)($departments[$i] ?? ''));
        $businessUnit = trim((string)($businessUnits[$i] ?? ''));
        $description = trim((string)($descriptions[$i] ?? ''));
        $classification = trim((string)($classifications[$i] ?? ''));
        $basicPay = (float)str_replace(',', '', (string)($basicPays[$i] ?? 0));
        $payClassification = trim((string)($payClassifications[$i] ?? ''));
        $paymentMethod = trim((string)($paymentMethods[$i] ?? ''));
        $accountNumber = trim((string)($accountNumbers[$i] ?? ''));
        $startOfWork = trim((string)($startOfWorks[$i] ?? ''));
        $endOfWork = trim((string)($endOfWorks[$i] ?? ''));
        $totalWorkHours = (float)str_replace(',', '', (string)($totalWorkHoursList[$i] ?? 0));
        $restDayValue = trim((string)($restDays[$i] ?? ''));
        $totalRestDaysValue = (int)($totalRestDaysList[$i] ?? 0);
        $totalWorkdaysValue = (float)str_replace(',', '', (string)($totalWorkdaysList[$i] ?? 26));
        $start = trim((string)($starts[$i] ?? ''));
        $end = trim((string)($ends[$i] ?? ''));
        $jobBranchName = trim((string)($branchNames[$i] ?? ''));
        $jobBranch = (int)($branches[$i] ?? $default_branch_id);
        if ($jobBranchName !== '') {
            $findBranch = $conn->prepare("SELECT branch_id, branch_name FROM branches WHERE branch_name = ? LIMIT 1");
            if ($findBranch) {
                $findBranch->bind_param('s', $jobBranchName);
                $findBranch->execute();
                $foundBranch = $findBranch->get_result()->fetch_assoc();
                $findBranch->close();
                if ($foundBranch) {
                    $jobBranch = (int)$foundBranch['branch_id'];
                    $jobBranchName = (string)$foundBranch['branch_name'];
                }
            }
        }
        if (!$view_all_branches && $jobBranch <= 0) $jobBranch = $default_branch_id;
        if ($jobBranch <= 0) $jobBranch = $default_branch_id;
        $isPresent = isset($presentKeys[$i]) ? 1 : 0;
        if ($position === '' && $department === '' && $businessUnit === '' && $description === '' && $classification === '' && $start === '' && $end === '' && $basicPay <= 0 && $payClassification === '' && $paymentMethod === '' && $accountNumber === '' && $startOfWork === '' && $endOfWork === '' && $totalWorkHours <= 0 && $restDayValue === '' && $totalRestDaysValue <= 0) continue;
        if ($position === '') $position = 'Job Position';
        if ($isPresent) $end = '';
        $jobs[] = [
            'start_date' => $start,
            'end_date' => $end,
            'business_unit' => $businessUnit,
            'branch_id' => $jobBranch,
            'branch_name_snapshot' => $jobBranchName,
            'department' => $department,
            'position' => $position,
            'job_description' => $description,
            'employment_classification' => $classification,
            'basic_pay' => $basicPay,
            'pay_classification' => $payClassification,
            'payment_method' => $paymentMethod,
            'account_number' => $accountNumber,
            'start_of_work' => $startOfWork,
            'end_of_work' => $endOfWork,
            'total_work_hours' => $totalWorkHours,
            'rest_day' => $restDayValue,
            'total_rest_days' => $totalRestDaysValue,
            'total_workdays_per_month' => $totalWorkdaysValue,
            'is_present' => $isPresent
        ];
    }

    if (empty($jobs) && ($fallback['position'] !== '' || $fallback['department'] !== '' || $fallback['business_unit'] !== '')) {
        if (!$view_all_branches) $fallback['branch_id'] = $default_branch_id;
        $jobs[] = $fallback;
    }

    if (!empty($jobs)) {
        $presentIndex = -1;
        foreach ($jobs as $i => $job) {
            if (!empty($job['is_present'])) { $presentIndex = $i; break; }
        }
        if ($presentIndex < 0) {
            usort($jobs, function($a, $b) {
                return strcmp(($b['start_date'] ?: '0000-00-00'), ($a['start_date'] ?: '0000-00-00'));
            });
            $jobs[0]['is_present'] = 1;
            $jobs[0]['end_date'] = '';
        } else {
            foreach ($jobs as $i => &$job) {
                if ($i !== $presentIndex) $job['is_present'] = 0;
            }
            unset($job);
        }
    }

    // IMPORTANT FIX:
    // Kapag bagong employee pa lang, wala pang valid employee_id habang kinukuha natin
    // ang current job details. Huwag munang mag INSERT sa employee_job_history gamit ang 0
    // dahil may FOREIGN KEY ito papunta sa employees(employee_id).
    // Ise-save ang job history after ma-insert ang employee at makuha ang newEmployeeId.
    if ($employee_id <= 0) {
        usort($jobs, function($a, $b) {
            if ((int)$a['is_present'] !== (int)$b['is_present']) return (int)$b['is_present'] <=> (int)$a['is_present'];
            return strcmp(($b['start_date'] ?: '0000-00-00'), ($a['start_date'] ?: '0000-00-00'));
        });
        return $jobs[0] ?? $fallback;
    }

    $conn->query("DELETE FROM employee_job_history WHERE employee_id=" . (int)$employee_id);
    $stmt = $conn->prepare("INSERT INTO employee_job_history (employee_id, start_date, end_date, business_unit, branch_id, branch_name_snapshot, department, position, job_description, employment_classification, basic_pay, pay_classification, payment_method, account_number, start_of_work, end_of_work, total_work_hours, rest_day, total_rest_days, total_workdays_per_month, is_present, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    if ($stmt) {
        foreach ($jobs as $job) {
            $startDb = $job['start_date'] !== '' ? $job['start_date'] : null;
            $endDb = $job['end_date'] !== '' ? $job['end_date'] : null;
            $branchDb = (int)$job['branch_id'];
            $presentDb = (int)$job['is_present'];
            $basicPayDb = (float)($job['basic_pay'] ?? 0);
            $totalWorkHoursDb = (float)($job['total_work_hours'] ?? 0);
            $totalRestDaysDb = (int)($job['total_rest_days'] ?? 0);
            $totalWorkdaysDb = (float)($job['total_workdays_per_month'] ?? 26);
            $startWorkDb = !empty($job['start_of_work']) ? $job['start_of_work'] : null;
            $endWorkDb = !empty($job['end_of_work']) ? $job['end_of_work'] : null;
            $stmt->bind_param('isssisssssdsssssdsidi', $employee_id, $startDb, $endDb, $job['business_unit'], $branchDb, $job['branch_name_snapshot'], $job['department'], $job['position'], $job['job_description'], $job['employment_classification'], $basicPayDb, $job['pay_classification'], $job['payment_method'], $job['account_number'], $startWorkDb, $endWorkDb, $totalWorkHoursDb, $job['rest_day'], $totalRestDaysDb, $totalWorkdaysDb, $presentDb);
            $stmt->execute();
        }
        $stmt->close();
    }

    usort($jobs, function($a, $b) {
        if ((int)$a['is_present'] !== (int)$b['is_present']) return (int)$b['is_present'] <=> (int)$a['is_present'];
        return strcmp(($b['start_date'] ?: '0000-00-00'), ($a['start_date'] ?: '0000-00-00'));
    });
    return $jobs[0] ?? $fallback;
}
function saveEmployeeRelations(mysqli $conn, int $employee_id): void {
    if ($employee_id <= 0) return;
    $conn->query("DELETE FROM employee_allowances WHERE employee_id=" . (int)$employee_id);
    $allowanceNames = (isset($_POST['allowance_name']) && is_array($_POST['allowance_name'])) ? $_POST['allowance_name'] : [];
    $allowanceAmounts = (isset($_POST['allowance_amount']) && is_array($_POST['allowance_amount'])) ? $_POST['allowance_amount'] : [];
    $stmtAllowance = $conn->prepare("INSERT INTO employee_allowances (employee_id, allowance_name, allowance_amount, created_at) VALUES (?, ?, ?, NOW())");
    if ($stmtAllowance) {
        foreach ($allowanceNames as $i => $name) {
            $name = trim((string)$name);
            $amount = (float)str_replace(',', '', (string)($allowanceAmounts[$i] ?? 0));
            if ($name === '' && $amount <= 0) continue;
            if ($name === '') $name = 'Allowance';
            $stmtAllowance->bind_param('isd', $employee_id, $name, $amount);
            $stmtAllowance->execute();
        }
        $stmtAllowance->close();
    }

    $conn->query("DELETE FROM employee_government_registrations WHERE employee_id=" . (int)$employee_id);
    $govNames = (isset($_POST['gov_name']) && is_array($_POST['gov_name'])) ? $_POST['gov_name'] : [];
    $govNumbers = (isset($_POST['gov_number']) && is_array($_POST['gov_number'])) ? $_POST['gov_number'] : [];
    $stmtGov = $conn->prepare("INSERT INTO employee_government_registrations (employee_id, registration_name, registration_number, created_at) VALUES (?, ?, ?, NOW())");
    if ($stmtGov) {
        foreach ($govNames as $i => $name) {
            $name = trim((string)$name);
            $number = trim((string)($govNumbers[$i] ?? ''));
            if ($name === '' && $number === '') continue;
            if ($name === '') $name = 'Government Registration';
            $stmtGov->bind_param('iss', $employee_id, $name, $number);
            $stmtGov->execute();
        }
        $stmtGov->close();
    }

    foreach (['sss_attachment'=>'SSS', 'philhealth_attachment'=>'PhilHealth', 'pagibig_attachment'=>'Pag-IBIG', 'tin_attachment'=>'TIN'] as $input => $type) {
        if (isset($_FILES[$input])) saveEmployeeUploadedFile($conn, $employee_id, $_FILES[$input], $type);
    }
    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        foreach ($_FILES['attachments']['name'] as $i => $name) {
            $file = [
                'name' => $_FILES['attachments']['name'][$i] ?? '',
                'type' => $_FILES['attachments']['type'][$i] ?? '',
                'tmp_name' => $_FILES['attachments']['tmp_name'][$i] ?? '',
                'error' => $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['attachments']['size'][$i] ?? 0,
            ];
            saveEmployeeUploadedFile($conn, $employee_id, $file, 'General');
        }
    }
}
function jsonResponse(array $response): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}

function payrollColumnExists(mysqli $conn, string $table, string $column): bool {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    if ($safeTable === '' || $safeColumn === '') return false;
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return ($res && $res->num_rows > 0);
}

function payrollAddColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    if (!payrollColumnExists($conn, $table, $column)) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensurePayrollTables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS employee_payroll_system (
        payroll_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NULL,
        branch_id INT NULL,
        implementation_date DATE NULL,
        last_name VARCHAR(100) NULL,
        first_name VARCHAR(100) NULL,
        middle_name VARCHAR(100) NULL,
        employee_name VARCHAR(180) NOT NULL,
        email_address VARCHAR(160) NULL,
        hire_date DATE NULL,
        employee_id_number VARCHAR(100) NULL,
        biometrics_id_number VARCHAR(100) NULL,
        branch VARCHAR(160) NULL,
        department VARCHAR(160) NULL,
        job_position VARCHAR(160) NULL,
        employment_classification VARCHAR(120) NULL,
        monthly_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        pay_classification VARCHAR(120) NULL,
        payment_method VARCHAR(120) NULL,
        employee_account_number VARCHAR(120) NULL,
        start_of_work TIME NULL,
        end_of_work TIME NULL,
        total_work_hours DECIMAL(8,2) NOT NULL DEFAULT 8,
        rest_day VARCHAR(160) NULL,
        total_rest_days INT NOT NULL DEFAULT 0,
        total_workdays_per_month DECIMAL(8,2) NOT NULL DEFAULT 26,
        with_monthly_allowance TINYINT(1) NOT NULL DEFAULT 0,
        monthly_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
        with_sss TINYINT(1) NOT NULL DEFAULT 0,
        sss_number VARCHAR(80) NULL,
        with_philhealth TINYINT(1) NOT NULL DEFAULT 0,
        philhealth_number VARCHAR(80) NULL,
        with_pagibig TINYINT(1) NOT NULL DEFAULT 0,
        pagibig_number VARCHAR(80) NULL,
        remits_withholding_tax TINYINT(1) NOT NULL DEFAULT 0,
        tax_identification_number VARCHAR(80) NULL,
        attachments TEXT NULL,
        daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        hourly_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        regular_ot_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        regular_holiday_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        regular_holiday_ot_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        rest_day_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        rest_day_ot_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        special_holiday_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        special_holiday_ot_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        sss_ee_share DECIMAL(12,2) NOT NULL DEFAULT 0,
        philhealth_ee_share DECIMAL(12,2) NOT NULL DEFAULT 0,
        pagibig_ee_share DECIMAL(12,2) NOT NULL DEFAULT 0,
        sss_er_share DECIMAL(12,2) NOT NULL DEFAULT 0,
        philhealth_er_share DECIMAL(12,2) NOT NULL DEFAULT 0,
        pagibig_er_share DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_payroll_employee (employee_id),
        INDEX idx_payroll_branch (branch_id),
        INDEX idx_payroll_name (employee_name),
        INDEX idx_payroll_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'employee_id' => 'INT NULL AFTER payroll_id',
        'branch_id' => 'INT NULL AFTER employee_id',
        'implementation_date' => 'DATE NULL AFTER branch_id',
        'last_name' => 'VARCHAR(100) NULL AFTER implementation_date',
        'first_name' => 'VARCHAR(100) NULL AFTER last_name',
        'middle_name' => 'VARCHAR(100) NULL AFTER first_name',
        'employee_name' => 'VARCHAR(180) NOT NULL DEFAULT "" AFTER middle_name',
        'email_address' => 'VARCHAR(160) NULL AFTER employee_name',
        'hire_date' => 'DATE NULL AFTER email_address',
        'employee_id_number' => 'VARCHAR(100) NULL AFTER hire_date',
        'biometrics_id_number' => 'VARCHAR(100) NULL AFTER employee_id_number',
        'branch' => 'VARCHAR(160) NULL AFTER biometrics_id_number',
        'department' => 'VARCHAR(160) NULL AFTER branch',
        'job_position' => 'VARCHAR(160) NULL AFTER department',
        'employment_classification' => 'VARCHAR(120) NULL AFTER job_position',
        'monthly_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER employment_classification',
        'pay_classification' => 'VARCHAR(120) NULL AFTER monthly_rate',
        'payment_method' => 'VARCHAR(120) NULL AFTER pay_classification',
        'employee_account_number' => 'VARCHAR(120) NULL AFTER payment_method',
        'start_of_work' => 'TIME NULL AFTER employee_account_number',
        'end_of_work' => 'TIME NULL AFTER start_of_work',
        'total_work_hours' => 'DECIMAL(8,2) NOT NULL DEFAULT 8 AFTER end_of_work',
        'rest_day' => 'VARCHAR(160) NULL AFTER total_work_hours',
        'total_rest_days' => 'INT NOT NULL DEFAULT 0 AFTER rest_day',
        'total_workdays_per_month' => 'DECIMAL(8,2) NOT NULL DEFAULT 26 AFTER total_rest_days',
        'with_monthly_allowance' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER total_workdays_per_month',
        'monthly_allowance' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER with_monthly_allowance',
        'with_sss' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER monthly_allowance',
        'sss_number' => 'VARCHAR(80) NULL AFTER with_sss',
        'with_philhealth' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER sss_number',
        'philhealth_number' => 'VARCHAR(80) NULL AFTER with_philhealth',
        'with_pagibig' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER philhealth_number',
        'pagibig_number' => 'VARCHAR(80) NULL AFTER with_pagibig',
        'remits_withholding_tax' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER pagibig_number',
        'tax_identification_number' => 'VARCHAR(80) NULL AFTER remits_withholding_tax',
        'attachments' => 'TEXT NULL AFTER tax_identification_number',
        'daily_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER attachments',
        'hourly_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER daily_rate',
        'regular_ot_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER hourly_rate',
        'regular_holiday_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER regular_ot_rate',
        'regular_holiday_ot_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER regular_holiday_rate',
        'rest_day_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER regular_holiday_ot_rate',
        'rest_day_ot_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER rest_day_rate',
        'special_holiday_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER rest_day_ot_rate',
        'special_holiday_ot_rate' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER special_holiday_rate',
        'sss_ee_share' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER special_holiday_ot_rate',
        'philhealth_ee_share' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sss_ee_share',
        'pagibig_ee_share' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER philhealth_ee_share',
        'sss_er_share' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER pagibig_ee_share',
        'philhealth_er_share' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sss_er_share',
        'pagibig_er_share' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER philhealth_er_share',
        'status' => "ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER pagibig_er_share"
    ];
    foreach ($columns as $column => $definition) payrollAddColumnIfMissing($conn, 'employee_payroll_system', $column, $definition);
}
ensurePayrollTables($conn);

function peso($amount): string {
    return '₱' . number_format((float)$amount, 2);
}
function cleanMoney($value): float {
    return (float)str_replace([',', '₱', ' '], '', (string)$value);
}
function cleanDate(?string $value): ?string {
    $value = trim((string)$value);
    return $value !== '' ? $value : null;
}
function cleanTime(?string $value): ?string {
    $value = trim((string)$value);
    return $value !== '' ? $value : null;
}
function payrollUploadDir(): string {
    $dir = __DIR__ . '/../uploads/payroll_attachments';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
function payrollPublicPath(string $file): string {
    return '../uploads/payroll_attachments/' . $file;
}
function savePayrollAttachments(array $files): array {
    $saved = [];
    if (empty($files['name']) || !is_array($files['name'])) return $saved;
    $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];
    foreach ($files['name'] as $i => $name) {
        $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($name === '' || $error !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '' && !in_array($ext, $allowed, true)) continue;
        $base = preg_replace('/[^A-Za-z0-9_\.-]/', '_', pathinfo($name, PATHINFO_FILENAME));
        $stored = 'payroll_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
        $target = payrollUploadDir() . '/' . $stored;
        if (move_uploaded_file($files['tmp_name'][$i], $target)) {
            $saved[] = ['name' => $base . ($ext ? '.' . $ext : ''), 'path' => payrollPublicPath($stored)];
        }
    }
    return $saved;
}
function computePayrollRates(float $monthly, float $workdays, float $hours): array {
    $workdays = $workdays > 0 ? $workdays : 26;
    $hours = $hours > 0 ? $hours : 8;
    $daily = $monthly > 0 ? $monthly / $workdays : 0;
    $hourly = $hours > 0 ? $daily / $hours : 0;
    return [
        'daily_rate' => round($daily, 2),
        'hourly_rate' => round($hourly, 2),
        'regular_ot_rate' => round($hourly * 1.25, 2),
        'regular_holiday_rate' => round($daily * 2, 2),
        'regular_holiday_ot_rate' => round($hourly * 2.6, 2),
        'rest_day_rate' => round($daily * 1.3, 2),
        'rest_day_ot_rate' => round($hourly * 1.69, 2),
        'special_holiday_rate' => round($daily * 1.3, 2),
        'special_holiday_ot_rate' => round($hourly * 1.69, 2),
    ];
}

function payrollBindParams(mysqli_stmt $stmt, string $types, array $params): bool {
    $bindValues = array_merge([$types], $params);
    $refs = [];
    foreach ($bindValues as $key => $value) {
        $refs[$key] = &$bindValues[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];

        if ($action === 'save_payroll') {
            $payroll_id = (int)($_POST['payroll_id'] ?? 0);
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $selected_branch_id = (int)$branch_id;

            // IMPORTANT FIX:
            // Kapag galing edit payroll modal, dapat may payroll_id para UPDATE ang gawin.
            // Safety net ito kung hindi naisama ang payroll_id sa POST: hahanapin natin ang
            // existing payroll profile ng employee sa branch para ma-overwrite, hindi mag-INSERT ng duplicate row.
            if ($payroll_id <= 0 && $employee_id > 0) {
                $findExistingPayroll = $conn->prepare("SELECT payroll_id FROM employee_payroll_system WHERE employee_id=? AND branch_id=? ORDER BY payroll_id DESC LIMIT 1");
                if ($findExistingPayroll) {
                    $findExistingPayroll->bind_param('ii', $employee_id, $selected_branch_id);
                    $findExistingPayroll->execute();
                    $existingPayrollRow = $findExistingPayroll->get_result()->fetch_assoc();
                    $findExistingPayroll->close();
                    if ($existingPayrollRow) $payroll_id = (int)$existingPayrollRow['payroll_id'];
                }
            }

            $last_name = trim($_POST['last_name'] ?? '');
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $employee_name = trim($_POST['employee_name'] ?? '');
            if ($employee_name === '') $employee_name = trim($last_name . ', ' . $first_name . ' ' . $middle_name);
            if ($employee_name === ',' || $employee_name === '') throw new Exception('Employee name is required');

            $email_address = trim($_POST['email_address'] ?? '');
            if ($email_address !== '' && !filter_var($email_address, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address');

            $monthly_rate = cleanMoney($_POST['monthly_rate'] ?? 0);
            $total_work_hours = cleanMoney($_POST['total_work_hours'] ?? 8);
            $total_workdays_per_month = cleanMoney($_POST['total_workdays_per_month'] ?? 26);
            $monthly_allowance = cleanMoney($_POST['monthly_allowance'] ?? 0);
            $computed = computePayrollRates($monthly_rate, $total_workdays_per_month, $total_work_hours);

            $existingAttachments = [];
            if ($payroll_id > 0) {
                $getAttach = $conn->prepare("SELECT attachments FROM employee_payroll_system WHERE payroll_id=? AND branch_id=?");
                if ($getAttach) {
                    $getAttach->bind_param('ii', $payroll_id, $selected_branch_id);
                    $getAttach->execute();
                    $attachRow = $getAttach->get_result()->fetch_assoc();
                    $getAttach->close();
                    if (!empty($attachRow['attachments'])) {
                        $decodedAttachments = json_decode($attachRow['attachments'], true);
                        if (is_array($decodedAttachments)) $existingAttachments = $decodedAttachments;
                    }
                }
            }

            $newAttachments = isset($_FILES['attachments']) ? savePayrollAttachments($_FILES['attachments']) : [];

            $copiedEmployeeAttachments = [];
            if (!empty($_POST['employee_copied_attachments'])) {
                $decodedCopied = json_decode((string)$_POST['employee_copied_attachments'], true);
                if (is_array($decodedCopied)) {
                    foreach ($decodedCopied as $copiedFile) {
                        if (!is_array($copiedFile)) continue;
                        $copiedName = trim((string)($copiedFile['name'] ?? $copiedFile['original_name'] ?? 'Employee Attachment'));
                        $copiedPath = trim((string)($copiedFile['path'] ?? $copiedFile['file_path'] ?? ''));
                        if ($copiedPath === '') continue;
                        $copiedEmployeeAttachments[] = ['name' => $copiedName, 'path' => $copiedPath];
                    }
                }
            }

            $mergedAttachments = array_merge($existingAttachments, $copiedEmployeeAttachments, $newAttachments);
            $uniqueAttachments = [];
            $seenAttachmentPaths = [];
            foreach ($mergedAttachments as $fileRow) {
                if (!is_array($fileRow)) continue;
                $fileName = trim((string)($fileRow['name'] ?? $fileRow['original_name'] ?? 'Attachment'));
                $filePath = trim((string)($fileRow['path'] ?? $fileRow['file_path'] ?? ''));
                if ($filePath === '') continue;
                if (isset($seenAttachmentPaths[$filePath])) continue;
                $seenAttachmentPaths[$filePath] = true;
                $uniqueAttachments[] = ['name' => $fileName, 'path' => $filePath];
            }
            $attachments = json_encode(array_values($uniqueAttachments));

            $statusValue = $_POST['status'] ?? 'active';
            if (!in_array($statusValue, ['active', 'inactive'], true)) $statusValue = 'active';

            $data = [
                'employee_id' => $employee_id > 0 ? $employee_id : null,
                'branch_id' => $selected_branch_id,
                'implementation_date' => cleanDate($_POST['implementation_date'] ?? ''),
                'last_name' => $last_name,
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'employee_name' => $employee_name,
                'email_address' => $email_address,
                'hire_date' => cleanDate($_POST['hire_date'] ?? ''),
                'employee_id_number' => trim($_POST['employee_id_number'] ?? ''),
                'biometrics_id_number' => trim($_POST['biometrics_id_number'] ?? ''),
                'branch' => trim($_POST['branch'] ?? $branch_name),
                'department' => trim($_POST['department'] ?? ''),
                'job_position' => trim($_POST['job_position'] ?? ''),
                'employment_classification' => trim($_POST['employment_classification'] ?? ''),
                'monthly_rate' => $monthly_rate,
                'pay_classification' => trim($_POST['pay_classification'] ?? ''),
                'payment_method' => trim($_POST['payment_method'] ?? ''),
                'employee_account_number' => trim($_POST['employee_account_number'] ?? ''),
                'start_of_work' => cleanTime($_POST['start_of_work'] ?? ''),
                'end_of_work' => cleanTime($_POST['end_of_work'] ?? ''),
                'total_work_hours' => $total_work_hours,
                'rest_day' => trim($_POST['rest_day'] ?? ''),
                'total_rest_days' => (int)($_POST['total_rest_days'] ?? 0),
                'total_workdays_per_month' => $total_workdays_per_month,
                'with_monthly_allowance' => isset($_POST['with_monthly_allowance']) ? 1 : 0,
                'monthly_allowance' => $monthly_allowance,
                'with_sss' => isset($_POST['with_sss']) ? 1 : 0,
                'sss_number' => trim($_POST['sss_number'] ?? ''),
                'with_philhealth' => isset($_POST['with_philhealth']) ? 1 : 0,
                'philhealth_number' => trim($_POST['philhealth_number'] ?? ''),
                'with_pagibig' => isset($_POST['with_pagibig']) ? 1 : 0,
                'pagibig_number' => trim($_POST['pagibig_number'] ?? ''),
                'remits_withholding_tax' => isset($_POST['remits_withholding_tax']) ? 1 : 0,
                'tax_identification_number' => trim($_POST['tax_identification_number'] ?? ''),
                'attachments' => $attachments,
                'daily_rate' => $computed['daily_rate'],
                'hourly_rate' => $computed['hourly_rate'],
                'regular_ot_rate' => $computed['regular_ot_rate'],
                'regular_holiday_rate' => $computed['regular_holiday_rate'],
                'regular_holiday_ot_rate' => $computed['regular_holiday_ot_rate'],
                'rest_day_rate' => $computed['rest_day_rate'],
                'rest_day_ot_rate' => $computed['rest_day_ot_rate'],
                'special_holiday_rate' => $computed['special_holiday_rate'],
                'special_holiday_ot_rate' => $computed['special_holiday_ot_rate'],
                'sss_ee_share' => cleanMoney($_POST['sss_ee_share'] ?? 0),
                'philhealth_ee_share' => cleanMoney($_POST['philhealth_ee_share'] ?? 0),
                'pagibig_ee_share' => cleanMoney($_POST['pagibig_ee_share'] ?? 0),
                'sss_er_share' => cleanMoney($_POST['sss_er_share'] ?? 0),
                'philhealth_er_share' => cleanMoney($_POST['philhealth_er_share'] ?? 0),
                'pagibig_er_share' => cleanMoney($_POST['pagibig_er_share'] ?? 0),
                'status' => $statusValue
            ];

            $payrollColumns = [
                'employee_id', 'branch_id', 'implementation_date', 'last_name', 'first_name', 'middle_name',
                'employee_name', 'email_address', 'hire_date', 'employee_id_number', 'biometrics_id_number',
                'branch', 'department', 'job_position', 'employment_classification', 'monthly_rate',
                'pay_classification', 'payment_method', 'employee_account_number', 'start_of_work', 'end_of_work',
                'total_work_hours', 'rest_day', 'total_rest_days', 'total_workdays_per_month',
                'with_monthly_allowance', 'monthly_allowance', 'with_sss', 'sss_number', 'with_philhealth',
                'philhealth_number', 'with_pagibig', 'pagibig_number', 'remits_withholding_tax',
                'tax_identification_number', 'attachments', 'daily_rate', 'hourly_rate', 'regular_ot_rate',
                'regular_holiday_rate', 'regular_holiday_ot_rate', 'rest_day_rate', 'rest_day_ot_rate',
                'special_holiday_rate', 'special_holiday_ot_rate', 'sss_ee_share', 'philhealth_ee_share',
                'pagibig_ee_share', 'sss_er_share', 'philhealth_er_share', 'pagibig_er_share', 'status'
            ];
            $payrollTypeMap = [
                'employee_id'=>'i', 'branch_id'=>'i', 'implementation_date'=>'s', 'last_name'=>'s', 'first_name'=>'s', 'middle_name'=>'s',
                'employee_name'=>'s', 'email_address'=>'s', 'hire_date'=>'s', 'employee_id_number'=>'s', 'biometrics_id_number'=>'s',
                'branch'=>'s', 'department'=>'s', 'job_position'=>'s', 'employment_classification'=>'s', 'monthly_rate'=>'d',
                'pay_classification'=>'s', 'payment_method'=>'s', 'employee_account_number'=>'s', 'start_of_work'=>'s', 'end_of_work'=>'s',
                'total_work_hours'=>'d', 'rest_day'=>'s', 'total_rest_days'=>'i', 'total_workdays_per_month'=>'d',
                'with_monthly_allowance'=>'i', 'monthly_allowance'=>'d', 'with_sss'=>'i', 'sss_number'=>'s', 'with_philhealth'=>'i',
                'philhealth_number'=>'s', 'with_pagibig'=>'i', 'pagibig_number'=>'s', 'remits_withholding_tax'=>'i',
                'tax_identification_number'=>'s', 'attachments'=>'s', 'daily_rate'=>'d', 'hourly_rate'=>'d', 'regular_ot_rate'=>'d',
                'regular_holiday_rate'=>'d', 'regular_holiday_ot_rate'=>'d', 'rest_day_rate'=>'d', 'rest_day_ot_rate'=>'d',
                'special_holiday_rate'=>'d', 'special_holiday_ot_rate'=>'d', 'sss_ee_share'=>'d', 'philhealth_ee_share'=>'d',
                'pagibig_ee_share'=>'d', 'sss_er_share'=>'d', 'philhealth_er_share'=>'d', 'pagibig_er_share'=>'d', 'status'=>'s'
            ];

            $types = '';
            $params = [];
            foreach ($payrollColumns as $columnName) {
                $types .= $payrollTypeMap[$columnName];
                $params[] = $data[$columnName];
            }

            if ($payroll_id > 0) {
                $setParts = [];
                foreach ($payrollColumns as $columnName) {
                    $setParts[] = "`$columnName`=?";
                }
                $sql = "UPDATE employee_payroll_system SET " . implode(', ', $setParts) . ", updated_at=NOW() WHERE payroll_id=?";
                if (!$view_all_branches) $sql .= " AND branch_id=" . (int)$branch_id;

                $typesWithId = $types . 'i';
                $paramsWithId = $params;
                $paramsWithId[] = $payroll_id;

                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                if (!payrollBindParams($stmt, $typesWithId, $paramsWithId)) throw new Exception('Bind failed: ' . $stmt->error);
                if (!$stmt->execute()) throw new Exception('Failed to update payroll record: ' . $stmt->error);
                $stmt->close();
                jsonResponse(['success'=>true,'message'=>'Payroll record updated successfully']);
            } else {
                $columnList = '`' . implode('`, `', $payrollColumns) . '`';
                $placeholders = implode(', ', array_fill(0, count($payrollColumns), '?'));
                $sql = "INSERT INTO employee_payroll_system ($columnList, created_at, updated_at) VALUES ($placeholders, NOW(), NOW())";

                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                if (!payrollBindParams($stmt, $types, $params)) throw new Exception('Bind failed: ' . $stmt->error);
                if (!$stmt->execute()) throw new Exception('Failed to save payroll record: ' . $stmt->error);
                $stmt->close();
                jsonResponse(['success'=>true,'message'=>'Payroll record saved successfully']);
            }
        }

        if ($action === 'delete_payroll') {
            $payroll_id = (int)($_POST['payroll_id'] ?? 0);
            if ($payroll_id <= 0) throw new Exception('Invalid payroll record');
            $sql = "DELETE FROM employee_payroll_system WHERE payroll_id=?";
            if (!$view_all_branches) $sql .= " AND branch_id=" . (int)$branch_id;
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $payroll_id);
            if (!$stmt->execute()) throw new Exception('Failed to delete payroll record');
            $stmt->close();
            jsonResponse(['success'=>true,'message'=>'Payroll record deleted successfully']);
        }


        if ($action === 'save_employee') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $employee_name = trim($first_name . ' ' . ($middle_name !== '' ? $middle_name . ' ' : '') . $last_name);
            if ($employee_name === '') $employee_name = trim($_POST['employee_name'] ?? '');

            $birthday = trim($_POST['birthday'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $contact_number = trim($_POST['mobile_number'] ?? ($_POST['contact_number'] ?? ''));
            $phone_number = trim($_POST['phone_number'] ?? '');
            $mobile_number = trim($_POST['mobile_number'] ?? '');
            $employee_id_number = trim($_POST['employee_id_number'] ?? '');
            $biometrics_id_number = trim($_POST['biometrics_id_number'] ?? '');

            $sss = trim($_POST['sss'] ?? '');
            $philhealth = trim($_POST['philhealth'] ?? '');
            $pagibig = trim($_POST['pagibig'] ?? '');
            $tin = trim($_POST['tin'] ?? '');

            $start_date = trim($_POST['start_date'] ?? '');
            $business_unit = trim($_POST['business_unit'] ?? '');
            $selected_branch_id = (int)($_POST['branch_id'] ?? $branch_id);
            $department = trim($_POST['department'] ?? '');
            $position = trim($_POST['position'] ?? '');
            $job_description = trim($_POST['job_description'] ?? '');
            $employment_classification = trim($_POST['employment_classification'] ?? '');

            $basic_pay = (float)str_replace(',', '', (string)($_POST['basic_pay'] ?? 0));
            $pay_classification = trim($_POST['pay_classification'] ?? '');
            $payment_method = trim($_POST['payment_method'] ?? '');
            $account_number = trim($_POST['account_number'] ?? '');

            $start_of_work = trim($_POST['start_of_work'] ?? '');
            $end_of_work = trim($_POST['end_of_work'] ?? '');
            $total_work_hours = (float)str_replace(',', '', (string)($_POST['total_work_hours'] ?? 0));
            $rest_day = trim($_POST['rest_day'] ?? '');
            $total_rest_days = (int)($_POST['total_rest_days'] ?? 0);
            $total_workdays_per_month = (float)str_replace(',', '', (string)($_POST['total_workdays_per_month'] ?? 26));

            $with_sss = isset($_POST['with_sss']) ? 1 : 0;
            $with_philhealth = isset($_POST['with_philhealth']) ? 1 : 0;
            $with_pagibig = isset($_POST['with_pagibig']) ? 1 : 0;
            $remits_withholding_tax = isset($_POST['remits_withholding_tax']) ? 1 : 0;
            $status = $_POST['status'] ?? 'active';

            if ($employee_name === '') throw new Exception('First name and last name are required');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address');
            if (!in_array($status, ['active','inactive'], true)) $status = 'active';
            if (!$view_all_branches) $selected_branch_id = $branch_id;
            if ($selected_branch_id <= 0) $selected_branch_id = $branch_id;

            $currentJob = saveEmployeeJobs($conn, $employee_id > 0 ? $employee_id : 0, $selected_branch_id, (bool)$view_all_branches);
            if ($employee_id <= 0) {
                // Job history will be saved after insert once the new employee_id is available.
                $currentJob = [
                    'start_date' => trim((string)($_POST['start_date'] ?? '')),
                    'business_unit' => trim((string)($_POST['business_unit'] ?? '')),
                    'branch_id' => $selected_branch_id,
                    'department' => trim((string)($_POST['department'] ?? '')),
                    'position' => trim((string)($_POST['position'] ?? '')),
                    'job_description' => trim((string)($_POST['job_description'] ?? '')),
                    'employment_classification' => trim((string)($_POST['employment_classification'] ?? '')),
                    'basic_pay' => (float)str_replace(',', '', (string)($_POST['basic_pay'] ?? 0)),
                    'pay_classification' => trim((string)($_POST['pay_classification'] ?? '')),
                    'payment_method' => trim((string)($_POST['payment_method'] ?? '')),
                    'account_number' => trim((string)($_POST['account_number'] ?? '')),
                    'start_of_work' => trim((string)($_POST['start_of_work'] ?? '')),
                    'end_of_work' => trim((string)($_POST['end_of_work'] ?? '')),
                    'total_work_hours' => (float)str_replace(',', '', (string)($_POST['total_work_hours'] ?? 0)),
                    'rest_day' => trim((string)($_POST['rest_day'] ?? '')),
                    'total_rest_days' => (int)($_POST['total_rest_days'] ?? 0),
                    'total_workdays_per_month' => (float)str_replace(',', '', (string)($_POST['total_workdays_per_month'] ?? 26))
                ];
            }
            $start_date = trim((string)($currentJob['start_date'] ?? $start_date));
            $business_unit = trim((string)($currentJob['business_unit'] ?? $business_unit));
            $department = trim((string)($currentJob['department'] ?? $department));
            $position = trim((string)($currentJob['position'] ?? $position));
            $job_description = trim((string)($currentJob['job_description'] ?? $job_description));
            $employment_classification = trim((string)($currentJob['employment_classification'] ?? $employment_classification));

            $birthday_db = $birthday !== '' ? $birthday : null;
            $start_date_db = $start_date !== '' ? $start_date : null;
            $start_work_db = $start_of_work !== '' ? $start_of_work : null;
            $end_work_db = $end_of_work !== '' ? $end_of_work : null;

            if ($employee_id > 0) {
                $sql = "UPDATE employees SET branch_id=?, first_name=?, middle_name=?, last_name=?, employee_name=?, contact_number=?, phone_number=?, mobile_number=?, email=?, birthday=?, employee_id_number=?, biometrics_id_number=?, tin=?, philhealth=?, sss=?, pagibig=?, business_unit=?, department=?, position=?, job_description=?, employment_classification=?, start_date=?, basic_pay=?, pay_classification=?, payment_method=?, account_number=?, start_of_work=?, end_of_work=?, total_work_hours=?, rest_day=?, total_rest_days=?, total_workdays_per_month=?, with_sss=?, with_philhealth=?, with_pagibig=?, remits_withholding_tax=?, status=?, updated_at=NOW() WHERE employee_id=?";
                if (!$view_all_branches) $sql .= " AND branch_id=" . (int)$branch_id;
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('isssssssssssssssssssssdsssssdsidiiiisi', $selected_branch_id, $first_name, $middle_name, $last_name, $employee_name, $contact_number, $phone_number, $mobile_number, $email, $birthday_db, $employee_id_number, $biometrics_id_number, $tin, $philhealth, $sss, $pagibig, $business_unit, $department, $position, $job_description, $employment_classification, $start_date_db, $basic_pay, $pay_classification, $payment_method, $account_number, $start_work_db, $end_work_db, $total_work_hours, $rest_day, $total_rest_days, $total_workdays_per_month, $with_sss, $with_philhealth, $with_pagibig, $remits_withholding_tax, $status, $employee_id);
                if (!$stmt->execute()) throw new Exception('Failed to update employee: ' . $stmt->error);
                $stmt->close();
                saveEmployeeRelations($conn, $employee_id);
                saveEmployeeProvision($conn, $employee_id, $basic_pay, $total_workdays_per_month, $total_work_hours, $total_work_hours, $pay_classification);
                jsonResponse(['success'=>true,'message'=>'Employee updated successfully']);
            } else {
                $stmt = $conn->prepare("INSERT INTO employees (branch_id, first_name, middle_name, last_name, employee_name, contact_number, phone_number, mobile_number, email, birthday, employee_id_number, biometrics_id_number, tin, philhealth, sss, pagibig, business_unit, department, position, job_description, employment_classification, start_date, basic_pay, pay_classification, payment_method, account_number, start_of_work, end_of_work, total_work_hours, rest_day, total_rest_days, total_workdays_per_month, with_sss, with_philhealth, with_pagibig, remits_withholding_tax, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->bind_param('isssssssssssssssssssssdsssssdsidiiiis', $selected_branch_id, $first_name, $middle_name, $last_name, $employee_name, $contact_number, $phone_number, $mobile_number, $email, $birthday_db, $employee_id_number, $biometrics_id_number, $tin, $philhealth, $sss, $pagibig, $business_unit, $department, $position, $job_description, $employment_classification, $start_date_db, $basic_pay, $pay_classification, $payment_method, $account_number, $start_work_db, $end_work_db, $total_work_hours, $rest_day, $total_rest_days, $total_workdays_per_month, $with_sss, $with_philhealth, $with_pagibig, $remits_withholding_tax, $status);
                if (!$stmt->execute()) throw new Exception('Failed to add employee: ' . $stmt->error);
                $newEmployeeId = (int)$stmt->insert_id;
                $stmt->close();
                saveEmployeeJobs($conn, $newEmployeeId, $selected_branch_id, (bool)$view_all_branches);
                saveEmployeeRelations($conn, $newEmployeeId);
                saveEmployeeProvision($conn, $newEmployeeId, $basic_pay, $total_workdays_per_month, $total_work_hours, $total_work_hours, $pay_classification);
                jsonResponse(['success'=>true,'message'=>'Employee added successfully']);
            }
        }


        if ($action === 'add_employee_job') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            if ($employee_id <= 0) throw new Exception('Invalid employee');

            $checkSql = "SELECT * FROM employees WHERE employee_id=?";
            if (!$view_all_branches) $checkSql .= " AND branch_id=" . (int)$branch_id;
            $checkStmt = $conn->prepare($checkSql);
            if (!$checkStmt) throw new Exception('Prepare failed: ' . $conn->error);
            $checkStmt->bind_param('i', $employee_id);
            $checkStmt->execute();
            $employeeRow = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            if (!$employeeRow) throw new Exception('Employee not found');

            $start_date = cleanDate($_POST['job_start_date'] ?? '');
            $end_date = cleanDate($_POST['job_end_date'] ?? '');
            $business_unit = trim((string)($_POST['job_business_unit'] ?? ''));
            $department = trim((string)($_POST['job_department'] ?? ''));
            $position = trim((string)($_POST['job_position'] ?? ''));
            $job_description = trim((string)($_POST['job_description_history'] ?? ''));
            $employment_classification = trim((string)($_POST['job_employment_classification'] ?? ''));
            $basic_pay = cleanMoney($_POST['job_basic_pay'] ?? 0);
            $pay_classification = trim((string)($_POST['job_pay_classification'] ?? 'Monthly'));
            $payment_method = trim((string)($_POST['job_payment_method'] ?? 'Cash'));
            $account_number = trim((string)($_POST['job_account_number'] ?? ''));
            $start_of_work = cleanTime($_POST['job_start_of_work'] ?? '');
            $end_of_work = cleanTime($_POST['job_end_of_work'] ?? '');
            $total_work_hours = cleanMoney($_POST['job_total_work_hours'] ?? 8);
            $rest_day = trim((string)($_POST['job_rest_day'] ?? ''));
            $total_rest_days = (int)($_POST['job_total_rest_days'] ?? 0);
            $total_workdays_per_month = cleanMoney($_POST['job_total_workdays_per_month'] ?? 26);
            $is_present = isset($_POST['job_is_present']) ? 1 : 0;

            if ($position === '') throw new Exception('Job position is required');

            $selected_branch_id = (int)($_POST['job_branch_id'] ?? ($employeeRow['branch_id'] ?? $branch_id));
            $branch_name_snapshot = trim((string)($_POST['job_branch_name'] ?? ''));
            if (!$view_all_branches) {
                $selected_branch_id = (int)$branch_id;
                $branch_name_snapshot = $branch_name;
            } elseif ($branch_name_snapshot !== '') {
                $findBranch = $conn->prepare("SELECT branch_id, branch_name FROM branches WHERE branch_name = ? LIMIT 1");
                if ($findBranch) {
                    $findBranch->bind_param('s', $branch_name_snapshot);
                    $findBranch->execute();
                    $foundBranch = $findBranch->get_result()->fetch_assoc();
                    $findBranch->close();
                    if ($foundBranch) {
                        $selected_branch_id = (int)$foundBranch['branch_id'];
                        $branch_name_snapshot = (string)$foundBranch['branch_name'];
                    }
                }
            }
            if ($selected_branch_id <= 0) $selected_branch_id = (int)($employeeRow['branch_id'] ?? $branch_id);

            if ($is_present) {
                $conn->query("UPDATE employee_job_history SET is_present=0 WHERE employee_id=" . (int)$employee_id);
                $end_date = null;
            }

            $stmt = $conn->prepare("INSERT INTO employee_job_history (employee_id, start_date, end_date, business_unit, branch_id, branch_name_snapshot, department, position, job_description, employment_classification, basic_pay, pay_classification, payment_method, account_number, start_of_work, end_of_work, total_work_hours, rest_day, total_rest_days, total_workdays_per_month, is_present, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('isssisssssdsssssdsidi', $employee_id, $start_date, $end_date, $business_unit, $selected_branch_id, $branch_name_snapshot, $department, $position, $job_description, $employment_classification, $basic_pay, $pay_classification, $payment_method, $account_number, $start_of_work, $end_of_work, $total_work_hours, $rest_day, $total_rest_days, $total_workdays_per_month, $is_present);
            if (!$stmt->execute()) throw new Exception('Failed to add job: ' . $stmt->error);
            $stmt->close();

            if ($is_present) {
                $updateSql = "UPDATE employees SET branch_id=?, business_unit=?, department=?, position=?, job_description=?, employment_classification=?, start_date=?, basic_pay=?, pay_classification=?, payment_method=?, account_number=?, start_of_work=?, end_of_work=?, total_work_hours=?, rest_day=?, total_rest_days=?, total_workdays_per_month=?, updated_at=NOW() WHERE employee_id=?";
                if (!$view_all_branches) $updateSql .= " AND branch_id=" . (int)$branch_id;
                $updateStmt = $conn->prepare($updateSql);
                if ($updateStmt) {
                    $updateStmt->bind_param('issssssdsssssdsidi', $selected_branch_id, $business_unit, $department, $position, $job_description, $employment_classification, $start_date, $basic_pay, $pay_classification, $payment_method, $account_number, $start_of_work, $end_of_work, $total_work_hours, $rest_day, $total_rest_days, $total_workdays_per_month, $employee_id);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                saveEmployeeProvision($conn, $employee_id, $basic_pay, $total_workdays_per_month, $total_work_hours, $total_work_hours, $pay_classification);
            }

            jsonResponse(['success'=>true,'message'=>'Job added successfully']);
        }

        if ($action === 'update_employee_job') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $job_id = (int)($_POST['job_id'] ?? 0);
            if ($employee_id <= 0 || $job_id <= 0) throw new Exception('Invalid job record');

            $checkSql = "SELECT e.* FROM employees e INNER JOIN employee_job_history j ON j.employee_id=e.employee_id WHERE e.employee_id=? AND j.job_id=?";
            if (!$view_all_branches) $checkSql .= " AND e.branch_id=" . (int)$branch_id;
            $checkStmt = $conn->prepare($checkSql);
            if (!$checkStmt) throw new Exception('Prepare failed: ' . $conn->error);
            $checkStmt->bind_param('ii', $employee_id, $job_id);
            $checkStmt->execute();
            $employeeRow = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            if (!$employeeRow) throw new Exception('Employee/job record not found');

            $start_date = cleanDate($_POST['job_start_date'] ?? '');
            $end_date = cleanDate($_POST['job_end_date'] ?? '');
            $business_unit = trim((string)($_POST['job_business_unit'] ?? ''));
            $department = trim((string)($_POST['job_department'] ?? ''));
            $position = trim((string)($_POST['job_position'] ?? ''));
            $job_description = trim((string)($_POST['job_description_history'] ?? ''));
            $employment_classification = trim((string)($_POST['job_employment_classification'] ?? ''));
            $basic_pay = cleanMoney($_POST['job_basic_pay'] ?? 0);
            $pay_classification = trim((string)($_POST['job_pay_classification'] ?? 'Monthly'));
            $payment_method = trim((string)($_POST['job_payment_method'] ?? 'Cash'));
            $account_number = trim((string)($_POST['job_account_number'] ?? ''));
            $start_of_work = cleanTime($_POST['job_start_of_work'] ?? '');
            $end_of_work = cleanTime($_POST['job_end_of_work'] ?? '');
            $total_work_hours = cleanMoney($_POST['job_total_work_hours'] ?? 8);
            $rest_day = trim((string)($_POST['job_rest_day'] ?? ''));
            $total_rest_days = (int)($_POST['job_total_rest_days'] ?? 0);
            $total_workdays_per_month = cleanMoney($_POST['job_total_workdays_per_month'] ?? 26);
            $is_present = isset($_POST['job_is_present']) ? 1 : 0;

            if ($position === '') throw new Exception('Job position is required');

            $selected_branch_id = (int)($_POST['job_branch_id'] ?? ($employeeRow['branch_id'] ?? $branch_id));
            $branch_name_snapshot = trim((string)($_POST['job_branch_name'] ?? ''));
            if (!$view_all_branches) {
                $selected_branch_id = (int)$branch_id;
                $branch_name_snapshot = $branch_name;
            } elseif ($branch_name_snapshot !== '') {
                $findBranch = $conn->prepare("SELECT branch_id, branch_name FROM branches WHERE branch_name = ? LIMIT 1");
                if ($findBranch) {
                    $findBranch->bind_param('s', $branch_name_snapshot);
                    $findBranch->execute();
                    $foundBranch = $findBranch->get_result()->fetch_assoc();
                    $findBranch->close();
                    if ($foundBranch) {
                        $selected_branch_id = (int)$foundBranch['branch_id'];
                        $branch_name_snapshot = (string)$foundBranch['branch_name'];
                    }
                }
            }
            if ($selected_branch_id <= 0) $selected_branch_id = (int)($employeeRow['branch_id'] ?? $branch_id);

            if ($is_present) {
                $conn->query("UPDATE employee_job_history SET is_present=0 WHERE employee_id=" . (int)$employee_id . " AND job_id<>" . (int)$job_id);
                $end_date = null;
            }

            $stmt = $conn->prepare("UPDATE employee_job_history SET start_date=?, end_date=?, business_unit=?, branch_id=?, branch_name_snapshot=?, department=?, position=?, job_description=?, employment_classification=?, basic_pay=?, pay_classification=?, payment_method=?, account_number=?, start_of_work=?, end_of_work=?, total_work_hours=?, rest_day=?, total_rest_days=?, total_workdays_per_month=?, is_present=?, updated_at=NOW() WHERE job_id=? AND employee_id=?");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('sssisssssdsssssdsidiii', $start_date, $end_date, $business_unit, $selected_branch_id, $branch_name_snapshot, $department, $position, $job_description, $employment_classification, $basic_pay, $pay_classification, $payment_method, $account_number, $start_of_work, $end_of_work, $total_work_hours, $rest_day, $total_rest_days, $total_workdays_per_month, $is_present, $job_id, $employee_id);
            if (!$stmt->execute()) throw new Exception('Failed to update job: ' . $stmt->error);
            $stmt->close();

            if ($is_present) {
                $updateSql = "UPDATE employees SET branch_id=?, business_unit=?, department=?, position=?, job_description=?, employment_classification=?, start_date=?, basic_pay=?, pay_classification=?, payment_method=?, account_number=?, start_of_work=?, end_of_work=?, total_work_hours=?, rest_day=?, total_rest_days=?, total_workdays_per_month=?, updated_at=NOW() WHERE employee_id=?";
                if (!$view_all_branches) $updateSql .= " AND branch_id=" . (int)$branch_id;
                $updateStmt = $conn->prepare($updateSql);
                if ($updateStmt) {
                    $updateStmt->bind_param('issssssdsssssdsidi', $selected_branch_id, $business_unit, $department, $position, $job_description, $employment_classification, $start_date, $basic_pay, $pay_classification, $payment_method, $account_number, $start_of_work, $end_of_work, $total_work_hours, $rest_day, $total_rest_days, $total_workdays_per_month, $employee_id);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                saveEmployeeProvision($conn, $employee_id, $basic_pay, $total_workdays_per_month, $total_work_hours, $total_work_hours, $pay_classification);
            }

            jsonResponse(['success'=>true,'message'=>'Job row updated successfully']);
        }

        if ($action === 'delete_employee') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            if ($employee_id <= 0) throw new Exception('Invalid employee');
            $sql = "DELETE FROM employees WHERE employee_id=?";
            if (!$view_all_branches) $sql .= " AND branch_id=" . (int)$branch_id;
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $employee_id);
            if (!$stmt->execute()) throw new Exception('Failed to delete employee');
            jsonResponse(['success'=>true,'message'=>'Employee deleted successfully']);
        }

        if ($action === 'save_dtr') {
            $employee_ids = [];
            if (isset($_POST['employee_ids']) && is_array($_POST['employee_ids'])) {
                $employee_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['employee_ids']))));
            }

            $single_employee_id = (int)($_POST['employee_id'] ?? 0);
            if (empty($employee_ids) && $single_employee_id > 0) {
                $employee_ids[] = $single_employee_id;
            }

            $attendance_dates = (isset($_POST['attendance_dates']) && is_array($_POST['attendance_dates'])) ? $_POST['attendance_dates'] : [];
            $start_times = (isset($_POST['start_times']) && is_array($_POST['start_times'])) ? $_POST['start_times'] : [];
            $end_times = (isset($_POST['end_times']) && is_array($_POST['end_times'])) ? $_POST['end_times'] : [];

            $default_attendance_date = trim($_POST['attendance_date'] ?? '');
            $default_start_time = trim($_POST['start_time'] ?? '');
            $default_end_time = trim($_POST['end_time'] ?? '');

            if (empty($employee_ids)) throw new Exception('Select at least one employee');

            $savedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $duplicateTimeInCount = 0;
            $completedCount = 0;
            $invalidCount = 0;

            foreach ($employee_ids as $employee_id) {
                if ($employee_id <= 0) {
                    $skippedCount++;
                    continue;
                }

                $attendance_date = trim((string)($attendance_dates[$employee_id] ?? $default_attendance_date));
                $start_time = trim((string)($start_times[$employee_id] ?? $default_start_time));
                $end_time = trim((string)($end_times[$employee_id] ?? $default_end_time));

                if ($attendance_date === '') {
                    $invalidCount++;
                    continue;
                }

                if ($start_time === '' && $end_time === '') {
                    $invalidCount++;
                    continue;
                }

                $checkSql = "SELECT employee_id, branch_id FROM employees WHERE employee_id=?";
                if (!$view_all_branches) $checkSql .= " AND branch_id=" . (int)$branch_id;
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

                $existingSql = "SELECT dtr_id, start_time, end_time FROM employee_dtr WHERE employee_id=? AND attendance_date=?";
                if (!$view_all_branches) $existingSql .= " AND branch_id=" . (int)$branch_id;
                $existingSql .= " ORDER BY dtr_id DESC LIMIT 1";
                $existingStmt = $conn->prepare($existingSql);
                $existingStmt->bind_param('is', $employee_id, $attendance_date);
                $existingStmt->execute();
                $existingDtr = $existingStmt->get_result()->fetch_assoc();
                $existingStmt->close();

                if ($existingDtr) {
                    $hasExistingEnd = !empty($existingDtr['end_time']) && $existingDtr['end_time'] !== '00:00:00';

                    if ($hasExistingEnd) {
                        $completedCount++;
                        continue;
                    }

                    if ($end_time !== '') {
                        $savedStart = $existingDtr['start_time'];
                        $duration = minutesBetweenTimes($savedStart, $end_time);
                        if ($duration <= 0) {
                            $invalidCount++;
                            continue;
                        }

                        $stmt = $conn->prepare("UPDATE employee_dtr SET end_time=?, duration_minutes=?, updated_at=NOW() WHERE dtr_id=?");
                        $stmt->bind_param('sii', $end_time, $duration, $existingDtr['dtr_id']);
                        if (!$stmt->execute()) throw new Exception('Failed to update DTR');
                        $stmt->close();
                        $updatedCount++;
                        continue;
                    }

                    $duplicateTimeInCount++;
                    continue;
                }

                if ($start_time === '') {
                    $invalidCount++;
                    continue;
                }

                if ($end_time === '') {
                    $stmt = $conn->prepare("INSERT INTO employee_dtr (employee_id, branch_id, attendance_date, start_time, end_time, duration_minutes, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, 0, NOW(), NOW())");
                    $stmt->bind_param('iiss', $employee_id, $empBranch, $attendance_date, $start_time);
                    if (!$stmt->execute()) throw new Exception('Failed to save Start Time');
                    $stmt->close();
                    $savedCount++;
                    continue;
                }

                $duration = minutesBetweenTimes($start_time, $end_time);
                if ($duration <= 0) {
                    $invalidCount++;
                    continue;
                }

                $stmt = $conn->prepare("INSERT INTO employee_dtr (employee_id, branch_id, attendance_date, start_time, end_time, duration_minutes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->bind_param('iisssi', $employee_id, $empBranch, $attendance_date, $start_time, $end_time, $duration);
                if (!$stmt->execute()) throw new Exception('Failed to save DTR');
                $stmt->close();
                $savedCount++;
            }

            if ($savedCount === 0 && $updatedCount === 0) {
                if ($duplicateTimeInCount > 0 && $completedCount === 0 && $invalidCount === 0) {
                    throw new Exception('Selected employee already has a Time In record for the selected date. Time In is not allowed again, but Time Out is allowed if the record is still pending.');
                }
                if ($completedCount > 0 && $duplicateTimeInCount === 0 && $invalidCount === 0) {
                    throw new Exception('Selected employee already has a completed DTR record for the selected date.');
                }
                throw new Exception('No DTR record was saved. Check if the selected employees already have a DTR record for the date, or if the time values are valid.');
            }

            $parts = [];
            if ($savedCount > 0) $parts[] = $savedCount . ' time in saved';
            if ($updatedCount > 0) $parts[] = $updatedCount . ' time out saved';
            if ($duplicateTimeInCount > 0) $parts[] = $duplicateTimeInCount . ' duplicate time in blocked';
            if ($completedCount > 0) $parts[] = $completedCount . ' already completed';
            if ($invalidCount > 0) $parts[] = $invalidCount . ' invalid/skipped';
            if ($skippedCount > 0) $parts[] = $skippedCount . ' skipped';
            jsonResponse(['success'=>true,'message'=>'DTR processed: ' . implode(', ', $parts) . '. Lunch break 12:00 PM to 1:00 PM was excluded from completed durations.']);
        }

        if ($action === 'set_out_selected') {
            $dtr_ids = [];
            if (isset($_POST['dtr_ids']) && is_array($_POST['dtr_ids'])) {
                $dtr_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['dtr_ids']))));
            }
            $end_time = trim($_POST['end_time'] ?? date('H:i'));

            if (empty($dtr_ids)) throw new Exception('Select at least one pending DTR record');
            if ($end_time === '') throw new Exception('End time is required');

            $updatedCount = 0;
            $skippedCount = 0;

            foreach ($dtr_ids as $dtr_id) {
                if ($dtr_id <= 0) {
                    $skippedCount++;
                    continue;
                }

                $getSql = "SELECT dtr_id, branch_id, start_time FROM employee_dtr WHERE dtr_id=? AND (end_time IS NULL OR end_time='00:00:00')";
                if (!$view_all_branches) $getSql .= " AND branch_id=" . (int)$branch_id;
                $getStmt = $conn->prepare($getSql);
                $getStmt->bind_param('i', $dtr_id);
                $getStmt->execute();
                $row = $getStmt->get_result()->fetch_assoc();
                $getStmt->close();

                if (!$row) {
                    $skippedCount++;
                    continue;
                }

                $duration = minutesBetweenTimes($row['start_time'], $end_time);
                if ($duration <= 0) {
                    $skippedCount++;
                    continue;
                }

                $updateStmt = $conn->prepare("UPDATE employee_dtr SET end_time=?, duration_minutes=?, updated_at=NOW() WHERE dtr_id=?");
                $updateStmt->bind_param('sii', $end_time, $duration, $dtr_id);
                if (!$updateStmt->execute()) throw new Exception('Failed to set out time');
                $updateStmt->close();
                $updatedCount++;
            }

            if ($updatedCount === 0) throw new Exception('No pending DTR record was updated. Check the selected records and end time.');

            $message = $updatedCount . ' DTR record(s) updated';
            if ($skippedCount > 0) $message .= ', ' . $skippedCount . ' skipped';
            $message .= '. Lunch break 12:00 PM to 1:00 PM was excluded from completed durations.';
            jsonResponse(['success'=>true,'message'=>$message]);
        }

        throw new Exception('Invalid action');
    } catch (Exception $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()]);
    }
}

$where = $view_all_branches ? "1=1" : "e.branch_id = " . (int)$branch_id;
$employees = [];
$res = $conn->query("SELECT e.*, b.branch_name FROM employees e LEFT JOIN branches b ON e.branch_id=b.branch_id WHERE $where ORDER BY e.employee_name ASC");
if ($res) while ($row = $res->fetch_assoc()) $employees[] = $row;

$branches = [];
$branchRes = $conn->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name ASC");
if ($branchRes) while ($row = $branchRes->fetch_assoc()) $branches[] = $row;

$currentBranchName = $branch_name;
foreach ($branches as $br) {
    if ((int)($br['branch_id'] ?? 0) === (int)$branch_id) {
        $currentBranchName = $br['branch_name'];
        break;
    }
}
$payrollEmployees = array_values(array_filter($employees, function($emp) use ($branch_id) {
    return (int)($emp['branch_id'] ?? 0) === (int)$branch_id;
}));

$payrollWhere = 'branch_id=' . (int)$branch_id;
$payrollRows = [];
$payrollRes = $conn->query("SELECT * FROM employee_payroll_system WHERE $payrollWhere ORDER BY implementation_date DESC, employee_name ASC");
if ($payrollRes) while ($row = $payrollRes->fetch_assoc()) $payrollRows[] = $row;
$totalPayrollProfiles = count($payrollRows);
$activePayrollProfiles = count(array_filter($payrollRows, fn($r) => ($r['status'] ?? '') === 'active'));
$totalPayrollMonthly = array_sum(array_map(fn($r) => (float)($r['monthly_rate'] ?? 0), $payrollRows));
$totalPayrollAllowance = array_sum(array_map(fn($r) => (float)($r['monthly_allowance'] ?? 0), $payrollRows));


$employeeAllowances = [];
$employeeGovRegs = [];
$employeeAttachments = [];
$employeeProvisions = [];
$employeeJobHistory = [];
if (!empty($employees)) {
    $ids = implode(',', array_map('intval', array_column($employees, 'employee_id')));
    if ($ids !== '') {
        $resAllow = $conn->query("SELECT * FROM employee_allowances WHERE employee_id IN ($ids) ORDER BY allowance_id ASC");
        if ($resAllow) while ($row = $resAllow->fetch_assoc()) $employeeAllowances[(int)$row['employee_id']][] = $row;
        $resGov = $conn->query("SELECT * FROM employee_government_registrations WHERE employee_id IN ($ids) ORDER BY registration_id ASC");
        if ($resGov) while ($row = $resGov->fetch_assoc()) $employeeGovRegs[(int)$row['employee_id']][] = $row;
        $resAtt = $conn->query("SELECT * FROM employee_attachments WHERE employee_id IN ($ids) ORDER BY uploaded_at DESC, attachment_id DESC");
        if ($resAtt) while ($row = $resAtt->fetch_assoc()) $employeeAttachments[(int)$row['employee_id']][] = $row;
        $resProv = $conn->query("SELECT * FROM employee_13th_month_provisions WHERE employee_id IN ($ids)");
        if ($resProv) while ($row = $resProv->fetch_assoc()) $employeeProvisions[(int)$row['employee_id']] = $row;
        $resJobs = $conn->query("SELECT j.*, COALESCE(NULLIF(j.branch_name_snapshot, ''), b.branch_name) AS branch_name FROM employee_job_history j LEFT JOIN branches b ON j.branch_id=b.branch_id WHERE j.employee_id IN ($ids) ORDER BY j.is_present DESC, COALESCE(j.start_date, '0000-00-00') DESC, j.job_id DESC");
        if ($resJobs) while ($row = $resJobs->fetch_assoc()) $employeeJobHistory[(int)$row['employee_id']][] = $row;
    }
}
foreach ($employees as &$empRow) {
    $empId = (int)($empRow['employee_id'] ?? 0);
    $empRow['allowances'] = $employeeAllowances[$empId] ?? [];
    $empRow['government_registrations'] = $employeeGovRegs[$empId] ?? [];
    $empRow['attachments'] = $employeeAttachments[$empId] ?? [];
    $empRow['thirteenth_month_provision'] = $employeeProvisions[$empId] ?? null;
    $empRow['job_history'] = $employeeJobHistory[$empId] ?? [];
}
unset($empRow);

$dtrRows = [];
$dtrSql = "SELECT d.*, e.employee_name, e.contact_number, b.branch_name,
          (SELECT SUM(d2.duration_minutes) FROM employee_dtr d2 WHERE d2.employee_id=d.employee_id AND d2.attendance_date=d.attendance_date) AS daily_total_minutes
          FROM employee_dtr d
          INNER JOIN employees e ON d.employee_id=e.employee_id
          LEFT JOIN branches b ON d.branch_id=b.branch_id
          WHERE " . ($view_all_branches ? "1=1" : "d.branch_id=" . (int)$branch_id) . "
          ORDER BY d.attendance_date DESC, e.employee_name ASC, d.start_time ASC LIMIT 300";
$res = $conn->query($dtrSql);
if ($res) while ($row = $res->fetch_assoc()) $dtrRows[] = $row;

$totalEmployees = count($employees);
$activeEmployees = count(array_filter($employees, fn($e) => ($e['status'] ?? '') === 'active'));
$today = date('Y-m-d');
$todayMinutes = 0;
$todayEmployees = [];
$dtrRecordMap = [];
foreach ($dtrRows as $r) {
    $mapEmployeeId = (string)($r['employee_id'] ?? '');
    $mapDate = (string)($r['attendance_date'] ?? '');
    if ($mapEmployeeId !== '' && $mapDate !== '') {
        if (!isset($dtrRecordMap[$mapEmployeeId])) $dtrRecordMap[$mapEmployeeId] = [];
        $hasEndTime = !empty($r['end_time']) && $r['end_time'] !== '00:00:00';
        $dtrRecordMap[$mapEmployeeId][$mapDate] = [
            'dtr_id' => (int)($r['dtr_id'] ?? 0),
            'start_time' => (string)($r['start_time'] ?? ''),
            'end_time' => $hasEndTime ? (string)$r['end_time'] : '',
            'is_open' => !$hasEndTime
        ];
    }
    if ($r['attendance_date'] === $today) {
        $todayMinutes += (int)$r['duration_minutes'];
        $todayEmployees[$r['employee_id']] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee List - Branch Admin</title>
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
    font-weight:600;
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
    font-weight:600;
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
        font-weight:600;
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
    font-weight: 600 !important; 
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
    font-weight: 600; 
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

.attendance-row-checkbox:checked,
#selectAllAttendanceRows:checked {
    background-color: var(--primary-green);
    border-color: var(--primary-green);
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



.details-add-job-wrap{
    border:1px solid rgba(45,106,79,.18);
    background:#f8fffb;
    border-radius:14px;
    padding:14px;
    margin-bottom:14px;
    box-shadow:0 6px 16px rgba(22,101,52,.06);
}
.details-add-job-form .form-label{
    font-size:.78rem;
    font-weight:700;
    color:#526070;
}
.details-add-job-form .form-control,
.details-add-job-form .form-select{
    min-height:38px;
    border-radius:10px;
}

/* ===== EMPLOYEE DETAILS TABBED MODAL ===== */
#employeeDetailsModal .modal-dialog{
    max-width: 980px;
}
#employeeDetailsModal .modal-content{
    max-height: 90vh !important;
}
#employeeDetailsModal .modal-body{
    min-height: 0 !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}
#employeeDetailsModal .employee-details-tabs{
    flex: 0 0 auto !important;
    margin-bottom: 1rem !important;
    border-bottom: 1px solid #e5e7eb !important;
    gap: 0.35rem;
    flex-wrap: wrap;
}
#employeeDetailsModal .employee-details-tabs .nav-link{
    border: none !important;
    border-radius: 12px 12px 0 0 !important;
    color: #052A47 !important;
    font-weight: 700 !important;
    padding: 0.7rem 0.9rem !important;
    background: #f8fafc !important;
    font-size: 0.84rem !important;
}
#employeeDetailsModal .employee-details-tabs .nav-link.active{
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: #ffffff !important;
    box-shadow: 0 8px 18px rgba(4, 120, 87, 0.18) !important;
}
#employeeDetailsModal .employee-details-tab-content{
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: hidden !important;
}
#employeeDetailsModal .employee-details-tab-content > .tab-pane{
    height: 100% !important;
    max-height: 100% !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding-right: 8px !important;
    padding-bottom: 1rem !important;
    scrollbar-width: thin;
    scrollbar-color: #44D34E #f1f5f9;
}
#employeeDetailsModal .employee-details-tab-content > .tab-pane::-webkit-scrollbar{
    width: 8px;
}
#employeeDetailsModal .employee-details-tab-content > .tab-pane::-webkit-scrollbar-track{
    background: #f1f5f9;
    border-radius: 999px;
}
#employeeDetailsModal .employee-details-tab-content > .tab-pane::-webkit-scrollbar-thumb{
    background: #44D34E;
    border-radius: 999px;
}
#employeeDetailsModal .employee-details-tab-content > .tab-pane::-webkit-scrollbar-thumb:hover{
    background: #047857;
}
#employeeDetailsModal .details-provision-card{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:14px;
}
#employeeDetailsModal .details-provision-row{
    display:flex;
    justify-content:space-between;
    gap:14px;
    padding:8px 0;
    border-bottom:1px dashed #dbe4ee;
    font-size:.88rem;
}
#employeeDetailsModal .details-provision-row:last-child{
    border-bottom:0;
}
#employeeDetailsModal .details-provision-row span{
    color:#64748b;
    font-weight:700;
}
#employeeDetailsModal .details-provision-row strong{
    color:#052A47;
    white-space:nowrap;
}
#employeeDetailsModal .details-provision-row.total{
    background:linear-gradient(135deg,#047857,#44D34E);
    color:#fff;
    margin-top:8px;
    border-radius:12px;
    padding:10px 12px;
    border-bottom:0;
}
#employeeDetailsModal .details-provision-row.total span,
#employeeDetailsModal .details-provision-row.total strong{
    color:#fff;
}
@media (max-width: 576px){
    #employeeDetailsModal .employee-details-tabs .nav-link{
        width: 100%;
        border-radius: 12px !important;
        text-align: center;
    }
    #employeeDetailsModal .modal-body{
        max-height: calc(100vh - 145px) !important;
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


.employee-table th, .employee-table td { 
    vertical-align: middle; 
}
.mandatory-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:12px;
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:700;
    color:#052A47;
    height:100%;
}
.mandatory-box .form-check-input{
    margin:0;
}
.provision-card{
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    overflow:hidden;
}
.provision-row{
    display:flex;
    justify-content:space-between;
    gap:12px;
    padding:10px 14px;
    border-bottom:1px solid #edf2f7;
    font-size:.88rem;
    color:#052A47;
}
.provision-row:last-child{
    border-bottom:0;
}
.provision-row.muted{
    background:#f8fafc;
    color:#64748b;
}
.provision-row.total{
    background:linear-gradient(135deg,#047857,#44D34E);
    color:#fff;
    font-weight:800;
}
.dynamic-mini-row{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:10px;
    margin-bottom:8px;
}
.job-history-row{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:12px;
    margin-bottom:10px;
}
.job-history-row.is-present{
    border-color:#44D34E;
    background:linear-gradient(135deg,rgba(68,211,78,.10),rgba(4,120,87,.05));
}
.job-present-badge{
    display:inline-flex;
    align-items:center;
    gap:5px;
    border-radius:999px;
    background:#dcfce7;
    color:#047857;
    font-size:11px;
    font-weight:800;
    padding:4px 9px;
}
.job-history-table-wrap{
    width:100%;
    overflow-x:hidden;
    border-radius:12px;
}
.job-history-table{
    width:100%;
    min-width:0;
    border-collapse:separate;
    border-spacing:0;
    table-layout:fixed;
}
.job-history-table th{
    background:#f8fafc;
    color:#052A47;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.35px;
    padding:12px 10px;
    border-bottom:1px solid #e2e8f0;
    white-space:normal;
    line-height:1.15;
    text-align:center;
    vertical-align:middle;
}
.job-history-table td{
    padding:12px 10px;
    border-bottom:1px solid #eef2f7;
    font-size:13px;
    vertical-align:middle;
    word-break:break-word;
    text-align:center;
    color:#1f2937;
}
.job-history-table th:nth-child(1),
.job-history-table td:nth-child(1){
    width:14%;
}
.job-history-table th:nth-child(2),
.job-history-table td:nth-child(2){
    width:14%;
}
.job-history-table th:nth-child(3),
.job-history-table td:nth-child(3){
    width:20%;
}
.job-history-table th:nth-child(4),
.job-history-table td:nth-child(4){
    width:28%;
    text-align:left;
}
.job-history-table th:nth-child(5),
.job-history-table td:nth-child(5){
    width:12%;
}
.job-history-table th:nth-child(6),
.job-history-table td:nth-child(6){
    width:12%;
}
.job-history-table tbody tr:first-child{
    background:rgba(68,211,78,.08);
}
.job-history-table tbody tr:last-child td{
    border-bottom:0;
}
.job-history-table .present-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    padding:5px 9px;
    border-radius:999px;
    background:#dcfce7;
    color:#047857;
    font-weight:800;
    font-size:11px;
    white-space:nowrap;
}
.job-desc-cell{
    line-height:1.35;
}
.job-row-actions{
    white-space:nowrap;
}
.job-row-edit-btn{
    border-radius:999px;
    font-weight:800;
    font-size:11px;
    padding:5px 10px;
}
.job-history-row.row-edit-only-locked{
    opacity:.72;
    background:#f8fafc;
}
.job-history-row.row-edit-only-locked .btn-outline-danger{
    display:none !important;
}
.job-history-row.row-edit-only-active{
    border:2px solid #44d34e;
    box-shadow:0 10px 28px rgba(68,211,78,.16);
}
.job-history-row.row-edit-only-locked input,
.job-history-row.row-edit-only-locked select,
.job-history-row.row-edit-only-locked textarea{
    background:#f1f5f9 !important;
    cursor:not-allowed;
    pointer-events:none;
}
.job-history-helper{
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:10px;
    padding:9px 12px;
    border-radius:12px;
    background:#ecfdf5;
    color:#047857;
    font-weight:700;
    font-size:12px;
    border:1px solid #bbf7d0;
}
.job-detail-row{
    cursor:pointer;
    transition:background .15s ease,box-shadow .15s ease,transform .15s ease;
}
.job-detail-row:hover,.job-detail-row.active{
    background:rgba(68,211,78,.16)!important;
    box-shadow:inset 4px 0 0 #047857;
}
.job-detail-row:focus{
    outline:2px solid rgba(4,120,87,.35);
    outline-offset:-2px;
}
.job-detail-view{
    margin-top:12px;
    border:1px solid #d1fae5;
    border-radius:14px;
    background:#fff;
    box-shadow:0 12px 28px rgba(5,42,71,.08);
    padding:14px;
}
.job-detail-view.empty{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:90px;
    background:#f8fafc;
    color:#64748b;
    box-shadow:none;
    text-align:center;
    flex-wrap:wrap;
}
.job-detail-view.empty i{
    font-size:20px;
    color:#047857;
}
.job-detail-view.empty span{
    font-size:12px;
    width:100%;
}
.job-detail-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    padding-bottom:12px;
    margin-bottom:12px;
    border-bottom:1px solid #e5e7eb;
}
.job-detail-label{
    display:block;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.04em;
    color:#047857;
    margin-bottom:3px;
}
.job-detail-header h6{
    font-size:1rem;
    font-weight:800;
    color:#052A47;
    margin:0;
}
.job-detail-header p{
    font-size:12px;
    color:#64748b;
    margin:3px 0 0;
}
.job-detail-section{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:12px;
    margin-bottom:12px;
}
.job-detail-section span{
    display:block;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.04em;
    color:#64748b;
    margin-bottom:4px;
}
.job-detail-section p{
    margin:0;
    color:#052A47;
    font-size:13px;
    line-height:1.45;
    white-space:pre-wrap;
}
.job-detail-grid{
    grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
}
.past-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    padding:5px 9px;
    border-radius:999px;
    background:#e0f2fe;
    color:#0369a1;
    font-weight:800;
    font-size:11px;
    white-space:nowrap;
}

.employee-job-profile-modal .modal-dialog{
    max-width:820px;
}
.employee-job-profile-modal .modal-content{
    border:0;
    border-radius:18px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(5,42,71,.18);
}
.employee-job-profile-modal .modal-header{
    background:linear-gradient(135deg,#047857,#44D34E);
    color:#fff;
    border-bottom:0;
}
.employee-job-profile-modal .modal-header .btn-close{
    filter:brightness(0) invert(1);
    opacity:.9;
}
.employee-job-profile-modal .modal-body{
    background:#ffffff;
}
.job-profile-modal-summary{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:14px;
    margin-bottom:14px;
}
.job-profile-modal-summary h6{
    font-size:1.05rem;
    font-weight:900;
    color:#052A47;
    margin:0;
}
.job-profile-modal-summary p{
    margin:3px 0 0;
    color:#64748b;
    font-size:.82rem;
    font-weight:700;
}
.job-profile-section{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:14px;
    margin-bottom:14px;
    box-shadow:0 8px 22px rgba(15,23,42,.05);
}
.job-profile-table-wrap{
    width:100%;
    overflow-x:auto;
    border:1px solid #b7e4c7;
    border-radius:10px;
    background:#fff;
}
.job-profile-table{
    width:100%;
    min-width:640px;
    margin:0;
    border-collapse:collapse;
    font-size:.86rem;
}
.job-profile-table th{
    background:#ffffff;
    color:#64748b;
    font-size:.72rem;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.04em;
    padding:10px 12px;
    border:1px solid #d6dee6;
    border-top:0;
    text-align:left;
    white-space:nowrap;
}
.job-profile-table th:first-child{
    border-left:0;
}
.job-profile-table th:last-child{
    border-right:0;
}
.job-profile-table td{
    padding:11px 12px;
    border:1px solid #e2e8f0;
    color:#052A47;
    vertical-align:middle;
    font-weight:700;
    background:#f0fdf4;
}
.job-profile-table td:first-child{
    border-left:0;
}
.job-profile-table td:last-child{
    border-right:0;
}
.job-profile-table tbody tr:last-child td{
    border-bottom:0;
}
.job-profile-table .job-profile-value{
    white-space:pre-wrap;
    word-break:break-word;
}
.job-profile-table .rate-value{
    font-weight:900;
    color:#047857;
}
@media(max-width:768px){
    .job-profile-modal-summary{
        flex-direction:column;
    }
    .job-profile-table{
        font-size:.82rem;
        min-width:560px;
    }
    .job-profile-table th,.job-profile-table td{
        padding:10px;
    }
}

@media (max-width: 991.98px){
    .job-history-table th,.job-history-table td{
        font-size:12px;
        padding:10px 8px;
    }
    .job-history-table th:nth-child(4),
    .job-history-table td:nth-child(4){
        width:42%;
    }
    .job-detail-header{
        flex-direction:column;
    }
}

.btn-outline-primary-green{
    border:1px solid #047857!important;
    color:#047857!important;
    background:#fff!important;
    border-radius:10px;
    font-weight:700;
}
.btn-outline-primary-green:hover{
    background:linear-gradient(135deg,#047857,#44D34E)!important;
    color:#fff!important;
}
.profile-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(190px,1fr));
    gap:12px;
}
.profile-item{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:10px;
}
.profile-item span{
    display:block;
    font-size:.72rem;
    font-weight:700;
    color:#64748b;
    text-transform:uppercase;
    letter-spacing:.03em;
}
.profile-item strong{
    display:block;
    color:#052A47;
    font-size:.9rem;
    margin-top:3px;
    word-break:break-word;
}
.attachment-list a{
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin:3px 5px 3px 0;
    padding:6px 10px;
    border-radius:999px;
    background:#ecfdf5;
    color:#047857;
    text-decoration:none;
    font-weight:700;
    font-size:.78rem;
}


.employee-form-tabs{
    border-bottom:1px solid #e5e7eb !important;
    gap:.35rem;
    flex-wrap:wrap;
}
.employee-form-tabs .nav-link{
    border:0 !important;
    border-radius:12px 12px 0 0 !important;
    color:#052A47 !important;
    background:#f8fafc !important;
    font-weight:600 !important;
    font-size:.82rem !important;
    padding:.7rem .95rem !important;
}
.employee-form-tabs .nav-link.active{
    color:#fff !important;
    background:linear-gradient(135deg,#047857,#44D34E) !important;
    box-shadow:0 8px 18px rgba(4,120,87,.16) !important;
}
.employee-form-tab-content{
    min-height:420px;
}
@media(max-width:576px){
    .employee-form-tabs .nav-item{
        width:100%;
    }
    .employee-form-tabs .nav-link{
        width:100%;
        border-radius:12px !important;
        text-align:left;
    }
}

/* ===== EMPLOYEE MODAL TAB SCROLL FIX =====
   Keeps the modal header, tabs, and footer visible while the active tab content scrolls.
   This fixes Payroll tab content that cannot be scrolled on smaller screens. */
#employeeModal .modal-dialog{
    max-width: 1180px !important;
}

#employeeModal .modal-content{
    height: min(92vh, 920px) !important;
    max-height: 92vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}

#employeeModal .modal-header,
#employeeModal .modal-footer{
    flex: 0 0 auto !important;
}

#employeeModal form#employeeForm{
    min-height: 0 !important;
    flex: 1 1 auto !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}

#employeeModal .modal-body{
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

#employeeModal .employee-form-tabs{
    flex: 0 0 auto !important;
    margin-bottom: 1rem !important;
}

#employeeModal .employee-form-tab-content{
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: hidden !important;
}

#employeeModal .employee-form-tab-content > .tab-pane{
    height: 100% !important;
    max-height: 100% !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding-right: 8px !important;
    padding-bottom: 1rem !important;
}

#employeeModal #payrollInfoPane,
#employeeModal #attachmentsInfoPane,
#employeeModal #governmentInfoPane,
#employeeModal #jobsInfoPane,
#employeeModal #personalInfoPane{
    scrollbar-width: thin;
    scrollbar-color: #44D34E #f1f5f9;
}

#employeeModal .employee-form-tab-content > .tab-pane::-webkit-scrollbar{
    width: 8px;
}

#employeeModal .employee-form-tab-content > .tab-pane::-webkit-scrollbar-track{
    background: #f1f5f9;
    border-radius: 999px;
}

#employeeModal .employee-form-tab-content > .tab-pane::-webkit-scrollbar-thumb{
    background: #44D34E;
    border-radius: 999px;
}

#employeeModal .employee-form-tab-content > .tab-pane::-webkit-scrollbar-thumb:hover{
    background: #047857;
}

@media (max-width: 768px){
    #employeeModal .modal-dialog{
        margin: .5rem !important;
        max-width: calc(100% - 1rem) !important;
    }

    #employeeModal .modal-content{
        height: calc(100vh - 1rem) !important;
        max-height: calc(100vh - 1rem) !important;
    }

    #employeeModal .employee-form-tab-content > .tab-pane{
        padding-right: 4px !important;
    }
}


/* ===== FIX: EMPLOYEE DETAILS PAYROLL TAB SCROLLBAR ===== */
/* Keeps the Details modal header/footer fixed and lets long tab content scroll. */
#employeeDetailsModal .modal-content{
    max-height: 92vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#employeeDetailsModal .modal-body{
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: calc(92vh - 130px) !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

#employeeDetailsModal .employee-details-tabs{
    flex: 0 0 auto !important;
}

#employeeDetailsModal .employee-details-tab-content{
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: calc(92vh - 245px) !important;
    overflow: hidden !important;
}

#employeeDetailsModal .employee-details-tab-content > .tab-pane{
    height: 100% !important;
    max-height: calc(92vh - 245px) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding-right: 10px !important;
    padding-bottom: 18px !important;
    scrollbar-width: thin;
    scrollbar-color: #44D34E #f1f5f9;
}

#employeeDetailsModal #detailsPayrollPane{
    height: 100% !important;
    max-height: calc(92vh - 245px) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding-right: 10px !important;
    padding-bottom: 24px !important;
}

#employeeDetailsModal #detailsPayrollPane .form-section:last-child{
    margin-bottom: 1.25rem !important;
}

#employeeDetailsModal #detailsPayrollPane::-webkit-scrollbar,
#employeeDetailsModal .employee-details-tab-content > .tab-pane::-webkit-scrollbar{
    width: 8px;
}

#employeeDetailsModal #detailsPayrollPane::-webkit-scrollbar-track,
#employeeDetailsModal .employee-details-tab-content > .tab-pane::-webkit-scrollbar-track{
    background: #f1f5f9;
    border-radius: 999px;
}

#employeeDetailsModal #detailsPayrollPane::-webkit-scrollbar-thumb,
#employeeDetailsModal .employee-details-tab-content > .tab-pane::-webkit-scrollbar-thumb{
    background: #44D34E;
    border-radius: 999px;
}

#employeeDetailsModal #detailsPayrollPane::-webkit-scrollbar-thumb:hover,
#employeeDetailsModal .employee-details-tab-content > .tab-pane::-webkit-scrollbar-thumb:hover{
    background: #047857;
}

@media (max-width: 576px){
    #employeeDetailsModal .modal-body{
        max-height: calc(100vh - 125px) !important;
    }
    #employeeDetailsModal .employee-details-tab-content,
    #employeeDetailsModal .employee-details-tab-content > .tab-pane,
    #employeeDetailsModal #detailsPayrollPane{
        max-height: calc(100vh - 245px) !important;
    }
}



/* Employee Details Attachment Preview Modal - Motorpool-style preview behavior */
#employeeAttachmentPreviewModal {
    padding: 0 !important;
}

#employeeAttachmentPreviewModal .modal-dialog {
    position: fixed;
    inset: 0;
    margin: 0 !important;
    width: 100vw;
    height: 100vh;
    max-width: 100vw !important;
    display: flex;
    align-items: center;
    justify-content: center;
}

#employeeAttachmentPreviewModal .modal-content {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    overflow: visible !important;
}

#employeeAttachmentPreviewModal .modal-body {
    padding: 0 !important;
    margin: 0 !important;
    overflow: visible !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    max-height: none !important;
    background: transparent !important;
}

#employeeAttachmentPreviewModal .attachment-container {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
}

#employeeAttachmentPreviewModal .attachment-wrapper {
    position: relative;
    display: inline-block;
    line-height: 0;
}

#employeeAttachmentPreviewModal .attachment-content {
    display: inline-block;
    line-height: 0;
}

#employeeAttachmentPreviewModal .attachment-content img {
    display: block;
    max-width: 92vw;
    max-height: 92vh;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
    box-shadow: 0 4px 30px rgba(0,0,0,.3);
}

#employeeAttachmentPreviewModal .attachment-content embed {
    display: block;
    width: 92vw;
    height: 92vh;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 4px 30px rgba(0,0,0,.3);
}

#employeeAttachmentPreviewModal .attachment-content .alert {
    max-width: 500px;
    margin: 20px;
    display: block;
    line-height: 1.4;
}

#employeeAttachmentPreviewModal .btn-close-attachment {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 34px;
    height: 34px;
    border: none;
    border-radius: 50%;
    background: rgba(0,0,0,.7);
    color: #fff;
    z-index: 9999;
    display: flex !important;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 14px;
    transition: .2s ease;
    padding: 0;
    margin: 0;
}

#employeeAttachmentPreviewModal .btn-close-attachment:hover {
    background: rgba(0,0,0,.9);
    transform: scale(1.05);
}

#employeeAttachmentPreviewModal .btn-download-attachment {
    position: absolute;
    bottom: 10px;
    right: 10px;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: rgba(0,0,0,.7);
    color: #fff;
    z-index: 9999;
    display: flex !important;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 14px;
    transition: .2s ease;
}

#employeeAttachmentPreviewModal .btn-download-attachment:hover {
    background: rgba(0,0,0,.9);
    color: #fff;
    transform: scale(1.05);
}

.employee-attachment-preview-empty{
    padding: 1rem 1.25rem;
    text-align: center;
    color: #475569;
    background: #fff;
    border-radius: 10px;
    line-height: 1.4;
    box-shadow: 0 4px 30px rgba(0,0,0,.3);
}
.employee-attachment-preview-empty i{
    font-size: 2rem;
    color: #047857;
    display: block;
    margin-bottom: .5rem;
}
.employee-attachment-gallery{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(145px,1fr));
    gap:14px;
    width:100%;
}
.employee-attachment-card{
    border:1px solid #e5e7eb;
    background:#ffffff;
    border-radius:14px;
    overflow:hidden;
    cursor:pointer;
    padding:0;
    text-align:left;
    box-shadow:0 8px 20px rgba(15,23,42,.06);
    transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}
.employee-attachment-card:hover{
    transform:translateY(-2px);
    border-color:#44D34E;
    box-shadow:0 14px 28px rgba(4,120,87,.14);
}
.employee-attachment-thumb{
    height:118px;
    background:#f8fafc;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    border-bottom:1px solid #eef2f7;
}
.employee-attachment-thumb img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}
.employee-attachment-file-icon{
    width:58px;
    height:58px;
    border-radius:16px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#047857,#44D34E);
    color:#fff;
    font-size:1.9rem;
}
.employee-attachment-file-icon.pdf{
    background:linear-gradient(135deg,#b91c1c,#ef4444);
}
.employee-attachment-file-icon.doc{
    background:linear-gradient(135deg,#1d4ed8,#60a5fa);
}
.employee-attachment-file-icon.xls{
    background:linear-gradient(135deg,#047857,#22c55e);
}
.employee-attachment-card-body{
    padding:10px 11px 11px;
}
.employee-attachment-card-type{
    display:inline-flex;
    align-items:center;
    gap:4px;
    max-width:100%;
    padding:3px 7px;
    border-radius:999px;
    background:#ecfdf5;
    color:#047857;
    font-size:.68rem;
    font-weight:800;
    margin-bottom:7px;
    text-transform:uppercase;
    letter-spacing:.02em;
}
.employee-attachment-card-name{
    color:#052A47;
    font-size:.78rem;
    font-weight:800;
    line-height:1.25;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
    word-break:break-word;
}
.employee-attachment-card-hint{
    color:#64748b;
    font-size:.7rem;
    font-weight:700;
    margin-top:6px;
}
.employee-attachment-empty{
    border:1px dashed #cbd5e1;
    border-radius:14px;
    padding:24px;
    text-align:center;
    color:#64748b;
    background:#f8fafc;
}
.employee-attachment-empty i{
    display:block;
    color:#047857;
    font-size:2rem;
    margin-bottom:8px;
}

@media (max-width: 576px) {
    #employeeAttachmentPreviewModal .attachment-content img,
    #employeeAttachmentPreviewModal .attachment-content embed {
        max-width: 96vw;
        max-height: 88vh;
        width: 96vw;
    }
    #employeeAttachmentPreviewModal .btn-close-attachment,
    #employeeAttachmentPreviewModal .btn-download-attachment {
        width: 32px;
        height: 32px;
    }
}




/* ===== EMBEDDED PAYROLL SYSTEM TAB ===== */
.payroll-tab-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.payroll-search-wrap{position:relative;max-width:420px;width:100%;}
.payroll-search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b;z-index:1;}
.payroll-search-wrap input{padding-left:40px;border:1px solid #e2e8f0;border-radius:12px;height:42px;font-size:.88rem;}
.payroll-actions{display:flex;gap:8px;flex-wrap:wrap;}
.employee-list-toolbar{margin-bottom:14px;}
.employee-search-wrap{max-width:420px;width:100%;}
.employee-list-actions .btn{white-space:nowrap;}
.btn-payroll-green{background:linear-gradient(135deg,#047857,#44D34E)!important;color:#fff!important;border:0!important;border-radius:12px!important;padding:9px 14px!important;font-weight:600!important;box-shadow:0 8px 18px rgba(4,120,87,.18)!important;}
.btn-payroll-light{background:#f8fafc!important;color:#052A47!important;border:1px solid #e2e8f0!important;border-radius:12px!important;padding:9px 14px!important;font-weight:800!important;}
.payroll-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px;}
.payroll-summary-card{background:linear-gradient(135deg,#047857,#059669);color:#fff;border-radius:16px;padding:14px;display:flex;align-items:center;gap:12px;box-shadow:0 8px 18px rgba(4,120,87,.16);}
.payroll-summary-card i{font-size:1.4rem;}.payroll-summary-value{font-size:1.05rem;font-weight:900;line-height:1.1}.payroll-summary-label{font-size:.72rem;opacity:.9;font-weight:700;}
.payroll-table-wrap{max-height:620px;overflow-x:hidden;overflow-y:auto;border:1px solid #e5e7eb;border-radius:14px;background:#fff;}
.payroll-table{width:100% !important;min-width:0 !important;table-layout:auto;border-collapse:collapse;background:#fff;}
.payroll-table th{position:sticky;top:0;z-index:2;background:#f8fafc;color:#052A47;text-transform:uppercase;font-size:.64rem;letter-spacing:.03em;font-weight:800;border-bottom:1px solid #e5e7eb;padding:10px 8px;white-space:normal;line-height:1.2;text-align:center;}
.payroll-table td{padding:10px 8px;border-bottom:1px solid #edf2f7;font-size:.76rem;white-space:normal;text-align:center;vertical-align:middle;word-break:break-word;}
.payroll-table td.text-start{text-align:left;}.payroll-table tbody tr:hover{background:#f8fafc;}
.payroll-clickable-row{cursor:pointer;}
.payroll-clickable-row:hover{background:#ecfdf5!important;}
.payroll-badge-status{border-radius:999px;padding:5px 10px;font-weight:800;font-size:.72rem}.payroll-badge-active{background:#d1fae5;color:#047857}.payroll-badge-inactive{background:#fee2e2;color:#b91c1c}
.payroll-modal-tabs{border-bottom:1px solid #e5e7eb;margin-bottom:16px;gap:6px;position:sticky;top:-20px;background:#f8fafc;z-index:3;padding-top:3px;}
.payroll-modal-tabs .nav-link{border:0!important;border-radius:12px 12px 0 0!important;color:#052A47!important;font-weight:800!important;background:#fff!important;padding:10px 13px!important;font-size:.82rem;}
.payroll-modal-tabs .nav-link.active{background:linear-gradient(135deg,#047857,#44D34E)!important;color:#fff!important;}
.payroll-modal-tab-content>.tab-pane{max-height:58vh;overflow-y:auto;overflow-x:hidden;padding-right:6px;}
.payroll-attachment-list a{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;text-decoration:none;color:#052A47;margin:3px;font-weight:700;font-size:.75rem;}
.payroll-preview-frame{width:100%;height:76vh;border:0;border-radius:12px;background:#f8fafc;}
@media(max-width:991px){.payroll-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.payroll-tab-toolbar{align-items:stretch}.payroll-actions{width:100%;}.payroll-actions .btn{flex:1;}.employee-search-wrap{max-width:100%;}}
@media(max-width:576px){.payroll-summary-grid{grid-template-columns:1fr}.payroll-summary-card{padding:12px}.payroll-modal-tabs .nav-link{width:100%;border-radius:12px!important;}}
@media print{.sidebar,.mobile-nav,.navbar-top,.stat-card-row,.employee-toolbar,.dashboard-tabs .nav-link:not(.active),.payroll-tab-toolbar,.table-btn,.modal,.swal2-container{display:none!important}.main-content{margin:0!important;padding:0!important}.payroll-table-wrap{max-height:none;overflow:visible;border:0}.payroll-table{min-width:100%;font-size:8px;border-collapse:collapse;table-layout:auto}.payroll-table th{position:static;background:#f1f5f9!important;color:#052A47!important}.payroll-table th,.payroll-table td{padding:4px;border:1px solid #cbd5e1!important;white-space:normal!important;text-align:center!important}.payroll-table td.text-start{text-align:left!important}}


/* ===== FIXED HEIGHT EMPLOYEE DETAILS MODAL =====
   Keeps Employee Details modal height consistent while switching tabs.
   Personal Information height is used as the visual base; each tab scrolls inside only.
*/
#employeeDetailsModal .modal-dialog{
    max-width: 980px !important;
    height: 90vh !important;
    margin-top: 5vh !important;
    margin-bottom: 5vh !important;
    display: flex !important;
    align-items: stretch !important;
}
#employeeDetailsModal .modal-content{
    height: 90vh !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}
#employeeDetailsModal .modal-header,
#employeeDetailsModal .modal-footer{
    flex: 0 0 auto !important;
}
#employeeDetailsModal .modal-body{
    flex: 1 1 auto !important;
    min-height: 0 !important;
    height: auto !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}
#employeeDetailsModal .employee-details-tabs{
    flex: 0 0 auto !important;
}
#employeeDetailsModal .employee-details-tab-content{
    flex: 1 1 auto !important;
    min-height: 0 !important;
    height: 100% !important;
    overflow: hidden !important;
}
#employeeDetailsModal .employee-details-tab-content > .tab-pane{
    height: 100% !important;
    max-height: 100% !important;
    min-height: 100% !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
}
@media (max-width: 576px){
    #employeeDetailsModal .modal-dialog{
        height: 94vh !important;
        margin-top: 3vh !important;
        margin-bottom: 3vh !important;
    }
    #employeeDetailsModal .modal-content{
        height: 94vh !important;
        max-height: 94vh !important;
    }
}
/* Parent */
.sidebar .nav-link{
    position:relative;
}
.sidebar-parent-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    flex: 0 0 24px;
}

.task-parent-badge {
    position: absolute;
    top: -10px;
    right: -3px;

    min-width: 17px;
    height: 17px;
    padding: 0 4px;

    border-radius: 999px;
    background: #ef4444;
    color: #fff;

    font-size: 10px;
    font-weight: 700;
    line-height: 1;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    z-index: 30;
    pointer-events: none;
    box-sizing: border-box;
}

/* Badge sa Tasks child kapag open ang dropdown */
.task-child-badge {
    margin-left: auto;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: #ef4444;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    display: none;
    align-items: center;
    justify-content: center;
}

/* Closed dropdown: parent badge visible */
.employees-dropdown .task-parent-badge {
    display: inline-flex;
}

/* Open dropdown: parent badge hidden */
.employees-dropdown.employees-menu-open .task-parent-badge {
    display: none;
}

/* Open dropdown: Tasks badge visible */
.employees-dropdown.employees-menu-open .task-child-badge {
    display: inline-flex;
}

/* Allow badge to extend outside icon */
.employees-dropdown > .nav-link,
.sidebar-parent-icon {
    overflow: visible !important;
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
                <!-- Tasks -->
                <li class="nav-item">
                    <a class="nav-link" href="tasks.php">
                        <i class="bi bi-calendar-check"></i>
                        <span class="nav-text">Tasks</span>
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
                                <a class="nav-link" href="customer_list.php">
                                    <i class="bi bi-person-badge"></i>
                                    <span class="nav-text">Customer List</span>
                                </a>
                            </li>

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
                        </ul>
                    </div>
                </li>

                <!-- Employees Dropdown -->
                <li class="nav-item dropdown-nav employees-dropdown">
                    <a class="nav-link"
                    href="#"
                    onclick="toggleSidebarDropdown(event, 'employeesMenu')">

                        <span class="sidebar-parent-icon">
                            <i class="bi bi-briefcase"></i>

                            <?php if ($task_badge_count > 0): ?>
                                <span class="task-parent-badge">
                                    <?= $task_badge_count ?>
                                </span>
                            <?php endif; ?>
                        </span>

                        <span class="nav-text">Employees</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="employeesMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link active" href="employeelist.php">
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

                            <li class="nav-item">
                                <a class="nav-link" href="tasks.php">
                                    <i class="bi bi-calendar-check"></i>
                                    <span class="nav-text">Tasks</span>

                                    <?php if ($task_badge_count > 0): ?>
                                        <span class="task-child-badge">
                                            <?= $task_badge_count ?>
                                        </span>
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

                            <li class="nav-item">
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
    <div class="page-content active">
        <div class="navbar-top">
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
            <div class="page-title">
                <h2>Employee List</h2>
                <p>Manage employee profiles <?php if (!$view_all_branches): ?>for your branch<?php endif; ?></p>
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
                    <button class="nav-link active" id="employee-list-main-tab" data-bs-toggle="tab" data-bs-target="#employeeListMainPane" type="button" role="tab" aria-controls="employeeListMainPane" aria-selected="true">
                        <i class="bi bi-people me-1"></i>Employee List
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="payroll-system-main-tab" data-bs-toggle="tab" data-bs-target="#payrollSystemMainPane" type="button" role="tab" aria-controls="payrollSystemMainPane" aria-selected="false">
                        <i class="bi bi-cash-stack me-1"></i>Payroll System
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="employeeListMainPane" role="tabpanel" aria-labelledby="employee-list-main-tab" tabindex="0">
                    <div class="tab-card">
                        <div class="payroll-tab-toolbar employee-list-toolbar">
                            <div class="payroll-search-wrap employee-search-wrap">
                                <i class="bi bi-search"></i>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search employee..." oninput="filterTables()">
                            </div>
                            <div class="payroll-actions employee-list-actions">
                                <button class="btn btn-payroll-green" type="button" onclick="showEmployeeModal()"><i class="bi bi-plus-circle me-1"></i>Add Employee</button>
                            </div>
                        </div>
                    <div class="table-responsive">
                        <table class="employee-table" id="employeeListTable">
                            <thead><tr><th>Employee Name</th><th>Employee ID</th><th>Position</th><th>Contact</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (empty($employees)): ?>
                                <tr><td colspan="5" class="empty-state-table text-center"><i class="bi bi-person-plus"></i><h5>No Employees Found</h5><p>Add employee profiles to start recording attendance.</p></td></tr>
                            <?php else: foreach ($employees as $emp): ?>
                                <tr class="employee-profile-row" data-search="<?= strtolower(htmlspecialchars(($emp['employee_name'] ?? '').' '.($emp['contact_number'] ?? '').' '.($emp['email'] ?? ''))) ?>" onclick='showEmployeeDetails(<?= json_encode($emp, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                    <td data-label="Employee Name" class="employee-name-cell"><strong><?= htmlspecialchars($emp['employee_name']) ?></strong></td>
                                    <td data-label="Employee ID"><?= htmlspecialchars($emp['employee_id_number'] ?: '-') ?></td>
                                    <td data-label="Position"><?= htmlspecialchars($emp['position'] ?: '-') ?></td>
                                    <td data-label="Contact"><?= htmlspecialchars(($emp['mobile_number'] ?: $emp['contact_number']) ?: '-') ?></td>
                                    <td data-label="Status"><span class="status-badge <?= $emp['status']==='active' ? 'active' : 'inactive' ?>"><?= ucfirst($emp['status']) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>

                </div>

                <div class="tab-pane fade" id="payrollSystemMainPane" role="tabpanel" aria-labelledby="payroll-system-main-tab" tabindex="0">
                    <div class="tab-card">
                        <div class="payroll-tab-toolbar">
                            <div class="payroll-search-wrap"><i class="bi bi-search"></i><input type="text" class="form-control" id="payrollSearch" placeholder="Search payroll records..." oninput="filterPayrollTable()"></div>
                            <div class="payroll-actions">
                                <button class="btn btn-payroll-light" type="button" onclick="printPayrollTable()"><i class="bi bi-printer me-1"></i>Print</button>
                                <button class="btn btn-payroll-light" type="button" onclick="exportPayrollExcel()"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export</button>
                                <button class="btn btn-payroll-green" type="button" onclick="openPayrollModal()"><i class="bi bi-plus-circle me-1"></i>Add Payroll Profile</button>
                            </div>
                        </div>
                        <div class="payroll-table-wrap">
                            <table class="payroll-table" id="payrollTable">
                                <thead>
                                    <tr>
                                        <th>Implementation Date</th>
                                        <th>Employee Name</th>
                                        <th>Department</th>
                                        <th>Job Position</th>
                                        <th>Employment Classification</th>
                                        <th>Monthly Rate</th>
                                        <th>Payment Method</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($payrollRows)): ?>
                                    <tr>
                                        <td colspan="8" class="empty-state-table text-center">
                                            <i class="bi bi-cash-stack"></i>
                                            <h5>No Payroll Profile Found</h5>
                                            <p>Click Add Payroll Profile to start.</p>
                                        </td>
                                    </tr>
                                <?php else: foreach ($payrollRows as $row): ?>
                                    <tr class="payroll-clickable-row"
                                        data-search="<?= htmlspecialchars(strtolower(implode(' ', array_map('strval', $row)))) ?>"
                                        onclick='viewPayroll(<?= json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                        
                                        <td><?= htmlspecialchars($row['implementation_date'] ?? '') ?></td>
                                        <td class="text-start">
                                            <strong><?= htmlspecialchars($row['employee_name'] ?? '') ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($row['department'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['job_position'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['employment_classification'] ?? '') ?></td>
                                        <td><?= peso($row['monthly_rate'] ?? 0) ?></td>
                                        <td><?= htmlspecialchars($row['payment_method'] ?? '') ?></td>
                                        <td>
                                            <span class="payroll-badge-status <?= ($row['status'] ?? '') === 'active' ? 'payroll-badge-active' : 'payroll-badge-inactive' ?>">
                                                <?= htmlspecialchars(ucfirst($row['status'] ?? 'active')) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div><div class="modal fade" id="employeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="employeeModalTitle"><i class="bi bi-person-plus me-2"></i>Add Employee</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="employeeForm" enctype="multipart/form-data"><div class="modal-body">
            <input type="hidden" name="action" value="save_employee"><input type="hidden" name="employee_id" id="employeeId"><input type="hidden" name="employee_name" id="employeeName">

            <ul class="nav nav-tabs employee-form-tabs mb-3" id="employeeFormTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="personal-info-tab" data-bs-toggle="tab" data-bs-target="#personalInfoPane" type="button" role="tab" aria-controls="personalInfoPane" aria-selected="true"><i class="bi bi-person-vcard me-1"></i>Personal Information</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="government-info-tab" data-bs-toggle="tab" data-bs-target="#governmentInfoPane" type="button" role="tab" aria-controls="governmentInfoPane" aria-selected="false"><i class="bi bi-card-checklist me-1"></i>Government Registrations</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="jobs-info-tab" data-bs-toggle="tab" data-bs-target="#jobsInfoPane" type="button" role="tab" aria-controls="jobsInfoPane" aria-selected="false"><i class="bi bi-briefcase me-1"></i>Jobs</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="payroll-info-tab" data-bs-toggle="tab" data-bs-target="#payrollInfoPane" type="button" role="tab" aria-controls="payrollInfoPane" aria-selected="false"><i class="bi bi-cash-stack me-1"></i>Payroll</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="attachments-info-tab" data-bs-toggle="tab" data-bs-target="#attachmentsInfoPane" type="button" role="tab" aria-controls="attachmentsInfoPane" aria-selected="false"><i class="bi bi-paperclip me-1"></i>Attachments</button>
                </li>
            </ul>

            <div class="tab-content employee-form-tab-content" id="employeeFormTabContent">
                <div class="tab-pane fade show active" id="personalInfoPane" role="tabpanel" aria-labelledby="personal-info-tab" tabindex="0">
                    <div class="form-section mb-0">
                        <div class="form-section-title"><i class="bi bi-person-vcard"></i>Employee Information</div>
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label">First Name <span class="text-danger">*</span></label><input type="text" class="form-control" name="first_name" id="firstName" required></div>
                            <div class="col-md-4"><label class="form-label">Middle Name</label><input type="text" class="form-control" name="middle_name" id="middleName"></div>
                            <div class="col-md-4"><label class="form-label">Last Name <span class="text-danger">*</span></label><input type="text" class="form-control" name="last_name" id="lastName" required></div>
                            <div class="col-md-4"><label class="form-label">Birthday</label><input type="date" class="form-control" name="birthday" id="birthday"></div>
                            <div class="col-md-4"><label class="form-label">Email Address</label><input type="email" class="form-control" name="email" id="employeeEmail"></div>
                            <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status" id="employeeStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                            <div class="col-md-3"><label class="form-label">Phone Number</label><input type="text" class="form-control" name="phone_number" id="phoneNumber"></div>
                            <div class="col-md-3"><label class="form-label">Mobile Number</label><input type="text" class="form-control" name="mobile_number" id="mobileNumber"></div>
                            <div class="col-md-3"><label class="form-label">Employee ID Number</label><input type="text" class="form-control" name="employee_id_number" id="employeeIdNumber"></div>
                            <div class="col-md-3"><label class="form-label">Biometrics ID Number</label><input type="text" class="form-control" name="biometrics_id_number" id="biometricsIdNumber"></div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="governmentInfoPane" role="tabpanel" aria-labelledby="government-info-tab" tabindex="0">
                    <div class="form-section mb-0">
                        <div class="form-section-title"><i class="bi bi-card-checklist"></i>Government Registrations <small class="text-muted ms-1">with attachment</small></div>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">SSS Number</label><div class="input-group"><input type="text" class="form-control" name="sss" id="sss"><input type="file" class="form-control" name="sss_attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"></div></div>
                            <div class="col-md-6"><label class="form-label">PhilHealth Number</label><div class="input-group"><input type="text" class="form-control" name="philhealth" id="philhealth"><input type="file" class="form-control" name="philhealth_attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"></div></div>
                            <div class="col-md-6"><label class="form-label">Pag-IBIG Number</label><div class="input-group"><input type="text" class="form-control" name="pagibig" id="pagibig"><input type="file" class="form-control" name="pagibig_attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"></div></div>
                            <div class="col-md-6"><label class="form-label">TIN</label><div class="input-group"><input type="text" class="form-control" name="tin" id="tin"><input type="file" class="form-control" name="tin_attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"></div></div>
                        </div>
                        <div id="governmentExtraRows" class="mt-3"></div>
                        <button type="button" class="btn btn-outline-primary-green btn-sm mt-2" onclick="addGovernmentRow()"><i class="bi bi-plus-circle me-1"></i>Add New</button>
                    </div>
                </div>

                <div class="tab-pane fade" id="jobsInfoPane" role="tabpanel" aria-labelledby="jobs-info-tab" tabindex="0">
                    <div class="form-section mb-0">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <div class="form-section-title mb-0"><i class="bi bi-briefcase"></i>Jobs <small class="text-muted ms-1">historical positions</small></div>
                            <button type="button" class="btn btn-outline-primary-green btn-sm" onclick="addJobRow()"><i class="bi bi-plus-circle me-1"></i>Add Job</button>
                        </div>
                        <input type="hidden" name="start_date" id="startDate">
                        <input type="hidden" name="business_unit" id="businessUnit">
                        <input type="hidden" name="department" id="department">
                        <input type="hidden" name="position" id="position">
                        <input type="hidden" name="employment_classification" id="employmentClassification">
                        <input type="hidden" name="job_description" id="jobDescription">
                        <?php if (!$view_all_branches): ?><input type="hidden" name="branch_id" value="<?= (int)$branch_id ?>"><?php endif; ?>
                        <div id="jobHistoryRows"></div>
                    </div>
                </div>

                <div class="tab-pane fade" id="payrollInfoPane" role="tabpanel" aria-labelledby="payroll-info-tab" tabindex="0">
                    <div class="form-section">
                        <div class="form-section-title"><i class="bi bi-cash-stack"></i>Payment</div>
                        <div class="row g-3">
                            <div class="col-md-3"><label class="form-label">Basic Pay</label><input type="number" step="0.01" class="form-control provision-input" name="basic_pay" id="basicPay" value="0"></div>
                            <div class="col-md-3"><label class="form-label">Pay Classification</label><select class="form-select" name="pay_classification" id="payClassification"><option value="Monthly">Monthly</option><option value="Daily">Daily</option><option value="Hourly">Hourly</option></select></div>
                            <div class="col-md-3"><label class="form-label">Payment Method</label><select class="form-select" name="payment_method" id="paymentMethod"><option value="Cash">Cash</option><option value="Bank Transfer">Bank Transfer</option><option value="Check">Check</option><option value="Payroll Account">Payroll Account</option></select></div>
                            <div class="col-md-3"><label class="form-label">Account Number</label><input type="text" class="form-control" name="account_number" id="accountNumber"></div>
                        </div>
                        <div id="allowanceRows" class="mt-3"></div>
                        <button type="button" class="btn btn-outline-primary-green btn-sm mt-2" onclick="addAllowanceRow()"><i class="bi bi-plus-circle me-1"></i>Add Allowance</button>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="bi bi-calendar-week"></i>Schedule</div>
                        <div class="row g-3">
                            <div class="col-md-2"><label class="form-label">Start of Work</label><input type="time" class="form-control schedule-input provision-input" name="start_of_work" id="startOfWork"></div>
                            <div class="col-md-2"><label class="form-label">End of Work</label><input type="time" class="form-control schedule-input provision-input" name="end_of_work" id="endOfWork"></div>
                            <div class="col-md-2"><label class="form-label">Total Work Hours</label><input type="number" step="0.01" class="form-control provision-input" name="total_work_hours" id="totalWorkHours" value="8"></div>
                            <div class="col-md-2"><label class="form-label">Rest Day</label><input type="text" class="form-control" name="rest_day" id="restDay" placeholder="Sunday"></div>
                            <div class="col-md-2"><label class="form-label">Total Rest Days</label><input type="number" class="form-control" name="total_rest_days" id="totalRestDays" value="4"></div>
                            <div class="col-md-2"><label class="form-label">Total Workdays / Month</label><input type="number" step="0.01" class="form-control provision-input" name="total_workdays_per_month" id="totalWorkdaysPerMonth" value="26"></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="bi bi-shield-check"></i>Mandatories</div>
                        <div class="row g-2">
                            <div class="col-md-3"><div class="mandatory-box"><input class="form-check-input" type="checkbox" name="with_sss" id="withSss"><label for="withSss">With SSS?</label></div></div>
                            <div class="col-md-3"><div class="mandatory-box"><input class="form-check-input" type="checkbox" name="with_philhealth" id="withPhilhealth"><label for="withPhilhealth">With PhilHealth?</label></div></div>
                            <div class="col-md-3"><div class="mandatory-box"><input class="form-check-input" type="checkbox" name="with_pagibig" id="withPagibig"><label for="withPagibig">With Pag-IBIG?</label></div></div>
                            <div class="col-md-3"><div class="mandatory-box"><input class="form-check-input" type="checkbox" name="remits_withholding_tax" id="remitsWithholdingTax"><label for="remitsWithholdingTax">Remits Withholding Tax?</label></div></div>
                        </div>
                    </div>

                    <div class="form-section mb-0">
                        <div class="form-section-title"><i class="bi bi-calculator"></i>13th Month Provision</div>
                        <div class="provision-card">
                            <div class="provision-row"><span>Basic Pay (Monthly)</span><strong id="provBasicPay">0.00</strong></div>
                            <div class="provision-row muted"><span>Divided by 12</span><strong>12.00</strong></div>
                            <div class="provision-row"><span>Monthly Provision</span><strong id="provMonthly">0.00</strong></div>
                            <div class="provision-row muted"><span>Divided by Total Workdays</span><strong id="provWorkdays">26.00</strong></div>
                            <div class="provision-row"><span>Daily Provision</span><strong id="provDaily">0.00</strong></div>
                            <div class="provision-row muted"><span>Divided by Total Workhours</span><strong id="provWorkhours">8.00</strong></div>
                            <div class="provision-row"><span>Hourly Provision</span><strong id="provHourly">0.00</strong></div>
                            <div class="provision-row muted"><span>Multiplied by Total Regular Hours Worked (OT not included)</span><strong id="provRegularHours">8.00</strong></div>
                            <div class="provision-row total"><span>13th Month Provision for the day</span><strong id="provDay">0.00</strong></div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="attachmentsInfoPane" role="tabpanel" aria-labelledby="attachments-info-tab" tabindex="0">
                    <div class="form-section mb-0">
                        <div class="form-section-title"><i class="bi bi-paperclip"></i>Attachments</div>
                        <input type="file" class="form-control" name="attachments[]" id="employeeAttachments" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">
                        <small class="text-muted d-block mt-2">Allowed: PDF, images, Word, and Excel files.</small>
                    </div>
                </div>
            </div>
        </div><div class="modal-footer justify-content-end gap-2 flex-wrap">
            <button type="submit" class="btn btn-outline-primary-green action-footer-btn" data-save-mode="new"><i class="bi bi-save me-1"></i>Save & New</button>
            <button type="submit" class="btn btn-primary-green action-footer-btn" data-save-mode="close"><i class="bi bi-save me-1"></i>Save & Close</button>
            <button type="button" class="btn btn-outline-secondary action-footer-btn" onclick="clearEmployeeForm()"><i class="bi bi-eraser me-1"></i>Clear</button>
        </div></form>
    </div></div>
</div>

<div class="modal fade employee-details-modal" id="employeeDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-lines-fill me-2"></i>Employee Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs employee-details-tabs" id="employeeDetailsTabs" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" id="details-personal-tab" data-bs-toggle="tab" data-bs-target="#detailsPersonalPane" type="button" role="tab" aria-controls="detailsPersonalPane" aria-selected="true"><i class="bi bi-person-vcard me-1"></i>Personal Information</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="details-government-tab" data-bs-toggle="tab" data-bs-target="#detailsGovernmentPane" type="button" role="tab" aria-controls="detailsGovernmentPane" aria-selected="false"><i class="bi bi-card-checklist me-1"></i>Government Registrations</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="details-jobs-tab" data-bs-toggle="tab" data-bs-target="#detailsJobsPane" type="button" role="tab" aria-controls="detailsJobsPane" aria-selected="false"><i class="bi bi-briefcase me-1"></i>Jobs</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="details-payroll-tab" data-bs-toggle="tab" data-bs-target="#detailsPayrollPane" type="button" role="tab" aria-controls="detailsPayrollPane" aria-selected="false"><i class="bi bi-cash-stack me-1"></i>Payroll</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="details-attachments-tab" data-bs-toggle="tab" data-bs-target="#detailsAttachmentsPane" type="button" role="tab" aria-controls="detailsAttachmentsPane" aria-selected="false"><i class="bi bi-paperclip me-1"></i>Attachments</button></li>
                </ul>

                <div class="tab-content employee-details-tab-content" id="employeeDetailsTabContent">
                    <div class="tab-pane fade show active" id="detailsPersonalPane" role="tabpanel" aria-labelledby="details-personal-tab" tabindex="0">
                        <div class="form-section mb-0">
                            <div class="form-section-title"><i class="bi bi-person-vcard"></i>Employee Information</div>
                            <div class="profile-grid" id="employeeDetailsPersonalGrid"></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="detailsGovernmentPane" role="tabpanel" aria-labelledby="details-government-tab" tabindex="0">
                        <div class="form-section mb-0">
                            <div class="form-section-title"><i class="bi bi-card-checklist"></i>Government Registrations</div>
                            <div class="profile-grid" id="employeeDetailsGovernmentGrid"></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="detailsJobsPane" role="tabpanel" aria-labelledby="details-jobs-tab" tabindex="0">
                        <div class="form-section mb-0">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <div class="form-section-title mb-0"><i class="bi bi-briefcase"></i>Jobs</div>
                                <button type="button" class="btn btn-outline-primary-green btn-sm" onclick="toggleEmployeeDetailsAddJob(true)"><i class="bi bi-plus-circle me-1"></i>Add Job</button>
                            </div>
                            <div id="employeeDetailsAddJobWrap" class="details-add-job-wrap d-none">
                                <form id="employeeDetailsAddJobForm" class="details-add-job-form">
                                    <input type="hidden" name="action" value="add_employee_job">
                                    <input type="hidden" name="employee_id" id="detailsAddJobEmployeeId">
                                    <?php if (!$view_all_branches): ?><input type="hidden" name="job_branch_id" value="<?= (int)$branch_id ?>"><input type="hidden" name="job_branch_name" value="<?= htmlspecialchars($branch_name, ENT_QUOTES) ?>"><?php endif; ?>
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                        <div class="small fw-bold text-muted"><i class="bi bi-plus-circle me-1"></i>Add New Job for this Employee</div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleEmployeeDetailsAddJob(false)"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-3"><label class="form-label">Effectivity Date</label><input type="date" class="form-control" name="job_start_date" required></div>
                                        <div class="col-md-3"><label class="form-label">End Date</label><input type="date" class="form-control" name="job_end_date"></div>
                                        <div class="col-md-3"><label class="form-label">Business Unit</label><input type="text" class="form-control" name="job_business_unit"></div>
                                        <?php if ($view_all_branches): ?>
                                        <div class="col-md-3"><label class="form-label">Branch</label><input type="text" class="form-control" name="job_branch_name" list="branchNameList" placeholder="Type branch"><input type="hidden" name="job_branch_id" value="<?= (int)$branch_id ?>"></div>
                                        <?php endif; ?>
                                        <div class="col-md-3"><label class="form-label">Department</label><input type="text" class="form-control" name="job_department"></div>
                                        <div class="col-md-3"><label class="form-label">Position <span class="text-danger">*</span></label><input type="text" class="form-control" name="job_position" required></div>
                                        <div class="col-md-3"><label class="form-label">Classification</label><select class="form-select" name="job_employment_classification"><option value="">Select</option><option value="Regular">Regular</option><option value="Probationary">Probationary</option><option value="Contractual">Contractual</option><option value="Part-time">Part-time</option><option value="Project-based">Project-based</option></select></div>
                                        <div class="col-md-3"><label class="form-label">Present Position?</label><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="job_is_present" id="detailsAddJobPresent"><label class="form-check-label" for="detailsAddJobPresent">Set as present</label></div></div>
                                        <div class="col-12"><label class="form-label">Job Description</label><textarea class="form-control" name="job_description_history" rows="2" placeholder="Main description of Job / responsibilities"></textarea></div>
                                        <div class="col-md-3"><label class="form-label">Monthly Rate</label><input type="number" step="0.01" class="form-control" name="job_basic_pay" value="0"></div>
                                        <div class="col-md-3"><label class="form-label">Pay Classification</label><select class="form-select" name="job_pay_classification"><option value="Monthly">Monthly</option><option value="Daily">Daily</option><option value="Hourly">Hourly</option></select></div>
                                        <div class="col-md-3"><label class="form-label">Payment Method</label><select class="form-select" name="job_payment_method"><option value="Cash">Cash</option><option value="Bank Transfer">Bank Transfer</option><option value="Check">Check</option><option value="Payroll Account">Payroll Account</option></select></div>
                                        <div class="col-md-3"><label class="form-label">Account Number</label><input type="text" class="form-control" name="job_account_number"></div>
                                        <div class="col-md-2"><label class="form-label">Start Work</label><input type="time" class="form-control" name="job_start_of_work"></div>
                                        <div class="col-md-2"><label class="form-label">End Work</label><input type="time" class="form-control" name="job_end_of_work"></div>
                                        <div class="col-md-2"><label class="form-label">Total Hours</label><input type="number" step="0.01" class="form-control" name="job_total_work_hours" value="8"></div>
                                        <div class="col-md-2"><label class="form-label">Rest Day</label><input type="text" class="form-control" name="job_rest_day"></div>
                                        <div class="col-md-2"><label class="form-label">Rest Days</label><input type="number" class="form-control" name="job_total_rest_days" value="4"></div>
                                        <div class="col-md-2"><label class="form-label">Workdays/Month</label><input type="number" step="0.01" class="form-control" name="job_total_workdays_per_month" value="26"></div>
                                        <div class="col-12 d-flex justify-content-end gap-2">
                                            <button type="button" class="btn btn-outline-secondary action-footer-btn" onclick="toggleEmployeeDetailsAddJob(false)">Cancel</button>
                                            <button type="submit" class="btn btn-primary-green action-footer-btn"><i class="bi bi-save me-1"></i>Save Job</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div id="employeeDetailsJobsGrid"></div>
                        </div>
                    </div>
                    <div class="tab-pane fade details-payroll-scroll" id="detailsPayrollPane" role="tabpanel" aria-labelledby="details-payroll-tab" tabindex="0">
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-cash-stack"></i>Payment</div>
                            <div class="profile-grid" id="employeeDetailsPaymentGrid"></div>
                        </div>
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-calendar-week"></i>Schedule</div>
                            <div class="profile-grid" id="employeeDetailsScheduleGrid"></div>
                        </div>
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-shield-check"></i>Mandatories</div>
                            <div class="profile-grid" id="employeeDetailsMandatoriesGrid"></div>
                        </div>
                        <div class="form-section mb-0">
                            <div class="form-section-title"><i class="bi bi-calculator"></i>13th Month Provision</div>
                            <div class="details-provision-card" id="employeeDetailsProvisionCard"></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="detailsAttachmentsPane" role="tabpanel" aria-labelledby="details-attachments-tab" tabindex="0">
                        <div class="form-section mb-0">
                            <div class="form-section-title"><i class="bi bi-paperclip"></i>Attachments</div>
                            <div id="employeeDetailsAttachmentsList"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-end gap-2 flex-wrap">
                <button type="button" class="btn btn-primary-green action-footer-btn" id="employeeDetailsEditBtn"><i class="bi bi-pencil me-1"></i>Edit Employee</button>
                <button type="button" class="btn btn-danger action-footer-btn" id="employeeDetailsDeleteBtn"><i class="bi bi-trash me-1"></i>Delete Employee</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade employee-job-profile-modal" id="employeeJobProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="employeeJobProfileTitle"><i class="bi bi-briefcase-fill me-2"></i>Job Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="job-profile-modal-summary" id="employeeJobProfileSummary"></div>

                <div class="job-profile-section">
                    <div class="form-section-title"><i class="bi bi-card-text"></i>Job Descriptions</div>
                    <div class="job-profile-table-wrap" id="employeeJobProfileJobsGrid"></div>
                </div>

                <div class="job-profile-section">
                    <div class="form-section-title"><i class="bi bi-cash-stack"></i>Rate</div>
                    <div class="job-profile-table-wrap" id="employeeJobProfileRateGrid"></div>
                </div>

                <div class="job-profile-section mb-0">
                    <div class="form-section-title"><i class="bi bi-calendar-week"></i>Schedule</div>
                    <div class="job-profile-table-wrap" id="employeeJobProfileScheduleGrid"></div>
                </div>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-outline-secondary action-footer-btn" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="employeeJobRowEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="employeeJobRowEditForm">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Job Row</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update_employee_job">
                <input type="hidden" name="employee_id" id="editJobRowEmployeeId">
                <input type="hidden" name="job_id" id="editJobRowJobId">
                <?php if (!$view_all_branches): ?><input type="hidden" name="job_branch_id" id="editJobRowBranchId" value="<?= (int)$branch_id ?>"><input type="hidden" name="job_branch_name" id="editJobRowBranchName" value="<?= htmlspecialchars($branch_name, ENT_QUOTES) ?>"><?php endif; ?>
                <div class="alert alert-success py-2 mb-3"><i class="bi bi-info-circle me-1"></i>Selected job row lang ang mae-edit dito. Hindi gagalawin ang ibang job rows.</div>
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">Effectivity Date</label><input type="date" class="form-control" name="job_start_date" id="editJobRowStartDate" required></div>
                    <div class="col-md-3"><label class="form-label">End Date</label><input type="date" class="form-control" name="job_end_date" id="editJobRowEndDate"></div>
                    <div class="col-md-3"><label class="form-label">Business Unit</label><input type="text" class="form-control" name="job_business_unit" id="editJobRowBusinessUnit"></div>
                    <?php if ($view_all_branches): ?>
                    <div class="col-md-3"><label class="form-label">Branch</label><input type="text" class="form-control" name="job_branch_name" id="editJobRowBranchName" list="branchNameList" placeholder="Type branch"><input type="hidden" name="job_branch_id" id="editJobRowBranchId" value="<?= (int)$branch_id ?>"></div>
                    <?php endif; ?>
                    <div class="col-md-3"><label class="form-label">Department</label><input type="text" class="form-control" name="job_department" id="editJobRowDepartment"></div>
                    <div class="col-md-3"><label class="form-label">Position <span class="text-danger">*</span></label><input type="text" class="form-control" name="job_position" id="editJobRowPosition" required></div>
                    <div class="col-md-3"><label class="form-label">Classification</label><select class="form-select" name="job_employment_classification" id="editJobRowClassification"><option value="">Select</option><option value="Regular">Regular</option><option value="Probationary">Probationary</option><option value="Contractual">Contractual</option><option value="Part-time">Part-time</option><option value="Project-based">Project-based</option></select></div>
                    <div class="col-md-3"><label class="form-label">Present Position?</label><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="job_is_present" id="editJobRowPresent"><label class="form-check-label" for="editJobRowPresent">Set as present</label></div></div>
                    <div class="col-12"><label class="form-label">Job Description</label><textarea class="form-control" name="job_description_history" id="editJobRowDescription" rows="3" placeholder="Main description of Job / responsibilities"></textarea></div>
                    <div class="col-md-3"><label class="form-label">Monthly Rate</label><input type="number" step="0.01" class="form-control" name="job_basic_pay" id="editJobRowBasicPay" value="0"></div>
                    <div class="col-md-3"><label class="form-label">Pay Classification</label><select class="form-select" name="job_pay_classification" id="editJobRowPayClassification"><option value="Monthly">Monthly</option><option value="Daily">Daily</option><option value="Hourly">Hourly</option></select></div>
                    <div class="col-md-3"><label class="form-label">Payment Method</label><select class="form-select" name="job_payment_method" id="editJobRowPaymentMethod"><option value="Cash">Cash</option><option value="Bank Transfer">Bank Transfer</option><option value="Check">Check</option><option value="Payroll Account">Payroll Account</option></select></div>
                    <div class="col-md-3"><label class="form-label">Account Number</label><input type="text" class="form-control" name="job_account_number" id="editJobRowAccountNumber"></div>
                    <div class="col-md-2"><label class="form-label">Start Work</label><input type="time" class="form-control" name="job_start_of_work" id="editJobRowStartWork"></div>
                    <div class="col-md-2"><label class="form-label">End Work</label><input type="time" class="form-control" name="job_end_of_work" id="editJobRowEndWork"></div>
                    <div class="col-md-2"><label class="form-label">Total Work Hours</label><input type="number" step="0.01" class="form-control" name="job_total_work_hours" id="editJobRowTotalWorkHours" value="8"></div>
                    <div class="col-md-2"><label class="form-label">Rest Day</label><input type="text" class="form-control" name="job_rest_day" id="editJobRowRestDay"></div>
                    <div class="col-md-2"><label class="form-label">Rest Days</label><input type="number" class="form-control" name="job_total_rest_days" id="editJobRowTotalRestDays" value="4"></div>
                    <div class="col-md-2"><label class="form-label">Workdays/Month</label><input type="number" step="0.01" class="form-control" name="job_total_workdays_per_month" id="editJobRowWorkdaysPerMonth" value="26"></div>
                </div>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-outline-secondary action-footer-btn" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Cancel</button>
                <button type="submit" class="btn btn-primary-green action-footer-btn"><i class="bi bi-save me-1"></i>Update Job Row</button>
            </div>
        </form>
    </div>
</div>


<div class="modal fade" id="employeeAttachmentPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0 shadow-none">
            <div class="modal-body p-0">
                <div class="attachment-container">
                    <div class="attachment-wrapper">
                        <button type="button" class="btn-close-attachment" data-bs-dismiss="modal" aria-label="Close">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <a href="#" id="employeeAttachmentDownloadBtn" class="btn-download-attachment" download target="_blank" rel="noopener">
                            <i class="bi bi-download"></i>
                        </a>
                        <div class="attachment-content" id="employeeAttachmentPreviewContent">
                            <div class="spinner-border text-light" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="payrollModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content" id="payrollForm" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin"></i> <span id="payrollModalTitle">Add Payroll Profile</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save_payroll">
                <input type="hidden" name="payroll_id" id="payroll_id">
                <input type="hidden" name="employee_id" id="employee_id">
                <input type="hidden" name="employee_copied_attachments" id="employee_copied_attachments">

                <ul class="nav nav-tabs payroll-modal-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBasic" type="button">Basic Info</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabJob" type="button">Job & Schedule</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRates" type="button">Rates</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabMandatories" type="button">Mandatories</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAttachments" type="button">Attachments</button></li>
                </ul>

                <div class="tab-content payroll-modal-tab-content">
                    <div class="tab-pane fade show active" id="tabBasic">
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-person-lines-fill"></i> Employee Information</div>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Select Existing Employee</label><select class="form-select" id="existingEmployeeSelect" onchange="fillFromEmployee()"><option value="">Manual Entry</option><?php foreach ($payrollEmployees as $emp): ?><option value='<?= json_encode($emp, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>'><?= htmlspecialchars($emp['employee_name'] ?? '') ?></option><?php endforeach; ?></select><small class="text-muted">Employees from <?= htmlspecialchars($currentBranchName) ?> only.</small></div>
                                <div class="col-md-4"><label class="form-label">Implementation Date</label><input type="date" class="form-control" name="implementation_date" id="implementation_date"></div>
                                <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status" id="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                                <div class="col-md-4"><label class="form-label">Last Name</label><input type="text" class="form-control" name="last_name" id="last_name"></div>
                                <div class="col-md-4"><label class="form-label">First Name</label><input type="text" class="form-control" name="first_name" id="first_name"></div>
                                <div class="col-md-4"><label class="form-label">Middle Name</label><input type="text" class="form-control" name="middle_name" id="middle_name"></div>
                                <div class="col-md-4"><label class="form-label">Employee Name</label><input type="text" class="form-control" name="employee_name" id="employee_name" required></div>
                                <div class="col-md-4"><label class="form-label">Email Address</label><input type="email" class="form-control" name="email_address" id="email_address"></div>
                                <div class="col-md-4"><label class="form-label">Hire Date</label><input type="date" class="form-control" name="hire_date" id="hire_date"></div>
                                <div class="col-md-4"><label class="form-label">Employee ID Number</label><input type="text" class="form-control" name="employee_id_number" id="employee_id_number"></div>
                                <div class="col-md-4"><label class="form-label">Biometrics ID Number</label><input type="text" class="form-control" name="biometrics_id_number" id="biometrics_id_number"></div>
                                <div class="col-md-4"><label class="form-label">Branch</label><input type="hidden" name="branch_id" id="branch_id" value="<?= (int)$branch_id ?>"><input type="text" class="form-control" name="branch" id="branch" value="<?= htmlspecialchars($currentBranchName) ?>" readonly></div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabJob">
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-briefcase"></i> Job Details</div>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Department</label><input type="text" class="form-control" name="department" id="department"></div>
                                <div class="col-md-4"><label class="form-label">Job Position</label><input type="text" class="form-control" name="job_position" id="job_position"></div>
                                <div class="col-md-4"><label class="form-label">Employment Classification</label><select class="form-select" name="employment_classification" id="employment_classification"><option value="">Select</option><option>Regular</option><option>Probationary</option><option>Project-based</option><option>Contractual</option><option>Part-time</option></select></div>
                            </div>
                        </div>
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-clock"></i> Work Schedule</div>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Start of Work</label><input type="time" class="form-control rate-input" name="start_of_work" id="start_of_work"></div>
                                <div class="col-md-4"><label class="form-label">End of Work</label><input type="time" class="form-control rate-input" name="end_of_work" id="end_of_work"></div>
                                <div class="col-md-4"><label class="form-label">Total Work Hours</label><input type="number" step="0.01" class="form-control rate-input" name="total_work_hours" id="total_work_hours" value="8"></div>
                                <div class="col-md-4"><label class="form-label">Rest Day</label><input type="text" class="form-control" name="rest_day" id="rest_day" placeholder="Sunday / Saturday"></div>
                                <div class="col-md-4"><label class="form-label">Total Rest Days</label><input type="number" class="form-control" name="total_rest_days" id="total_rest_days" value="0"></div>
                                <div class="col-md-4"><label class="form-label">Total Workdays per Month</label><input type="number" step="0.01" class="form-control rate-input" name="total_workdays_per_month" id="total_workdays_per_month" value="26"></div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabRates">
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-wallet2"></i> Payment Details</div>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Monthly Rate</label><input type="number" step="0.01" class="form-control rate-input" name="monthly_rate" id="monthly_rate" value="0"></div>
                                <div class="col-md-4"><label class="form-label">Pay Classification</label><select class="form-select" name="pay_classification" id="pay_classification"><option value="">Select</option><option>Monthly</option><option>Daily</option><option>Hourly</option></select></div>
                                <div class="col-md-4"><label class="form-label">Payment Method</label><select class="form-select" name="payment_method" id="payment_method"><option value="">Select</option><option>Cash</option><option>Bank Transfer</option><option>Check</option><option>Online Transfer</option></select></div>
                                <div class="col-md-4"><label class="form-label">Employee Account Number</label><input type="text" class="form-control" name="employee_account_number" id="employee_account_number"></div>
                                <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="with_monthly_allowance" id="with_monthly_allowance"><label class="form-check-label fw-bold" for="with_monthly_allowance">With Monthly Allowance?</label></div></div>
                                <div class="col-md-4"><label class="form-label">Monthly Allowance</label><input type="number" step="0.01" class="form-control" name="monthly_allowance" id="monthly_allowance" value="0"></div>
                            </div>
                        </div>
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-calculator"></i> Auto-computed Rates</div>
                            <div class="row g-3" id="computedRatesPreview"></div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabMandatories">
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-shield-check"></i> Employee Government Mandatories</div>
                            <div class="row g-3">
                                <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="with_sss" id="with_sss"><label class="form-check-label fw-bold" for="with_sss">With SSS?</label></div></div>
                                <div class="col-md-3"><label class="form-label">SSS Number</label><input type="text" class="form-control" name="sss_number" id="sss_number"></div>
                                <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="with_philhealth" id="with_philhealth"><label class="form-check-label fw-bold" for="with_philhealth">With PhilHealth?</label></div></div>
                                <div class="col-md-3"><label class="form-label">PhilHealth Number</label><input type="text" class="form-control" name="philhealth_number" id="philhealth_number"></div>
                                <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="with_pagibig" id="with_pagibig"><label class="form-check-label fw-bold" for="with_pagibig">With Pag-IBIG?</label></div></div>
                                <div class="col-md-3"><label class="form-label">Pag-IBIG Number</label><input type="text" class="form-control" name="pagibig_number" id="pagibig_number"></div>
                                <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="remits_withholding_tax" id="remits_withholding_tax"><label class="form-check-label fw-bold" for="remits_withholding_tax">Remits Withholding Tax?</label></div></div>
                                <div class="col-md-3"><label class="form-label">Tax Identification Number</label><input type="text" class="form-control" name="tax_identification_number" id="tax_identification_number"></div>
                            </div>
                        </div>
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-building-check"></i> Contribution Shares</div>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">SSS EE Share (with MPF)</label><input type="number" step="0.01" class="form-control" name="sss_ee_share" id="sss_ee_share" value="0"></div>
                                <div class="col-md-4"><label class="form-label">PhilHealth EE Share</label><input type="number" step="0.01" class="form-control" name="philhealth_ee_share" id="philhealth_ee_share" value="0"></div>
                                <div class="col-md-4"><label class="form-label">Pag-IBIG EE Share</label><input type="number" step="0.01" class="form-control" name="pagibig_ee_share" id="pagibig_ee_share" value="0"></div>
                                <div class="col-md-4"><label class="form-label">SSS ER Share (with EC Contribution and MPF)</label><input type="number" step="0.01" class="form-control" name="sss_er_share" id="sss_er_share" value="0"></div>
                                <div class="col-md-4"><label class="form-label">PhilHealth ER Share</label><input type="number" step="0.01" class="form-control" name="philhealth_er_share" id="philhealth_er_share" value="0"></div>
                                <div class="col-md-4"><label class="form-label">Pag-IBIG ER Share</label><input type="number" step="0.01" class="form-control" name="pagibig_er_share" id="pagibig_er_share" value="0"></div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabAttachments">
                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-paperclip"></i> Attachments</div>
                            <input type="file" class="form-control" name="attachments[]" id="attachments" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">
                            <small class="text-muted d-block mt-2">Allowed files: PDF, image, Word, Excel.</small>
                            <div id="existingAttachmentBox" class="attachment-list mt-3"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-end gap-2 flex-wrap">
                <button type="button" class="btn btn-payroll-light" onclick="clearPayrollForm()"><i class="bi bi-eraser me-1"></i>Clear</button>
                <button type="submit" class="btn btn-outline-primary-green action-footer-btn payroll-save-btn" data-save-mode="new"><i class="bi bi-save me-1"></i>Save & New</button>
                <button type="submit" class="btn btn-payroll-green action-footer-btn payroll-save-btn" data-save-mode="close"><i class="bi bi-save me-1"></i>Save & Close</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="payrollViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-eye"></i> Payroll Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="payrollViewBody"></div>
        <div class="modal-footer justify-content-end gap-2 flex-wrap">
            <button class="btn btn-payroll-green" type="button" id="payrollViewEditBtn" onclick="return openPayrollEditFromDetails(event);"><i class="bi bi-pencil me-1"></i>Edit</button>
            <button class="btn btn-danger" type="button" id="payrollViewDeleteBtn"><i class="bi bi-trash me-1"></i>Delete</button>
            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
        </div>
    </div></div>
</div>

<div class="modal fade" id="attachmentPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-paperclip"></i> <span id="previewTitle">Attachment Preview</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><iframe class="payroll-preview-frame" id="previewFrame"></iframe></div>
    </div></div>
</div>

<div class="mobile-nav" id="mobileNav">
    <ul class="nav">
        <li class="nav-item"><a href="branchdashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a></li>
        <li class="nav-item"><a href="current_inventory.php" class="nav-link"><i class="bi bi-shop"></i><span>Warehouse</span></a></li>
        <li class="nav-item"><a href="drivers.php" class="nav-link"><i class="bi bi-people-fill"></i><span>Users</span></a></li>
        <li class="nav-item"><a href="employeelist.php" class="nav-link active"><i class="bi bi-people-fill"></i><span>Employee<br>List</span></a></li>
        <li class="nav-item"><a href="employee.php" class="nav-link"><i class="bi bi-clock-history"></i><span>Employee<br>Attendance</span></a></li>
        <li class="nav-item"><a href="#" class="nav-link logout-link" onclick="confirmLogout(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></li>
    </ul>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>

const dtrRecordMap = <?= json_encode($dtrRecordMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const employeeModal = () => new bootstrap.Modal(document.getElementById('employeeModal'));

function resetEmployeeFormTabs(){
    const firstTab = document.getElementById('personal-info-tab');
    if (firstTab && window.bootstrap) {
        bootstrap.Tab.getOrCreateInstance(firstTab).show();
    }
}

const dtrModal = () => new bootstrap.Modal(document.getElementById('dtrModal'));
const employeeDetailsModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('employeeDetailsModal'));
const employeeJobProfileModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('employeeJobProfileModal'));
let currentEmployeeDetails = null;

document.addEventListener('DOMContentLoaded', function(){
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const desktopBtn = document.getElementById('desktopToggleBtn');
    if (mobileBtn) mobileBtn.addEventListener('click', toggleSidebar);
    if (desktopBtn) desktopBtn.addEventListener('click', toggleSidebar);
    restoreSidebarState();

    const employeeFormEl = document.getElementById('employeeForm');
    const dtrFormEl = document.getElementById('dtrForm');
    if (employeeFormEl) employeeFormEl.addEventListener('submit', submitForm);
    if (dtrFormEl) dtrFormEl.addEventListener('submit', submitForm);
    document.querySelectorAll('.dtr-row-date').forEach(input => input.addEventListener('change', () => refreshDtrRowState(input.closest('.dtr-entry-row'))));
    document.querySelectorAll('#firstName,#middleName,#lastName').forEach(input => input.addEventListener('input', updateFullNameHidden));
    document.querySelectorAll('.schedule-input').forEach(input => input.addEventListener('change', function(){ calculateWorkHours(); updateProvisionPreview(); }));
    document.querySelectorAll('.provision-input').forEach(input => input.addEventListener('input', updateProvisionPreview));
    const payClassificationPreview = document.getElementById('payClassification');
    if (payClassificationPreview) payClassificationPreview.addEventListener('change', updateProvisionPreview);
    refreshAllDtrRowStates();
    updateProvisionPreview();
});

document.addEventListener('DOMContentLoaded', function () {
    updateEmployeesTaskBadge();
});


document.addEventListener('shown.bs.tab', function(event){
    const targetSelector = event.target ? event.target.getAttribute('data-bs-target') : '';
    if (!targetSelector) return;
    const pane = document.querySelector(targetSelector);
    if (pane && pane.closest('#employeeModal')) pane.scrollTop = 0;
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
    updateEmployeesTaskBadge();
    if (arrow) arrow.style.transform = target.classList.contains('show') ? 'translateY(-50%) rotate(180deg)' : 'translateY(-50%) rotate(0deg)';
    return false;

    updateEmployeesTaskBadge();
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
function money(value){
    const n = Number(value || 0);
    return n.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function updateFullNameHidden(){
    const first = document.getElementById('firstName')?.value || '';
    const middle = document.getElementById('middleName')?.value || '';
    const last = document.getElementById('lastName')?.value || '';
    const hidden = document.getElementById('employeeName');
    if (hidden) hidden.value = [first, middle, last].filter(Boolean).join(' ');
}
function calculateWorkHours(){
    const start = document.getElementById('startOfWork')?.value;
    const end = document.getElementById('endOfWork')?.value;
    const total = document.getElementById('totalWorkHours');
    if (!start || !end || !total) return;
    const s = new Date('2000-01-01T' + start + ':00');
    let e = new Date('2000-01-01T' + end + ':00');
    if (e < s) e = new Date(e.getTime() + 86400000);
    let hours = (e - s) / 3600000;
    if (start < '13:00' && end > '12:00') hours -= 1;
    total.value = Math.max(0, hours).toFixed(2);
}
function updateProvisionPreview(){
    const basic = Number(document.getElementById('basicPay')?.value || 0);
    const payClassification = document.getElementById('payClassification')?.value || 'Monthly';
    const workdays = Number(document.getElementById('totalWorkdaysPerMonth')?.value || 26) || 26;
    const workhours = Number(document.getElementById('totalWorkHours')?.value || 8) || 8;
    const monthlyBasicPay = resolveMonthlyRateFromPay(basic, payClassification, workdays, workhours);
    const monthly = monthlyBasicPay / 12;
    const daily = monthly / workdays;
    const hourly = daily / workhours;
    const day = hourly * workhours;
    const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = money(value); };
    set('provBasicPay', monthlyBasicPay); set('provMonthly', monthly); set('provWorkdays', workdays); set('provDaily', daily); set('provWorkhours', workhours); set('provHourly', hourly); set('provRegularHours', workhours); set('provDay', day);
}

const employeeBranchOptionsHtml = `<?php foreach ($branches as $br): ?><option value="<?= (int)$br['branch_id'] ?>"><?= htmlspecialchars($br['branch_name'], ENT_QUOTES) ?></option><?php endforeach; ?>`;
const employeeDefaultBranchId = '<?= (int)$branch_id ?>';
const employeeDefaultBranchName = `<?= htmlspecialchars($currentBranchName, ENT_QUOTES) ?>`;
const employeeCanViewAllBranches = <?= $view_all_branches ? 'true' : 'false' ?>;
function classificationOptionsHtml(selected=''){
    const opts = ['', 'Regular', 'Probationary', 'Contractual', 'Part-time', 'Project-based'];
    return opts.map(v => '<option value="'+attrValue(v)+'" '+(String(selected||'')===v?'selected':'')+'>'+(v?escapeHtml(v):'Select Classification')+'</option>').join('');
}
function syncCurrentJobHidden(){
    const rows = Array.from(document.querySelectorAll('#jobHistoryRows .job-history-row'));
    if (!rows.length) return;
    let selected = rows.find(row => row.querySelector('.job-present-check')?.checked) || rows[0];
    const get = selector => selected.querySelector(selector)?.value || '';
    const set = (id, value) => { const el = document.getElementById(id); if (el) el.value = value || ''; };
    set('startDate', get('.job-start-date'));
    set('businessUnit', get('.job-business-unit'));
    set('department', get('.job-department'));
    set('position', get('.job-position'));
    set('employmentClassification', get('.job-classification'));
    set('jobDescription', get('.job-description'));
    set('basicPay', get('.job-basic-pay'));
    set('payClassification', get('.job-pay-classification'));
    set('paymentMethod', get('.job-payment-method'));
    set('accountNumber', get('.job-account-number'));
    set('startOfWork', get('.job-start-of-work'));
    set('endOfWork', get('.job-end-of-work'));
    set('totalWorkHours', get('.job-total-work-hours'));
    set('restDay', get('.job-rest-day'));
    set('totalRestDays', get('.job-total-rest-days'));
    set('totalWorkdaysPerMonth', get('.job-total-workdays'));
    updateProvisionPreview();
}
function refreshJobRows(){
    const rows = Array.from(document.querySelectorAll('#jobHistoryRows .job-history-row'));
    rows.forEach((row, index) => {
        const present = row.querySelector('.job-present-check')?.checked;
        row.classList.toggle('is-present', !!present);
        const badge = row.querySelector('.job-present-label');
        if (badge) badge.innerHTML = present ? '<span class="job-present-badge"><i class="bi bi-check-circle"></i>Present</span>' : '<span class="text-muted small">Past Position</span>';
        row.querySelectorAll('input, select, textarea').forEach(input => {
            input.name = input.name.replace(/\[\d+\]/, '['+index+']');
        });
    });
    syncCurrentJobHidden();
}
function addJobRow(job={}){
    const wrap = document.getElementById('jobHistoryRows');
    if (!wrap) return;
    const index = wrap.querySelectorAll('.job-history-row').length;
    const branchValue = String(job.branch_id || employeeDefaultBranchId);
    const branchName = job.branch_name || employeeDefaultBranchName || '';
    const row = document.createElement('div');
    row.className = 'job-history-row';
    const hiddenBranch = '<input type="hidden" class="job-branch-id" name="job_branch_id['+index+']" value="'+attrValue(branchValue)+'">';
    row.innerHTML =
        '<div class="d-flex justify-content-between align-items-center gap-2 mb-2">' +
            '<div class="job-present-label"></div>' +
            '<button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest(\'.job-history-row\').remove(); refreshJobRows();"><i class="bi bi-trash"></i></button>' +
        '</div>' +
        '<div class="row g-3">' +
            '<div class="col-md-3"><label class="form-label">Start Date</label><input type="date" class="form-control job-start-date" name="job_start_date['+index+']" value="'+attrValue(job.start_date || '')+'"></div>' +
            '<div class="col-md-3"><label class="form-label">End Date</label><input type="date" class="form-control job-end-date" name="job_end_date['+index+']" value="'+attrValue(job.end_date || '')+'"></div>' +
            '<div class="col-md-3"><label class="form-label">Business Unit</label><input type="text" class="form-control job-business-unit" name="job_business_unit['+index+']" value="'+attrValue(job.business_unit || '')+'"></div>' +
            '<div class="col-md-3"><label class="form-label">Branch</label><input type="text" class="form-control job-branch-name" name="job_branch_name['+index+']" value="'+attrValue(branchName)+'" placeholder="Type branch">'+hiddenBranch+'</div>' +
            '<div class="col-md-3"><label class="form-label">Department</label><input type="text" class="form-control job-department" name="job_department['+index+']" value="'+attrValue(job.department || '')+'"></div>' +
            '<div class="col-md-3"><label class="form-label">Position <span class="text-danger">*</span></label><input type="text" class="form-control job-position" name="job_position['+index+']" value="'+attrValue(job.position || '')+'"></div>' +
            '<div class="col-md-3"><label class="form-label">Classification</label><select class="form-select job-classification" name="job_employment_classification['+index+']">'+classificationOptionsHtml(job.employment_classification || '')+'</select></div>' +
            '<div class="col-md-3"><label class="form-label">Present Position?</label><div class="form-check mt-2"><input class="form-check-input job-present-check" type="checkbox" name="job_is_present['+index+']" '+((job.is_present == 1 || job.is_present === true)?'checked':'')+'><label class="form-check-label">Set as present</label></div></div>' +
            '<div class="col-12"><div class="form-section-title mt-2 mb-1"><i class="bi bi-card-text"></i>Main Job Description</div><textarea class="form-control job-description" name="job_description_history['+index+']" rows="2" placeholder="Main description of Job / responsibilities">'+escapeHtml(job.job_description || '')+'</textarea></div>' +
            '<div class="col-12"><div class="form-section-title mt-2 mb-1"><i class="bi bi-cash-stack"></i>Pay / Rate</div></div>' +
            '<div class="col-md-3"><label class="form-label">Basic Pay</label><input type="number" step="0.01" class="form-control job-basic-pay" name="job_basic_pay['+index+']" value="'+attrValue(job.basic_pay || '')+'"></div>' +
            '<div class="col-md-3"><label class="form-label">Pay Classification</label><select class="form-select job-pay-classification" name="job_pay_classification['+index+']"><option value="Monthly" '+((job.pay_classification || '')==='Monthly'?'selected':'')+'>Monthly</option><option value="Daily" '+((job.pay_classification || '')==='Daily'?'selected':'')+'>Daily</option><option value="Hourly" '+((job.pay_classification || '')==='Hourly'?'selected':'')+'>Hourly</option></select></div>' +
            '<div class="col-md-3"><label class="form-label">Payment Method</label><select class="form-select job-payment-method" name="job_payment_method['+index+']"><option value="Cash" '+((job.payment_method || '')==='Cash'?'selected':'')+'>Cash</option><option value="Bank Transfer" '+((job.payment_method || '')==='Bank Transfer'?'selected':'')+'>Bank Transfer</option><option value="Check" '+((job.payment_method || '')==='Check'?'selected':'')+'>Check</option><option value="Payroll Account" '+((job.payment_method || '')==='Payroll Account'?'selected':'')+'>Payroll Account</option></select></div>' +
            '<div class="col-md-3"><label class="form-label">Account Number</label><input type="text" class="form-control job-account-number" name="job_account_number['+index+']" value="'+attrValue(job.account_number || '')+'"></div>' +
            '<div class="col-12"><div class="form-section-title mt-2 mb-1"><i class="bi bi-calendar-week"></i>Schedule</div></div>' +
            '<div class="col-md-2"><label class="form-label">Start of Work</label><input type="time" class="form-control job-start-of-work" name="job_start_of_work['+index+']" value="'+attrValue((job.start_of_work || '').slice(0,5))+'"></div>' +
            '<div class="col-md-2"><label class="form-label">End of Work</label><input type="time" class="form-control job-end-of-work" name="job_end_of_work['+index+']" value="'+attrValue((job.end_of_work || '').slice(0,5))+'"></div>' +
            '<div class="col-md-2"><label class="form-label">Total Work Hours</label><input type="number" step="0.01" class="form-control job-total-work-hours" name="job_total_work_hours['+index+']" value="'+attrValue(job.total_work_hours || '')+'"></div>' +
            '<div class="col-md-2"><label class="form-label">Rest Day</label><input type="text" class="form-control job-rest-day" name="job_rest_day['+index+']" value="'+attrValue(job.rest_day || '')+'"></div>' +
            '<div class="col-md-2"><label class="form-label">Total Rest Days</label><input type="number" class="form-control job-total-rest-days" name="job_total_rest_days['+index+']" value="'+attrValue(job.total_rest_days || '')+'"></div>' +
            '<div class="col-md-2"><label class="form-label">Workdays / Month</label><input type="number" step="0.01" class="form-control job-total-workdays" name="job_total_workdays_per_month['+index+']" value="'+attrValue(job.total_workdays_per_month || '')+'"></div>' +
        '</div>';
    wrap.appendChild(row);
    row.querySelector('.job-present-check')?.addEventListener('change', function(){
        if (this.checked) {
            document.querySelectorAll('#jobHistoryRows .job-present-check').forEach(cb => { if (cb !== this) cb.checked = false; });
            const end = row.querySelector('.job-end-date');
            if (end) end.value = '';
        }
        refreshJobRows();
    });
    row.querySelectorAll('input, select, textarea').forEach(input => input.addEventListener('input', syncCurrentJobHidden));
    row.querySelectorAll('input, select, textarea').forEach(input => input.addEventListener('change', syncCurrentJobHidden));
    if (wrap.querySelectorAll('.job-history-row').length === 1 && !row.querySelector('.job-present-check')?.checked) {
        const cb = row.querySelector('.job-present-check');
        if (cb) cb.checked = true;
    }
    refreshJobRows();
}
function renderJobHistoryTable(jobs){
    if (!Array.isArray(jobs) || !jobs.length) return '<div class="empty-state-table text-center py-4"><i class="bi bi-briefcase"></i><h5>No Job History Found</h5><p>No recorded job positions yet.</p></div>';
    const sorted = jobs.slice().sort((a,b) => {
        if (Number(a.is_present||0) !== Number(b.is_present||0)) return Number(b.is_present||0) - Number(a.is_present||0);
        return String(b.start_date || '0000-00-00').localeCompare(String(a.start_date || '0000-00-00'));
    });
    window.employeeDetailsJobHistory = sorted;
    return '<div class="job-history-helper"><i class="bi bi-info-circle"></i> Click a job row to view the job profile modal, or use Edit to update only that row.</div>' +
        '<div class="job-history-table-wrap"><table class="job-history-table"><thead><tr><th>Start Date</th><th>End Date</th><th>Position</th><th>Job Description</th><th>Total Work Hours</th><th>Action</th></tr></thead><tbody>' +
        sorted.map((j, index) => {
            const endDate = Number(j.is_present||0)===1 ? '<span class="present-pill"><i class="bi bi-check-circle"></i>Present</span>' : formatDateDisplay(j.end_date);
            return '<tr class="job-detail-row" tabindex="0" role="button" data-job-index="'+index+'" onclick="showEmployeeJobDetails('+index+')" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();showEmployeeJobDetails('+index+');}">' +
                '<td>'+formatDateDisplay(j.start_date)+'</td>' +
                '<td>'+endDate+'</td>' +
                '<td><strong>'+displayValue(j.position)+'</strong></td>' +
                '<td class="job-desc-cell">'+displayValue(j.job_description)+'</td>' +
                '<td>'+displayValue(j.total_work_hours)+'</td>' +
                '<td class="job-row-actions"><button type="button" class="btn btn-outline-primary-green btn-sm job-row-edit-btn" onclick="event.stopPropagation(); openEmployeeJobRowEditModal('+index+');"><i class="bi bi-pencil-square me-1"></i>Edit</button></td>' +
            '</tr>';
        }).join('') + '</tbody></table></div>';
}
function computeJobProfileRates(job){
    const workdays = Number(job.total_workdays_per_month || 26) || 26;
    const workhours = Number(job.total_work_hours || 8) || 8;
    const monthlyRate = resolveMonthlyRateFromPay(job.basic_pay || job.monthly_rate || 0, job.pay_classification || 'Monthly', workdays, workhours);
    const dailyRate = monthlyRate > 0 ? monthlyRate / workdays : 0;
    const hourlyRate = dailyRate > 0 && workhours > 0 ? dailyRate / workhours : 0;
    return {monthlyRate, dailyRate, hourlyRate};
}
function jobProfileTable(headers, values, valueClass){
    const safeHeaders = headers.map(function(header){
        return '<th>' + escapeHtml(header || '') + '</th>';
    }).join('');
    const safeValues = values.map(function(value){
        return '<td class="job-profile-value ' + (valueClass || '') + '">' + displayValue(value) + '</td>';
    }).join('');
    return '<table class="job-profile-table"><thead><tr>' + safeHeaders + '</tr></thead><tbody><tr>' + safeValues + '</tr></tbody></table>';
}

function showEmployeeJobDetails(index){
    const jobs = Array.isArray(window.employeeDetailsJobHistory) ? window.employeeDetailsJobHistory : [];
    const job = jobs[index];
    if (!job) return;
    document.querySelectorAll('.job-detail-row').forEach(row => row.classList.remove('active'));
    const activeRow = document.querySelector('.job-detail-row[data-job-index="'+index+'"]');
    if (activeRow) activeRow.classList.add('active');

    const status = Number(job.is_present||0)===1 ? '<span class="present-pill"><i class="bi bi-check-circle"></i>Present</span>' : '<span class="past-pill"><i class="bi bi-clock-history"></i>Past Employment</span>';
    const period = formatDateDisplay(job.start_date) + ' to ' + (Number(job.is_present||0)===1 ? 'Present' : formatDateDisplay(job.end_date));
    const rates = computeJobProfileRates(job);

    const title = document.getElementById('employeeJobProfileTitle');
    if (title) title.innerHTML = '<i class="bi bi-briefcase-fill me-2"></i>Job Profile';

    const summary = document.getElementById('employeeJobProfileSummary');
    if (summary) {
        summary.innerHTML = '<div><h6>'+displayValue(job.position)+'</h6><p>'+period+'</p></div><div>'+status+'</div>';
    }

    const jobsGrid = document.getElementById('employeeJobProfileJobsGrid');
    if (jobsGrid) {
        jobsGrid.innerHTML = jobProfileTable(
            ['Job Description', 'Business Unit', 'Department', 'Branch'],
            [job.job_description, job.business_unit, job.department, (job.branch_name || '')]
        );
    }

    const rateGrid = document.getElementById('employeeJobProfileRateGrid');
    if (rateGrid) {
        rateGrid.innerHTML = jobProfileTable(
            ['Monthly Rate', 'Daily Rate', 'Hourly Rate'],
            ['₱' + money(rates.monthlyRate), '₱' + money(rates.dailyRate), '₱' + money(rates.hourlyRate)],
            'rate-value'
        );
    }

    const scheduleGrid = document.getElementById('employeeJobProfileScheduleGrid');
    if (scheduleGrid) {
        scheduleGrid.innerHTML = jobProfileTable(
            ['Effectivity Date', 'Start Work', 'End Work', 'Total Work Hours', 'Rest Day'],
            [formatDateDisplay(job.start_date), (job.start_of_work || '').slice(0,5), (job.end_of_work || '').slice(0,5), job.total_work_hours, job.rest_day]
        );
    }

    const openJobProfileModal = () => {
        const jobModalEl = document.getElementById('employeeJobProfileModal');
        if (!jobModalEl) return;
        bootstrap.Modal.getOrCreateInstance(jobModalEl).show();
    };

    const detailsModalEl = document.getElementById('employeeDetailsModal');
    if (detailsModalEl && detailsModalEl.classList.contains('show')) {
        const detailsInstance = bootstrap.Modal.getOrCreateInstance(detailsModalEl);
        detailsModalEl.addEventListener('hidden.bs.modal', function handleDetailsHidden(){
            detailsModalEl.removeEventListener('hidden.bs.modal', handleDetailsHidden);
            openJobProfileModal();
        }, { once: true });
        detailsInstance.hide();
    } else {
        openJobProfileModal();
    }
}

function addAllowanceRow(name='', amount=''){
    const wrap = document.getElementById('allowanceRows');
    if (!wrap) return;
    const row = document.createElement('div');
    row.className = 'dynamic-mini-row row g-2 align-items-end';
    row.innerHTML = '<div class="col-md-6"><label class="form-label">Allowance Name</label><input type="text" class="form-control" name="allowance_name[]" value="'+escapeHtml(name)+'"></div><div class="col-md-5"><label class="form-label">Amount</label><input type="number" step="0.01" class="form-control" name="allowance_amount[]" value="'+escapeHtml(amount)+'"></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100" onclick="this.closest(\'.dynamic-mini-row\').remove()"><i class="bi bi-trash"></i></button></div>';
    wrap.appendChild(row);
}
function addGovernmentRow(name='', number=''){
    const wrap = document.getElementById('governmentExtraRows');
    if (!wrap) return;
    const row = document.createElement('div');
    row.className = 'dynamic-mini-row row g-2 align-items-end';
    row.innerHTML = '<div class="col-md-5"><label class="form-label">Registration Name</label><input type="text" class="form-control" name="gov_name[]" value="'+escapeHtml(name)+'"></div><div class="col-md-6"><label class="form-label">Registration Number</label><input type="text" class="form-control" name="gov_number[]" value="'+escapeHtml(number)+'"></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100" onclick="this.closest(\'.dynamic-mini-row\').remove()"><i class="bi bi-trash"></i></button></div>';
    wrap.appendChild(row);
}
function detailItem(label, value){
    return '<div class="profile-item"><span>' + escapeHtml(label) + '</span><strong>' + (String(value ?? '').includes('<') ? (value || '-') : displayValue(value)) + '</strong></div>';
}
function renderDetailsGrid(id, items){
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = items.map(item => detailItem(item[0], item[1])).join('');
}
function attrValue(value){
    return String(value ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function employeeAttachmentExt(filePath){
    const cleanPath = String(filePath || '').split('?')[0].split('#')[0];
    return cleanPath.includes('.') ? cleanPath.split('.').pop().toLowerCase() : '';
}
function employeeAttachmentKind(filePath){
    const ext = employeeAttachmentExt(filePath);
    if (['jpg','jpeg','png','webp','gif','bmp','svg'].includes(ext)) return 'image';
    if (ext === 'pdf') return 'pdf';
    if (['doc','docx'].includes(ext)) return 'doc';
    if (['xls','xlsx','csv'].includes(ext)) return 'xls';
    return 'file';
}
function employeeAttachmentIcon(kind){
    if (kind === 'pdf') return '<i class="bi bi-file-earmark-pdf"></i>';
    if (kind === 'doc') return '<i class="bi bi-file-earmark-word"></i>';
    if (kind === 'xls') return '<i class="bi bi-file-earmark-excel"></i>';
    return '<i class="bi bi-file-earmark-text"></i>';
}
function attachmentLinks(emp){
    const attachments = Array.isArray(emp.attachments) ? emp.attachments : [];
    if (!attachments.length) {
        return '<div class="employee-attachment-empty"><i class="bi bi-paperclip"></i><div class="fw-bold">No uploaded attachments.</div><div class="small">Uploaded employee files will appear here.</div></div>';
    }

    return '<div class="employee-attachment-gallery">' + attachments.map(a => {
        const rawPath = a.file_path || a.path || '';
        const rawName = a.original_name || a.name || 'Attachment';
        const rawType = a.attachment_type || a.type || 'File';
        const filePath = attrValue(rawPath);
        const fileName = attrValue(rawName);
        const fileType = attrValue(rawType);
        const kind = employeeAttachmentKind(rawPath);
        const ext = employeeAttachmentExt(rawPath).toUpperCase() || 'FILE';
        const thumb = kind === 'image'
            ? '<img src="'+filePath+'" alt="'+fileName+'">'
            : '<div class="employee-attachment-file-icon '+kind+'">'+employeeAttachmentIcon(kind)+'</div>';
        return '' +
            '<button type="button" class="employee-attachment-card attachment-preview-btn" data-file-path="'+filePath+'" data-file-name="'+fileName+'" data-file-type="'+fileType+'">' +
                '<div class="employee-attachment-thumb">'+thumb+'</div>' +
                '<div class="employee-attachment-card-body">' +
                    '<div class="employee-attachment-card-type"><i class="bi bi-paperclip"></i>'+escapeHtml(rawType || 'File')+'</div>' +
                    '<div class="employee-attachment-card-name">'+escapeHtml(rawName || 'Attachment')+'</div>' +
                    '<div class="employee-attachment-card-hint">'+(kind === 'image' ? 'Tap to preview image' : (kind === 'pdf' ? 'Tap to preview PDF' : 'Tap to open/download'))+' · '+escapeHtml(ext)+'</div>' +
                '</div>' +
            '</button>';
    }).join('') + '</div>';
}

let employeeAttachmentPreviewModal;

function getOpenEmployeeParentModalId() {
    const modal = document.querySelector('.modal.show:not(#employeeAttachmentPreviewModal)');
    return modal ? modal.id : '';
}

function normalizeEmployeeAttachmentPath(filePath){
    const raw = String(filePath || '').trim();
    if (!raw) return '';
    if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('data:')) return raw;
    return raw.replace(/\\/g, '/');
}

function openEmployeeAttachmentPreview(filePath, fileName, fileType){
    if (!filePath) return;

    const src = normalizeEmployeeAttachmentPath(filePath);
    const cleanName = String(fileName || 'Attachment');
    const ext = employeeAttachmentExt(src);
    const content = document.getElementById('employeeAttachmentPreviewContent');
    const downloadBtn = document.getElementById('employeeAttachmentDownloadBtn');
    const parentModalId = getOpenEmployeeParentModalId();

    if (parentModalId) {
        sessionStorage.setItem('employeeAttachmentReturnModalId', parentModalId);
        const parentModalElement = document.getElementById(parentModalId);
        const parentModal = bootstrap.Modal.getInstance(parentModalElement) || bootstrap.Modal.getOrCreateInstance(parentModalElement);
        parentModal.hide();
    } else {
        sessionStorage.removeItem('employeeAttachmentReturnModalId');
    }

    if (downloadBtn) {
        downloadBtn.href = src || '#';
        downloadBtn.download = cleanName;
        downloadBtn.setAttribute('target', '_blank');
        downloadBtn.setAttribute('rel', 'noopener');
    }

    if (content) {
        content.innerHTML = '<div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div>';

        setTimeout(function(){
            if (['jpg','jpeg','png','gif','webp','bmp','svg'].includes(ext)) {
                const img = document.createElement('img');
                img.src = src;
                img.alt = cleanName;
                img.style.opacity = '0';
                img.onload = function(){ img.style.opacity = '1'; };
                img.onerror = function(){
                    content.innerHTML = '<div class="employee-attachment-preview-empty"><i class="bi bi-exclamation-triangle"></i>Unable to load this image.</div>';
                };
                content.innerHTML = '';
                content.appendChild(img);
            } else if (ext === 'pdf') {
                const embed = document.createElement('embed');
                embed.src = src;
                embed.type = 'application/pdf';
                content.innerHTML = '';
                content.appendChild(embed);
            } else {
                content.innerHTML = '<div class="employee-attachment-preview-empty"><i class="bi bi-info-circle"></i>This file type cannot be previewed directly. Please download to view.</div>';
            }
        }, 80);
    }

    const previewEl = document.getElementById('employeeAttachmentPreviewModal');
    if (!previewEl) return;

    if (!employeeAttachmentPreviewModal) {
        employeeAttachmentPreviewModal = new bootstrap.Modal(previewEl);
    }

    previewEl.removeEventListener('hidden.bs.modal', handleEmployeeAttachmentPreviewHidden);
    previewEl.addEventListener('hidden.bs.modal', handleEmployeeAttachmentPreviewHidden);

    setTimeout(function(){
        employeeAttachmentPreviewModal.show();
    }, parentModalId ? 180 : 0);
}

function handleEmployeeAttachmentPreviewHidden(){
    requestAnimationFrame(function(){
        const content = document.getElementById('employeeAttachmentPreviewContent');
        if (content) {
            content.innerHTML = '<div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div>';
        }

        const returnModalId = sessionStorage.getItem('employeeAttachmentReturnModalId');
        sessionStorage.removeItem('employeeAttachmentReturnModalId');

        if (returnModalId) {
            const returnModalElement = document.getElementById(returnModalId);
            if (returnModalElement) {
                setTimeout(function(){
                    bootstrap.Modal.getOrCreateInstance(returnModalElement).show();
                    if (returnModalId === 'employeeDetailsModal') {
                        const attachmentsTab = document.getElementById('details-attachments-tab');
                        if (attachmentsTab) {
                            setTimeout(function(){
                                bootstrap.Tab.getOrCreateInstance(attachmentsTab).show();
                            }, 120);
                        }
                    }
                    if (!document.body.classList.contains('modal-open')) {
                        document.body.classList.add('modal-open');
                    }
                }, 80);
                return;
            }
        }

        const anyModalOpen = document.querySelector('.modal.show');
        if (!anyModalOpen) {
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    });
}

const employeeAttachmentPreviewModalEl = document.getElementById('employeeAttachmentPreviewModal');
if (employeeAttachmentPreviewModalEl) {
    employeeAttachmentPreviewModalEl.addEventListener('hidden.bs.modal', handleEmployeeAttachmentPreviewHidden);
}

document.addEventListener('click', function(e){
    const btn = e.target.closest('.attachment-preview-btn');
    if (!btn) return;
    e.preventDefault();
    openEmployeeAttachmentPreview(btn.dataset.filePath || '', btn.dataset.fileName || 'Attachment', btn.dataset.fileType || 'File');
});

function toggleEmployeeDetailsAddJob(show){
    const wrap = document.getElementById('employeeDetailsAddJobWrap');
    const form = document.getElementById('employeeDetailsAddJobForm');
    if (!wrap) return;
    if (show) {
        if (form) {
            form.reset();
            const empIdInput = document.getElementById('detailsAddJobEmployeeId');
            if (empIdInput && currentEmployeeDetails) empIdInput.value = currentEmployeeDetails.employee_id || '';
            const presentCheck = document.getElementById('detailsAddJobPresent');
            if (presentCheck) presentCheck.checked = false;
        }
        wrap.classList.remove('d-none');
        setTimeout(function(){
            const firstInput = wrap.querySelector('input[name="job_start_date"]');
            if (firstInput) firstInput.focus();
        }, 80);
    } else {
        wrap.classList.add('d-none');
        if (form) form.reset();
    }
}

function initEmployeeDetailsAddJobForm(){
    const form = document.getElementById('employeeDetailsAddJobForm');
    if (!form || form.dataset.bound === '1') return;
    form.dataset.bound = '1';
    form.addEventListener('submit', function(e){
        e.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        const oldHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
        }
        fetch(window.location.href, {
            method: 'POST',
            body: new FormData(form)
        })
        .then(function(res){ return res.json(); })
        .then(function(data){
            if (!data || !data.success) throw new Error((data && data.message) ? data.message : 'Failed to save job');
            alert(data.message || 'Job added successfully');
            window.location.reload();
        })
        .catch(function(err){
            alert(err.message || 'Failed to save job');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = oldHtml;
            }
        });
    });
}

function showEmployeeDetails(emp){
    currentEmployeeDetails = emp;
    initEmployeeDetailsAddJobForm();
initEmployeeJobRowEditForm();
    toggleEmployeeDetailsAddJob(false);
    const detailsAddJobEmployeeId = document.getElementById('detailsAddJobEmployeeId');
    if (detailsAddJobEmployeeId) detailsAddJobEmployeeId.value = emp.employee_id || '';
    const status = emp.status ? emp.status.charAt(0).toUpperCase() + emp.status.slice(1) : '-';
    const prov = emp.thirteenth_month_provision || {};
    const allowances = Array.isArray(emp.allowances) && emp.allowances.length ? emp.allowances.map(a => escapeHtml(a.allowance_name) + ': ₱' + money(a.allowance_amount)).join('<br>') : '-';
    const extraGov = Array.isArray(emp.government_registrations) && emp.government_registrations.length ? emp.government_registrations.map(g => escapeHtml(g.registration_name) + ': ' + displayValue(g.registration_number)).join('<br>') : '-';

    renderDetailsGrid('employeeDetailsPersonalGrid', [
        ['First Name', emp.first_name],
        ['Middle Name', emp.middle_name],
        ['Last Name', emp.last_name],
        ['Birthday', formatDateDisplay(emp.birthday)],
        ['Email Address', emp.email],
        ['Phone Number', emp.phone_number],
        ['Mobile Number', emp.mobile_number || emp.contact_number],
        ['Employee ID Number', emp.employee_id_number],
        ['Biometrics ID Number', emp.biometrics_id_number],
        ['Status', status]
    ]);

    renderDetailsGrid('employeeDetailsGovernmentGrid', [
        ['SSS Number', emp.sss],
        ['PhilHealth Number', emp.philhealth],
        ['Pag-IBIG Number', emp.pagibig],
        ['TIN', emp.tin],
        ['Other Registrations', extraGov]
    ]);

    const jobsHistory = Array.isArray(emp.job_history) && emp.job_history.length ? emp.job_history : [{
        start_date: emp.start_date,
        end_date: '',
        business_unit: emp.business_unit,
        branch_name: emp.branch_name || '<?= htmlspecialchars($branch_name, ENT_QUOTES) ?>',
        department: emp.department,
        position: emp.position,
        job_description: emp.job_description,
        employment_classification: emp.employment_classification,
        basic_pay: emp.basic_pay,
        pay_classification: emp.pay_classification,
        payment_method: emp.payment_method,
        account_number: emp.account_number,
        start_of_work: emp.start_of_work,
        end_of_work: emp.end_of_work,
        total_work_hours: emp.total_work_hours,
        rest_day: emp.rest_day,
        total_rest_days: emp.total_rest_days,
        total_workdays_per_month: emp.total_workdays_per_month,
        is_present: 1
    }];
    const jobsGrid = document.getElementById('employeeDetailsJobsGrid');
    if (jobsGrid) jobsGrid.innerHTML = renderJobHistoryTable(jobsHistory);

    renderDetailsGrid('employeeDetailsPaymentGrid', [
        ['Basic Pay', '₱' + money(emp.basic_pay)],
        ['Allowances', allowances],
        ['Pay Classification', emp.pay_classification],
        ['Payment Method', emp.payment_method],
        ['Account Number', emp.account_number]
    ]);

    renderDetailsGrid('employeeDetailsScheduleGrid', [
        ['Start of Work', emp.start_of_work],
        ['End of Work', emp.end_of_work],
        ['Total Work Hours', emp.total_work_hours],
        ['Rest Day', emp.rest_day],
        ['Total Rest Days', emp.total_rest_days],
        ['Total Workdays per Month', emp.total_workdays_per_month]
    ]);

    renderDetailsGrid('employeeDetailsMandatoriesGrid', [
        ['With SSS?', emp.with_sss == 1 ? 'Yes' : 'No'],
        ['With PhilHealth?', emp.with_philhealth == 1 ? 'Yes' : 'No'],
        ['With Pag-IBIG?', emp.with_pagibig == 1 ? 'Yes' : 'No'],
        ['Remits Withholding Tax?', emp.remits_withholding_tax == 1 ? 'Yes' : 'No']
    ]);

    const provisionCard = document.getElementById('employeeDetailsProvisionCard');
    if (provisionCard) {
        const workdays = Number(emp.total_workdays_per_month ?? prov.total_workdays ?? 26) || 26;
        const workhours = Number(emp.total_work_hours ?? prov.total_workhours ?? 8) || 8;
        // FIX: Display the monthly equivalent, not the raw daily basic pay, when Pay Classification is Daily.
        const basic = resolveMonthlyRateFromPay(emp.basic_pay ?? prov.basic_pay_monthly ?? 0, emp.pay_classification || 'Monthly', workdays, workhours);
        const monthly = basic / 12;
        const daily = workdays > 0 ? monthly / workdays : 0;
        const hourly = workhours > 0 ? daily / workhours : 0;
        const regular = Number(prov.regular_hours_worked ?? workhours);
        const dayProvision = hourly * regular;
        provisionCard.innerHTML =
            '<div class="details-provision-row"><span>Basic Pay (Monthly)</span><strong>₱' + money(basic) + '</strong></div>' +
            '<div class="details-provision-row"><span>Divided by</span><strong>12.00</strong></div>' +
            '<div class="details-provision-row"><span>Monthly Provision</span><strong>₱' + money(monthly) + '</strong></div>' +
            '<div class="details-provision-row"><span>Divided by Total Workdays</span><strong>' + money(workdays) + '</strong></div>' +
            '<div class="details-provision-row"><span>Daily Provision</span><strong>₱' + money(daily) + '</strong></div>' +
            '<div class="details-provision-row"><span>Divided by Total Workhours</span><strong>' + money(workhours) + '</strong></div>' +
            '<div class="details-provision-row"><span>Hourly Provision</span><strong>₱' + money(hourly) + '</strong></div>' +
            '<div class="details-provision-row"><span>Multiplied by Total Regular Hours Worked (OT not included)</span><strong>' + money(regular) + '</strong></div>' +
            '<div class="details-provision-row total"><span>13th Month Provision for the day</span><strong>₱' + money(dayProvision) + '</strong></div>';
    }

    const attachmentWrap = document.getElementById('employeeDetailsAttachmentsList');
    if (attachmentWrap) attachmentWrap.innerHTML = attachmentLinks(emp);

    const firstTab = document.getElementById('details-personal-tab');
    if (firstTab && window.bootstrap) bootstrap.Tab.getOrCreateInstance(firstTab).show();

    const editBtn = document.getElementById('employeeDetailsEditBtn');
    if (editBtn) editBtn.onclick = function(){ bootstrap.Modal.getInstance(document.getElementById('employeeDetailsModal'))?.hide(); setTimeout(function(){ editEmployee(currentEmployeeDetails, null); }, 200); };
    const deleteBtn = document.getElementById('employeeDetailsDeleteBtn');
    if (deleteBtn) deleteBtn.onclick = function(){ if (!currentEmployeeDetails || !currentEmployeeDetails.employee_id) return; bootstrap.Modal.getInstance(document.getElementById('employeeDetailsModal'))?.hide(); setTimeout(function(){ deleteEmployee(currentEmployeeDetails.employee_id); }, 200); };
    employeeDetailsModal().show();
}
function clearEmployeeForm(){
    const form = document.getElementById('employeeForm');
    form.reset();
    document.getElementById('employeeId').value = '';
    document.getElementById('employeeStatus').value = 'active';
    const employeeBranchInput = document.getElementById('employeeBranchId');
    if (employeeBranchInput) employeeBranchInput.value = '<?= (int)$branch_id ?>';
    document.getElementById('totalWorkHours').value = '8';
    document.getElementById('totalRestDays').value = '4';
    document.getElementById('totalWorkdaysPerMonth').value = '26';
    document.getElementById('allowanceRows').innerHTML = '';
    document.getElementById('governmentExtraRows').innerHTML = '';
    const jobRows = document.getElementById('jobHistoryRows');
    if (jobRows) { jobRows.innerHTML = ''; addJobRow({branch_id: employeeDefaultBranchId, is_present: 1}); setJobRowsEditMode(null); }
    document.getElementById('employeeModalTitle').innerHTML = '<i class="bi bi-person-plus me-2"></i>Add Employee';
    resetEmployeeFormTabs();
    updateFullNameHidden();
    updateProvisionPreview();
}
function showEmployeeModal(){ clearEmployeeForm(); employeeModal().show(); }
function setJobRowsEditMode(editIndex=null){
    const rows = Array.from(document.querySelectorAll('#jobHistoryRows .job-history-row'));
    rows.forEach((row, index) => {
        const locked = editIndex !== null && index !== Number(editIndex);
        row.classList.toggle('row-edit-only-locked', locked);
        row.classList.toggle('row-edit-only-active', editIndex !== null && index === Number(editIndex));
        row.querySelectorAll('input, textarea').forEach(el => {
            if (locked) el.setAttribute('readonly', 'readonly'); else el.removeAttribute('readonly');
        });
        row.querySelectorAll('select').forEach(el => {
            if (locked) { el.dataset.lockedSelect = '1'; el.setAttribute('tabindex', '-1'); }
            else { delete el.dataset.lockedSelect; el.removeAttribute('tabindex'); }
        });
        row.querySelectorAll('.job-present-check').forEach(el => {
            if (locked) el.dataset.lockedCheckbox = '1'; else delete el.dataset.lockedCheckbox;
        });
    });
}

document.addEventListener('mousedown', function(event){
    const lockedSelect = event.target.closest('select[data-locked-select="1"]');
    if (lockedSelect) event.preventDefault();
    const lockedCheck = event.target.closest('.job-present-check[data-locked-checkbox="1"]');
    if (lockedCheck) event.preventDefault();
}, true);

document.addEventListener('keydown', function(event){
    const lockedSelect = event.target.closest('select[data-locked-select="1"]');
    if (lockedSelect) event.preventDefault();
    const lockedCheck = event.target.closest('.job-present-check[data-locked-checkbox="1"]');
    if (lockedCheck && (event.key === ' ' || event.key === 'Enter')) event.preventDefault();
}, true);

function setJobRowEditValue(id, value){
    const el = document.getElementById(id);
    if (!el) return;
    if (el.type === 'checkbox') el.checked = Number(value || 0) === 1;
    else el.value = value == null ? '' : value;
}
function openEmployeeJobRowEditModal(index){
    if (!currentEmployeeDetails) return;
    const jobs = Array.isArray(currentEmployeeDetails.job_history) && currentEmployeeDetails.job_history.length ? currentEmployeeDetails.job_history : [];
    const job = jobs[Number(index)] || null;
    if (!job || !job.job_id) {
        alert('Hindi ma-edit ang row na ito dahil walang job_id record. Gamitin muna ang full employee edit/save para ma-save ang job history.');
        return;
    }
    const form = document.getElementById('employeeJobRowEditForm');
    if (form) form.reset();
    setJobRowEditValue('editJobRowEmployeeId', currentEmployeeDetails.employee_id || '');
    setJobRowEditValue('editJobRowJobId', job.job_id || '');
    setJobRowEditValue('editJobRowStartDate', job.start_date || '');
    setJobRowEditValue('editJobRowEndDate', job.end_date || '');
    setJobRowEditValue('editJobRowBusinessUnit', job.business_unit || '');
    setJobRowEditValue('editJobRowBranchId', job.branch_id || currentEmployeeDetails.branch_id || '');
    setJobRowEditValue('editJobRowBranchName', job.branch_name || job.branch_name_snapshot || currentEmployeeDetails.branch_name || '');
    setJobRowEditValue('editJobRowDepartment', job.department || '');
    setJobRowEditValue('editJobRowPosition', job.position || '');
    setJobRowEditValue('editJobRowClassification', job.employment_classification || '');
    setJobRowEditValue('editJobRowPresent', job.is_present || 0);
    setJobRowEditValue('editJobRowDescription', job.job_description || '');
    setJobRowEditValue('editJobRowBasicPay', job.basic_pay || 0);
    setJobRowEditValue('editJobRowPayClassification', job.pay_classification || 'Monthly');
    setJobRowEditValue('editJobRowPaymentMethod', job.payment_method || 'Cash');
    setJobRowEditValue('editJobRowAccountNumber', job.account_number || '');
    setJobRowEditValue('editJobRowStartWork', job.start_of_work ? String(job.start_of_work).slice(0,5) : '');
    setJobRowEditValue('editJobRowEndWork', job.end_of_work ? String(job.end_of_work).slice(0,5) : '');
    setJobRowEditValue('editJobRowTotalWorkHours', job.total_work_hours || 8);
    setJobRowEditValue('editJobRowRestDay', job.rest_day || '');
    setJobRowEditValue('editJobRowTotalRestDays', job.total_rest_days || 0);
    setJobRowEditValue('editJobRowWorkdaysPerMonth', job.total_workdays_per_month || 26);

    const detailsModalEl = document.getElementById('employeeDetailsModal');
    const showModal = function(){ bootstrap.Modal.getOrCreateInstance(document.getElementById('employeeJobRowEditModal')).show(); };
    if (detailsModalEl && detailsModalEl.classList.contains('show')) {
        detailsModalEl.addEventListener('hidden.bs.modal', function handleHidden(){
            detailsModalEl.removeEventListener('hidden.bs.modal', handleHidden);
            showModal();
        }, { once: true });
        bootstrap.Modal.getOrCreateInstance(detailsModalEl).hide();
    } else {
        showModal();
    }
}
function editEmployeeJobRow(index){
    openEmployeeJobRowEditModal(index);
}
function initEmployeeJobRowEditForm(){
    const form = document.getElementById('employeeJobRowEditForm');
    if (!form || form.dataset.bound === '1') return;
    form.dataset.bound = '1';
    form.addEventListener('submit', function(e){
        e.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        const oldHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Updating...';
        }
        fetch(window.location.href, { method: 'POST', body: new FormData(form) })
        .then(function(res){ return res.json(); })
        .then(function(data){
            if (!data || !data.success) throw new Error((data && data.message) ? data.message : 'Failed to update job row');
            alert(data.message || 'Job row updated successfully');
            window.location.reload();
        })
        .catch(function(err){
            alert(err.message || 'Failed to update job row');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = oldHtml;
            }
        });
    });
}



function editEmployee(emp, editableJobIndex=null){
    clearEmployeeForm();
    const set = (id, value) => { const el = document.getElementById(id); if (el) el.value = value || ''; };
    set('employeeId', emp.employee_id); set('firstName', emp.first_name); set('middleName', emp.middle_name); set('lastName', emp.last_name);
    if ((!emp.first_name && !emp.last_name) && emp.employee_name) { const parts = String(emp.employee_name).split(' '); set('firstName', parts.shift() || ''); set('lastName', parts.join(' ')); }
    set('employeeEmail', emp.email); set('birthday', emp.birthday); set('phoneNumber', emp.phone_number); set('mobileNumber', emp.mobile_number || emp.contact_number); set('employeeIdNumber', emp.employee_id_number); set('biometricsIdNumber', emp.biometrics_id_number);
    set('tin', emp.tin); set('philhealth', emp.philhealth); set('sss', emp.sss); set('pagibig', emp.pagibig);
    set('startDate', emp.start_date); set('businessUnit', emp.business_unit); set('department', emp.department); set('position', emp.position); set('jobDescription', emp.job_description); set('employmentClassification', emp.employment_classification);
    const jobRows = document.getElementById('jobHistoryRows');
    if (jobRows) {
        jobRows.innerHTML = '';
        const jobs = Array.isArray(emp.job_history) && emp.job_history.length ? emp.job_history : [{start_date: emp.start_date, business_unit: emp.business_unit, branch_id: emp.branch_id, department: emp.department, position: emp.position, job_description: emp.job_description, employment_classification: emp.employment_classification, basic_pay: emp.basic_pay, pay_classification: emp.pay_classification, payment_method: emp.payment_method, account_number: emp.account_number, start_of_work: emp.start_of_work, end_of_work: emp.end_of_work, total_work_hours: emp.total_work_hours, rest_day: emp.rest_day, total_rest_days: emp.total_rest_days, total_workdays_per_month: emp.total_workdays_per_month, is_present: 1}];
        jobs.forEach(j => addJobRow(j));
        refreshJobRows();
        setJobRowsEditMode(editableJobIndex);
    }
    set('basicPay', emp.basic_pay); set('payClassification', emp.pay_classification || 'Monthly'); set('paymentMethod', emp.payment_method || 'Cash'); set('accountNumber', emp.account_number);
    set('startOfWork', emp.start_of_work ? String(emp.start_of_work).slice(0,5) : ''); set('endOfWork', emp.end_of_work ? String(emp.end_of_work).slice(0,5) : ''); set('totalWorkHours', emp.total_work_hours || 8); set('restDay', emp.rest_day); set('totalRestDays', emp.total_rest_days || 0); set('totalWorkdaysPerMonth', emp.total_workdays_per_month || 26);
    document.getElementById('withSss').checked = emp.with_sss == 1; document.getElementById('withPhilhealth').checked = emp.with_philhealth == 1; document.getElementById('withPagibig').checked = emp.with_pagibig == 1; document.getElementById('remitsWithholdingTax').checked = emp.remits_withholding_tax == 1; document.getElementById('employeeStatus').value = emp.status || 'active';
    (emp.allowances || []).forEach(a => addAllowanceRow(a.allowance_name || '', a.allowance_amount || ''));
    (emp.government_registrations || []).forEach(g => addGovernmentRow(g.registration_name || '', g.registration_number || ''));
    updateFullNameHidden(); updateProvisionPreview();
    document.getElementById('employeeModalTitle').innerHTML = editableJobIndex !== null ? '<i class="bi bi-pencil-square me-2"></i>Edit Job Row Only' : '<i class="bi bi-pencil me-2"></i>Edit Employee';
    employeeModal().show();
}

function getDtrRecordForRow(row){
    if (!row) return null;
    const employeeId = row.dataset.employeeId || row.querySelector('.dtr-employee-checkbox')?.value || '';
    const dateValue = row.querySelector('.dtr-row-date')?.value || '';
    return employeeId && dateValue && dtrRecordMap[employeeId] ? (dtrRecordMap[employeeId][dateValue] || null) : null;
}
function refreshDtrRowState(row){
    if (!row) return;
    const checkbox = row.querySelector('.dtr-employee-checkbox');
    const startInput = row.querySelector('.dtr-row-start');
    const endInput = row.querySelector('.dtr-row-end');
    const statusBadge = row.querySelector('.dtr-row-status');
    const record = getDtrRecordForRow(row);

    if (!record) {
        if (startInput) {
            startInput.disabled = false;
            startInput.readOnly = false;
            startInput.placeholder = '';
            startInput.title = '';
        }
        if (endInput) {
            endInput.disabled = false;
            endInput.readOnly = false;
            endInput.title = '';
        }
        if (checkbox) checkbox.disabled = false;
        if (statusBadge) {
            statusBadge.className = 'status-badge status-active dtr-row-status';
            statusBadge.textContent = 'Ready';
        }
        return;
    }

    if (startInput) {
        startInput.value = '';
        startInput.disabled = true;
        startInput.readOnly = true;
        startInput.placeholder = record.start_time || 'Already timed in';
        startInput.title = 'Time In already exists for this date.';
    }

    if (record.is_open) {
        if (endInput) {
            endInput.disabled = false;
            endInput.readOnly = false;
            endInput.title = 'Time Out is allowed for the pending Time In record.';
        }
        if (checkbox) checkbox.disabled = false;
        if (statusBadge) {
            statusBadge.className = 'status-badge status-active dtr-row-status';
            statusBadge.textContent = 'Pending Out';
        }
    } else {
        if (endInput) {
            endInput.value = '';
            endInput.disabled = true;
            endInput.readOnly = true;
            endInput.title = 'This date already has a completed DTR record.';
        }
        if (checkbox) {
            checkbox.checked = false;
            checkbox.disabled = true;
            row.classList.remove('table-active');
        }
        if (statusBadge) {
            statusBadge.className = 'status-badge status-inactive dtr-row-status';
            statusBadge.textContent = 'Recorded';
        }
    }
}
function refreshAllDtrRowStates(){
    document.querySelectorAll('.dtr-entry-row').forEach(refreshDtrRowState);
    updateDtrSelectionCount();
}

function clearDtrForm(){
    const form = document.getElementById('dtrForm');
    form.reset();
    document.getElementById('singleDtrEmployee').value = '';
    document.querySelector('#dtrModal .modal-title').innerHTML = '<i class="bi bi-clock-history me-2"></i>Daily Time Record (DTR)';
    const todayValue = new Date().toISOString().slice(0,10);
    document.querySelectorAll('.dtr-entry-row').forEach(row => {
        const dateInput = row.querySelector('.dtr-row-date');
        const startInput = row.querySelector('.dtr-row-start');
        const endInput = row.querySelector('.dtr-row-end');
        if (dateInput) { dateInput.value = todayValue; dateInput.disabled = false; }
        if (startInput) { startInput.value = ''; startInput.readOnly = false; startInput.disabled = false; }
        if (endInput) { endInput.value = ''; endInput.readOnly = false; endInput.disabled = false; }
    });
    document.querySelectorAll('.dtr-employee-checkbox').forEach(cb => {
        cb.checked = false;
        cb.disabled = false;
        cb.closest('tr')?.classList.remove('table-active');
    });
    const selectAll = document.getElementById('selectAllDtrEmployees');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.disabled = false;
        selectAll.indeterminate = false;
    }
    refreshAllDtrRowStates();
    updateDtrSelectionCount();
    const saveNewButton = document.querySelector('#dtrForm button[data-save-mode="new"]');
    const saveCloseButton = document.querySelector('#dtrForm button[data-save-mode="close"]');
    if (saveNewButton) saveNewButton.innerHTML = '<i class="bi bi-save me-1"></i>Save & New';
    if (saveCloseButton) saveCloseButton.innerHTML = '<i class="bi bi-save me-1"></i>Save & Close';
}
function showDtrModal(){
    clearDtrForm();
    dtrModal().show();
}
function toggleAllDtrEmployees(source){
    document.querySelectorAll('.dtr-employee-checkbox:not(:disabled)').forEach(cb => {
        cb.checked = source.checked;
        cb.closest('tr')?.classList.toggle('table-active', cb.checked);
    });
    updateDtrSelectionCount();
}
function updateDtrSelectionCount(){
    const boxes = Array.from(document.querySelectorAll('.dtr-employee-checkbox:not(:disabled)'));
    const selected = boxes.filter(cb => cb.checked);
    const counter = document.getElementById('dtrSelectedCount');
    if (counter) counter.textContent = selected.length;
    boxes.forEach(cb => cb.closest('tr')?.classList.toggle('table-active', cb.checked));
    const selectAll = document.getElementById('selectAllDtrEmployees');
    if (selectAll) {
        selectAll.checked = boxes.length > 0 && selected.length === boxes.length;
        selectAll.indeterminate = selected.length > 0 && selected.length < boxes.length;
    }
}
function clearDtrSelection(){
    document.getElementById('singleDtrEmployee').value = '';
    document.querySelectorAll('.dtr-employee-checkbox').forEach(cb => {
        cb.checked = false;
        cb.disabled = false;
        cb.closest('tr')?.classList.remove('table-active');
    });
    const selectAll = document.getElementById('selectAllDtrEmployees');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.disabled = false;
        selectAll.indeterminate = false;
    }
    updateDtrSelectionCount();
}
function getCurrentTimeValue(){
    const now = new Date();
    return String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
}
function setEmployeeOut(employeeId, employeeName, attendanceDate, startTime){
    const form = document.getElementById('dtrForm');
    form.reset();
    document.getElementById('singleDtrEmployee').value = String(employeeId);
    document.querySelectorAll('.dtr-employee-checkbox').forEach(cb => {
        cb.checked = cb.value === String(employeeId);
        cb.disabled = cb.value !== String(employeeId);
        cb.closest('tr')?.classList.toggle('table-active', cb.checked);
    });
    const selectAll = document.getElementById('selectAllDtrEmployees');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
        selectAll.disabled = true;
    }
    updateDtrSelectionCount();
    document.querySelectorAll('.dtr-entry-row').forEach(row => {
        const cb = row.querySelector('.dtr-employee-checkbox');
        const isTarget = cb && cb.value === String(employeeId);
        const dateInput = row.querySelector('.dtr-row-date');
        const startInput = row.querySelector('.dtr-row-start');
        const endInput = row.querySelector('.dtr-row-end');
        if (dateInput) dateInput.value = isTarget ? attendanceDate : new Date().toISOString().slice(0,10);
        if (startInput) { startInput.value = isTarget ? (startTime || '') : ''; startInput.readOnly = isTarget; }
        if (endInput) endInput.value = isTarget ? getCurrentTimeValue() : '';
    });
    document.querySelector('#dtrModal .modal-title').innerHTML = '<i class="bi bi-box-arrow-right me-2"></i>Set OUT - ' + employeeName;
    const saveNewButton = document.querySelector('#dtrForm button[data-save-mode="new"]');
    const saveCloseButton = document.querySelector('#dtrForm button[data-save-mode="close"]');
    if (saveNewButton) saveNewButton.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save OUT & New';
    if (saveCloseButton) saveCloseButton.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save OUT & Close';
    dtrModal().show();
}

function toggleAttendanceSelectionButton(){
    const boxes = Array.from(document.querySelectorAll('.attendance-row-checkbox:not(:disabled)'))
        .filter(cb => cb.closest('tr') && cb.closest('tr').style.display !== 'none');
    if (boxes.length === 0) {
        Swal.fire('No pending records', 'There are no visible pending attendance records to select.', 'info');
        return;
    }
    const selectedCount = boxes.filter(cb => cb.checked).length;
    const shouldSelect = selectedCount !== boxes.length;
    boxes.forEach(cb => {
        cb.checked = shouldSelect;
        cb.closest('tr')?.classList.toggle('table-active', shouldSelect);
    });
    updateAttendanceSelectionCount();
}

function toggleAllAttendanceRows(source){
    document.querySelectorAll('.attendance-row-checkbox:not(:disabled)').forEach(cb => {
        cb.checked = source.checked;
        cb.closest('tr')?.classList.toggle('table-active', cb.checked);
    });
    updateAttendanceSelectionCount();
}
function updateAttendanceSelectionCount(){
    const boxes = Array.from(document.querySelectorAll('.attendance-row-checkbox:not(:disabled)'));
    const selected = boxes.filter(cb => cb.checked);
    boxes.forEach(cb => cb.closest('tr')?.classList.toggle('table-active', cb.checked));
    const selectAll = document.getElementById('selectAllAttendanceRows');
    if (selectAll) {
        selectAll.checked = boxes.length > 0 && selected.length === boxes.length;
        selectAll.indeterminate = selected.length > 0 && selected.length < boxes.length;
    }
    const button = document.getElementById('selectedSetOutBtn');
    if (button) {
        button.disabled = selected.length === 0;
        button.innerHTML = selected.length > 0
            ? '<i class="bi bi-box-arrow-right me-1"></i>Set OUT (' + selected.length + ')'
            : '<i class="bi bi-box-arrow-right me-1"></i>Set OUT';
    }
    const selectButton = document.getElementById('attendanceSelectAllBtn');
    if (selectButton) {
        const visibleBoxes = boxes.filter(cb => cb.closest('tr') && cb.closest('tr').style.display !== 'none');
        const visibleSelected = visibleBoxes.filter(cb => cb.checked);
        selectButton.disabled = visibleBoxes.length === 0;
        selectButton.innerHTML = visibleBoxes.length > 0 && visibleSelected.length === visibleBoxes.length
            ? '<i class="bi bi-x-square me-1"></i>Deselect All'
            : '<i class="bi bi-check2-square me-1"></i>Select All';
    }
}
function setSelectedAttendanceOut(){
    const selected = Array.from(document.querySelectorAll('.attendance-row-checkbox:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        Swal.fire('No record selected', 'Select at least one pending attendance record.', 'warning');
        return;
    }
    const currentTime = getCurrentTimeValue();
    Swal.fire({
        title: 'Set OUT Time',
        html: '<label class="form-label text-start w-100">End Time</label><input type="time" id="bulkOutTime" class="form-control" value="' + currentTime + '">',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Save OUT',
        confirmButtonColor: '#44D34E',
        preConfirm: () => {
            const value = document.getElementById('bulkOutTime').value;
            if (!value) {
                Swal.showValidationMessage('End time is required');
                return false;
            }
            return value;
        }
    }).then(result => {
        if (!result.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'set_out_selected');
        fd.append('end_time', result.value);
        selected.forEach(id => fd.append('dtr_ids[]', id));
        Swal.fire({title:'Saving...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
        fetch('employee.php', {method:'POST', body:fd})
            .then(r=>r.json())
            .then(data=>{
                if (!data.success) {
                    Swal.fire('Error', data.message || 'Something went wrong', 'error');
                    return;
                }
                Swal.fire({icon:'success',title:'Success',text:data.message,timer:1300,showConfirmButton:false}).then(()=>location.reload());
            })
            .catch(()=>Swal.fire('Error','Unable to process request','error'));
    });
}
function submitForm(e){
    e.preventDefault();
    const form = e.target;
    const saveMode = e.submitter?.dataset?.saveMode || 'close';
    const isEmployeeForm = form.id === 'employeeForm';
    const isDtrForm = form.id === 'dtrForm';
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
        }).catch(()=>Swal.fire('Error','Unable to process request','error'));
}
function deleteEmployee(id){
    Swal.fire({title:'Delete employee?',text:'This will also delete DTR records of this employee.',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes, delete'}).then(res=>{
        if(!res.isConfirmed) return;
        const fd = new FormData(); fd.append('action','delete_employee'); fd.append('employee_id',id);
        Swal.fire({title:'Deleting...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
        fetch(window.location.pathname.split('/').pop() || 'employee.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
            if(data.success){ Swal.fire({icon:'success',title:'Deleted',text:data.message,timer:1200,showConfirmButton:false}).then(()=>location.reload()); }
            else Swal.fire('Error',data.message || 'Delete failed','error');
        }).catch(()=>Swal.fire('Error','Unable to process request','error'));
    });
}
function filterTables(){
    const q = (document.getElementById('searchInput').value || '').toLowerCase();
    document.querySelectorAll('.employee-profile-row,.dtr-row').forEach(row=>{ row.style.display = (row.dataset.search || row.innerText.toLowerCase()).includes(q) ? '' : 'none'; });
    updateAttendanceSelectionCount();
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


/* ===== Payroll System Tab Scripts ===== */

const payrollModal = new bootstrap.Modal(document.getElementById('payrollModal'));
const payrollViewModal = new bootstrap.Modal(document.getElementById('payrollViewModal'));
const payrollAttachmentPreviewModal = new bootstrap.Modal(document.getElementById('attachmentPreviewModal'));
const form = document.getElementById('payrollForm');
let payrollSaveMode = 'close';
document.querySelectorAll('.payroll-save-btn').forEach(btn => {
    btn.addEventListener('click', function(){ payrollSaveMode = this.dataset.saveMode || 'close'; });
});
const moneyFields = ['daily_rate','hourly_rate','regular_ot_rate','regular_holiday_rate','regular_holiday_ot_rate','rest_day_rate','rest_day_ot_rate','special_holiday_rate','special_holiday_ot_rate','sss_ee_share','philhealth_ee_share','pagibig_ee_share','sss_er_share','philhealth_er_share','pagibig_er_share'];

function peso(num){return '₱' + (parseFloat(num||0)).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
function cleanNum(v){return parseFloat(String(v||'0').replace(/,/g,'')) || 0;}
function resolveMonthlyRateFromPay(basePay, payClassification, workdays, workhours){
    const pay = cleanNum(basePay);
    const classification = String(payClassification || '').trim().toLowerCase();
    const days = cleanNum(workdays) || 26;
    const hours = cleanNum(workhours) || 8;

    // Kapag Daily ang pay classification, ang basic pay ay daily rate.
    // Kaya ang Monthly Rate dapat daily basic pay x total workdays per month.
    if (classification === 'daily') return pay * days;

    // Optional support: kapag Hourly naman, hourly basic pay x workhours x workdays.
    if (classification === 'hourly') return pay * hours * days;

    // Kapag Monthly, ang basic pay mismo ang monthly rate.
    return pay;
}
function getVal(id){const el=document.getElementById(id);return el?el.value:'';}
function payrollField(id){
    const formEl = document.getElementById('payrollForm');
    if (formEl) {
        const scoped = formEl.querySelector('#' + CSS.escape(id));
        if (scoped) return scoped;
    }
    return document.getElementById(id);
}
function setVal(id,val){
    const el=payrollField(id);
    if(!el) return;
    if(el.type === 'file') { el.value=''; return; }
    el.value=val ?? '';
}
function setCheck(id,val){const el=payrollField(id);if(el)el.checked=Number(val)===1 || val===true || val==='1' || val==='yes' || val==='Yes';}
function esc(v){return String(v??'').replace(/[&<>'"]/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[s]));}
function formatTime(t){return t ? String(t).slice(0,5) : '';}

function computeRates(){
    const monthly = cleanNum(getVal('monthly_rate'));
    const workdays = cleanNum(getVal('total_workdays_per_month')) || 26;
    const hours = cleanNum(getVal('total_work_hours')) || 8;
    const daily = monthly / workdays;
    const hourly = daily / hours;
    const rates = {
        'Daily Rate': daily,
        'Hourly Rate': hourly,
        'Regular OT Rate': hourly * 1.25,
        'Regular Holiday Rate': daily * 2,
        'Regular Holiday OT Rate': hourly * 2.6,
        'Rest Day Rate': daily * 1.3,
        'Rest Day OT Rate': hourly * 1.69,
        'Special Holiday Rate': daily * 1.3,
        'Special Holiday OT Rate': hourly * 1.69
    };
    document.getElementById('computedRatesPreview').innerHTML = Object.entries(rates).map(([label,value]) => `<div class="col-md-4"><div class="rate-card"><div class="rate-label">${label}</div><div class="rate-value">${peso(value)}</div></div></div>`).join('');
}

document.querySelectorAll('.rate-input').forEach(el=>el.addEventListener('input', computeRates));

function setPayrollFormMode(mode){
    const isEdit = mode === 'edit';
    const saveNewBtn = document.querySelector('#payrollForm .payroll-save-btn[data-save-mode="new"]');
    const saveCloseBtn = document.querySelector('#payrollForm .payroll-save-btn[data-save-mode="close"]');
    const clearBtn = document.querySelector('#payrollForm .btn-payroll-light[onclick="clearPayrollForm()"]');
    if (saveNewBtn) saveNewBtn.classList.toggle('d-none', isEdit);
    if (clearBtn) clearBtn.classList.toggle('d-none', isEdit);
    if (saveCloseBtn) {
        saveCloseBtn.innerHTML = isEdit
            ? '<i class="bi bi-save me-1"></i>Update Payroll'
            : '<i class="bi bi-save me-1"></i>Save & Close';
    }
    payrollSaveMode = 'close';
}

function resetPayrollTabs(){
    const firstTab = document.querySelector('#payrollModal .payroll-modal-tabs .nav-link[data-bs-target="#tabBasic"]');
    if (firstTab && bootstrap.Tab) bootstrap.Tab.getOrCreateInstance(firstTab).show();
}

function openPayrollModal(){
    clearPayrollForm();
    setPayrollFormMode('add');
    document.getElementById('payrollModalTitle').textContent='Add Payroll Profile';
    setVal('implementation_date', new Date().toISOString().slice(0,10));
    setVal('branch_id', '<?= (int)$branch_id ?>');
    setVal('branch', '<?= addslashes($currentBranchName) ?>');
    resetPayrollTabs();
    payrollModal.show();
    computeRates();
}

function clearPayrollForm(){
    form.reset();
    setVal('payroll_id',''); setVal('employee_id',''); setVal('employee_copied_attachments','');
    setVal('branch_id', '<?= (int)$branch_id ?>');
    setVal('branch', '<?= addslashes($currentBranchName) ?>');
    const employeeSelect = payrollField('existingEmployeeSelect');
    if (employeeSelect) employeeSelect.value='';
    const attachmentBox = payrollField('existingAttachmentBox');
    if (attachmentBox) attachmentBox.innerHTML='';
    setVal('total_work_hours','8'); setVal('total_workdays_per_month','26'); setVal('total_rest_days','0'); setVal('monthly_rate','0'); setVal('monthly_allowance','0');
    ['sss_ee_share','philhealth_ee_share','pagibig_ee_share','sss_er_share','philhealth_er_share','pagibig_er_share'].forEach(id=>setVal(id,'0'));
    computeRates();
}

function attachmentsHTML(row){
    let files=[];
    try{files=JSON.parse(row.attachments||'[]')||[]}catch(e){files=[]}
    if(!files.length) return '<span class="text-muted">No attachment</span>';
    return files.map((f,i)=>`<a href="javascript:void(0)" onclick="previewAttachment('${esc(f.path)}','${esc(f.name||('Attachment '+(i+1)))}')"><i class="bi bi-file-earmark"></i>${esc(f.name||('Attachment '+(i+1)))}</a>`).join('');
}

function previewAttachment(path,name){
    document.getElementById('previewTitle').textContent=name;
    document.getElementById('previewFrame').src=path;
    payrollAttachmentPreviewModal.show();
}

document.getElementById('attachmentPreviewModal').addEventListener('hidden.bs.modal',()=>{document.getElementById('previewFrame').src='';});

let currentPayrollViewRow = null;
let payrollEditOpening = false;

function preparePayrollEditForm(row){
    if (!row || !Number(row.payroll_id || 0)) {
        throw new Error('Invalid payroll record selected.');
    }

    clearPayrollForm();
    setPayrollFormMode('edit');
    document.getElementById('payrollModalTitle').textContent='Edit Payroll Profile';

    Object.keys(row).forEach(k=>{
        const el = payrollField(k);
        if(!el) return;

        // IMPORTANT FIX:
        // File inputs cannot be filled programmatically by the browser.
        // The payroll row has an `attachments` field, and without this guard
        // JS tries to set that JSON value into <input type="file" id="attachments">,
        // causing: Failed to set the 'value' property on 'HTMLInputElement'.
        if (el.type === 'file') {
            el.value = '';
            return;
        }

        if(el.type==='checkbox') el.checked = Number(row[k])===1;
        else if(el.type==='time') el.value = formatTime(row[k]);
        else el.value = row[k] ?? '';
    });

    setVal('payroll_id', row.payroll_id || '');
    setVal('employee_id', row.employee_id || '');
    setVal('employee_copied_attachments', '');
    setVal('branch_id', row.branch_id || '<?= (int)$branch_id ?>');
    ['implementation_date','hire_date'].forEach(id=>setVal(id, (row[id] || '').toString().slice(0,10)));
    ['start_of_work','end_of_work'].forEach(id=>setVal(id, formatTime(row[id] || '')));
    ['with_monthly_allowance','with_sss','with_philhealth','with_pagibig','remits_withholding_tax'].forEach(id=>setCheck(id,row[id]));

    const employeeSelect = payrollField('existingEmployeeSelect');
    if (employeeSelect) {
        // Auto-fill/select the matching employee in the dropdown during edit.
        // Hindi ito tatawag ng fillFromEmployee(), para hindi ma-clear ang payroll_id.
        const targetEmployeeId = String(row.employee_id || '');
        employeeSelect.value = '';
        if (targetEmployeeId !== '') {
            Array.from(employeeSelect.options).some(function(opt){
                if (!opt.value) return false;
                try {
                    const emp = JSON.parse(opt.value);
                    if (String(emp.employee_id || '') === targetEmployeeId) {
                        employeeSelect.value = opt.value;
                        return true;
                    }
                } catch(e) {}
                return false;
            });
        }
    }
    const attachmentBox = payrollField('existingAttachmentBox');
    if (attachmentBox) attachmentBox.innerHTML = attachmentsHTML(row);

    resetPayrollTabs();
    computeRates();
}

function showPayrollEditModal(row){
    preparePayrollEditForm(row);
    const editModalEl = document.getElementById('payrollModal');
    const editModal = bootstrap.Modal.getOrCreateInstance(editModalEl, {backdrop:true, keyboard:true});
    editModal.show();
    setTimeout(function(){
        document.body.classList.add('modal-open');
        editModalEl.style.display = 'block';
        editModalEl.classList.add('show');
        document.querySelectorAll('.modal-backdrop').forEach(function(backdrop, index){
            if (index > 0) backdrop.remove();
        });
    }, 60);
}

function editPayroll(row){
    try {
        showPayrollEditModal(row);
    } catch (err) {
        console.error('Payroll edit failed:', err);
        Swal.fire('Error', err.message || 'Failed to open edit payroll form.', 'error');
    }
}

function openPayrollEditFromDetails(e){
    if (e) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
    }

    if (payrollEditOpening) return false;
    const editRow = currentPayrollViewRow;
    if (!editRow || !Number(editRow.payroll_id || 0)) {
        Swal.fire('Error', 'Invalid payroll record selected.', 'error');
        return false;
    }

    payrollEditOpening = true;

    try {
        preparePayrollEditForm(editRow);
        const viewEl = document.getElementById('payrollViewModal');
        const editEl = document.getElementById('payrollModal');
        const viewInstance = bootstrap.Modal.getOrCreateInstance(viewEl);
        const editInstance = bootstrap.Modal.getOrCreateInstance(editEl, {backdrop:true, keyboard:true});

        let opened = false;
        const openEditNow = function(){
            if (opened) return;
            opened = true;
            document.querySelectorAll('.modal-backdrop').forEach(function(backdrop){ backdrop.remove(); });
            document.body.classList.add('modal-open');
            editInstance.show();
            setTimeout(function(){
                document.body.classList.add('modal-open');
                editEl.style.display = 'block';
                editEl.classList.add('show');
                payrollEditOpening = false;
            }, 80);
        };

        viewEl.addEventListener('hidden.bs.modal', openEditNow, {once:true});
        viewInstance.hide();
        setTimeout(openEditNow, 260);
    } catch (err) {
        payrollEditOpening = false;
        console.error('Payroll details edit failed:', err);
        Swal.fire('Error', err.message || 'Failed to open edit payroll form.', 'error');
    }
    return false;
}

function viewPayroll(row){
    currentPayrollViewRow = row;
    const basic = [
        ['Implementation Date', row.implementation_date],['Employee Name', row.employee_name],['Email Address', row.email_address],['Hire Date', row.hire_date],['Employee ID Number', row.employee_id_number],['Biometrics ID Number', row.biometrics_id_number],['Branch', row.branch],['Department', row.department],['Job Position', row.job_position],['Employment Classification', row.employment_classification]
    ];
    const payroll = [
        ['Monthly Rate', peso(row.monthly_rate)],['Pay Classification', row.pay_classification],['Payment Method', row.payment_method],['Account Number', row.employee_account_number],['Start of Work', formatTime(row.start_of_work)],['End of Work', formatTime(row.end_of_work)],['Total Work Hours', row.total_work_hours],['Rest Day', row.rest_day],['Total Rest Days', row.total_rest_days],['Total Workdays per Month', row.total_workdays_per_month],['With Monthly Allowance?', Number(row.with_monthly_allowance)===1?'Yes':'No'],['Monthly Allowance', peso(row.monthly_allowance)]
    ];
    const rates = [
        ['Daily Rate', peso(row.daily_rate)],['Hourly Rate', peso(row.hourly_rate)],['Regular OT Rate', peso(row.regular_ot_rate)],['Regular Holiday Rate', peso(row.regular_holiday_rate)],['Regular Holiday OT Rate', peso(row.regular_holiday_ot_rate)],['Rest Day Rate', peso(row.rest_day_rate)],['Rest Day OT Rate', peso(row.rest_day_ot_rate)],['Special Holiday Rate', peso(row.special_holiday_rate)],['Special Holiday OT Rate', peso(row.special_holiday_ot_rate)]
    ];
    const mandatories = [
        ['With SSS?', Number(row.with_sss)===1?'Yes':'No'],['SSS Number', row.sss_number],['With PhilHealth?', Number(row.with_philhealth)===1?'Yes':'No'],['PhilHealth Number', row.philhealth_number],['With Pag-IBIG?', Number(row.with_pagibig)===1?'Yes':'No'],['Pag-IBIG Number', row.pagibig_number],['Remits Withholding Tax?', Number(row.remits_withholding_tax)===1?'Yes':'No'],['TIN', row.tax_identification_number],['SSS EE Share', peso(row.sss_ee_share)],['PhilHealth EE Share', peso(row.philhealth_ee_share)],['Pag-IBIG EE Share', peso(row.pagibig_ee_share)],['SSS ER Share', peso(row.sss_er_share)],['PhilHealth ER Share', peso(row.philhealth_er_share)],['Pag-IBIG ER Share', peso(row.pagibig_er_share)]
    ];
    const render = (title, icon, rows) => `<div class="form-section"><div class="form-section-title"><i class="bi ${icon}"></i>${title}</div><div class="row g-2">${rows.map(([a,b])=>`<div class="col-md-4"><div class="rate-card"><div class="rate-label">${esc(a)}</div><div class="rate-value" style="font-size:.9rem">${esc(b)}</div></div></div>`).join('')}</div></div>`;
    document.getElementById('payrollViewBody').innerHTML = render('Employee Information','bi-person-lines-fill',basic) + render('Payroll & Schedule','bi-wallet2',payroll) + render('Computed Rates','bi-calculator',rates) + render('Mandatories','bi-shield-check',mandatories) + `<div class="form-section"><div class="form-section-title"><i class="bi bi-paperclip"></i>Attachments</div><div class="attachment-list">${attachmentsHTML(row)}</div></div>`;
    const editBtn = document.getElementById('payrollViewEditBtn');
    const deleteBtn = document.getElementById('payrollViewDeleteBtn');
    if (editBtn) editBtn.onclick = function(e){
        currentPayrollViewRow = row;
        return openPayrollEditFromDetails(e);
    };
    if (deleteBtn) deleteBtn.onclick = function(){ deletePayroll(Number(row.payroll_id || 0)); };
    payrollViewModal.show();
}


// Extra safety binding for Payroll Details Edit button.
// This catches the click even if inline onclick is overwritten after the modal is rendered.
document.addEventListener('click', function(e){
    const btn = e.target.closest('#payrollViewEditBtn');
    if (!btn) return;
    openPayrollEditFromDetails(e);
}, true);

function deletePayroll(id){
    Swal.fire({title:'Delete payroll record?',text:'This action cannot be undone.',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626',cancelButtonColor:'#6b7280',confirmButtonText:'Yes, delete'}).then(res=>{
        if(!res.isConfirmed) return;
        const fd=new FormData(); fd.append('action','delete_payroll'); fd.append('payroll_id',id);
        fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
            if(data.success) Swal.fire({icon:'success',title:'Deleted',text:data.message,timer:1400,showConfirmButton:false}).then(()=>location.reload());
            else Swal.fire('Error',data.message||'Failed to delete','error');
        }).catch(()=>Swal.fire('Error','Request failed','error'));
    });
}

function normalizePayrollAttachment(file, index){
    if(!file || typeof file !== 'object') return null;
    const path = file.path || file.file_path || file.attachment_path || '';
    if(!path) return null;
    const name = file.name || file.original_name || file.attachment_name || ('Employee Attachment ' + (index + 1));
    return {name:name, path:path};
}
function getEmployeeAllowanceTotal(emp){
    let total = 0;
    if(Array.isArray(emp.allowances)) {
        emp.allowances.forEach(a => total += cleanNum(a.allowance_amount || a.amount || 0));
    }
    if(total <= 0 && emp.monthly_allowance) total = cleanNum(emp.monthly_allowance);
    return total;
}
function getGovValue(emp, label, fallback){
    if(fallback) return fallback;
    const regs = Array.isArray(emp.government_registrations) ? emp.government_registrations : [];
    const found = regs.find(r => String(r.registration_name || '').toLowerCase().includes(label.toLowerCase()));
    return found ? (found.registration_number || '') : '';
}
function fillFromEmployee(){
    const sel=document.getElementById('existingEmployeeSelect');
    if(!sel.value) return;
    let emp={};
    try{ emp=JSON.parse(sel.value); }catch(e){ return; }

    const allowanceTotal = getEmployeeAllowanceTotal(emp);
    const employeeAttachments = Array.isArray(emp.attachments) ? emp.attachments.map(normalizePayrollAttachment).filter(Boolean) : [];

    setVal('employee_id', emp.employee_id || '');
    // Huwag i-clear ang payroll_id kapag edit mode para hindi magdoble ang record.
    const currentPayrollId = getVal('payroll_id');
    if (!currentPayrollId) setVal('payroll_id', '');
    setVal('branch_id', '<?= (int)$branch_id ?>');
    setVal('branch', emp.branch_name || emp.branch || '<?= addslashes($currentBranchName) ?>');

    setVal('last_name', emp.last_name || '');
    setVal('first_name', emp.first_name || '');
    setVal('middle_name', emp.middle_name || '');
    setVal('employee_name', emp.employee_name || [emp.first_name, emp.middle_name, emp.last_name].filter(Boolean).join(' '));
    setVal('email_address', emp.email || emp.email_address || '');
    setVal('hire_date', emp.start_date || emp.hire_date || '');
    setVal('employee_id_number', emp.employee_id_number || '');
    setVal('biometrics_id_number', emp.biometrics_id_number || '');

    const resolvedDepartment = emp.department || emp.department_name || emp.dept_name || emp.employee_department || emp.job_department || '';
    const resolvedPosition = emp.position || emp.job_position || emp.position_name || emp.job_title || '';
    setVal('department', resolvedDepartment);
    setVal('job_position', resolvedPosition);
    setVal('employment_classification', emp.employment_classification || '');

    const employeePayClassification = emp.pay_classification || 'Monthly';
    const employeeWorkdays = emp.total_workdays_per_month || '26';
    const employeeWorkhours = emp.total_work_hours || '8';
    const employeeBasicPay = emp.basic_pay || emp.monthly_rate || '0';
    const resolvedMonthlyRate = resolveMonthlyRateFromPay(employeeBasicPay, employeePayClassification, employeeWorkdays, employeeWorkhours);
    setVal('monthly_rate', resolvedMonthlyRate.toFixed(2));
    setVal('pay_classification', employeePayClassification);
    setVal('payment_method', emp.payment_method || '');
    setVal('employee_account_number', emp.account_number || emp.employee_account_number || '');
    setCheck('with_monthly_allowance', allowanceTotal > 0);
    setVal('monthly_allowance', allowanceTotal.toFixed(2));

    setVal('start_of_work', formatTime(emp.start_of_work || ''));
    setVal('end_of_work', formatTime(emp.end_of_work || ''));
    setVal('total_work_hours', emp.total_work_hours || '8');
    setVal('rest_day', emp.rest_day || '');
    setVal('total_rest_days', emp.total_rest_days || '0');
    setVal('total_workdays_per_month', emp.total_workdays_per_month || '26');

    setCheck('with_sss', emp.with_sss);
    setVal('sss_number', getGovValue(emp, 'sss', emp.sss || emp.sss_number || ''));
    setCheck('with_philhealth', emp.with_philhealth);
    setVal('philhealth_number', getGovValue(emp, 'philhealth', emp.philhealth || emp.philhealth_number || ''));
    setCheck('with_pagibig', emp.with_pagibig);
    setVal('pagibig_number', getGovValue(emp, 'pag-ibig', emp.pagibig || emp.pagibig_number || ''));
    setCheck('remits_withholding_tax', emp.remits_withholding_tax);
    setVal('tax_identification_number', getGovValue(emp, 'tin', emp.tin || emp.tax_identification_number || ''));

    setVal('employee_copied_attachments', JSON.stringify(employeeAttachments));
    document.getElementById('existingAttachmentBox').innerHTML = employeeAttachments.length
        ? '<div class="small fw-bold text-success mb-2"><i class="bi bi-check-circle me-1"></i>Employee attachments will be copied to this payroll profile when saved.</div>' + employeeAttachments.map((f,i)=>`<a href="javascript:void(0)" onclick="previewAttachment('${esc(f.path)}','${esc(f.name || ('Employee Attachment '+(i+1)))}')"><i class="bi bi-file-earmark"></i>${esc(f.name || ('Employee Attachment '+(i+1)))}</a>`).join('')
        : '<span class="text-muted">No employee attachment found.</span>';

    computeRates();
    Swal.fire({icon:'success',title:'Employee information loaded',text:'All available employee details have been auto-filled.',timer:1300,showConfirmButton:false});
}

form.addEventListener('submit',function(e){
    e.preventDefault();
    const fd=new FormData(form);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if(data.success) {
            Swal.fire({icon:'success',title:'Saved',text:data.message,timer:1300,showConfirmButton:false}).then(()=>{
                if (payrollSaveMode === 'new') {
                    clearPayrollForm();
                    setPayrollFormMode('add');
                    document.getElementById('payrollModalTitle').textContent='Add Payroll Profile';
                    setVal('implementation_date', new Date().toISOString().slice(0,10));
                    resetPayrollTabs();
                    payrollModal.show();
                } else {
                    payrollModal.hide();
                    location.reload();
                }
            });
        }
        else Swal.fire('Error',data.message||'Failed to save','error');
    }).catch(()=>Swal.fire('Error','Request failed','error'));
});

document.getElementById('payrollSearch').addEventListener('input',function(){
    const q=this.value.toLowerCase().trim();
    document.querySelectorAll('#payrollTable tbody tr').forEach(tr=>{
        if(tr.querySelector('.empty-state')) return;
        tr.style.display=(tr.dataset.search||tr.textContent.toLowerCase()).includes(q)?'':'none';
    });
});

function getVisiblePayrollRows(){
    const table = document.getElementById('payrollTable');
    if (!table) return [];
    const headerRow = table.querySelector('thead tr');
    const bodyRows = [...table.querySelectorAll('tbody tr')].filter(tr => {
        if (tr.style.display === 'none') return false;
        if (tr.querySelector('.empty-state-table')) return false;
        return true;
    });
    return headerRow ? [headerRow, ...bodyRows] : bodyRows;
}

function payrollCellText(cell){
    return (cell?.innerText || '')
        .replace(/\s+/g, ' ')
        .replace(/\u00a0/g, ' ')
        .trim();
}

function escapePayrollHtml(value){
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildPayrollExportTable(){
    const rows = getVisiblePayrollRows();
    let html = '<table class="payroll-print-table" border="1" cellspacing="0" cellpadding="2">';
    rows.forEach((tr, rowIndex) => {
        html += '<tr>';
        [...tr.querySelectorAll('th,td')].forEach(cell => {
            const tag = rowIndex === 0 ? 'th' : 'td';
            const align = cell.classList.contains('text-start') ? 'left' : 'center';
            html += `<${tag} style="border:1px solid #94a3b8;text-align:${align};vertical-align:middle;white-space:nowrap;word-break:normal;overflow-wrap:normal;mso-number-format:'\@';">${escapePayrollHtml(payrollCellText(cell))}</${tag}>`;
        });
        html += '</tr>';
    });
    html += '</table>';
    return html;
}

function printPayrollTable(){
    const rows = getVisiblePayrollRows();
    if (rows.length <= 1) {
        Swal.fire('No records', 'No payroll records available to print.', 'info');
        return;
    }

    const printTable = buildPayrollExportTable();
    const printedAt = new Date().toLocaleString('en-PH', { hour12: true });
    const branchName = <?= json_encode($branch_name) ?>;

    const oldFrame = document.getElementById('payrollPrintFrame');
    if (oldFrame) oldFrame.remove();

    const printFrame = document.createElement('iframe');
    printFrame.id = 'payrollPrintFrame';
    printFrame.setAttribute('aria-hidden', 'true');
    printFrame.style.position = 'fixed';
    printFrame.style.right = '0';
    printFrame.style.bottom = '0';
    printFrame.style.width = '0';
    printFrame.style.height = '0';
    printFrame.style.border = '0';
    printFrame.style.opacity = '0';
    printFrame.style.pointerEvents = 'none';

    document.body.appendChild(printFrame);

    const doc = printFrame.contentWindow.document;
    doc.open();
    doc.write(`
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Payroll System Print</title>
            <style>
                @page { size: legal landscape; margin: 8mm; }
                * { box-sizing: border-box; }
                html, body { width: 100%; margin: 0; padding: 0; }
                body { font-family: Inter, Arial, sans-serif; color: #052A47; }
                .print-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 8px; border-bottom: 2px solid #047857; padding-bottom: 6px; }
                .print-title { font-size: 15px; font-weight: 900; margin: 0; color: #052A47; }
                .print-subtitle { font-size: 9px; margin-top: 2px; color: #475569; font-weight: 700; }
                .print-meta { font-size: 8px; text-align: right; color: #475569; line-height: 1.3; }
                table { width: 100%; max-width: 100%; border-collapse: collapse; table-layout: auto; }
                th { background: #ecfdf5; color: #052A47; font-size: 4.8px; text-transform: uppercase; letter-spacing: 0; font-weight: 900; }
                td { font-size: 4.8px; color: #0f172a; }
                th, td { border: 1px solid #94a3b8; padding: 1.2px 1.6px; vertical-align: middle; white-space: nowrap; word-break: keep-all; overflow-wrap: normal; line-height: 1.08; }
                tr:nth-child(even) td { background: #f8fafc; }
                .print-footer { margin-top: 8px; font-size: 8px; color: #64748b; text-align: right; }
                @media print {
                    html, body { width: 100%; }
                    table { page-break-inside: auto; }
                    tr { page-break-inside: avoid; page-break-after: auto; }
                    thead { display: table-header-group; }
                }
            </style>
        </head>
        <body>
            <div class="print-header">
                <div>
                    <h1 class="print-title">Payroll System</h1>
                    <div class="print-subtitle">Table Layout Print Preview</div>
                </div>
                <div class="print-meta">
                    <div><strong>Branch:</strong> ${escapePayrollHtml(branchName)}</div>
                    <div><strong>Printed:</strong> ${escapePayrollHtml(printedAt)}</div>
                    <div><strong>Records:</strong> ${rows.length - 1}</div>
                </div>
            </div>
            ${printTable}
            <div class="print-footer">Generated from Employee List & Payroll System</div>
        </body>
        </html>
    `);
    doc.close();

    const runPrint = () => {
        try {
            printFrame.contentWindow.focus();
            printFrame.contentWindow.print();
        } catch (error) {
            Swal.fire('Print failed', 'Unable to open print preview. Please try again.', 'error');
        }

        setTimeout(() => {
            const frame = document.getElementById('payrollPrintFrame');
            if (frame) frame.remove();
        }, 1500);
    };

    if (printFrame.contentWindow.document.readyState === 'complete') {
        setTimeout(runPrint, 250);
    } else {
        printFrame.onload = () => setTimeout(runPrint, 250);
    }
}

function exportPayrollExcel(){
    const rows = getVisiblePayrollRows();
    if (rows.length <= 1) {
        Swal.fire('No records', 'No payroll records available to export.', 'info');
        return;
    }

    const exportedAt = new Date().toLocaleString('en-PH', { hour12: true });
    const branchName = <?= json_encode($branch_name) ?>;
    const table = buildPayrollExportTable();
    const excelHtml = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
        <head>
            <meta charset="utf-8">
            <!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Payroll System</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
            <style>
                table{border-collapse:collapse;}
                th{background:#ecfdf5;color:#052A47;font-weight:bold;text-align:center;border:1px solid #94a3b8;}
                td{border:1px solid #cbd5e1;text-align:center;mso-number-format:'\@';}
                .meta{font-weight:bold;color:#052A47;}
            </style>
        </head>
        <body>
            <table>
                <tr><td class="meta" colspan="50">Payroll System</td></tr>
                <tr><td class="meta" colspan="50">Branch: ${escapePayrollHtml(branchName)}</td></tr>
                <tr><td class="meta" colspan="50">Exported: ${escapePayrollHtml(exportedAt)}</td></tr>
                <tr><td colspan="50"></td></tr>
            </table>
            ${table}
        </body>
        </html>`;

    const blob = new Blob(['\ufeff', excelHtml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'payroll_system_' + new Date().toISOString().slice(0,10) + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}


computeRates();

function updateEmployeesTaskBadge() {
    const employeesMenu = document.getElementById('employeesMenu');
    const employeesDropdown = employeesMenu?.closest('.employees-dropdown');

    if (!employeesMenu || !employeesDropdown) return;

    employeesDropdown.classList.toggle(
        'employees-menu-open',
        employeesMenu.classList.contains('show')
    );
}
</script>

</body>
</html>
