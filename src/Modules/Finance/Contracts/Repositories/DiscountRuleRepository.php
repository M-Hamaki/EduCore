<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for discount rules (versioned, scoped).
 */
interface DiscountRuleRepository
{
    /**
     * Find the active rule for a code/scope within an academic year.
     *
     * @param string $code
     * @param int $academicYearId
     * @param string $scopeKey
     * @return array|null
     */
    public function findActiveRule(string $code, int $academicYearId, string $scopeKey, string $atDate): ?array;

    /** Resolve a charge-type rule first, then fall back to the general ALL scope. */
    public function findApplicableRule(string $code, int $academicYearId, string $chargeTypeKey, string $atDate): ?array;

    public function findRuleById(int $id): ?array;

    /**
     * Create a new rule version (draft).
     *
     * @param array $fields
     * @return int the rule id
     */
    public function createVersion(array $fields): int;

    /**
     * Activate a rule version (deactivates prior versions of the same code).
     *
     * @param int $ruleId
     * @param int $activatedBy
     */
    public function activateRule(int $ruleId, int $activatedBy): void;

    public function archiveRule(int $ruleId): void;
}
