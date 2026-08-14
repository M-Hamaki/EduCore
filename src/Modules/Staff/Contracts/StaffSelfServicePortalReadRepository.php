<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

interface StaffSelfServicePortalReadRepository
{
    /** @return list<array<string,mixed>> */
    public function activeLeaveTypes(): array;

    /** @return list<array<string,mixed>> */
    public function leaveRequestsForStaff(int $staffUserId, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function leaveBalanceAccountsForStaff(int $staffUserId): array;

    /** @return list<array<string,mixed>> */
    public function activePermissionTypes(): array;

    /** @return list<array<string,mixed>> */
    public function permissionRequestsForStaff(int $staffUserId, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function permissionQuotaAccountsForStaff(int $staffUserId, string $periodKey): array;
}
