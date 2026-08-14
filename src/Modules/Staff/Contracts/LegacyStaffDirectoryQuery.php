<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

interface LegacyStaffDirectoryQuery
{
    /** @return list<array{id:int,name:string}> */
    public function listActiveStaff(): array;

    public function isEligibleActiveStaff(int $staffId): bool;

    /** @param list<int> $staffIds @return array<int,string> */
    public function namesByIds(array $staffIds): array;
}
