<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff employment query contract — owned by the StaffHr module.
 * Consumed read-only by Finance for: employee-child discount eligibility
 * (at charge due date), payroll run staff list, cost-center assignment.
 */
interface StaffEmploymentQuery
{
    /**
     * Get the active employment contract for a staff member at a given date.
     *
     * @return array{staff_id: int, employee_code: ?string, job_title: ?string, department: ?string, hire_date: ?string, current_work_status: string, is_active: bool}|null
     */
    public function activeContractOf(int $staffId, ?string $atDate = null): ?array;

    /**
     * Get documented relationships for a staff member (for employee-child eligibility).
     *
     * @return array<int, array{staff_id: int, student_id: int, relationship_type: string, is_active: bool}>
     */
    public function relationshipsOf(int $staffId): array;

    /** Return the documented guardian relationship for this exact staff/student pair. */
    public function documentedRelationshipToStudent(int $staffId, int $studentId): ?array;
}
