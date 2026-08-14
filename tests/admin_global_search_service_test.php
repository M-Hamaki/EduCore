<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/Search/Contracts/GlobalSearchReadRepository.php';
require_once __DIR__ . '/../src/Modules/Search/Application/GlobalSearchAccessPolicy.php';
require_once __DIR__ . '/../src/Modules/Search/Application/GlobalSearchQueryService.php';

use EduCore\Modules\Search\Application\GlobalSearchAccessPolicy;
use EduCore\Modules\Search\Application\GlobalSearchQueryService;
use EduCore\Modules\Search\Contracts\GlobalSearchReadRepository;

function globalSearchAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FakeGlobalSearchReadRepository implements GlobalSearchReadRepository
{
    /** @var array<int,string> */
    public array $calls = [];
    /** @var array<int,int>|null */
    public ?array $studentScope = null;
    /** @var array<int,int>|null */
    public ?array $classScope = null;
    /** @var array<int,array{original:string,normalized:string}> */
    public array $lastTokens = [];

    public function searchStudents(array $tokens, int $academicYearId, ?array $allowedClassIds, int $limit): array
    {
        $this->calls[] = 'students';
        $this->studentScope = $allowedClassIds;
        $this->lastTokens = $tokens;
        return [
            ['id' => 2, 'name' => 'آية محمد', 'student_code' => 'S200', 'class_name' => 'Birds 1'],
            ['id' => 1, 'name' => 'آية', 'student_code' => 'S100', 'class_name' => 'Birds 2'],
        ];
    }

    public function searchStaff(array $tokens, int $limit): array
    {
        $this->calls[] = 'staff';
        return [
            ['id' => 9, 'name' => 'نورهان محمد موسى', 'role' => 'teacher', 'employee_code' => 'E900'],
        ];
    }

    public function searchClasses(array $tokens, int $academicYearId, ?array $allowedClassIds, int $limit): array
    {
        $this->calls[] = 'classes';
        $this->classScope = $allowedClassIds;
        return [['id' => 4, 'name' => 'Birds 1', 'grade_name' => 'الأول', 'stage_name' => 'ابتدائي']];
    }

    public function searchSubjects(array $tokens, int $limit): array
    {
        $this->calls[] = 'subjects';
        return [['id' => 7, 'name' => 'لغة عربية', 'code' => 'AR']];
    }

    public function searchBuses(array $tokens, int $limit): array
    {
        $this->calls[] = 'buses';
        return [['id' => 3, 'bus_number' => '12', 'capacity' => 30, 'status' => 'active']];
    }
}

$policy = new GlobalSearchAccessPolicy();
globalSearchAssert($policy->canUse('super_admin', null), 'Super admin must be allowed to use global search.');
globalSearchAssert(
    $policy->capabilities('super_admin', null) === [
        'students' => true,
        'staff' => true,
        'classes' => true,
        'subjects' => true,
        'buses' => true,
    ],
    'Super admin must receive every search capability.'
);
globalSearchAssert(
    $policy->capabilities('student_affairs_manager', ['students.php', 'class_lists.php']) === [
        'students' => true,
        'staff' => false,
        'classes' => true,
        'subjects' => false,
        'buses' => false,
    ],
    'Custom admin roles must only receive groups backed by their page grants.'
);
globalSearchAssert(!$policy->canUse('teacher', []), 'A role without admin pages must not use the admin search endpoint.');

$repository = new FakeGlobalSearchReadRepository();
$service = new GlobalSearchQueryService($repository);
$results = $service->search(
    'آية',
    ['students' => true, 'staff' => false, 'classes' => true, 'subjects' => false, 'buses' => false],
    5,
    [10, 11],
    5
);

globalSearchAssert($repository->calls === ['students', 'classes'], 'Only authorized groups may be queried.');
globalSearchAssert($repository->studentScope === [10, 11], 'Student search must receive the active academic scope.');
globalSearchAssert($repository->classScope === [10, 11], 'Class search must receive the active academic scope.');
globalSearchAssert(($results['students'][0]['id'] ?? 0) === 1, 'Exact normalized names must rank before prefix matches.');
globalSearchAssert(
    ($results['students'][0]['url'] ?? '') === 'students.php?action=view&id=1',
    'Student results must use the stable profile-view route.'
);
globalSearchAssert($results['staff'] === [], 'Unauthorized staff data must remain absent.');

$repository->calls = [];
$emptyScopedResults = $service->search(
    'لغة',
    ['students' => true, 'staff' => false, 'classes' => true, 'subjects' => true, 'buses' => false],
    5,
    [],
    5
);
globalSearchAssert($repository->calls === ['subjects'], 'An empty academic scope must fail closed for students and classes.');
globalSearchAssert($emptyScopedResults['students'] === [] && $emptyScopedResults['classes'] === [], 'Scoped groups must stay empty.');

$repository->calls = [];
$staffResults = $service->search(
    'نورهان محمد',
    ['students' => false, 'staff' => true, 'classes' => false, 'subjects' => false, 'buses' => false],
    5,
    null,
    5
);
globalSearchAssert(
    ($staffResults['staff'][0]['url'] ?? '') === 'staff.php?action=view&id=9',
    'Staff results must use the stable profile-view route.'
);
globalSearchAssert($repository->calls === ['staff'], 'Staff-only capability must not query other groups.');

$repository->calls = [];
$service->search(
    'واحد اثنان ثلاثة أربعة خمسة ستة سبعة ثمانية تسعة عشرة',
    ['students' => true, 'staff' => false, 'classes' => false, 'subjects' => false, 'buses' => false],
    5,
    null,
    5
);
globalSearchAssert(count($repository->lastTokens) === 8, 'Search input must cap token expansion.');

echo "ADMIN_GLOBAL_SEARCH_SERVICE_TEST_PASSED\n";
