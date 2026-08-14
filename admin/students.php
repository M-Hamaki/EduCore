<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$studentDataScope = defined('STUDENT_DATA_SCOPE') ? STUDENT_DATA_SCOPE : ($_GET['scope'] ?? ($_GET['student_scope'] ?? ($_POST['student_scope'] ?? 'current')));
$studentDataScope = in_array($studentDataScope, ['current', 'graduates', 'transferred', 'discontinued'], true) ? $studentDataScope : 'current';
$studentsBasePages = ['current' => 'students.php', 'graduates' => 'graduate_students.php', 'transferred' => 'transferred_students.php', 'discontinued' => 'discontinued_students.php'];
$studentsBasePage = $studentsBasePages[$studentDataScope];
$scopeTitles = ['current' => 'إدارة الطلاب المقيدين', 'graduates' => 'الخريجين', 'transferred' => 'المنقولون من المدرسة', 'discontinued' => 'الطلاب المنقطعون'];
$scopeListTitles = ['current' => 'قائمة الطلاب المقيدين', 'graduates' => 'قائمة الخريجين', 'transferred' => 'قائمة المنقولين من المدرسة', 'discontinued' => 'قائمة الطلاب المنقطعين'];

// Determine active tab for redirects and UI
$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'basic');
$validTabs = ['basic', 'guardians', 'health', 'siblings', 'academic_history', 'attachments'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'basic';
}

// Extracted back parameters to return exactly to the same page/filters in the list
$back_spage = $_GET['spage'] ?? ($_POST['back_spage'] ?? '');
$back_stage_id = $_GET['stage_id'] ?? ($_POST['back_stage_id'] ?? '');
$back_grade_id = $_GET['grade_id'] ?? ($_POST['back_grade_id'] ?? '');
$back_class_id = $_GET['class_id'] ?? ($_POST['back_class_id'] ?? '');

$back_stage_ids = $_GET['stage_ids'] ?? ($_POST['back_stage_ids'] ?? []);
$back_grade_ids = $_GET['grade_ids'] ?? ($_POST['back_grade_ids'] ?? []);
$back_class_ids = $_GET['class_ids'] ?? ($_POST['back_class_ids'] ?? []);

$backParams = [];
if ($back_spage)
    $backParams['spage'] = $back_spage;
if ($back_stage_id)
    $backParams['stage_id'] = $back_stage_id;
if ($back_grade_id)
    $backParams['grade_id'] = $back_grade_id;
if ($back_class_id)
    $backParams['class_id'] = $back_class_id;

if (!empty($back_stage_ids))
    $backParams['stage_ids'] = $back_stage_ids;
if (!empty($back_grade_ids))
    $backParams['grade_ids'] = $back_grade_ids;
if (!empty($back_class_ids))
    $backParams['class_ids'] = $back_class_ids;

$backParams['student_scope'] = $studentDataScope;

$backQuery = !empty($backParams) ? '?' . http_build_query($backParams) : '';
$backQueryAmp = !empty($backParams) ? '&' . http_build_query($backParams) : '';

// Set page title
$page_title = $scopeTitles[$studentDataScope];
$custom_page_title = true; // This page has its own custom title

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/evaluation.php';
require_once '../classes/evaluation_type.php';
require_once '../classes/utilities.php';
require_once '../classes/excel_handler.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once __DIR__ . '/includes/profile_excel_import.php';
require_once '../classes/UndoManager.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/StudentEnrollment.php';
require_once '../classes/ProfileInputValidator.php';
require_once '../classes/StudentEnrollmentService.php';
require_once '../classes/StudentProfileRepository.php';
require_once '../classes/StudentProfilePayload.php';
require_once '../classes/StudentBulkCreateService.php';
require_once '../classes/StudentArchiveService.php';
require_once '../classes/StudentAttachmentService.php';
require_once '../classes/StudentRelationshipService.php';
require_once '../classes/StudentProfileRequestMapper.php';
require_once '../classes/StudentGuardianService.php';
require_once '../classes/StudentProfileLifecycleService.php';
require_once '../classes/StudentProfileCommandService.php';
require_once '../classes/StudentProfilePageQuery.php';
require_once '../classes/StudentListPageQuery.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../classes/SpecialistAcademicScopeService.php';
require_once '../classes/StudentChangeRequestService.php';

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
ActivityLog::setDb($db);
UndoManager::setDb($db);
$currentAcademicYearId = AcademicYear::currentId($db);
$currentAcademicYear = AcademicYear::getCurrent($db);
$staffPortalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$current_user_role = $staffPortalContext->role();
$isSpecialistPortal = $current_user_role === 'specialist';
$allowedStudentClassIds = $staffPortalContext->allowedClassIds();
$canCreateStudents = !$isSpecialistPortal;
$canArchiveStudents = !$isSpecialistPortal;
$studentProfilePendingMode = $isSpecialistPortal;

