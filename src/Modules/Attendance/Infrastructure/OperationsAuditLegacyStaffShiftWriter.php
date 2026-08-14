<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use EduCore\Modules\Attendance\Contracts\LegacyStaffShiftAuditWriter;
use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Operations\Audit\EntityChangeTracker;

final class OperationsAuditLegacyStaffShiftWriter implements LegacyStaffShiftAuditWriter
{
    private AuditService $audit;

    public function __construct(AuditService $audit)
    {
        $this->audit = $audit;
    }

    public function defaultSettingsChanged(array $before, array $after): void
    {
        $this->audit->recordEvent('settings', 'staff_shift_settings', null, 'إعدادات دوام الموظفين', [
            'changes' => EntityChangeTracker::diff($before, $after),
        ]);
    }

    public function overrideCreated(int $id, string $staffName, array $after): void
    {
        $this->audit->recordInsert('staff_shift', 'staff_shift_overrides', $id, $staffName, $after, 'إضافة دوام موظف');
    }

    public function overrideUpdated(int $id, string $staffName, array $before, array $after): void
    {
        $this->audit->recordUpdate('staff_shift', 'staff_shift_overrides', $id, $staffName, $before, $after, 'تعديل دوام موظف');
    }

    public function overrideDeleted(int $id, string $staffName, array $before): void
    {
        $this->audit->recordDelete('staff_shift', 'staff_shift_overrides', $id, $staffName, $before, 'حذف دوام موظف');
    }
}
