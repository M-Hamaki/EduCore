<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Domain\Policy\EmployeeChildEligibilityPolicy;
use EduCore\Modules\Finance\Domain\Policy\SiblingDiscountPolicy;
use EduCore\Modules\Staff\Contracts\StaffEmploymentQuery;
use EduCore\Modules\Students\Contracts\StudentEnrollmentQuery;
use InvalidArgumentException;
use RuntimeException;

final class DiscountEligibilityService
{
    public function __construct(
        private StudentEnrollmentQuery $enrollments,
        private StaffEmploymentQuery $employment,
        private SiblingDiscountPolicy $siblings,
        private EmployeeChildEligibilityPolicy $employeeChildren
    ) {
    }

    /** Return the stable 1-based sibling tier for the requested academic year. */
    public function siblingOrder(int $studentId, int $academicYearId): int
    {
        if ($studentId <= 0 || $academicYearId <= 0) {
            throw new InvalidArgumentException('Student and academic year are required.');
        }

        $ordered = $this->siblings->orderSiblings(
            $this->enrollments->familyGroupOf($studentId, $academicYearId)
        );
        foreach ($ordered as $sibling) {
            if ((int) ($sibling['student_id'] ?? 0) === $studentId) {
                return (int) $sibling['sibling_order'];
            }
        }

        throw new RuntimeException('The student has no active enrollment in the requested family group/year.');
    }

    public function isEmployeeChildEligible(int $staffId, int $studentId, string $chargeDueDate): bool
    {
        if ($staffId <= 0 || $studentId <= 0 || !$this->isDate($chargeDueDate)) {
            throw new InvalidArgumentException('Staff, student, and a valid charge due date are required.');
        }

        $relationship = $this->employment->documentedRelationshipToStudent($staffId, $studentId);
        $contract = $this->employment->activeContractOf($staffId, $chargeDueDate);
        return $relationship !== null
            && $contract !== null
            && $this->employeeChildren->isEligible($relationship, $contract);
    }

    private function isDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
