<?php
/**
 * إدارة أرصدة الإجازات السنوية
 */
$page_title = "أرصدة الإجازات السنوية";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../vendor/autoload.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/Staff/bootstrap.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$staffModuleFactory = new \EduCore\Modules\Staff\Infrastructure\StaffModuleFactory(
    $db,
    new \EduCore\Modules\Operations\Audit\AuditService($db)
);
$leaveService = $staffModuleFactory->legacyLeaveCompatibility();
$leaveBalanceLedger = $staffModuleFactory->leaveBalanceLedger();
$leavePortalQuery = $staffModuleFactory->permissionPortal();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2020 || $year > 2100) {
    $year = (int)date('Y');
}

$filterRole = trim((string)($_GET['role'] ?? 'teacher'));
$allowedRoles = ['teacher' => 'المعلمون', 'specialist' => 'الأخصائيون', 'admin' => 'الإداريون', 'all' => 'جميع العاملين'];
if (!isset($allowedRoles[$filterRole])) {
    $filterRole = 'teacher';
}

$filterUserId = (int)($_GET['user_id'] ?? 0);

$leaveTypes = [
    'regular' => 'اعتيادية',
    'sick' => 'مرضية',
    'casual' => 'عارضة',
    'exceptional' => 'استثنائية',
    'other' => 'أخرى'
];
$roleLabels = ['teacher' => 'معلم', 'specialist' => 'أخصائي', 'admin' => 'إداري'];

$staffList = $leaveService->getActiveStaffList();
$deductibleTypes = $leaveService->getDeductibleTypes($leaveTypes);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectQuery = Utilities::buildQueryString(['year', 'role', 'user_id']);
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $_SESSION['error_message'] = 'خطأ في التحقق الأمني. يرجى إعادة المحاولة.';
        header('Location: leave_balances.php' . $redirectQuery);
        exit();
    }

    try {
        if (isset($_POST['record_opening_leave_balance'])) {
            $staffUserId = (int)($_POST['staff_user_id'] ?? 0);
            $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
            $periodKey = trim((string)($_POST['entitlement_period_key'] ?? ''));
            $periodFrom = trim((string)($_POST['period_from'] ?? ''));
            $periodTo = trim((string)($_POST['period_to'] ?? ''));
            $units = trim((string)($_POST['opening_units'] ?? ''));
            $activeLeaveView = $leavePortalQuery->leaveForStaff($staffUserId);
            $activeLeaveTypeIds = array_map(
                static fn(array $row): int => (int)($row['id'] ?? 0),
                (array)($activeLeaveView['leave_types'] ?? [])
            );
            if (!in_array($leaveTypeId, $activeLeaveTypeIds, true)) {
                throw new InvalidArgumentException('نوع الإجازة المحدد غير متاح لهذا العامل.');
            }
            $logicalKey = hash('sha256', implode('|', ['opening', $staffUserId, $leaveTypeId, $periodKey]));
            $receipt = $leaveBalanceLedger->record([
                'actor_id' => (int)($_SESSION['user_id'] ?? 0),
                'staff_user_id' => $staffUserId,
                'leave_type_id' => $leaveTypeId,
                'entitlement_period_key' => $periodKey,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'negative_balance_limit_units' => '0.000',
                'movement_type' => 'grant',
                'units' => $units,
                'source_type' => 'admin_opening_balance',
                'source_id' => null,
                'logical_key' => $logicalKey,
                'idempotency_key' => 'staff-leave-opening:' . $staffUserId . ':' . $leaveTypeId . ':' . $periodKey,
                'reason_code' => 'OPENING_BALANCE',
            ]);
            $_SESSION['success_message'] = !empty($receipt['replayed'])
                ? 'الرصيد الافتتاحي مسجل مسبقًا ولم تتم مضاعفته.'
                : 'تم تسجيل الرصيد الافتتاحي في سجل الحركات الآمن.';
        } elseif (isset($_POST['save_balance_policy'])) {
            $labels = $_POST['policy_label'] ?? [];
            $monthsFrom = $_POST['months_from'] ?? [];
            $monthsTo = $_POST['months_to'] ?? [];
            $balances = $_POST['policy_balance'] ?? [];
            $tiers = [];
            $rowCount = max(count($labels), count($monthsFrom), count($monthsTo), count($balances));

            for ($index = 0; $index < $rowCount; $index++) {
                $tiers[] = [
                    'label' => $labels[$index] ?? '',
                    'months_from' => $monthsFrom[$index] ?? '',
                    'months_to' => $monthsTo[$index] ?? '',
                    'balance' => $balances[$index] ?? ''
                ];
            }

            $leaveService->saveLeaveBalancePolicy($tiers);
            $_SESSION['success_message'] = 'تم حفظ سياسات رصيد الإجازات بنجاح';
        } elseif (isset($_POST['apply_balance_policy'])) {
            $updatedCount = $leaveService->applyLeaveBalancePolicy(
                (int)($_POST['year'] ?? $year),
                $deductibleTypes,
                (string)($_POST['role'] ?? $filterRole),
                !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null
            );
            $_SESSION['success_message'] = 'تم تطبيق السياسة على ' . $updatedCount . ' موظف';
        } elseif (isset($_POST['save_staff_balance'])) {
            $leaveService->updateAnnualLeaveBalance(
                (int)($_POST['staff_user_id'] ?? 0),
                (float)($_POST['annual_leave_balance'] ?? 0),
                (string)($_POST['leave_balance_notes'] ?? '')
            );
            $_SESSION['success_message'] = 'تم تحديث رصيد الإجازات بنجاح';
        }
    } catch (InvalidArgumentException $exception) {
        $_SESSION['error_message'] = $exception->getMessage();
    } catch (Throwable $exception) {
        $reference = 'LEAVE-BAL-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        error_log($reference . ' legacy leave balance compatibility error: ' . $exception->getMessage());
        $_SESSION['error_message'] = 'تعذر تنفيذ عملية أرصدة الإجازات الآن. لم يتم حفظ أي تغيير غير مكتمل. مرجع المتابعة: ' . $reference;
    }

    header('Location: leave_balances.php' . $redirectQuery);
    exit();
}

