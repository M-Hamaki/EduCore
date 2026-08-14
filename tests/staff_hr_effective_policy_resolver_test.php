<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/Staff/Domain/Policy/PolicyScope.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/Domain/Policy/EffectiveDatedPolicy.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/Application/Policy/EffectivePolicyResolver.php';

use EduCore\Modules\Staff\Application\Policy\EffectivePolicyResolver;
use EduCore\Modules\Staff\Domain\Policy\EffectiveDatedPolicy;
use EduCore\Modules\Staff\Domain\Policy\PolicyScope;

/** @param array<string,mixed> $configuration */
function staffHrPolicy(
    int $policyId,
    int $versionId,
    string $scopeType,
    ?int $scopeId,
    int $priority = 0,
    string $effectiveFrom = '2026-01-01',
    string $state = EffectiveDatedPolicy::STATE_PUBLISHED,
    array $configuration = []
): EffectiveDatedPolicy {
    $from = new DateTimeImmutable($effectiveFrom . ' 00:00:00 UTC');
    return new EffectiveDatedPolicy(
        $policyId,
        $versionId,
        1,
        $state,
        $from,
        null,
        new PolicyScope($scopeType, $scopeId, $priority, $from),
        $configuration
    );
}

function staffHrThrows(string $exceptionClass, callable $callback, ?string $messagePart = null): bool
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error instanceof $exceptionClass
            && ($messagePart === null || strpos($error->getMessage(), $messagePart) !== false);
    }
    return false;
}

$resolver = new EffectivePolicyResolver();
$atDate = new DateTimeImmutable('2026-08-15 12:00:00 UTC');

$global = staffHrPolicy(1, 101, PolicyScope::TYPE_GLOBAL, null, 100);
$jobTitle = staffHrPolicy(2, 102, PolicyScope::TYPE_JOB_TITLE, 8, 1);
$orgUnit = staffHrPolicy(3, 103, PolicyScope::TYPE_ORG_UNIT, 4, 1);
$group = staffHrPolicy(4, 104, PolicyScope::TYPE_GROUP, 9, 1);
$staff = staffHrPolicy(5, 105, PolicyScope::TYPE_STAFF, 77, 0);

$higherGroupPriority = staffHrPolicy(6, 106, PolicyScope::TYPE_GROUP, 10, 25);
$newerGroup = staffHrPolicy(7, 107, PolicyScope::TYPE_GROUP, 11, 25, '2026-07-01');
$draftStaff = staffHrPolicy(
    8,
    108,
    PolicyScope::TYPE_STAFF,
    77,
    999,
    '2026-01-01',
    EffectiveDatedPolicy::STATE_DRAFT
);
$futureStaff = staffHrPolicy(9, 109, PolicyScope::TYPE_STAFF, 77, 999, '2026-09-01');
$expiredFrom = new DateTimeImmutable('2026-01-01 00:00:00 UTC');
$expiredTo = new DateTimeImmutable('2026-07-31 23:59:59 UTC');
$expiredStaff = new EffectiveDatedPolicy(
    10,
    110,
    1,
    EffectiveDatedPolicy::STATE_PUBLISHED,
    $expiredFrom,
    $expiredTo,
    new PolicyScope(PolicyScope::TYPE_STAFF, 77, 999, $expiredFrom, $expiredTo)
);

$scopeWinner = $resolver->resolve([$global, $jobTitle, $orgUnit, $group, $staff], $atDate);
$priorityWinner = $resolver->resolve([$global, $group, $higherGroupPriority], $atDate);
$dateWinner = $resolver->resolve([$higherGroupPriority, $newerGroup], $atDate);
$filteredWinner = $resolver->resolve([$global, $draftStaff, $futureStaff, $expiredStaff], $atDate);
$explained = $resolver->resolveWithExplanation([$global, $group], $atDate);

$expectations = [
    'staff_scope_precedes_all_other_scopes' => $scopeWinner !== null && $scopeWinner->versionId() === 105,
    'group_precedes_org_unit_job_title_and_global' => $resolver->resolve([$global, $jobTitle, $orgUnit, $group], $atDate)->versionId() === 104,
    'org_unit_precedes_job_title_and_global' => $resolver->resolve([$global, $jobTitle, $orgUnit], $atDate)->versionId() === 103,
    'job_title_precedes_global' => $resolver->resolve([$global, $jobTitle], $atDate)->versionId() === 102,
    'priority_breaks_tie_within_same_scope_level' => $priorityWinner !== null && $priorityWinner->versionId() === 106,
    'later_effective_start_breaks_remaining_tie' => $dateWinner !== null && $dateWinner->versionId() === 107,
    'draft_future_and_expired_candidates_are_ignored' => $filteredWinner !== null && $filteredWinner->versionId() === 101,
    'no_effective_policy_returns_null' => $resolver->resolve([$futureStaff], $atDate) === null,
    'selection_explanation_is_stable' => $explained !== null
        && $explained['policy']->versionId() === 104
        && $explained['reason']['scope_type'] === PolicyScope::TYPE_GROUP
        && $explained['reason']['policy_version_id'] === 104,
    'equal_rank_priority_and_effective_date_are_rejected' => staffHrThrows(
        DomainException::class,
        static function () use ($resolver, $atDate): void {
            $resolver->resolve([
                staffHrPolicy(20, 220, PolicyScope::TYPE_GROUP, 31, 10, '2026-06-01'),
                staffHrPolicy(21, 221, PolicyScope::TYPE_GROUP, 32, 10, '2026-06-01'),
            ], $atDate);
        },
        'AMBIGUOUS_EFFECTIVE_POLICY'
    ),
    'same_query_row_is_idempotently_collapsed' => $resolver->resolve([$group, $group], $atDate)->versionId() === 104,
    'non_policy_candidate_is_rejected' => staffHrThrows(
        InvalidArgumentException::class,
        static function () use ($resolver, $atDate): void {
            $resolver->resolve([new stdClass()], $atDate);
        }
    ),
    'global_scope_rejects_an_id' => staffHrThrows(
        InvalidArgumentException::class,
        static function (): void {
            new PolicyScope(
                PolicyScope::TYPE_GLOBAL,
                1,
                0,
                new DateTimeImmutable('2026-01-01 UTC')
            );
        }
    ),
    'non_global_scope_requires_an_id' => staffHrThrows(
        InvalidArgumentException::class,
        static function (): void {
            new PolicyScope(
                PolicyScope::TYPE_GROUP,
                null,
                0,
                new DateTimeImmutable('2026-01-01 UTC')
            );
        }
    ),
    'invalid_effective_range_is_rejected' => staffHrThrows(
        InvalidArgumentException::class,
        static function (): void {
            new PolicyScope(
                PolicyScope::TYPE_STAFF,
                1,
                0,
                new DateTimeImmutable('2026-02-01 UTC'),
                new DateTimeImmutable('2026-01-01 UTC')
            );
        }
    ),
    'mutable_configuration_objects_are_rejected' => staffHrThrows(
        InvalidArgumentException::class,
        static function (): void {
            staffHrPolicy(30, 330, PolicyScope::TYPE_GLOBAL, null, 0, '2026-01-01', EffectiveDatedPolicy::STATE_PUBLISHED, [
                'unsafe' => new stdClass(),
            ]);
        }
    ),
    'policy_primitives_expose_no_public_properties' => (new ReflectionClass(PolicyScope::class))->getProperties(ReflectionProperty::IS_PUBLIC) === []
        && (new ReflectionClass(EffectiveDatedPolicy::class))->getProperties(ReflectionProperty::IS_PUBLIC) === [],
];

$failed = [];
foreach ($expectations as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
