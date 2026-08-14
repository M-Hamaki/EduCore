<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Domain\Policy;

/**
 * Employee-child discount eligibility policy.
 *
 * Eligibility is verified at the charge due date from a documented relationship
 * and an active employment contract (verified via StaffEmploymentQuery — owned by StaffHr).
 */
final class EmployeeChildEligibilityPolicy
{
    /**
     * Check if a student is eligible for the employee-child discount.
     *
     * @param array $relationship  ['staff_id' => int, 'relationship_type' => string, 'is_active' => bool]
     * @param array $employmentContract  ['staff_id' => int, 'is_active' => bool, 'current_work_status' => string]
     * @return bool
     */
    public function isEligible(array $relationship, array $employmentContract): bool
    {
        if (empty($relationship) || empty($employmentContract)) {
            return false;
        }
        if ((bool) ($relationship['is_active'] ?? false) !== true) {
            return false;
        }
        if ((bool) ($employmentContract['is_active'] ?? false) !== true) {
            return false;
        }
        $status = (string) ($employmentContract['current_work_status'] ?? '');
        if ($status !== '' && $status !== 'active' && $status !== 'on_duty') {
            return false;
        }
        if ((int) ($relationship['staff_id'] ?? 0) !== (int) ($employmentContract['staff_id'] ?? -1)) {
            return false;
        }
        return true;
    }
}
