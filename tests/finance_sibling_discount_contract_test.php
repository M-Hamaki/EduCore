<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';

use EduCore\Modules\Finance\Application\DiscountEligibilityService;
use EduCore\Modules\Finance\Domain\Policy\EmployeeChildEligibilityPolicy;
use EduCore\Modules\Finance\Domain\Policy\SiblingDiscountPolicy;
use EduCore\Modules\Staff\Contracts\StaffEmploymentQuery;
use EduCore\Modules\Students\Contracts\StudentEnrollmentQuery;

$enrollments = new class implements StudentEnrollmentQuery {
    public int $requestedYear = 0;
    public function enrollmentOf(int $studentId, int $academicYearId): ?array { return null; }
    public function familyGroupOf(int $studentId, int $academicYearId): array
    {
        $this->requestedYear = $academicYearId;
        return [
            ['student_id' => 30, 'enrollment_date' => '2025-09-02'],
            ['student_id' => 20, 'enrollment_date' => '2025-09-01'],
            ['student_id' => 10, 'enrollment_date' => '2025-09-01'],
        ];
    }
};
$employment = new class implements StaffEmploymentQuery {
    public function activeContractOf(int $staffId, ?string $atDate = null): ?array { return null; }
    public function relationshipsOf(int $staffId): array { return []; }
    public function documentedRelationshipToStudent(int $staffId, int $studentId): ?array { return null; }
};
$service = new DiscountEligibilityService($enrollments, $employment, new SiblingDiscountPolicy(), new EmployeeChildEligibilityPolicy());

if ($service->siblingOrder(10, 77) !== 1 || $service->siblingOrder(20, 77) !== 2 || $service->siblingOrder(30, 77) !== 3 || $enrollments->requestedYear !== 77) {
    fwrite(STDERR, "FAILED: sibling order is not oldest enrollment then student_id within the requested year.\n");
    exit(1);
}

echo "Sibling discount contract test PASSED.\n";
