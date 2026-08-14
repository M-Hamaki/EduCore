<?php
$page_title = "البيانات المالية للعاملين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/StaffEmploymentLifecycleService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';
FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__);
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

if (isset($_GET['action']) && $_GET['action'] === 'get_staff_financial') {
    header('Content-Type: application/json; charset=utf-8');
    $sId = (int)($_GET['staff_id'] ?? 0);
    $stmt = $db->prepare("SELECT u.id, u.role, u.status, sp.current_work_status, COALESCE(NULLIF(sp.full_name_ar, ''), u.name) AS display_name,
                                 sp.employee_code, sp.job_title,
                                 sp.basic_salary, sp.allowance_transport, sp.allowance_housing,
                                 sp.other_allowances_data, sp.deduction_insurance, sp.deduction_tax,
                                 sp.other_deductions_data, sp.net_salary, sp.advances_data, sp.financial_notes
                          FROM users u
                          LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                          WHERE u.id = ? AND (u.role IS NULL OR u.role <> 'student')
                          LIMIT 1");
    $stmt->execute([$sId]);
    $staffData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($staffData) {
        $staffData['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($staffData['job_title'] ?? null);
        echo json_encode(['success' => true, 'data' => $staffData]);
    } else {
        echo json_encode(['success' => false, 'message' => 'العامل غير موجود']);
    }
    exit();
}

function staff_financial_decimal($value, string $label): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (!is_numeric($value) || (float)$value < 0) {
        throw new RuntimeException($label . ' يجب أن يكون رقماً موجباً.');
    }
    return number_format((float)$value, 2, '.', '');
}

function staff_financial_json_list($json, array $keys): string {
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $rows = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $clean = [];
        foreach ($keys as $key) {
            $clean[$key] = trim((string)($row[$key] ?? ''));
        }
        if (implode('', $clean) !== '') {
            $rows[] = $clean;
        }
    }
    return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

function staff_financial_advances_json($json): string {
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $rows = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $clean = [
            'name' => trim((string)($row['name'] ?? '')),
            'amount' => trim((string)($row['amount'] ?? '')),
            'date' => trim((string)($row['date'] ?? '')),
            'paid' => trim((string)($row['paid'] ?? '')),
            'notes' => trim((string)($row['notes'] ?? '')),
            'payments' => [],
        ];
        foreach (($row['payments'] ?? []) as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $cleanPayment = [
                'amount' => trim((string)($payment['amount'] ?? '')),
                'date' => trim((string)($payment['date'] ?? '')),
                'notes' => trim((string)($payment['notes'] ?? '')),
            ];
            if (implode('', $cleanPayment) !== '') {
                $clean['payments'][] = $cleanPayment;
            }
        }
        if (($clean['name'] . $clean['amount'] . $clean['date'] . $clean['paid'] . $clean['notes']) !== '' || !empty($clean['payments'])) {
            $rows[] = $clean;
        }
    }
    return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

function staff_financial_redirect(int $staffId = 0): void {
    $suffix = $staffId > 0 ? '?staff_id=' . $staffId : '';
    header('Location: staff_financial_data.php' . $suffix);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staffId = (int)($_POST['staff_id'] ?? 0);
    try {
        $staffStmt = $db->prepare("SELECT u.id, u.name, u.role, COALESCE(sp.full_name_ar, u.name) AS display_name
                                   FROM users u
                                   LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                                   WHERE u.id = ? AND (u.role IS NULL OR u.role <> 'student')
                                   LIMIT 1");
        $staffStmt->execute([$staffId]);
        $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            throw new RuntimeException('العامل غير موجود.');
        }

        $oldStmt = $db->prepare("SELECT basic_salary, allowance_transport, allowance_housing,
                                        other_allowances_data, deduction_insurance, deduction_tax,
                                        other_deductions_data, net_salary, advances_data, financial_notes
                                 FROM staff_profiles WHERE user_id = ? LIMIT 1");
        $oldStmt->execute([$staffId]);
        $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $data = [
            'basic_salary' => staff_financial_decimal($_POST['basic_salary'] ?? '', 'الراتب الأساسي'),
            'allowance_transport' => staff_financial_decimal($_POST['allowance_transport'] ?? '', 'بدل الانتقال'),
            'allowance_housing' => staff_financial_decimal($_POST['allowance_housing'] ?? '', 'بدل السكن'),
            'other_allowances_data' => staff_financial_json_list($_POST['other_allowances_data'] ?? '[]', ['name', 'amount']),
            'deduction_insurance' => staff_financial_decimal($_POST['deduction_insurance'] ?? '', 'التأمينات'),
            'deduction_tax' => staff_financial_decimal($_POST['deduction_tax'] ?? '', 'الضرائب'),
            'other_deductions_data' => staff_financial_json_list($_POST['other_deductions_data'] ?? '[]', ['name', 'amount']),
            'net_salary' => staff_financial_decimal($_POST['net_salary'] ?? '', 'صافي المرتب'),
            'advances_data' => staff_financial_advances_json($_POST['advances_data'] ?? '[]'),
            'financial_notes' => trim((string)($_POST['financial_notes'] ?? '')),
        ];

        ActivityLog::setDb($db);
        $db->beginTransaction();

        $profileExistsStmt = $db->prepare("SELECT id FROM staff_profiles WHERE user_id = ? LIMIT 1");
        $profileExistsStmt->execute([$staffId]);
        if (!$profileExistsStmt->fetchColumn()) {
            $insertProfile = $db->prepare("INSERT INTO staff_profiles (user_id) VALUES (?)");
            $insertProfile->execute([$staffId]);
        }

        $update = $db->prepare("UPDATE staff_profiles
                                SET basic_salary = ?, allowance_transport = ?, allowance_housing = ?,
                                    other_allowances_data = ?, deduction_insurance = ?, deduction_tax = ?,
                                    other_deductions_data = ?, net_salary = ?, advances_data = ?, financial_notes = ?
                                WHERE user_id = ?");
        $update->execute([
            $data['basic_salary'],
            $data['allowance_transport'],
            $data['allowance_housing'],
            $data['other_allowances_data'],
            $data['deduction_insurance'],
            $data['deduction_tax'],
            $data['other_deductions_data'],
            $data['net_salary'],
            $data['advances_data'],
            $data['financial_notes'],
            $staffId,
        ]);

        $logged = ActivityLog::logUpdate('staff_financial', $staffId, $staff['display_name'], [
            'summary' => 'تحديث البيانات المالية للعامل',
            'changes' => ['from' => $oldData, 'to' => $data],
        ]);
        if (!$logged) {
            throw new RuntimeException('تعذر تسجيل عملية التحديث. لم يتم حفظ أي تغيير.');
        }

        $db->commit();

        $_SESSION['success_message'] = 'تم حفظ البيانات المالية للعامل بنجاح.';
    } catch (Throwable $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        // تسجيل الخطأ الداخلي للسجل فقط — عرض رسالة عامة للمستخدم
        error_log('staff_financial_data save error: ' . $e->getMessage());
        $_SESSION['error_message'] = 'حدث خطأ غير متوقع أثناء حفظ البيانات المالية. يرجى المحاولة مرة أخرى.';
    }

    staff_financial_redirect($staffId);
}

$filter_role = $_GET['role'] ?? '';
$filter_job = StaffEmploymentLifecycleService::canonicalJobTitle($_GET['job_title'] ?? null) ?? '';
$filter_fin_status = $_GET['fin_status'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Distinct filter options
$rolesList = $db->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL AND role <> 'student' ORDER BY role")->fetchAll(PDO::FETCH_COLUMN);
$jobTitlesList = StaffEmploymentLifecycleService::canonicalJobTitleOptionsFromValues(
    $db->query("SELECT DISTINCT job_title FROM staff_profiles WHERE job_title IS NOT NULL AND job_title <> '' ORDER BY job_title")->fetchAll(PDO::FETCH_COLUMN)
);

$staffSql = "SELECT u.id, u.role, u.status, sp.current_work_status, COALESCE(NULLIF(sp.full_name_ar, ''), u.name) AS display_name,
                    sp.employee_code, sp.job_title, sp.basic_salary, sp.net_salary
             FROM users u
             LEFT JOIN staff_profiles sp ON sp.user_id = u.id
             WHERE (u.role IS NULL OR u.role <> 'student')";
$staffParams = [];

if ($filter_role !== '') {
    $staffSql .= " AND u.role = ?";
    $staffParams[] = $filter_role;
}
if ($filter_job !== '') {
    $jobTitleValues = StaffEmploymentLifecycleService::jobTitleFilterValues($filter_job);
    if ($jobTitleValues === []) {
        $staffSql .= ' AND 1 = 0';
    } else {
        $staffSql .= ' AND sp.job_title IN (' . implode(',', array_fill(0, count($jobTitleValues), '?')) . ')';
        array_push($staffParams, ...$jobTitleValues);
    }
}
if ($filter_status !== '') {
    $staffSql .= " AND u.status = ?";
    $staffParams[] = $filter_status;
}
if ($filter_fin_status === 'with') {
    $staffSql .= " AND sp.net_salary IS NOT NULL AND sp.net_salary > 0";
} elseif ($filter_fin_status === 'without') {
    $staffSql .= " AND (sp.net_salary IS NULL OR sp.net_salary = 0 OR sp.net_salary = '')";
}

$staffSql .= " ORDER BY display_name";
$staffStmt = $db->prepare($staffSql);
$staffStmt->execute($staffParams);
$staffList = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($staffList as &$staffRow) {
    $staffRow['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($staffRow['job_title'] ?? null);
}
unset($staffRow);

$selectedStaffId = (int)($_GET['staff_id'] ?? 0);
$selectedStaff = null;
$financial = [
    'basic_salary' => '',
    'allowance_transport' => '',
    'allowance_housing' => '',
    'other_allowances_data' => '[]',
    'deduction_insurance' => '',
    'deduction_tax' => '',
    'other_deductions_data' => '[]',
    'net_salary' => '',
    'advances_data' => '[]',
    'financial_notes' => '',
];

if ($selectedStaffId > 0) {
    $selectedStmt = $db->prepare("SELECT u.id, u.role, u.status, COALESCE(NULLIF(sp.full_name_ar, ''), u.name) AS display_name,
                                         sp.employee_code, sp.job_title,
                                         sp.basic_salary, sp.allowance_transport, sp.allowance_housing,
                                         sp.other_allowances_data, sp.deduction_insurance, sp.deduction_tax,
                                         sp.other_deductions_data, sp.net_salary, sp.advances_data, sp.financial_notes
                                  FROM users u
                                  LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                                  WHERE u.id = ? AND (u.role IS NULL OR u.role <> 'student')
                                  LIMIT 1");
    $selectedStmt->execute([$selectedStaffId]);
    $selectedStaff = $selectedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedStaff) {
        $selectedStaff['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($selectedStaff['job_title'] ?? null);
        foreach ($financial as $key => $default) {
            $financial[$key] = $selectedStaff[$key] ?? $default;
        }
    }
}

$totalStaffCount = count($staffList);
$withFinancialCount = 0;
$totalNetSalariesSum = 0;

foreach ($staffList as $stItem) {
    if (!empty($stItem['net_salary']) && (float)$stItem['net_salary'] > 0) {
        $withFinancialCount++;
        $totalNetSalariesSum += (float)$stItem['net_salary'];
    }
}

include_once '../includes/admin_header.php';
echo FinanceLegacyAdapter::bridgeNotice(__FILE__);
?>

<div class="staff-financial-page admin-unified-container">
    <!-- Page Header -->
    <div class="admin-page-heading mb-4">
        <h1 class="h2"><i class="fas fa-money-check-alt me-2 text-primary"></i>البيانات المالية للعاملين</h1>
    </div>

    <!-- Alerts -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-user-tie"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $totalStaffCount; ?>">0</div>
                    <div class="stat-card-label">إجمالي الكادر والوظائف</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $withFinancialCount; ?>">0</div>
                    <div class="stat-card-label">سجلات مالية مُعرّفة</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <div class="stat-card-icon"><i class="fas fa-wallet"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)round($totalNetSalariesSum); ?>">0</div>
                    <div class="stat-card-label">إجمالي الرواتب المسجلة (ج.م)</div>
                </div>
            </div>
        </div>
    </div>

    <?php
    $onDutyCount = 0;
    $offDutyCount = 0;
    foreach ($staffList as $sItem) {
        if (($sItem['current_work_status'] ?? 'on_duty') !== 'off_duty') {
            $onDutyCount++;
        } else {
            $offDutyCount++;
        }
    }
    ?>

    <!-- Main Work Status Tabs -->
    <ul class="nav nav-tabs mb-3 border-bottom" id="staffWorkStatusTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold active staff-tab-btn" data-status-tab="on_duty" type="button" role="tab">
                <i class="fas fa-user-check me-2 text-success"></i>على رأس العمل
                <span class="badge bg-success ms-1"><?php echo number_format($onDutyCount); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold staff-tab-btn" data-status-tab="off_duty" type="button" role="tab">
                <i class="fas fa-user-clock me-2 text-secondary"></i>ليس على رأس العمل
                <span class="badge bg-secondary ms-1"><?php echo number_format($offDutyCount); ?></span>
            </button>
        </li>
    </ul>

    <!-- Main Surface Card -->
    <div class="admin-list-surface">
        <!-- Enrolled Students Style Filter Bar -->
        <div class="admin-filter-bar mb-3">
            <div class="admin-filter-controls">
                <!-- Job Title Dropdown -->
                <?php if (!empty($jobTitlesList)): ?>
                <div class="dropdown d-inline-block me-2">
                    <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="jobDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 160px;">
                        <span>المسمى الوظيفي: <span id="selectedJobsLabel" class="fw-bold">الكل</span></span>
                    </button>
                    <div class="dropdown-menu p-3" aria-labelledby="jobDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                        <?php foreach ($jobTitlesList as $idx => $jt): ?>
                            <div class="form-check mb-1">
                                <input class="form-check-input job-checkbox" type="checkbox" value="<?php echo htmlspecialchars($jt); ?>" id="job_<?php echo $idx; ?>">
                                <label class="form-check-label" for="job_<?php echo $idx; ?>"><?php echo htmlspecialchars($jt); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Financial Status Dropdown -->
                <div class="dropdown d-inline-block me-2">
                    <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="finDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 160px;">
                        <span>الحالة المالية: <span id="selectedFinLabel" class="fw-bold">الكل</span></span>
                    </button>
                    <div class="dropdown-menu p-3" aria-labelledby="finDropdown" style="max-height: 250px; overflow-y: auto; min-width: 190px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                        <div class="form-check mb-1">
                            <input class="form-check-input fin-checkbox" type="checkbox" value="with" id="fin_with">
                            <label class="form-check-label" for="fin_with">مسجل ماليًا</label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input fin-checkbox" type="checkbox" value="without" id="fin_without">
                            <label class="form-check-label" for="fin_without">غير مسجل ماليًا</label>
                        </div>
                    </div>
                </div>

                <!-- Work Status Dropdown -->
                <div class="dropdown d-inline-block me-2">
                    <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="statusDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 170px;">
                        <span>حالة العمل: <span id="selectedStatusLabel" class="fw-bold">الكل</span></span>
                    </button>
                    <div class="dropdown-menu p-3" aria-labelledby="statusDropdown" style="max-height: 250px; overflow-y: auto; min-width: 190px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                        <div class="form-check mb-1">
                            <input class="form-check-input status-checkbox" type="checkbox" value="on_duty" id="st_onduty">
                            <label class="form-check-label" for="st_onduty">على رأس العمل</label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input status-checkbox" type="checkbox" value="off_duty" id="st_offduty">
                            <label class="form-check-label" for="st_offduty">ليس على رأس العمل</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="admin-filter-actions">
                <button type="button" id="resetFiltersBtn" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
                    <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
                </button>
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
                    <i class="fas fa-cog me-1"></i>إعدادات الجدول
                </button>
            </div>
        </div>

        <!-- Table Surface -->
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table" id="staffFinancialTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>كود الموظف</th>
                        <th>اسم الموظف</th>
                        <th>المسمى الوظيفي</th>
                        <th>الراتب الأساسي</th>
                        <th>صافي المرتب</th>
                        <th>الحالة المالية</th>
                        <th>حالة العمل</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staffList as $idx => $st):
                        $isOnDuty = ($st['current_work_status'] ?? 'on_duty') !== 'off_duty';
                        $workStatusVal = $isOnDuty ? 'on_duty' : 'off_duty';
                        $hasFinData = !empty($st['net_salary']) && (float)$st['net_salary'] > 0;
                    ?>
                    <tr data-role="<?php echo htmlspecialchars($st['role'] ?? ''); ?>"
                        data-job="<?php echo htmlspecialchars($st['job_title'] ?? ''); ?>"
                        data-status="<?php echo $workStatusVal; ?>"
                        data-fin-status="<?php echo $hasFinData ? 'with' : 'without'; ?>">
                        <td><?php echo $idx + 1; ?></td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($st['employee_code'] ?? '-'); ?></small></td>
                        <td class="fw-bold text-primary"><?php echo htmlspecialchars($st['display_name']); ?></td>
                        <td><?php echo htmlspecialchars($st['job_title'] ?? '-'); ?></td>
                        <td><?php echo !empty($st['basic_salary']) ? number_format((float)$st['basic_salary'], 2) . ' ج.م' : '<span class="text-muted">-</span>'; ?></td>
                        <td><strong class="text-success"><?php echo !empty($st['net_salary']) ? number_format((float)$st['net_salary'], 2) . ' ج.م' : '<span class="text-muted">-</span>'; ?></strong></td>
                        <td>
                            <?php if ($hasFinData): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                    <i class="fas fa-check-circle me-1"></i>مسجل مالياً
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">
                                    <i class="fas fa-exclamation-circle me-1"></i>غير مسجل
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $isOnDuty ? 'success' : 'secondary'; ?>">
                                <?php echo $isOnDuty ? 'على رأس العمل' : 'ليس على رأس العمل'; ?>
                            </span>
                        </td>
                        <td class="text-center actions-column admin-table-actions">
                            <button type="button" class="btn btn-action-pills btn-edit btn-edit-financial"
                                    data-id="<?php echo (int)$st['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($st['display_name']); ?>"
                                    data-bs-toggle="tooltip" title="تعديل البيانات المالية" aria-label="تعديل البيانات المالية">
                                <i class="fas fa-pen"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Financial Modal -->
<div class="modal fade" id="editFinancialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content admin-modal admin-modal-premium">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2 text-primary"></i>تعديل البيانات المالية: <span id="modalStaffDisplayName" class="fw-bold text-dark"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" id="modalStaffFinancialForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="staff_id" id="modalStaffId" value="0">
                    <input type="hidden" name="action" value="save_financial_data">

                    <!-- Loading Spinner -->
                    <div id="modalLoadingSpinner" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">جاري التحميل...</span>
                        </div>
                        <p class="text-muted mt-2">جاري تحميل البيانات المالية للعامل...</p>
                    </div>

                    <!-- Form Content -->
                    <div id="modalFormContent" style="display: none;">
                        <div class="alert alert-light border rounded-3 p-3 mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2 shadow-xs">
                            <div>
                                <span class="fw-bold text-dark fs-6" id="modalStaffNameBadge"></span>
                                <span class="text-muted small ms-2" id="modalStaffJobBadge"></span>
                            </div>
                            <span class="badge px-3 py-2" id="modalStaffStatusBadge"></span>
                        </div>

                        <div class="p-2 px-3 mb-3 bg-light rounded-3 border-start border-3 border-success text-dark fw-bold d-flex align-items-center">
                            <i class="fas fa-money-bill-wave text-success me-2 fs-5"></i>الراتب والبدلات
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">الراتب الأساسي</label>
                                <input type="number" class="form-control salary-input" id="m_basic_salary" name="basic_salary" step="0.01" min="0" dir="ltr">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">بدل انتقال</label>
                                <input type="number" class="form-control salary-input" id="m_allowance_transport" name="allowance_transport" step="0.01" min="0" dir="ltr">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">بدل سكن</label>
                                <input type="number" class="form-control salary-input" id="m_allowance_housing" name="allowance_housing" step="0.01" min="0" dir="ltr">
                            </div>
                        </div>

                        <div class="p-2 px-3 mb-3 mt-4 bg-light rounded-3 border-start border-3 border-info text-dark fw-bold d-flex align-items-center">
                            <i class="fas fa-plus-circle text-info me-2 fs-5"></i>بدلات أخرى
                        </div>
                        <input type="hidden" name="other_allowances_data" id="other_allowances_data_field" value="[]">
                        <div id="other_allowances_container"></div>
                        <button type="button" class="btn btn-outline-success btn-sm mt-2 shadow-xs" id="add_other_allowance">
                            <i class="fas fa-plus me-1"></i>إضافة بدل
                        </button>

                        <hr class="my-4">
                        <div class="p-2 px-3 mb-3 bg-light rounded-3 border-start border-3 border-danger text-dark fw-bold d-flex align-items-center">
                            <i class="fas fa-minus-circle text-danger me-2 fs-5"></i>الاستقطاعات
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">تأمينات</label>
                                <input type="number" class="form-control salary-input" id="m_deduction_insurance" name="deduction_insurance" step="0.01" min="0" dir="ltr">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">ضرائب</label>
                                <input type="number" class="form-control salary-input" id="m_deduction_tax" name="deduction_tax" step="0.01" min="0" dir="ltr">
                            </div>
                        </div>

                        <div class="p-2 px-3 mb-3 mt-4 bg-light rounded-3 border-start border-3 border-danger text-dark fw-bold d-flex align-items-center">
                            <i class="fas fa-plus-circle text-danger me-2 fs-5"></i>استقطاعات أخرى
                        </div>
                        <input type="hidden" name="other_deductions_data" id="other_deductions_data_field" value="[]">
                        <div id="other_deductions_container"></div>
                        <button type="button" class="btn btn-outline-danger btn-sm mt-2 shadow-xs" id="add_other_deduction">
                            <i class="fas fa-plus me-1"></i>إضافة استقطاع
                        </button>

                        <hr class="my-4">
                        <div class="p-2 px-3 mb-3 bg-light rounded-3 border-start border-3 border-primary text-dark fw-bold d-flex align-items-center">
                            <i class="fas fa-calculator text-primary me-2 fs-5"></i>صافي المرتب
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">صافي المرتب</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="net_salary_field" name="net_salary" step="0.01" min="0" dir="ltr">
                                    <button type="button" class="btn btn-outline-primary" id="calc_net_salary" title="حساب تلقائي">
                                        <i class="fas fa-calculator me-1"></i> حساب تلقائي
                                    </button>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">
                        <div class="p-2 px-3 mb-3 bg-light rounded-3 border-start border-3 border-warning text-dark fw-bold d-flex align-items-center">
                            <i class="fas fa-hand-holding-usd text-warning me-2 fs-5"></i>السلف والقروض
                        </div>
                        <input type="hidden" name="advances_data" id="advances_data_field" value="[]">
                        <div id="advances_container"></div>
                        <button type="button" class="btn btn-outline-warning btn-sm mt-2 shadow-xs" id="add_advance">
                            <i class="fas fa-plus me-1"></i>إضافة سلفة / قرض
                        </button>

                        <hr class="my-4">
                        <div class="p-2 px-3 mb-3 bg-light rounded-3 border-start border-3 border-secondary text-dark fw-bold d-flex align-items-center">
                            <i class="fas fa-sticky-note text-secondary me-2 fs-5"></i>ملاحظات مالية عامة
                        </div>
                        <textarea class="form-control mb-3" id="m_financial_notes" name="financial_notes" rows="2" placeholder="ملاحظات مالية إضافية"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm"><i class="fas fa-save me-1"></i>حفظ البيانات المالية</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="removeFinanceRowModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>تأكيد الحذف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-center mb-0" id="removeFinanceRowMessage">هل تريد حذف هذا البند؟</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmRemoveFinanceRow"><i class="fas fa-trash me-1"></i>حذف</button>
            </div>
        </div>
    </div>
</div>

<!-- Table Settings Modal -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">اختر الأعمدة المراد إظهارها أو إخفاؤها من جدول البيانات المالية.</p>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input toggle-col-btn" type="checkbox" id="colCode" data-column="1" checked>
                    <label class="form-check-label fw-semibold" for="colCode">كود الموظف</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input toggle-col-btn" type="checkbox" id="colJob" data-column="3" checked>
                    <label class="form-check-label fw-semibold" for="colJob">المسمى الوظيفي</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input toggle-col-btn" type="checkbox" id="colBasic" data-column="4" checked>
                    <label class="form-check-label fw-semibold" for="colBasic">الراتب الأساسي</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input toggle-col-btn" type="checkbox" id="colNet" data-column="5" checked>
                    <label class="form-check-label fw-semibold" for="colNet">صافي المرتب</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input toggle-col-btn" type="checkbox" id="colFinStatus" data-column="6" checked>
                    <label class="form-check-label fw-semibold" for="colFinStatus">الحالة المالية</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input toggle-col-btn" type="checkbox" id="colWorkStatus" data-column="7" checked>
                    <label class="form-check-label fw-semibold" for="colWorkStatus">حالة العمل</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
var financeRemoveCallback = null;
function financeConfirmDelete(message, callback) {
    financeRemoveCallback = callback;
    var modalEl = document.getElementById('removeFinanceRowModal');
    document.getElementById('removeFinanceRowMessage').textContent = message;
    new bootstrap.Modal(modalEl).show();
}
document.getElementById('confirmRemoveFinanceRow')?.addEventListener('click', function() {
    if (typeof financeRemoveCallback === 'function') {
        financeRemoveCallback();
    }
    bootstrap.Modal.getInstance(document.getElementById('removeFinanceRowModal'))?.hide();
    financeRemoveCallback = null;
});

function parseJsonArray(id) {
    try {
        var data = JSON.parse(document.getElementById(id)?.value || '[]');
        return Array.isArray(data) ? data : [];
    } catch(e) {
        return [];
    }
}
function esc(value) {
    // تهريب كامل لجميع الأحرف الخاصة بـ HTML لتأمين القيم المُدمجة في سمات HTML
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

var otherAllowancesData = parseJsonArray('other_allowances_data_field');
var otherDeductionsData = parseJsonArray('other_deductions_data_field');
var advancesData = parseJsonArray('advances_data_field');

function renderSimpleRows(containerId, rows, nameClass, amountClass, removeClass, placeholder, hiddenId) {
    var container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '';
    rows.forEach(function(item, idx) {
        var row = document.createElement('div');
        row.className = 'row g-2 mb-2 align-items-end';
        row.innerHTML =
            '<div class="col-md-5"><input type="text" class="form-control form-control-sm ' + nameClass + '" placeholder="' + placeholder + '" value="' + esc(item.name) + '" data-idx="' + idx + '"></div>' +
            '<div class="col-md-4"><input type="number" class="form-control form-control-sm ' + amountClass + '" step="0.01" min="0" placeholder="القيمة" value="' + esc(item.amount) + '" data-idx="' + idx + '" dir="ltr"></div>' +
            '<div class="col-md-3"><button type="button" class="btn btn-outline-danger btn-sm ' + removeClass + '" data-idx="' + idx + '"><i class="fas fa-trash me-1"></i>حذف</button></div>';
        container.appendChild(row);
    });
    document.getElementById(hiddenId).value = JSON.stringify(rows.map(function(item) {
        return {name: item.name || '', amount: item.amount || ''};
    }));
}
function renderOtherAllowances() {
    renderSimpleRows('other_allowances_container', otherAllowancesData, 'oa-name', 'oa-amount', 'oa-remove', 'مسمى البدل', 'other_allowances_data_field');
}
function renderOtherDeductions() {
    renderSimpleRows('other_deductions_container', otherDeductionsData, 'od-name', 'od-amount', 'od-remove', 'مسمى الاستقطاع', 'other_deductions_data_field');
}
function syncOtherAllowances() {
    document.getElementById('other_allowances_data_field').value = JSON.stringify(otherAllowancesData.map(function(item) {
        return {name: item.name || '', amount: item.amount || ''};
    }));
}
function syncOtherDeductions() {
    document.getElementById('other_deductions_data_field').value = JSON.stringify(otherDeductionsData.map(function(item) {
        return {name: item.name || '', amount: item.amount || ''};
    }));
}
function syncAdvances() {
    document.getElementById('advances_data_field').value = JSON.stringify(advancesData.map(function(item) {
        return {name: item.name || '', amount: item.amount || '', date: item.date || '', paid: item.paid || '', notes: item.notes || '', payments: Array.isArray(item.payments) ? item.payments : []};
    }));
}
function renderAdvances() {
    var container = document.getElementById('advances_container');
    if (!container) return;
    container.innerHTML = '';
    advancesData.forEach(function(item, idx) {
        var row = document.createElement('div');
        row.className = 'row g-2 mb-2 align-items-end';
        row.innerHTML =
            '<div class="col-md-3"><input type="text" class="form-control form-control-sm adv-name" placeholder="مسمى السلفة" value="' + esc(item.name) + '" data-idx="' + idx + '"></div>' +
            '<div class="col-md-2"><input type="number" class="form-control form-control-sm adv-amount" step="0.01" min="0" placeholder="القيمة" value="' + esc(item.amount) + '" data-idx="' + idx + '" dir="ltr"></div>' +
            '<div class="col-md-2"><input type="text" class="form-control form-control-sm adv-date flatpickr-date" placeholder="اختر التاريخ..." value="' + esc(item.date) + '" data-idx="' + idx + '"></div>' +
            '<div class="col-md-2"><input type="number" class="form-control form-control-sm adv-paid" step="0.01" min="0" placeholder="المسدد" value="' + esc(item.paid) + '" data-idx="' + idx + '" dir="ltr"></div>' +
            '<div class="col-md-2"><input type="text" class="form-control form-control-sm adv-notes" placeholder="ملاحظات" value="' + esc(item.notes) + '" data-idx="' + idx + '"></div>' +
            '<div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm adv-remove" data-idx="' + idx + '" title="حذف"><i class="fas fa-trash"></i></button></div>';
        container.appendChild(row);
        // تهيئة Air Datepicker على حقول التاريخ المُحقنة ديناميكياً
        if (typeof initAirDatepickers === 'function') {
            initAirDatepickers(row);
        }
    });
    syncAdvances();
}

document.getElementById('add_other_allowance')?.addEventListener('click', function() {
    otherAllowancesData.push({name: '', amount: ''});
    renderOtherAllowances();
});
document.getElementById('add_other_deduction')?.addEventListener('click', function() {
    otherDeductionsData.push({name: '', amount: ''});
    renderOtherDeductions();
});
document.getElementById('add_advance')?.addEventListener('click', function() {
    advancesData.push({name: '', amount: '', date: '', paid: '', notes: ''});
    renderAdvances();
});

document.getElementById('other_allowances_container')?.addEventListener('input', function(e) {
    var idx = parseInt(e.target.dataset.idx, 10);
    if (isNaN(idx) || !otherAllowancesData[idx]) return;
    if (e.target.classList.contains('oa-name')) otherAllowancesData[idx].name = e.target.value;
    if (e.target.classList.contains('oa-amount')) otherAllowancesData[idx].amount = e.target.value;
    syncOtherAllowances();
});
document.getElementById('other_deductions_container')?.addEventListener('input', function(e) {
    var idx = parseInt(e.target.dataset.idx, 10);
    if (isNaN(idx) || !otherDeductionsData[idx]) return;
    if (e.target.classList.contains('od-name')) otherDeductionsData[idx].name = e.target.value;
    if (e.target.classList.contains('od-amount')) otherDeductionsData[idx].amount = e.target.value;
    syncOtherDeductions();
});
document.getElementById('advances_container')?.addEventListener('input', function(e) {
    var idx = parseInt(e.target.dataset.idx, 10);
    if (isNaN(idx) || !advancesData[idx]) return;
    if (e.target.classList.contains('adv-name')) advancesData[idx].name = e.target.value;
    if (e.target.classList.contains('adv-amount')) advancesData[idx].amount = e.target.value;
    if (e.target.classList.contains('adv-date')) advancesData[idx].date = e.target.value;
    if (e.target.classList.contains('adv-paid')) advancesData[idx].paid = e.target.value;
    if (e.target.classList.contains('adv-notes')) advancesData[idx].notes = e.target.value;
    syncAdvances();
});

document.addEventListener('click', function(e) {
    var btn = e.target.closest('.oa-remove, .od-remove, .adv-remove');
    if (!btn) return;
    financeConfirmDelete('هل تريد حذف هذا البند؟', function() {
        var idx = parseInt(btn.dataset.idx, 10);
        if (btn.classList.contains('oa-remove')) {
            otherAllowancesData.splice(idx, 1);
            renderOtherAllowances();
        } else if (btn.classList.contains('od-remove')) {
            otherDeductionsData.splice(idx, 1);
            renderOtherDeductions();
        } else {
            advancesData.splice(idx, 1);
            renderAdvances();
        }
    });
});

document.getElementById('calc_net_salary')?.addEventListener('click', function() {
    var basic = parseFloat(document.getElementById('m_basic_salary').value) || 0;
    var transport = parseFloat(document.getElementById('m_allowance_transport').value) || 0;
    var housing = parseFloat(document.getElementById('m_allowance_housing').value) || 0;
    var allowanceExtra = otherAllowancesData.reduce(function(total, row) { return total + (parseFloat(row.amount) || 0); }, 0);
    var insurance = parseFloat(document.getElementById('m_deduction_insurance').value) || 0;
    var tax = parseFloat(document.getElementById('m_deduction_tax').value) || 0;
    var deductionExtra = otherDeductionsData.reduce(function(total, row) { return total + (parseFloat(row.amount) || 0); }, 0);
    document.getElementById('net_salary_field').value = (basic + transport + housing + allowanceExtra - insurance - tax - deductionExtra).toFixed(2);
});

document.getElementById('modalStaffFinancialForm')?.addEventListener('submit', function() {
    renderOtherAllowances();
    renderOtherDeductions();
    renderAdvances();
});

document.querySelectorAll('.toggle-col-btn').forEach(function(checkbox) {
    checkbox.addEventListener('change', function() {
        var colIdx = parseInt(this.dataset.column, 10);
        if (typeof $.fn.DataTable !== 'undefined' && $.fn.DataTable.isDataTable('#staffFinancialTable')) {
            var table = $('#staffFinancialTable').DataTable();
            table.column(colIdx).visible(this.checked);
        }
    });
});

function parseJsonArrayStr(val) {
    try {
        var data = JSON.parse(val || '[]');
        return Array.isArray(data) ? data : [];
    } catch(e) {
        return [];
    }
}

document.addEventListener('click', function(e) {
    var editBtn = e.target.closest('.btn-edit-financial');
    if (!editBtn) return;

    var staffId = editBtn.dataset.id;
    var staffName = editBtn.dataset.name || '';

    document.getElementById('modalStaffId').value = staffId;
    document.getElementById('modalStaffDisplayName').textContent = staffName;
    document.getElementById('modalLoadingSpinner').style.display = 'block';
    document.getElementById('modalFormContent').style.display = 'none';

    var modalEl = document.getElementById('editFinancialModal');
    var bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    bsModal.show();

    fetch('staff_financial_data.php?action=get_staff_financial&staff_id=' + staffId)
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (res.success && res.data) {
                var d = res.data;
                document.getElementById('modalStaffNameBadge').textContent = d.display_name;
                document.getElementById('modalStaffJobBadge').textContent = d.job_title || '';
                var isOnDuty = (d.current_work_status || 'on_duty') !== 'off_duty';
                var statusBadge = document.getElementById('modalStaffStatusBadge');
                statusBadge.className = 'badge px-3 py-2 ' + (isOnDuty ? 'bg-success' : 'bg-secondary');
                statusBadge.textContent = isOnDuty ? 'على رأس العمل' : 'ليس على رأس العمل';

                document.getElementById('m_basic_salary').value = d.basic_salary || '';
                document.getElementById('m_allowance_transport').value = d.allowance_transport || '';
                document.getElementById('m_allowance_housing').value = d.allowance_housing || '';
                document.getElementById('m_deduction_insurance').value = d.deduction_insurance || '';
                document.getElementById('m_deduction_tax').value = d.deduction_tax || '';
                document.getElementById('net_salary_field').value = d.net_salary || '';
                document.getElementById('m_financial_notes').value = d.financial_notes || '';

                otherAllowancesData = parseJsonArrayStr(d.other_allowances_data);
                otherDeductionsData = parseJsonArrayStr(d.other_deductions_data);
                advancesData = parseJsonArrayStr(d.advances_data);

                renderOtherAllowances();
                renderOtherDeductions();
                renderAdvances();

                document.getElementById('modalLoadingSpinner').style.display = 'none';
                document.getElementById('modalFormContent').style.display = 'block';
            } else {
                alert(res.message || 'تعذر تحميل البيانات المالية للعامل');
                bsModal.hide();
            }
        })
        .catch(function() {
            alert('حدث خطأ في اتصال الخادم.');
            bsModal.hide();
        });
});

document.addEventListener('DOMContentLoaded', function() {
    if (typeof jQuery === 'undefined') return;

    var activeWorkStatusTab = 'on_duty';

    jQuery(document).on('click', '.staff-tab-btn', function() {
        jQuery('.staff-tab-btn').removeClass('active');
        jQuery(this).addClass('active');
        activeWorkStatusTab = jQuery(this).attr('data-status-tab') || 'on_duty';
        applyFilters();
    });

    function getCheckedValues(selector) {
        var vals = [];
        jQuery(selector + ':checked').each(function() {
            vals.push(jQuery(this).val());
        });
        return vals;
    }

    function updateFilterLabel(checkboxSelector, labelSelector) {
        var checked = jQuery(checkboxSelector + ':checked');
        if (checked.length === 0) {
            jQuery(labelSelector).text('الكل');
        } else if (checked.length === 1) {
            var txt = checked.first().next('label').text().trim();
            jQuery(labelSelector).text(txt);
        } else {
            jQuery(labelSelector).text(checked.length + ' تم تحديدهم');
        }
    }

    // Register DataTables search extension after DataTables loads
    if (jQuery.fn.DataTable) {
        jQuery.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (settings.nTable.id !== 'staffFinancialTable') return true;

            var node = settings.aoData[dataIndex].nTr;
            var job = node.getAttribute('data-job') || '';
            var finStatus = node.getAttribute('data-fin-status') || '';
            var status = node.getAttribute('data-status') || 'on_duty';

            if (status !== activeWorkStatusTab) {
                return false;
            }

            var selJobs = getCheckedValues('.job-checkbox');
            var selFin = getCheckedValues('.fin-checkbox');
            var selSt = getCheckedValues('.status-checkbox');

            if (selJobs.length > 0 && !selJobs.includes(job)) return false;
            if (selFin.length > 0 && !selFin.includes(finStatus)) return false;
            if (selSt.length > 0 && !selSt.includes(status)) return false;

            return true;
        });
    }

    function applyFilters() {
        updateFilterLabel('.job-checkbox', '#selectedJobsLabel');
        updateFilterLabel('.fin-checkbox', '#selectedFinLabel');
        updateFilterLabel('.status-checkbox', '#selectedStatusLabel');

        var selJobs = getCheckedValues('.job-checkbox');
        var selFin = getCheckedValues('.fin-checkbox');
        var selSt = getCheckedValues('.status-checkbox');

        if (jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable('#staffFinancialTable')) {
            jQuery('#staffFinancialTable').DataTable().draw();
        }

        jQuery('#staffFinancialTable tbody tr').each(function() {
            var $tr = jQuery(this);
            var job = $tr.attr('data-job') || '';
            var finStatus = $tr.attr('data-fin-status') || '';
            var status = $tr.attr('data-status') || 'on_duty';

            var matchTab = (status === activeWorkStatusTab);
            var matchJob = selJobs.length === 0 || selJobs.includes(job);
            var matchFin = selFin.length === 0 || selFin.includes(finStatus);
            var matchSt = selSt.length === 0 || selSt.includes(status);

            if (matchTab && matchJob && matchFin && matchSt) {
                $tr.css('display', '');
            } else {
                $tr.css('display', 'none');
            }
        });
    }

    jQuery(document).on('change', '.job-checkbox, .fin-checkbox, .status-checkbox', function() {
        applyFilters();
    });

    jQuery(document).on('click', '#resetFiltersBtn', function() {
        jQuery('.job-checkbox, .fin-checkbox, .status-checkbox').prop('checked', false);
        applyFilters();
    });

    applyFilters();
});

renderOtherAllowances();
renderOtherDeductions();
renderAdvances();
</script>

<?php include_once '../includes/admin_footer.php'; ?>