$policyTiers = $leaveService->getLeaveBalancePolicy();
$balanceRows = $leaveService->getAnnualLeaveBalanceRows($year, $deductibleTypes, $filterUserId > 0 ? $filterUserId : null, $filterRole);
$ledgerLeaveTypes = [];
$ledgerBalanceRows = [];
if ($filterUserId > 0) {
    try {
        $leaveLedgerView = $leavePortalQuery->leaveForStaff($filterUserId);
        $ledgerLeaveTypes = (array)($leaveLedgerView['leave_types'] ?? []);
        $ledgerBalanceRows = (array)($leaveLedgerView['balance_rows'] ?? []);
    } catch (Throwable $exception) {
        $ledgerLeaveTypes = [];
        $ledgerBalanceRows = [];
    }
}

$totalEffectiveBalance = 0.0;
$totalConsumedDays = 0.0;
$totalRemainingDays = 0.0;
foreach ($balanceRows as $balanceRow) {
    $totalEffectiveBalance += (float)$balanceRow['effective_balance'];
    $totalConsumedDays += (float)$balanceRow['consumed_days'];
    $totalRemainingDays += (float)$balanceRow['remaining_days'];
}

$balanceSummaryCards = [
    ['value' => count($balanceRows), 'label' => 'عدد السجلات', 'icon' => 'fa-users', 'gradient' => '#3b82f6, #2563eb'],
    ['value' => number_format($totalEffectiveBalance, 1), 'label' => 'إجمالي الأرصدة', 'icon' => 'fa-wallet', 'gradient' => '#10b981, #059669'],
    ['value' => number_format($totalConsumedDays, 1), 'label' => 'المستهلك', 'icon' => 'fa-calendar-minus', 'gradient' => '#f59e0b, #d97706'],
    ['value' => number_format($totalRemainingDays, 1), 'label' => 'المتبقي', 'icon' => 'fa-calendar-check', 'gradient' => '#8b5cf6, #7c3aed'],
];

require_once '../includes/admin_header.php';
require_once '../includes/widgets/hr_stat_cards.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-wallet me-2"></i>أرصدة الإجازات السنوية</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="leaves.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-calendar-check me-1"></i>العودة إلى الأجازات</a>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php renderHrStatCards($balanceSummaryCards); ?>

