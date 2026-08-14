<?php

declare(strict_types=1);

use EduCore\Modules\Staff\Application\Discipline\DisciplineCaseAdminQuery;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAdminReadRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/bootstrap.php';

final class DisciplineCaseUiMemoryRepository implements DisciplineCaseAdminReadRepository
{
    /** @var array{filters:array<string,mixed>,limit:int,offset:int}|null */
    public ?array $lastList = null;

    public function listSummaries(array $filters, int $limit, int $offset): array
    {
        $this->lastList = ['filters' => $filters, 'limit' => $limit, 'offset' => $offset];

        return [[
            'id' => 12,
            'case_no' => 'DISC-0012',
            'status' => 'reported',
            'confidentiality_level' => 'normal',
            'opened_at' => '2026-08-09 08:00:00.000000',
        ]];
    }

    public function countSummaries(array $filters): int
    {
        return 1;
    }

    public function summaryById(int $caseId): ?array
    {
        return $caseId === 12 ? [
            'id' => 12,
            'case_no' => 'DISC-0012',
            'status' => 'reported',
            'confidentiality_level' => 'normal',
        ] : null;
    }
}

$failures = [];
$assert = static function (string $name, bool $passed) use (&$failures): void {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
};
$throws = static function (callable $callback): bool {
    try {
        $callback();
    } catch (Throwable) {
        return true;
    }

    return false;
};

$repository = new DisciplineCaseUiMemoryRepository();
$query = new DisciplineCaseAdminQuery($repository);
$pageResult = $query->paginated([
    'status' => 'reported',
    'confidentiality_level' => 'normal',
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
], 50, 0);
$assert(
    'case_index_query_accepts_only_safe_normal_case_filters',
    $pageResult['total'] === 1
        && ($repository->lastList['filters']['confidentiality_level'] ?? null) === 'normal'
        && ($repository->lastList['filters']['status'] ?? null) === 'reported'
        && $query->summary(12)['case_no'] === 'DISC-0012'
        && $throws(static fn (): array => $query->paginated(['confidentiality_level' => 'restricted'], 50, 0))
);

$page = (string) file_get_contents(dirname(__DIR__) . '/admin/disciplinary.php');
$repositorySource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/PdoDisciplineCaseAdminReadRepository.php'
);
$querySource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Application/Discipline/DisciplineCaseAdminQuery.php'
);
$authPosition = strpos($page, "Utilities::validateSession('admin');");
$postPosition = strpos($page, '$_POST');
$assert(
    'compatible_route_keeps_auth_csrf_and_legacy_post_names_before_request_writes',
    $authPosition !== false
        && $postPosition !== false
        && $authPosition < $postPosition
        && str_contains($page, 'requireCsrfPost()')
        && str_contains($page, 'save_action')
        && str_contains($page, 'delete_action')
        && str_contains($page, 'StaffHrFeatureFlags::fromEnvironment()')
        && str_contains($page, 'disciplineCaseAdminQuery()')
);
$assert(
    'legacy_surface_has_no_hard_delete_and_uses_a_bootstrap_protection_modal',
    preg_match('/\bDELETE\s+FROM\s+staff_disciplinary\b/i', $page) !== 1
        && str_contains($page, 'disciplinaryRecordProtectedModal')
        && str_contains($page, 'openDisciplinaryRecordProtectedModal')
        && str_contains($page, 'admin-modal')
        && !str_contains($page, 'confirm(')
        && !str_contains($page, 'Swal')
);
$assert(
    'new_case_index_is_confidential_by_default_and_keeps_a_stable_case_route',
    str_contains($page, 'case_id')
        && str_contains($page, 'القضايا المقيدة حتى تتوافر صلاحية عرض قضية محددة')
        && str_contains($repositorySource, "c.confidentiality_level = 'normal'")
        && str_contains($querySource, "CONFIDENTIALITY_LEVELS = ['normal']")
        && !str_contains($repositorySource, 'staff_discipline_evidence')
        && !str_contains($repositorySource, 'decision_reason')
        && !str_contains($repositorySource, 'attachment_path')
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Staff-HR discipline UI contract failure(s).\n");
    exit(1);
}

echo "Staff-HR discipline UI contract tests passed.\n";