if ($isSpecialistPortal) {
    $studentDataScope = 'current';
    $studentsBasePage = 'students.php';
    $page_title = $scopeTitles['current'];
    $backParams['student_scope'] = 'current';
    $backQuery = '?' . http_build_query($backParams);
    $backQueryAmp = '&' . http_build_query($backParams);
    if (($_GET['action'] ?? '') === 'add') {
        unset($_GET['action']);
    }
}
$studentEnrollmentService = new StudentEnrollmentService($db);
$studentBulkCreateService = new StudentBulkCreateService($db, $studentEnrollmentService);
$studentProfileRepository = new StudentProfileRepository($db);
$profileAttachmentStorage = new ProfileAttachmentStorage();
$studentArchiveService = new StudentArchiveService($db, $profileAttachmentStorage);
$studentAttachmentService = new StudentAttachmentService($db, $studentProfileRepository, $profileAttachmentStorage);
$studentRelationshipService = new StudentRelationshipService($db, $studentProfileRepository);
$studentProfileRequestMapper = new StudentProfileRequestMapper();
$studentGuardianService = new StudentGuardianService($db);
$studentProfileLifecycleService = new StudentProfileLifecycleService($db, $studentEnrollmentService);
$studentProfileCommandService = new StudentProfileCommandService(
    $db,
    $studentProfileRepository,
    $studentProfileRequestMapper,
    $studentEnrollmentService,
    $studentGuardianService,
    $studentProfileLifecycleService
);
$studentChangeRequestService = new StudentChangeRequestService(
    $db,
    new SpecialistAcademicScopeService($db, (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? 'specialist')),
    $studentProfileCommandService
);

// Initialize user object
$user = new User($db);
$studentProfilePageQuery = new StudentProfilePageQuery($db, $user);
$studentListPageQuery = new StudentListPageQuery($db, $user);

// Initialize class object for dropdown
$class = new ClassRoom($db);

// Initialize evaluation objects
$evaluation = new Evaluation($db);
$evaluation_type = new EvaluationType($db);

// Initialize excel handler
$excel_handler = new ExcelHandler();

if (($_GET['download_profile_template'] ?? '') === 'student') {
    if ($isSpecialistPortal) {
        http_response_code(403);
        exit('غير مصرح بتنزيل نموذج إضافة الطلاب.');
    }
    profile_import_download_template('student');
}

function digits_only(?string $value): string
{
    return preg_replace('/\D+/', '', (string) ($value ?? ''));
}

function build_student_full_name(array $data): string
{
    return StudentProfilePayload::fullName($data);
}

function build_student_activity_details(array $before, array $after): ?array
{
    return StudentProfilePayload::activityDetails($before, $after);
}

function validate_student_scope_class(PDO $db, ?int $classId, string $scope): void
{
    return;
}

function resolve_graduate_class(PDO $db, ?int $classId, ?int $gradeId, string $scope): ?int
{
    return $classId;
}

function build_father_name_from_student(array $studentData): string
{
    return StudentProfilePayload::fatherName($studentData);
}

function normalize_guardians_fixed_parents(array $guardians, array $studentData): array
{
    return StudentProfilePayload::normalizeFixedParents($guardians, $studentData);
}

