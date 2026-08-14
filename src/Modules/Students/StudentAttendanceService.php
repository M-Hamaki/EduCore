<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use AcademicYearWriteGuard;
use DateTimeImmutable;
use EduCore\Modules\Operations\Audit\AuditService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use UndoManager;

final class StudentAttendanceService
{
    private const ALLOWED_STATUSES = ['present', 'absent', 'late', 'excused'];

    public function __construct(private PDO $db)
    {
    }

    /**
     * Replaces attendance values for the exact current roster while preserving
     * historical rows belonging to students who are no longer in the class.
     *
     * @param array<int|string,mixed> $statuses
     * @param array<int|string,mixed> $notes
     * @return array{count:int,changed:int,created:int}
     */
    public function saveClassDay(
        int $classId,
        int $academicYearId,
        string $attendanceDate,
        array $statuses,
        array $notes,
        int $actorId,
        string $actorRole
    ): array {
        if ($classId <= 0 || $academicYearId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('بيانات حفظ الحضور غير مكتملة.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$date || $date->format('Y-m-d') !== $attendanceDate
            || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new InvalidArgumentException('تاريخ الحضور غير صالح.');
        }
        if ($date > new DateTimeImmutable('today')) {
            throw new InvalidArgumentException('لا يمكن تسجيل حضور بتاريخ مستقبلي.');
        }

        $normalizedStatuses = [];
        foreach ($statuses as $studentId => $status) {
            $studentId = (int) $studentId;
            if (!is_scalar($status)) {
                throw new InvalidArgumentException('تتضمن بيانات الحضور حالة مشوهة.');
            }
            $status = trim((string) $status);
            if ($studentId <= 0 || !in_array($status, self::ALLOWED_STATUSES, true)) {
                throw new InvalidArgumentException('تتضمن بيانات الحضور طالبًا أو حالة غير صالحة.');
            }
            $normalizedStatuses[$studentId] = $status;
        }
        if ($normalizedStatuses === []) {
            throw new InvalidArgumentException('لا توجد بيانات حضور قابلة للحفظ.');
        }

        $normalizedNotes = [];
        foreach ($notes as $studentId => $note) {
            $studentId = (int) $studentId;
            if ($studentId > 0 && isset($normalizedStatuses[$studentId])) {
                if (!is_scalar($note)) {
                    throw new InvalidArgumentException('تتضمن ملاحظات الحضور قيمة مشوهة.');
                }
                $normalizedNotes[$studentId] = mb_substr(trim((string) $note), 0, 2000);
            }
        }

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();

            $year = (new AcademicYearWriteGuard($this->db))->assertWritable($academicYearId);
            $yearDates = $this->db->prepare('SELECT start_date, end_date FROM academic_years WHERE id = ? LIMIT 1 FOR UPDATE');
            $yearDates->execute([$academicYearId]);
            $year = array_merge($year, $yearDates->fetch(PDO::FETCH_ASSOC) ?: []);
            if (!empty($year['start_date']) && $attendanceDate < (string) $year['start_date']) {
                throw new InvalidArgumentException('تاريخ الحضور يسبق بداية العام الدراسي المحدد.');
            }
            if (!empty($year['end_date']) && $attendanceDate > (string) $year['end_date']) {
                throw new InvalidArgumentException('تاريخ الحضور يتجاوز نهاية العام الدراسي المحدد.');
            }

            $rosterStmt = $this->db->prepare(
                "SELECT u.id
                 FROM student_enrollments se
                 JOIN users u ON u.id = se.student_id
                 WHERE se.academic_year_id = ? AND se.class_id = ?
                   AND se.enrollment_status = 'enrolled'
                   AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
                 ORDER BY u.id FOR UPDATE"
            );
            $rosterStmt->execute([$academicYearId, $classId]);
            $rosterIds = array_map('intval', $rosterStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $submittedIds = array_keys($normalizedStatuses);
            sort($rosterIds);
            sort($submittedIds);
            if ($rosterIds !== $submittedIds) {
                throw new RuntimeException('تغير كشف الفصل أو أن الطلب غير مكتمل. أعد تحميل الطلاب ثم احفظ الحضور مرة أخرى.');
            }

            (new StudentOperationalGuard($this->db))->assertWritableMany($submittedIds);
            $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
            $beforeStmt = $this->db->prepare(
                "SELECT * FROM attendance
                 WHERE attendance_date = ? AND student_id IN ({$placeholders})
                 ORDER BY student_id FOR UPDATE"
            );
            $beforeStmt->execute(array_merge([$attendanceDate], $submittedIds));
            $beforeRows = $beforeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $beforeByStudent = [];
            foreach ($beforeRows as $row) {
                $rowStudentId = (int) $row['student_id'];
                if (isset($beforeByStudent[$rowStudentId])) {
                    throw new RuntimeException('توجد سجلات حضور مكررة لأحد الطلاب في التاريخ نفسه؛ أصلح التكرار قبل الحفظ.');
                }
                if ((int) $row['class_id'] !== $classId) {
                    throw new RuntimeException('يوجد سجل حضور تاريخي لأحد الطلاب في فصل آخر في التاريخ نفسه؛ لم يتم استبداله لحماية التاريخ.');
                }
                if (!empty($row['academic_year_id']) && (int) $row['academic_year_id'] !== $academicYearId) {
                    throw new RuntimeException('يوجد سجل حضور للطالب مرتبط بعام دراسي آخر في التاريخ نفسه.');
                }
                $beforeByStudent[$rowStudentId] = $row;
            }

            $update = $this->db->prepare(
                'UPDATE attendance SET class_id = ?, status = ?, notes = ?, recorded_by = ?, academic_year_id = ? WHERE id = ?'
            );
            $insert = $this->db->prepare(
                'INSERT INTO attendance (student_id, class_id, attendance_date, status, notes, recorded_by, academic_year_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($normalizedStatuses as $studentId => $status) {
                $note = $normalizedNotes[$studentId] ?? null;
                if ($note === '') $note = null;
                if (isset($beforeByStudent[$studentId])) {
                    $update->execute([
                        $classId,
                        $status,
                        $note,
                        $actorId,
                        $academicYearId,
                        (int) $beforeByStudent[$studentId]['id'],
                    ]);
                } else {
                    $insert->execute([$studentId, $classId, $attendanceDate, $status, $note, $actorId, $academicYearId]);
                }
            }

            $afterStmt = $this->db->prepare(
                "SELECT * FROM attendance
                 WHERE attendance_date = ? AND student_id IN ({$placeholders})
                 ORDER BY student_id"
            );
            $afterStmt->execute(array_merge([$attendanceDate], $submittedIds));
            $afterRows = $afterStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $afterByStudent = [];
            foreach ($afterRows as $row) {
                $rowStudentId = (int) $row['student_id'];
                if (isset($afterByStudent[$rowStudentId])) {
                    throw new RuntimeException('نتج سجل حضور مكرر؛ تم إلغاء الحفظ بالكامل.');
                }
                $afterByStudent[$rowStudentId] = $row;
            }

            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            $updatedItems = [];
            $created = 0;
            foreach ($submittedIds as $studentId) {
                $after = $afterByStudent[$studentId] ?? null;
                if (!$after) {
                    throw new RuntimeException('تعذر التحقق من اكتمال حفظ كشف الحضور.');
                }
                if (isset($beforeByStudent[$studentId])) {
                    if ($beforeByStudent[$studentId] != $after) {
                        $updatedItems[] = [
                            'table' => 'attendance',
                            'record_id' => (int) $after['id'],
                            'before' => $beforeByStudent[$studentId],
                            'after' => $after,
                            'description' => 'تعديل حضور طالب ضمن كشف فصل',
                        ];
                    }
                } else {
                    $audit->recordInsert(
                        'attendance',
                        'attendance',
                        (int) $after['id'],
                        'حضور طالب #' . $studentId,
                        $after,
                        'إضافة حضور طالب ضمن كشف فصل',
                        $batchId,
                        ['class_id' => $classId, 'attendance_date' => $attendanceDate]
                    );
                    $created++;
                }
            }
            if ($updatedItems) {
                $audit->recordCompositeUpdate(
                    'attendance_class_day',
                    $classId,
                    'كشف حضور الفصل #' . $classId,
                    $updatedItems,
                    [
                        'summary' => 'تعديل كشف حضور فصل',
                        'academic_year_id' => $academicYearId,
                        'attendance_date' => $attendanceDate,
                        'actor_role' => $actorRole,
                    ],
                    $batchId
                );
            }
            if (!$updatedItems && $created === 0) {
                $audit->recordEvent('attendance_noop', 'attendance_class_day', $classId, 'كشف حضور الفصل #' . $classId, [
                    'academic_year_id' => $academicYearId,
                    'attendance_date' => $attendanceDate,
                    'students_count' => count($submittedIds),
                    'actor_role' => $actorRole,
                ], ['batch_id' => $batchId]);
            }

            if ($ownsTransaction) $this->db->commit();
            return ['count' => count($submittedIds), 'changed' => count($updatedItems), 'created' => $created];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
