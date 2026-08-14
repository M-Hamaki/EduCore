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

final class StudentKinshipLinkException extends RuntimeException
{
}

final class StudentRelationshipGuardException extends RuntimeException
{
}

final class StudentRelationshipService
{
    private PDO $db;
    private StudentProfileRepository $profiles;

    public function __construct(PDO $db, StudentProfileRepository $profiles)
    {
        $this->db = $db;
        $this->profiles = $profiles;
    }

    public function link(int $studentId, int $relativeId, string $relationship): string
    {
        $this->assertPair($studentId, $relativeId);
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            $siblingRelationships = ['brother', 'sister', 'half_brother', 'half_sister', 'step_brother', 'step_sister'];
            if (in_array($relationship, $siblingRelationships, true)) {
                $before = $this->pairRows('student_siblings', 'sibling_id', $studentId, $relativeId, true);
                (new User($this->db))->linkSiblings($studentId, $relativeId, $relationship);
                $after = $this->pairRows('student_siblings', 'sibling_id', $studentId, $relativeId, false);
                $this->auditNewRows($audit, 'student_sibling', 'student_siblings', $before, $after, 'ربط شقيقين', $batchId);
                $message = 'تم ربط الشقيق بنجاح.';
            } else {
                $typeStmt = $this->db->prepare('SELECT * FROM kinship_types WHERE name = ? FOR UPDATE');
                $typeStmt->execute([$relationship]);
                $kinshipType = $typeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$kinshipType) {
                    $insert = $this->db->prepare('INSERT INTO kinship_types (name) VALUES (?)');
                    $insert->execute([$relationship]);
                    $kinshipTypeId = (int) $this->db->lastInsertId();
                    $kinshipType = ['id' => $kinshipTypeId, 'name' => $relationship];
                    $audit->recordInsert('kinship_type', 'kinship_types', $kinshipTypeId, $relationship, $kinshipType, 'إضافة نوع صلة قرابة', $batchId);
                } else {
                    $kinshipTypeId = (int) $kinshipType['id'];
                }

                $before = $this->pairRows('student_kinships', 'relative_id', $studentId, $relativeId, true);
                $link = $this->db->prepare('INSERT IGNORE INTO student_kinships (student_id, relative_id, kinship_type_id) VALUES (?, ?, ?)');
                $link->execute([$studentId, $relativeId, $kinshipTypeId]);
                $link->execute([$relativeId, $studentId, $kinshipTypeId]);
                $after = $this->pairRows('student_kinships', 'relative_id', $studentId, $relativeId, false);
                $this->auditNewRows($audit, 'student_kinship', 'student_kinships', $before, $after, 'ربط صلة قرابة', $batchId);
                $message = 'تم ربط صلة القرابة بنجاح.';
            }
            if ($ownsTransaction) $this->db->commit();
            return $message;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw new StudentKinshipLinkException($e->getMessage(), 0, $e);
        }
    }

    public function unlinkSibling(int $studentId, int $siblingId): void
    {
        $this->profiles->assertManageableStudent($studentId);
        $this->profiles->assertManageableStudent($siblingId);
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $before = $this->pairRows('student_siblings', 'sibling_id', $studentId, $siblingId, true);
            (new User($this->db))->unlinkSiblings($studentId, $siblingId);
            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            foreach ($before as $row) {
                $audit->recordDelete('student_sibling', 'student_siblings', (int) $row['id'], 'رابط شقيق', $row, 'إلغاء ربط شقيقين', $batchId);
            }
            if (!$before) $audit->recordEvent('unlink_sibling_noop', 'student', $studentId, $this->profiles->studentName($studentId), ['sibling_id' => $siblingId]);
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function linkKinshipByType(
        int $studentId,
        int $relativeId,
        int $kinshipTypeId,
        string $notes = ''
    ): void {
        $this->assertPair($studentId, $relativeId);
        if ($kinshipTypeId <= 0) {
            throw new InvalidArgumentException('صلة القرابة غير صالحة.');
        }
        $notes = mb_substr(trim($notes), 0, 1000);
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $typeStmt = $this->db->prepare("SELECT id, name FROM kinship_types WHERE id = ? AND status = 'active' FOR UPDATE");
            $typeStmt->execute([$kinshipTypeId]);
            $kinshipType = $typeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$kinshipType) {
                throw new InvalidArgumentException('صلة القرابة المحددة غير موجودة.');
            }

            $before = $this->pairRows('student_kinships', 'relative_id', $studentId, $relativeId, true);
            $link = $this->db->prepare(
                'INSERT IGNORE INTO student_kinships (student_id, relative_id, kinship_type_id, notes) VALUES (?, ?, ?, ?)'
            );
            $link->execute([$studentId, $relativeId, $kinshipTypeId, $notes !== '' ? $notes : null]);
            $link->execute([$relativeId, $studentId, $kinshipTypeId, $notes !== '' ? $notes : null]);
            $after = $this->pairRows('student_kinships', 'relative_id', $studentId, $relativeId, false);
            $this->auditNewRows(
                new AuditService($this->db),
                'student_kinship',
                'student_kinships',
                $before,
                $after,
                'ربط صلة قرابة: ' . (string) $kinshipType['name'],
                UndoManager::newBatchId()
            );
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function unlinkKinship(int $studentId, int $relativeId): void
    {
        $this->profiles->assertManageableStudent($studentId);
        $this->profiles->assertManageableStudent($relativeId);
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $before = $this->pairRows('student_kinships', 'relative_id', $studentId, $relativeId, true);
            $this->db->prepare('DELETE FROM student_kinships WHERE (student_id = ? AND relative_id = ?) OR (student_id = ? AND relative_id = ?)')
                ->execute([$studentId, $relativeId, $relativeId, $studentId]);
            $batchId = UndoManager::newBatchId();
            $audit = new AuditService($this->db);
            foreach ($before as $row) {
                $audit->recordDelete('student_kinship', 'student_kinships', (int) $row['id'], 'صلة قرابة', $row, 'إلغاء صلة قرابة', $batchId);
            }
            if (!$before) $audit->recordEvent('unlink_kinship_noop', 'student', $studentId, $this->profiles->studentName($studentId), ['relative_id' => $relativeId]);
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function assertPair(int $studentId, int $relativeId): void
    {
        try {
            $this->profiles->assertManageableStudent($studentId);
            $this->profiles->assertManageableStudent($relativeId);
            if ($studentId === $relativeId) {
                throw new InvalidArgumentException('لا يمكن ربط الطالب بنفسه.');
            }
        } catch (Throwable $e) {
            throw new StudentRelationshipGuardException($e->getMessage(), 0, $e);
        }
    }

    private function pairRows(string $table, string $relativeColumn, int $studentId, int $relativeId, bool $lock): array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE (student_id = ? AND `{$relativeColumn}` = ?) OR (student_id = ? AND `{$relativeColumn}` = ?) ORDER BY id{$suffix}");
        $stmt->execute([$studentId, $relativeId, $relativeId, $studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function auditNewRows(AuditService $audit, string $entityType, string $table, array $before, array $after, string $description, string $batchId): void
    {
        $beforeIds = array_flip(array_map(static fn(array $row): int => (int) $row['id'], $before));
        $created = 0;
        foreach ($after as $row) {
            $id = (int) $row['id'];
            if (isset($beforeIds[$id])) continue;
            $audit->recordInsert($entityType, $table, $id, $description, $row, $description, $batchId);
            $created++;
        }
        if ($created === 0) $audit->recordEvent('relationship_link_noop', 'student_relationship', null, $description, ['table' => $table]);
    }
}
