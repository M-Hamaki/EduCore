<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use ActivityLog;
use ClassRoom;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use StaffEmploymentLifecycleService;
use UndoManager;
use User;

final class StaffDeletionService
{
    private PDO $db;
    private User $users;
    private StaffProfileRepository $profiles;

    public function __construct(PDO $db, User $users, StaffProfileRepository $profiles)
    {
        $this->db = $db;
        $this->users = $users;
        $this->profiles = $profiles;
    }

    public function delete(int $userId): bool
    {
        $this->profiles->assertManageableStaff($userId, 'حذفه');
        $evaluationStatement = $this->db->prepare(
            'SELECT COUNT(*) FROM evaluations WHERE teacher_id = ?'
        );
        $evaluationStatement->execute([$userId]);
        if ((int) $evaluationStatement->fetchColumn() > 0) {
            return false;
        }

        $oldUser = UndoManager::fetchRecord('users', $userId);
        $this->db->beginTransaction();
        $retiredImage = null;
        try {
            $retiredImage = $this->users->deleteStaffProfileImage($userId);
            (new StaffAcademicScopeService($this->db))->removeAllAssignments(
                $userId,
                (int)($_SESSION['user_id'] ?? 0),
                'حذف جميع نطاقات أدوار العامل'
            );
            $this->users->removeAllClassAssignments($userId);
            $this->db->prepare('DELETE FROM teacher_subjects WHERE teacher_id = ?')
                ->execute([$userId]);
            $this->users->id = $userId;
            if (!$this->users->delete()) {
                throw new RuntimeException('حدث خطأ أثناء الحذف.');
            }
            ActivityLog::logDelete(
                'staff',
                $userId,
                $oldUser['name'] ?? ('موظف #' . $userId),
                ['summary' => 'تم حذف ملف الموظف']
            );
            if ($oldUser) {
                UndoManager::logDelete(
                    'users',
                    $userId,
                    $oldUser,
                    'حذف موظف: ' . ($oldUser['name'] ?? '')
                );
            }
            $this->db->commit();
            if (is_string($retiredImage) && $retiredImage !== '') {
                $path = dirname(__DIR__, 3) . '/uploads/staff/' . basename($retiredImage);
                if (is_file($path)) unlink($path);
            }
            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
