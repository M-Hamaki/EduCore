<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use ActivityLog;
use ClassRoom;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileAttachmentLabelPolicy;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use StaffEmploymentLifecycleService;
use UndoManager;
use User;

final class StaffAttachmentService
{
    private PDO $db;
    private StaffProfileRepository $profiles;
    private ProfileAttachmentStorage $storage;
    private string $profileImageDirectory;
    private $uploadMover;

    public function __construct(
        PDO $db,
        StaffProfileRepository $profiles,
        ProfileAttachmentStorage $storage,
        string $profileImageDirectory,
        ?callable $uploadMover = null
    ) {
        $this->db = $db;
        $this->profiles = $profiles;
        $this->storage = $storage;
        $this->profileImageDirectory = rtrim($profileImageDirectory, '/\\');
        $this->uploadMover = $uploadMover ?? 'move_uploaded_file';
    }

    public function uploadProfileImage(int $userId, array $file): void
    {
        $this->profiles->assertManageableStaff($userId);
        if (empty($file['name']) || ($file['error'] ?? null) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('يرجى اختيار ملف صورة صالح.');
        }
        $validatedFile = \FileUploadGuard::validate($file, [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ], 10 * 1024 * 1024);
        $extension = $validatedFile['extension'];
        if (!is_dir($this->profileImageDirectory)
            && !mkdir($this->profileImageDirectory, 0755, true)
            && !is_dir($this->profileImageDirectory)) {
            throw new RuntimeException('فشل في تجهيز مجلد الصورة الشخصية.');
        }

        $fileName = 'staff_' . $userId . '_' . time() . '.' . $extension;
        $destination = $this->profileImageDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (!(($this->uploadMover)($validatedFile['tmp_name'], $destination))) {
            throw new RuntimeException('فشل في رفع الملف الشخصي.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT profile_image FROM staff_profiles WHERE user_id = ? FOR UPDATE'
            );
            $stmt->execute([$userId]);
            $oldImage = (string) ($stmt->fetchColumn() ?: '');
            $this->db->prepare(
                'UPDATE staff_profiles SET profile_image = ? WHERE user_id = ?'
            )->execute([$fileName, $userId]);
            ActivityLog::log('update', 'staff', $userId, $this->profiles->displayName($userId), [
                'summary' => 'تم تحديث الصورة الشخصية للموظف',
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            @unlink($destination);
            throw $exception;
        }

        if ($oldImage !== '') {
            $oldPath = $this->profileImageDirectory . DIRECTORY_SEPARATOR . basename($oldImage);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
    }

    public function deleteAttachment(int $userId, int $attachmentId): bool
    {
        $this->profiles->assertManageableStaff($userId);
        $staffName = $this->profiles->displayName($userId);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT file_name, original_name, label
                 FROM staff_attachments
                 WHERE id = ? AND user_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$attachmentId, $userId]);
            $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$attachment) {
                $this->db->rollBack();
                return false;
            }
            $this->db->prepare('DELETE FROM staff_attachments WHERE id = ? AND user_id = ?')
                ->execute([$attachmentId, $userId]);
            ActivityLog::setDb($this->db);
            $audited = ActivityLog::log('update', 'staff', $userId, $staffName, [
                'summary' => 'تم حذف مرفق من ملف الموظف',
                'description' => $attachment['label'] ?: $attachment['original_name'],
            ]);
            if (!$audited) {
                throw new RuntimeException('تعذر تسجيل حذف المرفق في سجل النشاط.');
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        if (!$this->storage->delete('staff', (string) $attachment['file_name'])) {
            error_log('Staff attachment file cleanup failed for attachment ' . $attachmentId);
        }
        return true;
    }

    public function renameAttachment(int $userId, int $attachmentId, string $label): string
    {
        $this->profiles->assertManageableStaff($userId);
        $newLabel = ProfileAttachmentLabelPolicy::normalizeEditableLabel($label);
        $staffName = $this->profiles->displayName($userId);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT label FROM staff_attachments WHERE id = ? AND user_id = ? FOR UPDATE'
            );
            $stmt->execute([$attachmentId, $userId]);
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
                'UPDATE staff_attachments SET label = ? WHERE id = ? AND user_id = ?'
            )->execute([$newLabel, $attachmentId, $userId]);
            ActivityLog::setDb($this->db);
            $audited = ActivityLog::logChange(
                'update',
                'staff',
                $userId,
                $staffName,
                ['attachment_label' => $currentLabel],
                ['attachment_label' => $newLabel]
            );
            if (!$audited) {
                throw new RuntimeException('تعذر تسجيل تعديل اسم المرفق في سجل النشاط.');
            }
            $this->db->commit();
            return $newLabel;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
