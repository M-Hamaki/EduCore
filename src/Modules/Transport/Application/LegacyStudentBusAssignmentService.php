<?php

declare(strict_types=1);

namespace EduCore\Modules\Transport\Application;

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Operations\Audit\EntityChangeTracker;
use EduCore\Modules\Students\Contracts\StudentWriteEligibility;
use EduCore\Modules\Transport\Contracts\StudentBusAssignmentRepository;
use EduCore\Modules\Transport\Contracts\TransportTransactionManager;
use InvalidArgumentException;

final class LegacyStudentBusAssignmentService
{
    public function __construct(
        private StudentBusAssignmentRepository $assignments,
        private StudentWriteEligibility $students,
        private TransportTransactionManager $transactions,
        private AuditEventWriter $audit,
        private int $academicYearId
    ) {
        if ($academicYearId <= 0) {
            throw new InvalidArgumentException('A current academic year is required for bus assignments.');
        }
    }

    public function assign(
        int $studentId,
        ?int $busId,
        ?int $backupBusId,
        string $notes,
        int $actorId
    ): void {
        $this->transactions->transactional(function () use ($studentId, $busId, $backupBusId, $notes, $actorId): void {
            $student = $this->students->assertWritable($studentId);
            $busId = $busId !== null && $busId > 0 ? $busId : null;
            $backupBusId = $backupBusId !== null && $backupBusId > 0 ? $backupBusId : null;
            if ($busId !== null && $backupBusId !== null && $busId === $backupBusId) {
                throw new InvalidArgumentException('لا يمكن اختيار الحافلة نفسها كأساسية واحتياطية.');
            }
            foreach (array_filter([$busId, $backupBusId]) as $candidate) {
                if (!$this->assignments->activeBusExists((int) $candidate)) {
                    throw new InvalidArgumentException('الحافلة المحددة غير موجودة أو غير نشطة.');
                }
            }
            $before = $this->assignments->lock($studentId, $this->academicYearId);
            $after = $this->assignments->replace(
                $studentId,
                $this->academicYearId,
                $busId,
                $backupBusId,
                trim($notes) === '' ? null : trim($notes),
                $actorId
            );
            if (($before ?? []) === ($after ?? [])) {
                return;
            }
            $this->audit->recordEvent(
                $after === null ? 'archive' : ($before === null ? 'create' : 'update'),
                'student_bus_assignment',
                $studentId,
                (string) ($student['name'] ?? ('طالب #' . $studentId)),
                [
                    'academic_year_id' => $this->academicYearId,
                    'changes' => EntityChangeTracker::diff($before ?? [], $after ?? []),
                    'archive_only' => $after === null,
                ]
            );
        });
    }

    public function bulkAssign(array $input, int $actorId): void
    {
        $studentIds = array_values((array) ($input['student_ids'] ?? []));
        $busIds = array_values((array) ($input['bus_ids'] ?? []));
        $backupIds = array_values((array) ($input['backup_bus_ids'] ?? []));
        $notes = array_values((array) ($input['notes_arr'] ?? []));
        $this->transactions->transactional(function () use ($studentIds, $busIds, $backupIds, $notes, $actorId): void {
            $this->students->assertWritableMany($studentIds);
            foreach ($studentIds as $index => $_studentId) {
                $busId = (int) ($busIds[$index] ?? 0);
                $backupBusId = (int) ($backupIds[$index] ?? 0);
                if ($busId > 0 && $backupBusId > 0 && $busId === $backupBusId) {
                    throw new InvalidArgumentException('لا يمكن اختيار الحافلة نفسها كأساسية واحتياطية.');
                }
                foreach (array_filter([$busId, $backupBusId]) as $candidate) {
                    if (!$this->assignments->activeBusExists((int) $candidate)) {
                        throw new InvalidArgumentException('الحافلة المحددة غير موجودة أو غير نشطة.');
                    }
                }
            }
            foreach ($studentIds as $index => $studentId) {
                $this->assign(
                    (int) $studentId,
                    (int) ($busIds[$index] ?? 0),
                    (int) ($backupIds[$index] ?? 0),
                    (string) ($notes[$index] ?? ''),
                    $actorId
                );
            }
        });
    }
}
