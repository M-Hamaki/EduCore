<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use AcademicYear;
use ActivityLog;
use DateTime;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use UndoManager;
use User;
use EduCore\Modules\Operations\Audit\AuditService;

final class StudentGuardianService
{
    private const RELATIONSHIP_LABELS = [
        'father' => 'الأب', 'mother' => 'الأم', 'grandfather' => 'الجد', 'grandmother' => 'الجدة',
        'uncle_paternal' => 'العم', 'aunt_paternal' => 'العمة', 'uncle_maternal' => 'الخال',
        'aunt_maternal' => 'الخالة', 'brother' => 'الأخ', 'sister' => 'الأخت',
        'legal_guardian' => 'وصي قانوني', 'other' => 'صلة قرابة أخرى',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function save(User $user, int $studentId, array $guardians, bool $replaceExisting): array
    {
        if (!$guardians) {
            return [];
        }
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $beforeStmt = $this->db->prepare('SELECT * FROM student_guardians WHERE student_id = ? ORDER BY id FOR UPDATE');
            $beforeStmt->execute([$studentId]);
            $beforeRows = $beforeStmt->fetchAll(PDO::FETCH_ASSOC);
            $beforeById = [];
            foreach ($beforeRows as $row) $beforeById[(int) $row['id']] = $row;

            if ($replaceExisting) {
                $this->db->prepare('DELETE FROM student_guardians WHERE student_id = ?')->execute([$studentId]);
            }

            [$prepared, $missingNames] = $this->prepare($studentId, $guardians);
            $savedIds = [];
            foreach ($prepared as $guardian) {
                if ($replaceExisting) unset($guardian['id']);
                if (!$user->saveStudentGuardian($guardian)) {
                    throw new RuntimeException('فشل حفظ بيانات أحد أولياء الأمور.');
                }
                $savedId = !empty($guardian['id']) ? (int) $guardian['id'] : (int) $this->db->lastInsertId();
                if ($savedId > 0) $savedIds[] = $savedId;
            }

            $afterById = [];
            if ($savedIds) {
                $placeholders = implode(',', array_fill(0, count($savedIds), '?'));
                $afterStmt = $this->db->prepare("SELECT * FROM student_guardians WHERE id IN ({$placeholders}) ORDER BY id");
                $afterStmt->execute($savedIds);
                foreach ($afterStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $afterById[(int) $row['id']] = $row;
            }

            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            if ($replaceExisting) {
                foreach ($beforeRows as $before) {
                    $audit->recordDelete('student_guardian', 'student_guardians', (int) $before['id'], (string) $before['guardian_name'], $before, 'استبدال ولي أمر الطالب', $batchId);
                }
                foreach ($afterById as $after) {
                    $audit->recordInsert('student_guardian', 'student_guardians', (int) $after['id'], (string) $after['guardian_name'], $after, 'إضافة ولي أمر الطالب', $batchId);
                }
            } else {
                foreach ($afterById as $id => $after) {
                    if (isset($beforeById[$id])) {
                        $audit->recordUpdate('student_guardian', 'student_guardians', $id, (string) $after['guardian_name'], $beforeById[$id], $after, 'تعديل ولي أمر الطالب', $batchId);
                    } else {
                        $audit->recordInsert('student_guardian', 'student_guardians', $id, (string) $after['guardian_name'], $after, 'إضافة ولي أمر الطالب', $batchId);
                    }
                }
            }
            if ($ownsTransaction) $this->db->commit();
            return $missingNames;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function prepare(int $studentId, array $guardians): array
    {
        $prepared = [];
        $missingNames = [];
        foreach ($guardians as $guardian) {
            if (!is_array($guardian)) {
                continue;
            }
            $name = trim((string) ($guardian['guardian_name'] ?? ''));
            if ($name === '') {
                $missingNames[] = self::RELATIONSHIP_LABELS[$guardian['relationship'] ?? ''] ?? 'ولي الأمر';
                $name = 'ولي أمر بدون اسم';
            }
            $guardian['guardian_name'] = $name;
            $guardian['student_id'] = $studentId;
            if (($guardian['religion'] ?? '') === 'أخرى' && !empty(trim((string) ($guardian['religion_other'] ?? '')))) {
                $guardian['religion'] = trim((string) $guardian['religion_other']);
            }
            if (($guardian['nationality'] ?? '') === 'أخرى' && !empty(trim((string) ($guardian['nationality_other'] ?? '')))) {
                $guardian['nationality'] = trim((string) $guardian['nationality_other']);
            }
            $guardian['extra_phones'] = StudentProfilePayload::guardianExtraPhones($guardian);
            $guardian['extra_data'] = StudentProfilePayload::guardianExtraData($guardian);
            $prepared[] = $guardian;
        }
        return [$prepared, $missingNames];
    }
}