<div class="card shadow admin-card-surface mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-book-open me-2"></i>سجل أرصدة الإجازات الحديث</h5>
    </div>
    <div class="card-body">
        <?php if ($filterUserId <= 0): ?>
            <div class="alert alert-info mb-0"><i class="fas fa-circle-info me-2"></i>اختر عاملًا من فلتر الأرصدة أدناه لإضافة رصيد افتتاحي أو مراجعة فترات استحقاقه.</div>
        <?php elseif ($ledgerLeaveTypes === []): ?>
            <div class="alert alert-warning mb-0"><i class="fas fa-triangle-exclamation me-2"></i>لا توجد أنواع إجازات حديثة متاحة للعامل المحدد.</div>
        <?php else: ?>
            <form method="post" id="openingLeaveBalanceForm" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="staff_user_id" value="<?php echo $filterUserId; ?>">
                <div class="col-md-4">
                    <label class="form-label" for="openingLeaveType">نوع الإجازة</label>
                    <select class="form-select" name="leave_type_id" id="openingLeaveType" required>
                        <?php foreach ($ledgerLeaveTypes as $type): ?>
                            <option value="<?php echo (int)($type['id'] ?? 0); ?>"><?php echo htmlspecialchars((string)($type['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="openingPeriodKey">مفتاح الفترة</label>
                    <input class="form-control" name="entitlement_period_key" id="openingPeriodKey" value="CY<?php echo $year; ?>" maxlength="80" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="openingPeriodFrom">من</label>
                    <input type="date" class="form-control" name="period_from" id="openingPeriodFrom" value="<?php echo $year; ?>-01-01" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="openingPeriodTo">إلى</label>
                    <input type="date" class="form-control" name="period_to" id="openingPeriodTo" value="<?php echo $year; ?>-12-31" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="openingUnits">الرصيد</label>
                    <input type="number" min="0.001" step="0.001" class="form-control" name="opening_units" id="openingUnits" value="21.000" required>
                </div>
                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" name="record_opening_leave_balance" value="1" class="btn btn-success"><i class="fas fa-plus-circle me-1"></i>تسجيل رصيد افتتاحي</button>
                </div>
            </form>
            <?php if ($ledgerBalanceRows !== []): ?>
                <div class="table-responsive admin-table-wrap mt-4">
                    <table class="table table-hover table-striped admin-data-table mb-0">
                        <thead><tr><th>النوع</th><th>فترة الاستحقاق</th><th>المتاح</th><th>المحجوز</th><th>المستخدم</th></tr></thead>
                        <tbody>
                        <?php foreach ($ledgerBalanceRows as $ledgerRow): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($ledgerRow['type_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="font-monospace"><?php echo htmlspecialchars((string)($ledgerRow['period_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($ledgerRow['available_units'] ?? '0.000'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($ledgerRow['held_units'] ?? '0.000'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($ledgerRow['used_units'] ?? '0.000'), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>سياسات الرصيد حسب تاريخ التعيين</h5>
            <span class="badge bg-light text-dark"><?php echo count($policyTiers); ?> سياسة</span>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" id="leavePolicyForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-3" id="policyRowsTable">
                    <thead>
                        <tr>
                            <th>مسمى السياسة</th>
                            <th width="140">من شهر خدمة</th>
                            <th width="140">إلى شهر خدمة</th>
                            <th width="160">الرصيد السنوي</th>
                            <th width="90">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($policyTiers as $tier): ?>
                        <tr>
                            <td><input type="text" class="form-control" name="policy_label[]" value="<?php echo htmlspecialchars($tier['label'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                            <td><input type="number" min="0" class="form-control" name="months_from[]" value="<?php echo htmlspecialchars((string)$tier['months_from'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                            <td><input type="number" min="0" class="form-control" name="months_to[]" value="<?php echo $tier['months_to'] === null ? '' : htmlspecialchars((string)$tier['months_to'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="مفتوح"></td>
                            <td><input type="number" min="0" step="0.5" class="form-control" name="policy_balance[]" value="<?php echo htmlspecialchars((string)$tier['balance'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-policy-row"><i class="fas fa-trash"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-success" id="addPolicyRowBtn"><i class="fas fa-plus me-1"></i>إضافة سياسة</button>
                <button type="submit" name="save_balance_policy" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ السياسات</button>
            </div>
            <div class="alert alert-info mt-3 mb-0">
                <i class="fas fa-info-circle me-2"></i>تُحسب مدة الخدمة بعدد الأشهر من تاريخ التعيين حتى نهاية السنة المختارة، ثم يُحدد الرصيد المقترح من أول سياسة تنطبق على المدة.
            </div>
        </form>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <div class="row align-items-center">
            <div class="col-md-3">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>فلاتر الأرصدة <span class="badge bg-light text-dark ms-2"><?php echo count($balanceRows); ?></span></h5>
            </div>
            <div class="col-md-9">
                <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <input type="number" class="form-control form-control-sm" name="year" value="<?php echo $year; ?>" min="2020" max="2100" style="width:110px;">
                    <select class="form-select form-select-sm" name="role" style="width:auto; min-width:160px;">
                        <?php foreach ($allowedRoles as $roleKey => $roleLabel): ?>
                        <option value="<?php echo htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterRole === $roleKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" name="user_id" style="width:auto; min-width:180px;">
                        <option value="0">كل الموظفين</option>
                        <?php foreach ($staffList as $staffMember): ?>
                        <option value="<?php echo (int)$staffMember['id']; ?>" <?php echo $filterUserId === (int)$staffMember['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($staffMember['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>تطبيق</button>
                    <a href="leave_balances.php" class="btn btn-light btn-sm"><i class="fas fa-times me-1"></i>مسح</a>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body border-bottom">
        <form method="POST" class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="year" value="<?php echo $year; ?>">
            <input type="hidden" name="role" value="<?php echo htmlspecialchars($filterRole, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="user_id" value="<?php echo $filterUserId; ?>">
            <div class="text-muted small">يمكنك تعديل الرصيد يدوياً لكل موظف، أو تطبيق السياسة الحالية دفعة واحدة على النتائج المعروضة.</div>
            <button type="submit" name="apply_balance_policy" class="btn btn-outline-success"><i class="fas fa-magic me-1"></i>تطبيق السياسة على النتائج الحالية</button>
        </form>
    </div>
    <div class="card-body">
        <?php if (!empty($balanceRows)): ?>
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th width="50">#</th>
                        <th>الموظف</th>
                        <th>النوع</th>
                        <th>تاريخ التعيين</th>
                        <th>مدة الخدمة</th>
                        <th>السياسة</th>
                        <th>الرصيد المقترح</th>
                        <th>الرصيد الحالي</th>
                        <th>المستهلك</th>
                        <th>المتبقي</th>
                        <th>ملاحظات</th>
                        <th width="110">حفظ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($balanceRows as $index => $row): ?>
                    <?php $rowFormId = 'balance-form-' . (int)$row['user_id']; ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($row['staff_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($roleLabels[$row['role']] ?? $row['role'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?php echo !empty($row['hire_date']) ? htmlspecialchars($row['hire_date'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">غير مسجل</span>'; ?></td>
                        <td><?php echo $row['service_months'] === null ? '<span class="text-muted">-</span>' : (int)$row['service_months'] . ' شهر'; ?></td>
                        <td><?php echo htmlspecialchars($row['policy_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="badge bg-info"><?php echo number_format((float)$row['policy_balance'], 1); ?> يوم</span></td>
                        <td>
                            <input type="number" min="0" step="0.5" name="annual_leave_balance" form="<?php echo $rowFormId; ?>" class="form-control form-control-sm" value="<?php echo htmlspecialchars(number_format((float)$row['effective_balance'], 1, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" style="width:95px;">
                        </td>
                        <td><span class="badge bg-warning text-dark"><?php echo number_format((float)$row['consumed_days'], 1); ?> يوم</span></td>
                        <td><span class="badge bg-success"><?php echo number_format((float)$row['remaining_days'], 1); ?> يوم</span></td>
                        <td><input type="text" name="leave_balance_notes" form="<?php echo $rowFormId; ?>" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string)($row['leave_balance_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="ملاحظات الرصيد"></td>
                        <td>
                            <form method="POST" id="<?php echo $rowFormId; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="staff_user_id" value="<?php echo (int)$row['user_id']; ?>">
                                <button type="submit" name="save_staff_balance" class="btn btn-sm btn-outline-primary w-100"><i class="fas fa-save"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>لا توجد سجلات تطابق الفلاتر الحالية.</div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var addPolicyRowBtn = document.getElementById('addPolicyRowBtn');
    var policyTableBody = document.querySelector('#policyRowsTable tbody');

    function bindRemoveButtons() {
        document.querySelectorAll('.remove-policy-row').forEach(function (button) {
            button.onclick = function () {
                if (policyTableBody.querySelectorAll('tr').length > 1) {
                    this.closest('tr').remove();
                }
            };
        });
    }

    addPolicyRowBtn.addEventListener('click', function () {
        var row = document.createElement('tr');
        row.innerHTML = '' +
            '<td><input type="text" class="form-control" name="policy_label[]" value="" required></td>' +
            '<td><input type="number" min="0" class="form-control" name="months_from[]" value="" required></td>' +
            '<td><input type="number" min="0" class="form-control" name="months_to[]" value="" placeholder="مفتوح"></td>' +
            '<td><input type="number" min="0" step="0.5" class="form-control" name="policy_balance[]" value="" required></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger remove-policy-row"><i class="fas fa-trash"></i></button></td>';
        policyTableBody.appendChild(row);
        bindRemoveButtons();
    });

    bindRemoveButtons();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