function normalize_guardian_relationships(array $guardians): array
{
    return StudentProfilePayload::normalizeRelationships($guardians);
}

function sanitize_educational_guardianship(?string $value): string
{
    return StudentProfilePayload::sanitizeEducationalGuardianship($value);
}

function extract_educational_guardianship_from_extra_data(?string $json, array &$filteredItems = []): string
{
    return StudentProfilePayload::extractEducationalGuardianship($json, $filteredItems);
}

function merge_educational_guardianship_into_extra_data(?string $json, ?string $guardianship): ?string
{
    return StudentProfilePayload::mergeEducationalGuardianship($json, $guardianship);
}

// بناء مصفوفة JSON للأرقام الإضافية للطالب
function build_student_extra_phones(array $post): ?string
{
    return StudentProfilePayload::studentExtraPhones($post);
}

// بناء مصفوفة JSON للأرقام الإضافية لولي الأمر
function build_guardian_extra_phones(array $guardian): ?string
{
    return StudentProfilePayload::guardianExtraPhones($guardian);
}

// بناء مصفوفة JSON للبيانات الإضافية الحرة لولي الأمر
function build_guardian_extra_data(array $guardian): ?string
{
    return StudentProfilePayload::guardianExtraData($guardian);
}

// بناء مصفوفة JSON للبيانات الإضافية الحرة
function build_student_extra_data(array $post): ?string
{
    return StudentProfilePayload::studentExtraData($post);
}

function split_bulk_student_name(string $name): array
{
    return StudentProfilePayload::splitBulkName($name);
}

