<?php

declare(strict_types=1);

// Compatibility bootstrap for legacy entrypoints that require classes/Student*.php directly.
require_once dirname(__DIR__, 3) . '/classes/FileUploadGuard.php';
require_once dirname(__DIR__, 3) . '/classes/ProfileInputValidator.php';
require_once dirname(__DIR__, 3) . '/classes/ProfileAttachmentLabelPolicy.php';
require_once dirname(__DIR__, 3) . '/classes/AcademicYearWriteGuard.php';
require_once __DIR__ . '/Presentation/StudentExportFieldCatalog.php';
require_once __DIR__ . '/Presentation/StudentExportValueFormatter.php';
require_once __DIR__ . '/Presentation/StudentListColumnCatalog.php';
require_once __DIR__ . '/Presentation/StudentListDataTablePresenter.php';
require_once __DIR__ . '/DerivedStudentListDataTableQuery.php';

$studentModuleFiles = [
    'StudentProfilePayload.php',
    'StudentEnrollment.php',
    'StudentProfileRepository.php',
    'StudentProfileRequestMapper.php',
    'StudentEnrollmentService.php',
    'StudentGuardianService.php',
    'StudentProfileLifecycleService.php',
    'StudentBulkCreateService.php',
    'StudentDeletionService.php',
    'StudentArchiveService.php',
    'StudentArchiveQuery.php',
    'StudentOperationalGuard.php',
    'StudentAttendanceService.php',
    'StudentAccountClassificationService.php',
    'StudentAttachmentService.php',
    'StudentRelationshipService.php',
    'StudentProfileCommandService.php',
    'StudentChangeFieldPolicy.php',
    'StudentChangeRequestService.php',
    'StudentProfilePageQuery.php',
    'StudentListPageQuery.php',
    'StudentListDataTableQuery.php',
    'StudentOperationLogQuery.php',
];

foreach ($studentModuleFiles as $studentModuleFile) {
    require_once __DIR__ . '/' . $studentModuleFile;
}
