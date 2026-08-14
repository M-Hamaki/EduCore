<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use DateTimeImmutable;
use EduCore\Modules\Operations\Audit\AuditService;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use RuntimeException;
use Throwable;

final class StudentArchiveService
{
    public const PERMANENT_DELETE_DELAY_HOURS = 24;

    private const PROTECTED_REFERENCES = [
        'external_transfers' => ['student_id', 'سجل النقل الخارجي'],
        'student_external_transfers' => ['student_id', 'سجل النقل الخارجي'],
        'student_enrollments' => ['student_id', 'سجل القيد الأكاديمي'],
        'student_change_requests' => ['student_id', 'طلبات تعديل بيانات الطالب'],
        'student_promotion_decisions' => ['student_id', 'قرارات الترحيل الأكاديمي'],
        'student_transfers' => ['student_id', 'سجل النقل بين الفصول'],
        'attendance' => ['student_id', 'سجلات الحضور'],
        'evaluations' => ['student_id', 'التقييمات السلوكية'],
        'student_grades' => ['student_id', 'الدرجات القديمة'],
        'student_marks' => ['student_id', 'درجات منظومة الرصد'],
        'student_mark_audit' => ['student_id', 'تدقيق الدرجات'],
        'assessment_student_locks' => ['student_id', 'أقفال رصد الدرجات'],
        'published_reports' => ['student_id', 'التقارير المنشورة'],
        'student_fees' => ['student_id', 'المستحقات المالية'],
        'fee_payments' => ['student_id', 'المدفوعات'],
        'finance_student_accounts' => ['student_id', 'حسابات الطالب المالية'],
        'student_other_discounts' => ['student_id', 'خصومات الطالب'],
        'student_fee_balances_history' => ['student_id', 'سجل الأرصدة'],
        'grade_audit_log' => ['student_id', 'سجل تدقيق الدرجات'],
        'clinic_visits' => ['student_id', 'زيارات العيادة القديمة'],
        'student_clinic_visits' => ['student_id', 'زيارات العيادة'],
        'library_loans' => ['student_id', 'استعارات المكتبة'],
        'library_fines' => ['student_id', 'غرامات المكتبة'],
        'student_bus_assignments' => ['student_id', 'تعيينات النقل المدرسي'],
        'activity_results' => ['student_id', 'نتائج الأنشطة التعليمية'],
    ];

    /** Child profile data intentionally removed with an eligible empty student file. */
    private const PURGE_DEPENDENT_REFERENCES = [
        'student_profiles' => ['user_id'],
        'student_attachments' => ['user_id'],
        'student_guardians' => ['student_id'],
        'student_kinships' => ['student_id', 'relative_id'],
        'student_siblings' => ['student_id', 'sibling_id'],
    ];

    private PDO $db;
    private ProfileAttachmentStorage $storage;

    public function __construct(PDO $db, ProfileAttachmentStorage $storage)
    {
        $this->db = $db;
        $this->storage = $storage;
    }

    public function archive(int $studentId, int $actorId, string $reason): void
    {
        $this->assertSchemaReady();
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('سبب الأرشفة مطلوب ويجب أن يكون بين 5 و500 حرف.');
        }

