<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\LegacyStaffShiftAuditWriter;
use EduCore\Modules\Attendance\Contracts\LegacyStaffShiftRepository;
use InvalidArgumentException;
use RuntimeException;

final class LegacyStaffShiftCompatibilityService
{
    private const MAX_GRACE_MINUTES = 240;

    private LegacyStaffShiftRepository $repository;
    private AttendanceTransactionManager $transactions;
    private LegacyStaffShiftAuditWriter $audit;

    public function __construct(
        LegacyStaffShiftRepository $repository,
        AttendanceTransactionManager $transactions,
        LegacyStaffShiftAuditWriter $audit
    ) {
        $this->repository = $repository;
        $this->transactions = $transactions;
        $this->audit = $audit;
    }

    /** @return array<string, mixed> */
    public function viewData(): array
    {
        return $this->repository->viewData();
    }

    /** @param array<string, mixed> $input */
    public function saveDefaultShift(array $input): void
    {
        $start = (string) ($input['default_shift_start'] ?? '07:30');
        $end = (string) ($input['default_shift_end'] ?? '14:30');
        $grace = (int) ($input['default_shift_grace_minutes'] ?? 15);
        $this->validateShift($start, $end, $grace);

        $this->transactions->transactional(function () use ($start, $end, $grace): void {
            $before = $this->repository->lockDefaultSettings();
            $after = [
                'staff_shift_start' => $start,
                'staff_shift_end' => $end,
                'staff_shift_grace_minutes' => (string) $grace,
            ];
            $this->repository->upsertDefaultSetting('staff_shift_start', $start, 'وقت بداية دوام الموظفين الافتراضي');
            $this->repository->upsertDefaultSetting('staff_shift_end', $end, 'وقت نهاية دوام الموظفين الافتراضي');
            $this->repository->upsertDefaultSetting('staff_shift_grace_minutes', (string) $grace, 'فترة السماح الافتراضية للدوام بالدقائق');
            $this->audit->defaultSettingsChanged($before, $after);
        });
    }

    /** @param array<string, mixed> $input */
    public function saveOverride(array $input): void
    {
        $userId = (int) ($input['user_id'] ?? 0);
        $start = (string) ($input['shift_start'] ?? '07:30');
        $end = (string) ($input['shift_end'] ?? '14:30');
        $grace = (int) ($input['grace_minutes'] ?? 15);
        $this->validateShift($start, $end, $grace);
        if ($userId <= 0) {
            throw new InvalidArgumentException('اختر العامل المطلوب قبل الحفظ.');
        }
        $values = [
            'user_id' => $userId,
            'shift_start' => $start,
            'shift_end' => $end,
            'grace_minutes' => $grace,
            'is_active' => array_key_exists('is_active', $input) ? 1 : 0,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        $this->transactions->transactional(function () use ($userId, $values): void {
            if (!$this->repository->isEligibleActiveStaff($userId)) {
                throw new InvalidArgumentException('العامل غير نشط أو غير مؤهل لدوام العاملين. حدّث القائمة ثم اختر عاملًا آخر.');
            }
            $before = $this->repository->lockOverrideByUser($userId);
            $this->repository->storeOverride($values);
            $after = $this->repository->findOverrideByUser($userId);
            if ($after === null) {
                throw new RuntimeException('Shift override was not stored.');
            }
            $staffName = (string) $after['staff_name'];
            unset($after['staff_name']);
            if ($before !== null) {
                $this->audit->overrideUpdated((int) $after['id'], $staffName, $before, $after);
                return;
            }
            $this->audit->overrideCreated((int) $after['id'], $staffName, $after);
        });
    }

    public function deleteOverride(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('تعذر تحديد الدوام المطلوب حذفه.');
        }
        $this->transactions->transactional(function () use ($id): void {
            $before = $this->repository->lockOverrideById($id);
            if ($before === null) {
                throw new InvalidArgumentException('الدوام المخصص غير موجود أو سبق حذفه.');
            }
            $staffName = (string) $before['staff_name'];
            unset($before['staff_name']);
            $this->repository->deleteOverride($id);
            $this->audit->overrideDeleted($id, $staffName, $before);
        });
    }

    private function validateShift(string $start, string $end, int $grace): void
    {
        if (!$this->isValidTime($start) || !$this->isValidTime($end)) {
            throw new InvalidArgumentException('صيغة وقت الدوام غير صحيحة. استخدم ساعة ودقيقة صحيحتين.');
        }
        if ($start === $end) {
            throw new InvalidArgumentException('لا يمكن أن يتساوى وقت بداية الدوام مع نهايته. النهاية الأسبق تعني وردية ليلية لليوم التالي.');
        }
        if ($grace < 0 || $grace > self::MAX_GRACE_MINUTES) {
            throw new InvalidArgumentException('فترة السماح يجب أن تكون بين صفر و240 دقيقة.');
        }
    }

    private function isValidTime(string $time): bool
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return false;
        }
        return (int) $matches[1] <= 23 && (int) $matches[2] <= 59;
    }
}
