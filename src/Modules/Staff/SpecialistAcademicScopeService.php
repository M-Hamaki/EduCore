<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use PDO;

require_once __DIR__ . '/StaffAcademicScopeService.php';

/**
 * Temporary compatibility name for callers migrated incrementally. All runtime
 * storage and policy are owned by StaffAcademicScopeService.
 */
final class SpecialistAcademicScopeService extends StaffAcademicScopeService
{
    public function __construct(PDO $db, private string $roleKey = 'specialist')
    {
        parent::__construct($db);
        $this->roleKey = trim($this->roleKey) ?: 'specialist';
    }

    public function scope(int $staffId, int $academicYearId, ?string $roleKey = null): array
    {
        return parent::scope($staffId, $academicYearId, $roleKey ?: $this->roleKey);
    }

    public function allowedClassIdsForStaff(int $staffId, int $academicYearId, ?string $roleKey = null): array
    {
        return parent::allowedClassIdsForStaff($staffId, $academicYearId, $roleKey ?: $this->roleKey);
    }

    public function assertClassAllowed(int $staffId, int $academicYearId, int $classId, ?string $roleKey = null): void
    {
        parent::assertClassAllowed($staffId, $academicYearId, $classId, $roleKey ?: $this->roleKey);
    }

    public function assertStudentAllowed(int $staffId, int $academicYearId, int $studentId, ?string $roleKey = null): void
    {
        parent::assertStudentAllowed($staffId, $academicYearId, $studentId, $roleKey ?: $this->roleKey);
    }

    public function allowedTeacherIds(int $staffId, int $academicYearId, ?string $roleKey = null): array
    {
        return parent::allowedTeacherIds($staffId, $academicYearId, $roleKey ?: $this->roleKey);
    }
}