        $this->db->beginTransaction();
        try {
            $student = $this->fetchStudent($studentId, true);
            if (!empty($student['deleted_at'])) {
                throw new InvalidArgumentException('الطالب مؤرشف بالفعل.');
            }

            $before = $this->auditSnapshot($student);
            $stmt = $this->db->prepare(
                "UPDATE users
                 SET status_before_archive = status,
                     status = 'inactive', deleted_at = NOW(), archived_by = ?, archive_reason = ?
                 WHERE id = ? AND role = 'student' AND deleted_at IS NULL"
            );
            $stmt->execute([$actorId, $reason, $studentId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('تعذر أرشفة الطالب بسبب تغيير متزامن.');
            }

            $after = $this->auditSnapshot($this->fetchStudent($studentId, false));
            (new AuditService($this->db))->recordUpdate(
                'student', 'users', $studentId, (string) $student['name'],
                $before, $after, 'أرشفة طالب: ' . (string) $student['name']
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function restore(int $studentId): void
    {
        $this->assertSchemaReady();
        $this->db->beginTransaction();
        try {
            $student = $this->fetchStudent($studentId, true);
            if (empty($student['deleted_at'])) {
                throw new InvalidArgumentException('الطالب غير موجود في الأرشيف.');
            }

            $before = $this->auditSnapshot($student);
            $previousStatus = (string) ($student['status_before_archive'] ?? '');
            if (!in_array($previousStatus, ['active', 'inactive', 'graduated'], true)) {
                $previousStatus = 'inactive';
            }
            $stmt = $this->db->prepare(
                "UPDATE users
                 SET status = ?, deleted_at = NULL, archived_by = NULL,
                     archive_reason = NULL, status_before_archive = NULL
                 WHERE id = ? AND role = 'student' AND deleted_at IS NOT NULL"
            );
            $stmt->execute([$previousStatus, $studentId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('تعذر استرجاع الطالب بسبب تغيير متزامن.');
            }

            $after = $this->auditSnapshot($this->fetchStudent($studentId, false));
            (new AuditService($this->db))->recordUpdate(
                'student', 'users', $studentId, (string) $student['name'],
                $before, $after, 'استرجاع طالب من الأرشيف: ' . (string) $student['name']
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function permanentlyDelete(int $studentId, string $confirmationCode): array
    {
        $this->assertSchemaReady();
        $this->db->beginTransaction();
        $attachments = [];
        try {
            $student = $this->fetchStudent($studentId, true);
            if (empty($student['deleted_at'])) {
                throw new InvalidArgumentException('الحذف النهائي متاح للطلاب المؤرشفين فقط.');
            }

            $expectedCode = trim((string) ($student['student_code'] ?: $student['id']));
            if (!hash_equals($expectedCode, trim($confirmationCode))) {
                throw new InvalidArgumentException('كود التأكيد غير مطابق لكود الطالب.');
            }

            $archivedAt = new DateTimeImmutable((string) $student['deleted_at']);
            if ($archivedAt->modify('+' . self::PERMANENT_DELETE_DELAY_HOURS . ' hours') > new DateTimeImmutable('now')) {
                throw new RuntimeException('لا يمكن الحذف النهائي قبل مرور 24 ساعة على الأرشفة.');
            }

            $protected = $this->protectedRecordCounts($studentId);
            if ($protected) {
                $labels = [];
                foreach ($protected as $item) {
                    $labels[] = $item['label'] . ' (' . $item['count'] . ')';
                }
                throw new RuntimeException(
                    'لا يمكن حذف الطالب نهائيًا لوجود سجلات رسمية: ' . implode('، ', $labels) . '. استخدم الأرشفة للاحتفاظ بالتاريخ.'
                );
            }

            if ($this->tableColumnExists('student_attachments', 'user_id')) {
                $attachmentStmt = $this->db->prepare('SELECT id, file_name FROM student_attachments WHERE user_id = ? FOR UPDATE');
                $attachmentStmt->execute([$studentId]);
                $attachments = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $this->db->prepare('DELETE FROM student_attachments WHERE user_id = ?')->execute([$studentId]);
            }

            $delete = $this->db->prepare("DELETE FROM users WHERE id = ? AND role = 'student' AND deleted_at IS NOT NULL");
            $delete->execute([$studentId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('تعذر حذف الطالب نهائيًا بسبب تغيير متزامن.');
            }

            (new AuditService($this->db))->recordEvent('delete', 'student', $studentId, (string) $student['name'], [
                'summary' => 'حذف نهائي لطالب مؤرشف',
                'student_code' => $expectedCode,
                'archive_reason' => (string) ($student['archive_reason'] ?? ''),
                'attachment_cleanup_intent_count' => count($attachments),
                'undo_policy' => 'irreversible_student_purge_after_archive',
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $failedFiles = [];
        foreach ($attachments as $attachment) {
            if (!$this->storage->delete('student', (string) $attachment['file_name'])) {
                $failedFiles[] = (int) $attachment['id'];
            }
        }

        if ($attachments) {
            $failedStorageNames = [];
            foreach ($attachments as $attachment) {
                if (in_array((int) $attachment['id'], $failedFiles, true)) {
                    $failedStorageNames[] = (string) $attachment['file_name'];
                }
            }
            try {
                (new AuditService($this->db))->recordEvent('delete', 'student_attachment', $studentId, 'مرفقات طالب محذوف', [
                    'summary' => $failedFiles ? 'اكتمل تنظيف المرفقات جزئيًا' : 'اكتمل تنظيف المرفقات',
                    'requested_count' => count($attachments),
                    'failed_count' => count($failedFiles),
                    'failed_attachment_ids' => $failedFiles,
                    'failed_storage_names' => $failedStorageNames,
                    'undo_policy' => 'external_file_cleanup_not_undoable',
                ]);
            } catch (Throwable $auditError) {
                error_log('Student permanent delete file cleanup audit failed: ' . $auditError->getMessage());
                $failedFiles[] = 0;
            }
        }

        return ['failed_file_ids' => array_values(array_unique($failedFiles))];
    }

    public function protectedRecordCounts(int $studentId): array
    {
        $counts = [];
        foreach (self::PROTECTED_REFERENCES as $table => [$column, $label]) {
            if (!$this->tableColumnExists($table, $column)) {
                continue;
            }
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
            $stmt->execute([$studentId]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $counts[$table] = ['label' => $label, 'count' => $count];
            }
        }
        foreach ($this->unclassifiedStudentReferences() as [$table, $column]) {
            $identifierTable = str_replace('`', '``', $table);
            $identifierColumn = str_replace('`', '``', $column);
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$identifierTable}` WHERE `{$identifierColumn}` = ?");
            $stmt->execute([$studentId]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $counts[$table . '.' . $column] = [
                    'label' => 'مرجع بيانات طالب غير مصنف (' . $table . '.' . $column . ')',
                    'count' => $count,
                ];
            }
        }
        return $counts;
    }

    /** @return array<int,array{0:string,1:string}> */
    private function unclassifiedStudentReferences(): array
    {
        $stmt = $this->db->query(
            "SELECT TABLE_NAME, COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND (
                    COLUMN_NAME IN ('student_id', 'sibling_id', 'relative_id')
                    OR (LEFT(TABLE_NAME, 8) = 'student_' AND COLUMN_NAME = 'user_id')
               )
               AND LEFT(TABLE_NAME, 7) <> 'backup_'
               AND LEFT(TABLE_NAME, 4) <> 'bpa_'
             ORDER BY TABLE_NAME, COLUMN_NAME"
        );
        $references = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $table = (string) $row['TABLE_NAME'];
            $column = (string) $row['COLUMN_NAME'];
            if (isset(self::PROTECTED_REFERENCES[$table])
                && self::PROTECTED_REFERENCES[$table][0] === $column) {
                continue;
            }
            if (in_array($column, self::PURGE_DEPENDENT_REFERENCES[$table] ?? [], true)) {
                continue;
            }
            $references[] = [$table, $column];
        }
        return $references;
    }

    private function fetchStudent(int $studentId, bool $forUpdate): array
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('معرف الطالب غير صالح.');
        }
        $sql = "SELECT u.id, u.name, u.role, u.status, u.deleted_at, u.archived_by,
                       u.archive_reason, u.status_before_archive, sp.student_code
                FROM users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                WHERE u.id = ? AND u.role = 'student' LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            throw new InvalidArgumentException('ملف الطالب المطلوب غير موجود.');
        }
        return $student;
    }

    private function auditSnapshot(array $student): array
    {
        return array_intersect_key($student, array_flip([
            'id', 'name', 'role', 'status', 'deleted_at', 'archived_by', 'archive_reason', 'status_before_archive',
        ]));
    }

    private function assertSchemaReady(): void
    {
        $stmt = $this->db->query(
            "SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
               AND COLUMN_NAME IN ('deleted_at','archived_by','archive_reason','status_before_archive')"
        );
        if ((int) $stmt->fetchColumn() !== 4) {
            throw new RuntimeException('مخطط أرشيف الطلاب غير جاهز. شغّل migration الأرشفة أولاً.');
        }
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }
}
