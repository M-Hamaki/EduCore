<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Permission;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Staff\Contracts\LegacyPermissionAuditWriter;
use EduCore\Modules\Staff\Contracts\LegacyPermissionRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Application owner for the stable admin/permissions.php compatibility route.
 *
 * The route intentionally retains the legacy table, fields and actions until
 * the policy/workflow rollout is officially enabled. Its page no longer owns
 * validation, SQL, transaction handling, or audit persistence.
 */
final class LegacyPermissionCompatibilityService
{
    private const ALLOWED_TYPES = ['early_leave', 'late_arrival', 'errand'];
    private const ALLOWED_STATUSES = ['pending', 'approved', 'rejected'];

    public function __construct(
        private LegacyPermissionRepository $repository,
        private LegacyPermissionAuditWriter $audit
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function getActiveStaffList(): array
    {
        return $this->repository->activeStaffList();
    }

    /** @return array<string,mixed>|null */
    public function getPermissionById(int $permissionId): ?array
    {
        return $permissionId > 0 ? $this->repository->permissionById($permissionId) : null;
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function getPermissions(array $filters = []): array
    {
        return $this->repository->permissions($filters);
    }

    /** @return array{type_stats:array<string,int>,status_stats:array<string,int>,total:int} */
    public function getPermissionStats(): array
    {
        return $this->repository->permissionStats();
    }

    /**
     * Preserves the legacy page's input names and add/edit behavior.
     *
     * @param array<string,mixed> $input
     */
    public function savePermission(array $input, int $actorId, ?int $permissionId = null): int
    {
        $normalized = $this->normalizePayload($input, $actorId);

        return $this->repository->transactional(function () use ($normalized, $permissionId): int {
            $before = null;
            if ($permissionId !== null) {
                if ($permissionId <= 0) {
                    throw new InvalidArgumentException('تعذر تحديد الإذن المطلوب تعديله.');
                }
                $before = $this->repository->lockPermission($permissionId);
                if ($before === null) {
                    throw new InvalidArgumentException('الإذن المطلوب تعديله غير موجود أو تم حذفه.');
                }
            }

            $storedId = $permissionId ?? $this->repository->insertPermission($normalized);
            if ($permissionId !== null && !$this->repository->updatePermission($permissionId, $normalized)) {
                throw new RuntimeException('تعذر حفظ تعديل الإذن.');
            }
            if ($storedId <= 0) {
                throw new RuntimeException('تعذر حفظ الإذن.');
            }

            $after = $this->repository->lockPermission($storedId);
            if ($after === null) {
                throw new RuntimeException('تعذر إعادة تحميل الإذن بعد الحفظ.');
            }

            if ($before === null) {
                $this->audit->permissionCreated($storedId, $after);
            } elseif ($before != $after) {
                $this->audit->permissionUpdated($storedId, $before, $after);
            }

            return $storedId;
        });
    }

    /**
     * Retains the legacy delete action while keeping its audit record in the
     * same transaction. A missing row remains an idempotent no-op.
     */
    public function deletePermission(int $permissionId): bool
    {
        if ($permissionId <= 0) {
            return false;
        }

        return $this->repository->transactional(function () use ($permissionId): bool {
            $before = $this->repository->lockPermission($permissionId);
            if ($before === null) {
                return false;
            }
            if (!$this->repository->deletePermission($permissionId)) {
                throw new RuntimeException('تعذر حذف الإذن.');
            }
            $this->audit->permissionDeleted($permissionId, $before);

            return true;
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizePayload(array $input, int $actorId): array
    {
        $userId = (int) ($input['user_id'] ?? 0);
        $type = trim((string) ($input['permission_type'] ?? ''));
        $date = trim((string) ($input['permission_date'] ?? ''));
        $timeFrom = trim((string) ($input['time_from'] ?? ''));
        $timeTo = trim((string) ($input['time_to'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'pending'));
        $reason = trim((string) ($input['reason'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));

        if ($actorId <= 0) {
            throw new InvalidArgumentException('تعذر التحقق من المستخدم المنفذ للعملية. أعد تسجيل الدخول ثم حاول مرة أخرى.');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('يجب اختيار العامل قبل حفظ الإذن.');
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('نوع الإذن المختار غير صالح.');
        }
        if (!$this->isValidDate($date)) {
            throw new InvalidArgumentException('تاريخ الإذن غير صالح.');
        }
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('حالة الإذن المختارة غير صالحة.');
        }

        $timeFrom = $timeFrom === '' ? null : $timeFrom;
        $timeTo = $timeTo === '' ? null : $timeTo;
        if ($timeFrom !== null && !$this->isValidTime($timeFrom)) {
            throw new InvalidArgumentException('وقت بداية الإذن غير صالح.');
        }
        if ($timeTo !== null && !$this->isValidTime($timeTo)) {
            throw new InvalidArgumentException('وقت نهاية الإذن غير صالح.');
        }
        if ($timeFrom !== null && $timeTo !== null && strcmp($timeFrom, $timeTo) > 0) {
            throw new InvalidArgumentException('وقت بداية الإذن يجب أن يسبق وقت نهايته.');
        }

        return [
            'user_id' => $userId,
            'permission_type' => $type,
            'permission_date' => $date,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'reason' => $reason,
            'status' => $status,
            'approved_by' => $status === 'approved' ? $actorId : null,
            'notes' => $notes,
        ];
    }

    private function isValidDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }

    private function isValidTime(string $time): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!H:i', $time, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            && $parsed->format('H:i') === $time;
    }
}