function student_public_error_message(Throwable $error, string $databaseFallback): string
{
    for ($cursor = $error; $cursor !== null; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof PDOException) {
            error_log('Student affairs database operation failed: ' . $error->getMessage());
            return $databaseFallback;
        }
    }
    return $error->getMessage();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();

    if ($isSpecialistPortal) {
        $studentId = max(0, (int) ($_POST['edit_user_id'] ?? 0));
        try {
            if (!isset($_POST['save_student_profile']) || $studentId <= 0) {
                throw new RuntimeException('الإجراء المطلوب غير متاح للأخصائي.');
            }
            $staffPortalContext->assertStudentAllowed($studentId);
            $studentChangeRequestService->submitProfile(
                $staffPortalContext->userId(),
                $currentAcademicYearId,
                $studentId,
                $_POST
            );
            $_SESSION['success_message'] = 'تم إرسال تعديلات الطالب إلى صفحة العمليات المعلقة لمراجعة الإدارة.';
            header('Location: students.php' . $backQuery);
            exit;
        } catch (Throwable $e) {
            $_SESSION['error_message'] = 'تعذر إرسال طلب التعديل: ' . student_public_error_message($e, 'حدث خطأ في قاعدة البيانات ولم تُحفظ تغييرات جزئية.');
            $_SESSION['student_form_old_input'] = $_POST;
            $target = $studentId > 0
                ? '?action=edit&id=' . $studentId . '&tab=' . urlencode((string) ($_POST['active_tab'] ?? 'basic')) . $backQueryAmp
                : $backQuery;
            header('Location: students.php' . $target);
            exit;
        }
    }

    // استيراد ملفات الطلاب التفصيلية: تحقق كامل قبل أي عملية كتابة.
    if (isset($_POST['import_students']) && isset($_FILES['excel_file'])) {
        try {
            $result = profile_import_student($_FILES['excel_file'], $db, $user, $studentDataScope);
            $_SESSION['success_message'] = 'تم استيراد ' . $result['count'] . ' طالب بنجاح مع بياناتهم التفصيلية.';
        } catch (Throwable $e) {
            $_SESSION['error_message'] = 'تعذر استيراد ملف الطلاب: ' . student_public_error_message($e, 'حدث خطأ في قاعدة البيانات ولم يتم اعتماد الاستيراد.');
            error_log('Student detailed Excel import error: ' . $e->getMessage());
        }
        header("Location: " . $studentsBasePage . $backQuery);
        exit();
    }

    if (isset($_POST['add_students_bulk'])) {
        $bulkInput = is_array($_POST['bulk_students'] ?? null) ? $_POST['bulk_students'] : [];
        $bulkDefaultClassId = (int) ($_POST['bulk_default_class_id'] ?? 0);
        $bulkRedirectParams = $backParams;
        $bulkRedirectParams['bulk_add'] = '1';

        try {
            $createdStudents = $studentBulkCreateService->create(
                $bulkInput,
                $bulkDefaultClassId,
                $studentDataScope
            );

            $studentLinks = [];
            foreach (array_slice($createdStudents, 0, 5) as $createdStudent) {
                $studentLinks[] = '<a class="alert-link" href="students.php?action=edit&id=' . $createdStudent['id'] . '">' . htmlspecialchars($createdStudent['name'], ENT_QUOTES, 'UTF-8') . '</a>';
            }
            $_SESSION['success_message'] = 'تمت إضافة ' . count($createdStudents) . ' طالب بنجاح.';
            if ($studentLinks) {
                $_SESSION['success_message'] .= '<br><small>استكمال الملفات: ' . implode('، ', $studentLinks) . '</small>';
            }
            unset($_SESSION['student_bulk_old_input']);
            header('Location: ' . $studentsBasePage . $backQuery);
            exit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error_message'] = 'خطأ في الإضافة الجماعية: ' . student_public_error_message($e, 'حدث خطأ في قاعدة البيانات وتم التراجع عن الدفعة.');
            $_SESSION['student_bulk_old_input'] = [
                'default_class_id' => $bulkDefaultClassId,
                'students' => $bulkInput,
            ];
            header('Location: ' . $studentsBasePage . '?' . http_build_query($bulkRedirectParams));
            exit();
        }
    }

    if (isset($_POST['archive_student'])) {
        $studentId = (int) ($_POST['user_id'] ?? 0);
        try {
            $studentArchiveService->archive(
                $studentId,
                (int) ($_SESSION['user_id'] ?? 0),
                (string) ($_POST['archive_reason'] ?? '')
            );
            $_SESSION['success_message'] = 'تمت أرشفة الطالب مع الاحتفاظ بجميع بياناته التاريخية.';
        } catch (Throwable $e) {
            $_SESSION['error_message'] = 'خطأ: ' . student_public_error_message($e, 'تعذر أرشفة الطالب ولم تُحفظ تغييرات جزئية.');
        }
        header("Location: " . $studentsBasePage . $backQuery);
        exit();
    }

    // ===== رفع مرفق طالب (للصورة الشخصية - يُستخدم عبر زرها الخاص) =====
    if (isset($_POST['action']) && $_POST['action'] === 'upload_student_attachment') {
        $openAttachmentsTab = true;
        $userId = (int) $_POST['id'];
        try {
            $studentAttachmentService->assertManageableStudent($userId);
        } catch (Throwable $e) {
            $_SESSION['error_message'] = 'خطأ: ' . student_public_error_message($e, 'تعذر التحقق من ملف الطالب.');
            header("Location: " . $studentsBasePage . $backQuery);
            exit();
        }
        try {
            $studentAttachmentService->upload(
                $userId,
                (string) ($_POST['attachment_label'] ?? ''),
                is_array($_FILES['attachment_file'] ?? null) ? $_FILES['attachment_file'] : []
            );
            $_SESSION['success_message'] = "تم رفع المرفق بنجاح.";
        } catch (InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (Throwable $e) {
            if ($e instanceof PDOException || $e->getPrevious() instanceof PDOException) {
                error_log('Student attachment upload failed: ' . $e->getMessage());
            }
            $_SESSION['error_message'] = "فشل في حفظ المرفق.";
        }
        header("Location: " . $studentsBasePage . "?action=edit&id=" . $userId . "&tab=attachments" . $backQueryAmp);
        exit();
    }

    // ===== حذف مرفق طالب =====
    if (isset($_POST['action']) && $_POST['action'] === 'delete_student_attachment') {
        $openAttachmentsTab = true;
        $attId = (int) $_POST['attachment_id'];
        $userId = (int) $_POST['id'];
        try {
            $studentAttachmentService->assertManageableStudent($userId);
        } catch (Throwable $e) {
            $_SESSION['error_message'] = 'خطأ: ' . student_public_error_message($e, 'تعذر التحقق من ملف الطالب.');
            header("Location: " . $studentsBasePage . $backQuery);
            exit();
        }
        if ($studentAttachmentService->delete($userId, $attId)) {
            $_SESSION['success_message'] = "تم حذف المرفق بنجاح.";
        } else {
            $_SESSION['error_message'] = "المرفق غير موجود.";
        }
        header("Location: " . $studentsBasePage . "?action=edit&id=" . $userId . "&tab=attachments" . $backQueryAmp);
        exit();
    }

    // ===== حفظ بيانات ملف الطالب التفصيلي (إضافة أو تعديل) =====
    if (isset($_POST['save_student_profile'])) {
        $activeTab = $_POST['active_tab'] ?? $activeTab;
        $userId = !empty($_POST['edit_user_id']) ? (int) $_POST['edit_user_id'] : null;
        try {
            $result = $studentProfileCommandService->save(
                $_POST,
                $studentDataScope,
                (int) ($_SESSION['user_id'] ?? 0)
            );
            $_SESSION['success_message'] = $result['message'];
            $savedBasePage = $result['saved_base_page'];
            header("Location: " . $savedBasePage . ($savedBasePage === $studentsBasePage ? $backQuery : ''));
            exit;
        } catch (Throwable $e) {
            $_SESSION['error_message'] = 'خطأ: ' . student_public_error_message($e, 'حدث خطأ في قاعدة البيانات وتم التراجع عن الحفظ.');
            $_SESSION['student_form_old_input'] = $_POST;
            $errorRedirectTarget = !empty($userId)
                ? "?action=edit&id=" . $userId . "&tab=" . $activeTab . $backQueryAmp
                : "?action=add&tab=" . $activeTab . $backQueryAmp;
            header("Location: " . $studentsBasePage . $errorRedirectTarget);
            exit();
        }
    }

    // ===== ربط/إلغاء ربط أشقاء أو صلة قرابة =====
    if (isset($_POST['link_sibling'])) {
        $studentId = (int) $_POST['student_id'];
        $siblingId = (int) $_POST['sibling_id'];
        $rel = $_POST['sibling_relationship'] ?? 'brother';

        try {
            $_SESSION['success_message'] = $studentRelationshipService->link($studentId, $siblingId, $rel);
        } catch (StudentKinshipLinkException $e) {
            $_SESSION['error_message'] = 'حدث خطأ أثناء ربط صلة القرابة: ' . student_public_error_message($e, 'تعذر حفظ العلاقة في قاعدة البيانات.');
        } catch (StudentRelationshipGuardException $e) {
            $_SESSION['error_message'] = 'خطأ: ' . student_public_error_message($e, 'تعذر التحقق من بيانات الطالب.');
            header("Location: " . $studentsBasePage . $backQuery);
            exit();
        }
        header("Location: " . $studentsBasePage . "?action=edit&id=" . $studentId . "&tab=siblings" . $backQueryAmp);
        exit;
    } elseif (isset($_POST['unlink_sibling'])) {
        $studentId = (int) $_POST['student_id'];
        $siblingId = (int) $_POST['sibling_id'];
        try {
            $studentRelationshipService->unlinkSibling($studentId, $siblingId);
            $_SESSION['success_message'] = "تم إلغاء ربط الشقيق.";
        } catch (Throwable $e) {
            $_SESSION['error_message'] = 'خطأ: ' . student_public_error_message($e, 'تعذر إلغاء رابط الشقيق في قاعدة البيانات.');
        }
        header("Location: " . $studentsBasePage . "?action=edit&id=" . $studentId . "&tab=siblings" . $backQueryAmp);
        exit;
    } elseif (isset($_POST['unlink_kinship'])) {
        $studentId = (int) $_POST['student_id'];
        $relativeId = (int) $_POST['relative_id'];
        try {
            $studentRelationshipService->unlinkKinship($studentId, $relativeId);
            $_SESSION['success_message'] = "تم إلغاء صلة القرابة بنجاح.";
        } catch (Throwable $e) {
            $_SESSION['error_message'] = 'خطأ: ' . student_public_error_message($e, 'تعذر إلغاء صلة القرابة في قاعدة البيانات.');
        }
        header("Location: " . $studentsBasePage . "?action=edit&id=" . $studentId . "&tab=siblings" . $backQueryAmp);
        exit;
    }

}

