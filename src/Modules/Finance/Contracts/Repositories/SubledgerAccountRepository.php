<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for the unified sub-ledger account (students + staff).
 */
interface SubledgerAccountRepository
{
    /**
     * Find or create a sub-ledger account for a party (student/staff) × scope.
     *
     * @param string $partyType  'student' or 'staff'
     * @param int $partyId       users.id
     * @param string $scopeKey   academic year ID for students; 'STAFF_GLOBAL' for staff
     * @return array{id: int} the account row
     */
    public function findOrCreate(string $partyType, int $partyId, string $scopeKey): array;

    /**
     * Find an account by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;
}
