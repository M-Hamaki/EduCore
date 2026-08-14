<?php

declare(strict_types=1);

/**
 * AJAX endpoint for the student completeness list.
 * The selected academic year's enrollment row is the authoritative academic source.
 */
ob_start();

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Students/StudentCompletenessConfigService.php';
require_once '../src/Modules/Students/StudentCompletenessReadRepository.php';
require_once '../src/Modules/Students/Presentation/StudentCompletenessPresenter.php';

use EduCore\Modules\Students\Presentation\StudentCompletenessPresenter;
use EduCore\Modules\Students\StudentCompletenessConfigService;
use EduCore\Modules\Students\StudentCompletenessReadRepository;

Utilities::validateSession('admin');

/** @param array<string,mixed> $payload */
$respond = static function (array $payload, int $status = 200): void {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $db = (new Database())->getConnection();
    $selectedAcademicYear = AcademicYear::getCurrent($db);
    $activeAcademicYear = AcademicYear::getActive($db);
    if (!$selectedAcademicYear) {
        throw new InvalidArgumentException('لا يوجد عام دراسي مختار للعرض.');
    }
    $academicYearId = (int) $selectedAcademicYear['id'];
    $activeAcademicYearId = $activeAcademicYear ? (int) $activeAcademicYear['id'] : 0;

    $scope = new ScopedStaffPortalContext($db, $academicYearId);
    $allowedClassIds = $scope->allowedClassIds();
    $configService = new StudentCompletenessConfigService($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fields_config') {
        requireCsrfPost();
        $activeRole = trim((string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? ''));
        $canManageConfig = !$scope->isScoped() && in_array($activeRole, ['admin', 'super_admin'], true);
        if (!$canManageConfig) {
            $respond(['success' => false, 'message' => 'لا تملك صلاحية تعديل إعدادات الاحتساب.'], 403);
        }

        $decoded = json_decode((string) ($_POST['fields'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('بيانات إعدادات الحقول غير صالحة.');
        }
        $result = $configService->save($decoded);
        $respond([
            'success' => true,
            'message' => 'تم حفظ إعدادات الاحتساب وتسجيل العملية بنجاح.',
            'undo_id' => $result['undo_id'],
        ]);
    }

    $config = $configService->load();
    $repository = new StudentCompletenessReadRepository(
        $db,
        $academicYearId,
        $activeAcademicYearId,
        $config['fields']
    );

    $readIds = static function (string $plural, string $single): array {
        $value = $_GET[$plural] ?? ($_GET[$single] ?? []);
        if (!is_array($value)) {
            $value = $value === '' ? [] : [$value];
        }
        return array_values(array_unique(array_filter(
            array_map('intval', $value),
            static fn(int $id): bool => $id > 0
        )));
    };
    $searchValue = $_GET['search']['value'] ?? ($_GET['search_value'] ?? '');
    $filters = [
        'stage_ids' => $readIds('stage_ids', 'stage_id'),
        'grade_ids' => $readIds('grade_ids', 'grade_id'),
        'class_ids' => $readIds('class_ids', 'class_id'),
        'enrollment_status' => trim((string) ($_GET['enrollment_status'] ?? 'enrolled')),
        'academic_status' => trim((string) ($_GET['academic_status'] ?? '')),
        'annual_state' => trim((string) ($_GET['annual_state'] ?? '')),
        'profile_level' => trim((string) ($_GET['profile_level'] ?? ($_GET['level'] ?? ''))),
        'missing_section' => trim((string) ($_GET['missing_section'] ?? '')),
        'experimental_scope' => trim((string) ($_GET['experimental_scope'] ?? 'official')),
        'search' => trim((string) $searchValue),
    ];

    $action = trim((string) ($_GET['action'] ?? ''));
    if ($action === 'stats') {
        $respond($repository->stats($filters, $allowedClassIds));
    }

    if ($action !== 'datatable_data') {
        $respond(['success' => false, 'message' => 'الإجراء المطلوب غير معروف.'], 400);
    }

    $draw = max(1, (int) ($_GET['draw'] ?? 1));
    $start = max(0, (int) ($_GET['start'] ?? 0));
    $requestedLength = (int) ($_GET['length'] ?? 50);
    $length = $requestedLength === -1 ? -1 : max(10, min(500, $requestedLength));
    $orderColumn = (int) ($_GET['order'][0]['column'] ?? 1);
    $orderDirection = strtolower((string) ($_GET['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    $sortMap = [
        1 => 'name',
        2 => 'stage_name',
        4 => 'profile_pct',
        5 => 'annual_state',
    ];

    $result = $repository->dataTable(
        $filters,
        $allowedClassIds,
        $start,
        $length,
        $sortMap[$orderColumn] ?? 'name',
        $orderDirection
    );
    $presenter = new StudentCompletenessPresenter();
    $data = [];
    foreach ($result['data'] as $index => $record) {
        $data[] = $presenter->dataTableRow($record, $start + $index + 1);
    }

    $respond([
        'draw' => $draw,
        'recordsTotal' => $result['recordsTotal'],
        'recordsFiltered' => $result['recordsFiltered'],
        'data' => $data,
    ]);
} catch (InvalidArgumentException $exception) {
    $respond(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Student completeness endpoint failed: ' . $exception->getMessage());
    $respond(['success' => false, 'message' => 'تعذر تحميل بيانات اكتمال الطلاب حالياً.'], 500);
}