// الصفحة نفسها تُستخدم للإدارة والأخصائي؛ الاختلاف في سياق البيانات والإجراءات فقط.
$studentListData = $studentListPageQuery->load(
    $_GET,
    $studentDataScope,
    $allowedStudentClassIds
);
    $filter_class_id = $studentListData['filter_class_id'];
    $filter_grade_id = $studentListData['filter_grade_id'];
    $filter_stage_id = $studentListData['filter_stage_id'];
    $filter_class_ids = $studentListData['filter_class_ids'] ?? [];
    $filter_grade_ids = $studentListData['filter_grade_ids'] ?? [];
    $filter_stage_ids = $studentListData['filter_stage_ids'] ?? [];
    $students_use_datatables = $studentListData['students_use_datatables'];
    $students_per_page = $studentListData['students_per_page'];
$students_page = $studentListData['students_page'];
$students_offset = $studentListData['students_offset'];
$students_total_count = $studentListData['students_total_count'];
    $students_total_pages = $studentListData['students_total_pages'];
    $students = $studentListData['students'];
    $classes = $studentListData['classes'];
    $scopeGrades = $studentListData['scope_grades'];
    $stages = $studentListData['stages'];
    $grades = $studentListData['grades'];
    $page_action = $studentListData['page_action'];
    $is_list_mode = $studentListData['is_list_mode'];

    // Include admin header
