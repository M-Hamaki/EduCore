<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

require_once dirname(__DIR__) . '/Operations/Audit/AuditService.php';

use EduCore\Modules\Operations\Audit\AuditService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Owns the official/test classification of student login accounts. */
final class StudentAccountClassificationService
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{changed:bool,is_test_account:bool,name:string} */
    public function setTestAccount(int $studentId, bool $isTestAccount, int $actorId): array
    {
        if ($studentId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('تعذر تحديد الطالب أو منفذ العملية.');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT id, name, role, status, COALESCE(is_test_account, 0) AS is_test_account
                 FROM users
                 WHERE id = ? AND role = 'student' AND deleted_at IS NULL
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$studentId]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('الطالب غير موجود أو الحساب غير صالح.');
            }

            $wanted = $isTestAccount ? 1 : 0;
            if ((int) $before['is_test_account'] === $wanted) {
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return ['changed' => false, 'is_test_account' => $isTestAccount, 'name' => (string) $before['name']];
            }

            $update = $this->db->prepare(
                "UPDATE users SET is_test_account = ?
                 WHERE id = ? AND role = 'student' AND deleted_at IS NULL"
            );
            $update->execute([$wanted, $studentId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('تعذر تحديث نوع حساب الطالب.');
            }

            $after = $before;
            $after['is_test_account'] = $wanted;
            (new AuditService($this->db))->recordUpdate(
                'student_account',
                'users',
                $studentId,
                (string) $before['name'],
                $before,
                $after,
                $isTestAccount ? 'تصنيف حساب الطالب كحساب تجريبي' : 'إعادة تصنيف حساب الطالب كحساب رسمي'
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return ['changed' => true, 'is_test_account' => $isTestAccount, 'name' => (string) $before['name']];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
