<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';

use EduCore\Modules\Finance\Application\DiscountEligibilityService;
use EduCore\Modules\Finance\Domain\Policy\EmployeeChildEligibilityPolicy;
use EduCore\Modules\Finance\Domain\Policy\SiblingDiscountPolicy;
use EduCore\Modules\Staff\Contracts\StaffEmploymentQuery;
use EduCore\Modules\Students\Contracts\StudentEnrollmentQuery;

$enrollments = new class implements StudentEnrollmentQuery {
    public function enrollmentOf(int $studentId, int $academicYearId): ?array { return null; }
    public function familyGroupOf(int $studentId, int $academicYearId): array { return []; }
};
$employment = new class implements StaffEmploymentQuery {
    public ?string $requestedDate = null;
    public int $requestedStudent = 0;
    public function activeContractOf(int $staffId, ?string $atDate = null): ?array
    {
        $this->requestedDate = $atDate;
        return $atDate === '2026-10-15'
            ? ['staff_id' => $staffId, 'is_active' => true, 'current_work_status' => 'on_duty']
            : null;
    }
    public function relationshipsOf(int $staffId): array { return []; }
    public function documentedRelationshipToStudent(int $staffId, int $studentId): ?array
    {
        $this->requestedStudent = $studentId;
        return $studentId === 44 ? ['staff_id' => $staffId, 'student_id' => 44, 'is_active' => true, 'relationship_type' => 'father'] : null;
    }
};
$service = new DiscountEligibilityService($enrollments, $employment, new SiblingDiscountPolicy(), new EmployeeChildEligibilityPolicy());

if (!$service->isEmployeeChildEligible(9, 44, '2026-10-15') || $employment->requestedDate !== '2026-10-15' || $employment->requestedStudent !== 44) {
    fwrite(STDERR, "FAILED: exact student relationship and active employment were not checked at the charge due date.\n");
    exit(1);
}
if ($service->isEmployeeChildEligible(9, 45, '2026-10-15') || $service->isEmployeeChildEligible(9, 44, '2027-01-01')) {
    fwrite(STDERR, "FAILED: missing relationship or inactive employment date was accepted.\n");
    exit(1);
}

echo "Employee-child eligibility contract test PASSED.\n";