$adminAssetOptions = [
    'datatables' => $page_action !== 'view',
    'sweetalert' => false,
    'sortable' => false,
    'instant_attachment_upload' => !$studentProfilePendingMode && ($page_action === 'add' || $page_action === 'edit'),
    'dashboard_sortable' => false,
];
include_once '../includes/admin_header.php';

    // For add/edit, load required data
    $editStudent = null;
    $studentProfile = null;
    $studentGuardians = [];
    $studentSiblings = [];
    $studentTransfers = [];
    $studentAcademicHistory = [];
    $studentCurrentEnrollment = [];
    $studentExternalTransfer = [];
    $studentAttachments = [];

    // متغيرات التعبئة المسبقة للأرقام الإضافية
    $editExtraPhones = [];
    $editExtraData = [];
    $educationalGuardianship = '';
    $guardianExtraPhones = [];
    $guardianExtraData = [];

    if ($page_action === 'edit' && isset($_GET['id'])) {
        $editUserId = (int) $_GET['id'];
        $staffPortalContext->assertStudentAllowed($editUserId);
        $editData = $studentProfilePageQuery->editData($editUserId);
        $editStudent = $editData['student'];
        $studentProfile = $editData['profile'];
        $studentGuardians = $editData['guardians'];
        $studentSiblings = $editData['siblings'];
        $studentTransfers = $editData['transfers'];
        $studentAcademicHistory = $editData['academic_history'];
        $studentCurrentEnrollment = $editData['current_enrollment'];
        $studentExternalTransfer = $editData['external_transfer'];
        $studentAttachments = $editData['attachments'];
        $editExtraPhones = $editData['extra_phones'];
        $editExtraData = $editData['extra_data'];
        $educationalGuardianship = $editData['educational_guardianship'];
        $guardianExtraPhones = $editData['guardian_extra_phones'];
        $guardianExtraData = $editData['guardian_extra_data'];
    }

    // Load data for view profile
    $viewStudent = null;
    $viewProfile = null;
    $viewGuardians = [];
    $viewSiblings = [];
    $viewTransfers = [];
    $viewAcademicHistory = [];
    $viewCurrentEnrollment = [];
    $viewAttachments = [];

    if ($page_action === 'view' && isset($_GET['id'])) {
        $viewUserId = (int) $_GET['id'];
        $staffPortalContext->assertStudentAllowed($viewUserId);
        $viewData = $studentProfilePageQuery->viewData($viewUserId);
        if ($viewData !== null) {
            $viewStudent = $viewData['student'];
            $viewProfile = $viewData['profile'];
            $viewGuardians = $viewData['guardians'];
            $viewSiblings = $viewData['siblings'];
            $viewTransfers = $viewData['transfers'];
            $viewAcademicHistory = $viewData['academic_history'];
            $viewCurrentEnrollment = $viewData['current_enrollment'];
            $viewKinships = $viewData['kinships'];
            $viewAttachments = $viewData['attachments'];
            $viewClassName = $viewCurrentEnrollment['class_name'] ?? $viewData['class_name'];
        } else {
            $page_action = '';
        }
    }

