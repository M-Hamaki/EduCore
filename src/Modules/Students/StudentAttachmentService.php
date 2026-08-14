<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use AcademicYear;
use ActivityLog;
use DateTime;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileAttachmentLabelPolicy;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use UndoManager;
use User;

final class StudentAttachmentService
{
    private PDO $db;
    private StudentProfileRepository $profiles;
    private ProfileAttachmentStorage $storage;

    public function __construct(PDO $db, StudentProfileRepository $profiles, ProfileAttachmentStorage $storage)
    {
        $this->db = $db;
        $this->profiles = $profiles;
        $this->storage = $storage;
    }

    public function assertManageableStudent(int $studentId): void
    {
        $this->profiles->assertManageableStudent($studentId);
    }

    public function upload(int $studentId, string $label, array $file): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new InvalidArgumentException('يرجى إدخال اسم المرفق.');
        }
        if (empty($file['name']) || ($file['error'] ?? null) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('يرجى اختيار ملف للرفع.');
        }

        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'];
        $submittedExtension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($submittedExtension, $allowedExtensions, true)) {
            throw new InvalidArgumentException('نوع الملف غير مسموح. الأنواع المسموحة: ' . implode(', ', $allowedExtensions));
        }
        if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('حجم الملف يتجاوز الحد الأقصى (10MB).');
        }

        $validatedFile = \FileUploadGuard::validate($file, [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ], 10 * 1024 * 1024);
        $extension = $validatedFile['extension'];

        $fileName = 'att_' . $studentId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $storedName = null;
        try {
            $storedName = $this->storage->storeUploadedFile($validatedFile['tmp_name'], 'student', $fileName);
            $stmt = $this->db->prepare('INSERT INTO student_attachments (user_id, file_name, original_name, label, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $studentId,
                $storedName,
                $validatedFile['original_name'],
                $label,
                $validatedFile['size'],
                $validatedFile['mime'],
            ]);
            ActivityLog::log('update', 'student', $studentId, $this->profiles->studentName($studentId), [
                'summary' => 'تم رفع مرفق جديد إلى ملف الطالب',
                'description' => $label,
                'type' => strtoupper($extension),
            ]);
        } catch (Throwable $e) {
            if ($storedName !== null) {
                $this->storage->delete('student', $storedName);
            }
            error_log('Student attachment upload failed: ' . $e->getMessage());
            throw new RuntimeException('فشل في حفظ المرفق.', 0, $e);
        }
    }

    public function delete(int $studentId, int $attachmentId): bool
    {
        $this->assertManageableStudent($studentId);
        $studentName = $this->profiles->studentName($studentId);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT file_name, original_name, label
                 FROM student_attachments
                 WHERE id = ? AND user_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$attachmentId, $studentId]);
            $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$attachment) {
                $this->db->rollBack();
                return false;
            }

            $this->db->prepare('DELETE FROM student_attachments WHERE id = ? AND user_id = ?')
                ->execute([$attachmentId, $studentId]);
            ActivityLog::setDb($this->db);
            $audited = ActivityLog::log('update', 'student', $studentId, $studentName, [
                'summary' => 'تم حذف مرفق من ملف الطالب',
                'description' => $attachment['label'] ?: $attachment['original_name'],
            ]);
            if (!$audited) {
                throw new RuntimeException('تعذر تسجيل حذف المرفق في سجل النشاط.');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if (!$this->storage->delete('student', (string) $attachment['file_name'])) {
            error_log('Student attachment file cleanup failed for attachment ' . $attachmentId);
        }
        return true;
    }

    public function renameAttachment(int $studentId, int $attachmentId, string $label): string
    {
        $this->assertManageableStudent($studentId);
        $newLabel = ProfileAttachmentLabelPolicy::normalizeEditableLabel($label);
        $studentName = $this->profiles->studentName($studentId);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT label FROM student_attachments WHERE id = ? AND user_id = ? FOR UPDATE'
            );
            $stmt->execute([$attachmentId, $studentId]);
            $currentLabel = $stmt->fetchColumn();
            if ($currentLabel === false) {
                throw new InvalidArgumentException('المرفق غير موجود.');
            }

            $currentLabel = (string) $currentLabel;
            ProfileAttachmentLabelPolicy::assertCurrentLabelIsEditable($currentLabel);
            if ($currentLabel === $newLabel) {
                $this->db->commit();
                return $newLabel;
            }

            $this->db->prepare(
                'UPDATE student_attachments SET label = ? WHERE id = ? AND user_id = ?'
            )->execute([$newLabel, $attachmentId, $studentId]);
            ActivityLog::setDb($this->db);
            $audited = ActivityLog::logChange(
                'update',
                'student',
                $studentId,
                $studentName,
                ['attachment_label' => $currentLabel],
                ['attachment_label' => $newLabel]
            );
            if (!$audited) {
                throw new RuntimeException('تعذر تسجيل تعديل اسم المرفق في سجل النشاط.');
            }
            $this->db->commit();
            return $newLabel;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
