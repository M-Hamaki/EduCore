<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DomainException;
use InvalidArgumentException;

/**
 * An already-authorized report scope supplied by the HTTP/application owner.
 *
 * User input never creates this object. A future manager/HR authorization
 * adapter may mint a bounded staff list, while a separately authorized
 * operations owner may mint the all-staff scope.
 */
final class AttendanceReportScope
{
    /** @var list<int>|null */
    private ?array $staffUserIds;

    private function __construct(?array $staffUserIds)
    {
        $this->staffUserIds = $staffUserIds;
    }

    /** @param list<int> $staffUserIds */
    public static function forStaffIds(array $staffUserIds): self
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $staffUserIds),
            static fn (int $staffUserId): bool => $staffUserId > 0
        )));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_SCOPE_EMPTY');
        }

        return new self($ids);
    }

    /**
     * This is intentionally a composition-root-only capability. No request
     * parameter is accepted by this class to widen a bounded scope.
     */
    public static function forAllStaff(): self
    {
        return new self(null);
    }

    /** @return list<int>|null */
    public function staffIdsFor(?int $requestedStaffUserId): ?array
    {
        if ($requestedStaffUserId === null) {
            return $this->staffUserIds;
        }
        if ($requestedStaffUserId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_REPORT_SCOPE_STAFF_INVALID');
        }
        if ($this->staffUserIds === null) {
            return [$requestedStaffUserId];
        }
        if (!in_array($requestedStaffUserId, $this->staffUserIds, true)) {
            throw new DomainException('ATTENDANCE_REPORT_SCOPE_DENIED');
        }

        return [$requestedStaffUserId];
    }

    /**
     * Revalidates every rendered/exported row at the presentation boundary.
     *
     * The report query performs the same protection before a DTO is built.
     * Keeping this small check here prevents a future export or print adapter
     * from accidentally serializing a row that was not part of the supplied
     * authorized staff scope.
     */
    public function assertStaffUserIdAllowed(int $staffUserId): void
    {
        if ($staffUserId <= 0
            || ($this->staffUserIds !== null && !in_array($staffUserId, $this->staffUserIds, true))) {
            throw new DomainException('ATTENDANCE_REPORT_SCOPE_DENIED');
        }
    }
}