// Add debug information if no students or classes found
if (empty($students)) {
    error_log("No students found in students.php for role {$current_user_role}");
}

if (empty($classes)) {
    error_log("No classes found in students.php");
    $classes = []; // Ensure $classes is at least an empty array
}

// Ensure proper initialization of variables
if (!isset($students)) {
    $students = [];
}
?>

<div class="students-page">

    <?php if ($page_action !== 'view' && ($studentDataScope === 'current' || ($page_action !== 'add' && $page_action !== 'edit'))): ?>
        <!-- Page Title and Description -->
        <div class="admin-page-heading">
            <h1 class="h2"><i
                    class="fas fa-users me-2 text-primary"></i><?php echo htmlspecialchars($scopeTitles[$studentDataScope]); ?>
            </h1>
            <div class="btn-toolbar admin-top-actions">
                <?php if ($canCreateStudents): ?>
                <a href="<?php echo $studentsBasePage; ?>?action=add<?php echo $backQueryAmp; ?>"
                    class="btn btn-header-premium btn-success shadow-sm">
                    <i class="fas fa-plus-circle"></i>إضافة طالب جديد
                </a>
                <?php if ($studentDataScope === 'current'): ?>
                    <button type="button" class="btn btn-header-premium btn-primary shadow-sm"
                        data-bs-toggle="modal" data-bs-target="#bulkAddStudentsModal">
                        <i class="fas fa-users"></i>إضافة جماعية
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal"
                    data-bs-target="#importStudentsModal">
                    <i class="fas fa-file-import"></i>استيراد Excel
                </button>
                <?php endif; ?>
                <a href="export_students.php?student_scope=<?php echo urlencode($studentDataScope); ?>"
                    class="btn btn-header-premium btn-export-soft">
                    <i class="fas fa-file-excel"></i>تصدير Excel
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Content area -->
    <div class="py-2">
        <!-- Alerts -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

<?php require __DIR__ . '/../src/Modules/Students/Presentation/profile_view.php'; ?>

<?php require __DIR__ . '/../src/Modules/Students/Presentation/profile_form.php'; ?>

<?php require __DIR__ . '/../src/Modules/Students/Presentation/list_view.php'; ?>

<?php require __DIR__ . '/../src/Modules/Students/Presentation/profile_scripts.php'; ?>

<script>
    // Activate tab from URL parameter
    document.addEventListener('DOMContentLoaded', function () {
        const urlParams = new URLSearchParams(window.location.search);
        const tabName = urlParams.get('tab');
        if (tabName) {
            const tabEl = document.querySelector(`#tab-${tabName.replace(/_/g, '-')}`);
            if (tabEl) {
                const tab = new bootstrap.Tab(tabEl);
                tab.show();
            }
        }
    });
</script>


</div>

<?php
include_once '../includes/admin_footer.php';
?>
