<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Contracts;

/**
 * Student enrollment query contract — owned by the Students module.
 * Consumed read-only by Finance for: account creation, charge generation,
 * sibling ordering (oldest enrollment date first; ties by student_id).
 */
interface StudentEnrollmentQuery
{
    /**
     * Get enrollment info for a student in an academic year.
     *
     * @return array{id: int, student_id: int, grade_id: int, class_id: int, stage_id: int, enrollment_date: string, enrollment_status: string}|null
     */
    public function enrollmentOf(int $studentId, int $academicYearId): ?array;

    /**
     * Get the family group (sibling student IDs) for a student.
     *
     * @return array<int, array{student_id: int, enrollment_date: string}>
     */
    public function familyGroupOf(int $studentId, int $academicYearId): array;
}
