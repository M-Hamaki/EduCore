<?php

declare(strict_types=1);

namespace EduCore\Modules\Accounts;

use ActivityLog;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use StudentAccountClassificationService;
use Throwable;
use UndoManager;
use EduCore\Modules\Operations\Audit\AuditService;

require_once dirname(__DIR__, 3) . '/config/encryption.php';
require_once dirname(__DIR__, 3) . '/classes/ActivityLog.php';
require_once dirname(__DIR__, 3) . '/classes/UndoManager.php';
require_once dirname(__DIR__, 3) . '/classes/StudentAccountClassificationService.php';
require_once dirname(__DIR__) . '/Operations/Audit/AuditService.php';
require_once __DIR__ . '/SecurePasswordGenerator.php';
require_once __DIR__ . '/StudentLoginAccessPolicy.php';

/**
 * Handles bulk action commands on student accounts safely within transactions and batch audits.
 */
final class StudentAccountBulkCommandService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Executes bulk command on student accounts.
     *
     * @param string $action 'activate'|'deactivate'|'set_test'|'set_official'|'generate_credentials'|'reset_passwords'|'export_credentials'
     * @param AccountBulkSelection $selection Target accounts selection
     * @param int $academicYearId Current academic year ID
     * @param int $actorId Admin user performing action
     * @param string $onError 'stop' or 'skip' (status changes are always atomic)
     * @param string|null $disableReason Optional exact message displayed to a disabled student
     * @return array{succeeded:int,skipped:int,failed:int,batch_id:string,credentials:array<int,array{student_code:string,name:string,username:string,password:string}>,message:string}
     */
    public function execute(
        string $action,
        AccountBulkSelection $selection,
        int $academicYearId,
        int $actorId,
        string $onError = 'stop',
        ?string $disableReason = null
    ): array {
        $validActions = ['activate', 'deactivate', 'set_test', 'set_official', 'generate_credentials', 'reset_passwords', 'export_credentials'];
        if (!in_array($action, $validActions, true)) {
            throw new InvalidArgumentException('الإجراء الجماعي المطلوب غير معروف.');
        }
        if (!in_array($onError, ['stop', 'skip'], true)) {
            throw new InvalidArgumentException('وضع التعامل مع الأخطاء غير صالح.');
        }
        $disableReason = trim((string) $disableReason);
        if (mb_strlen($disableReason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('سبب التعطيل يجب ألا يتجاوز 500 حرف.');
        }
        if (in_array($action, ['activate', 'deactivate'], true)) {
            // Account status batches are all-or-none; partial suspension is not allowed.
            $onError = 'stop';
        }

        $userIds = $selection->resolveStudentUserIds($this->db, $academicYearId);
        if ($userIds === []) {
            return [
                'succeeded' => 0,
                'skipped' => 0,
                'failed' => 0,
                'batch_id' => '',
                'credentials' => [],
                'message' => 'لا توجد حسابات طلاب مطابقة للتنفيذ عليها.'
            ];
        }

        if ($action === 'export_credentials') {
            $result = $this->exportCredentials($userIds);
            ActivityLog::setDb($this->db);
            $logged = ActivityLog::log('export', 'student_account', null, 'تصدير جماعي لبيانات دخول الطلاب', [
                'count' => $result['succeeded'],
                'passwords_included' => true,
                'sensitive_export' => true,
            ], ['actor_id' => $actorId]);
            if ($logged !== true) {
                throw new RuntimeException('تعذر تسجيل تصدير بيانات الدخول؛ لم يتم إنشاء الملف.');
            }
            return $result;
        }

        $batchId = UndoManager::newBatchId();
        $succeeded = 0;
        $skipped = 0;
        $failed = 0;
        $credentials = [];

        $this->db->beginTransaction();
        try {
            ActivityLog::setDb($this->db);
            $classificationService = new StudentAccountClassificationService($this->db);
            $auditService = new AuditService($this->db);
            $loginPolicy = new StudentLoginAccessPolicy($this->db);

            $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $lockClause = $isSqlite ? '' : ' FOR UPDATE';

            foreach ($userIds as $userId) {
                $this->db->exec('SAVEPOINT bulk_student_account_item');
                try {
                    $stmt = $this->db->prepare("SELECT u.id, u.name, u.username, u.status, u.password, u.password_hash, COALESCE(u.is_test_account, 0) AS is_test_account, sp.student_code FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE u.id = ? AND u.role = 'student' AND u.deleted_at IS NULL LIMIT 1" . $lockClause);
                    $stmt->execute([$userId]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$student) {
                        if ($onError === 'stop') {
                            throw new RuntimeException("حساب الطالب غير موجود: ID {$userId}");
                        }
                        $failed++;
                        continue;
                    }

                    if ($action === 'activate' || $action === 'deactivate') {
                        $targetStatus = ($action === 'activate') ? 'active' : 'inactive';
                        if ((string)$student['status'] === $targetStatus) {
                            $skipped++;
                            continue;
                        }
                        if ($loginPolicy->hasTerminalAcademicStatus($userId)) {
                            throw new RuntimeException('لا يمكن تغيير حالة حساب طالب ذي حالة أكاديمية نهائية من إدارة الحسابات.');
                        }

                        $beforeStmt = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1' . $lockClause);
                        $beforeStmt->execute([$userId]);
                        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                        if ($action === 'deactivate') {
                            $this->db->prepare('UPDATE users SET status = ?, login_disabled_reason = ?, login_disabled_at = CURRENT_TIMESTAMP, login_disabled_by = ? WHERE id = ?')
                                ->execute(['inactive', $disableReason !== '' ? $disableReason : null, $actorId, $userId]);
                            $description = 'تعطيل حساب طالب';
                        } else {
                            $this->db->prepare('UPDATE users SET status = ?, login_disabled_reason = NULL, login_disabled_at = NULL, login_disabled_by = NULL WHERE id = ?')
                                ->execute(['active', $userId]);
                            $description = 'تفعيل حساب طالب';
                        }

                        $afterStmt = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                        $afterStmt->execute([$userId]);
                        $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $auditService->recordUpdate(
                            'student_account',
                            'users',
                            $userId,
                            (string) $student['name'],
                            $before,
                            $after,
                            $description,
                            $batchId
                        );
                        $succeeded++;

                    } elseif ($action === 'set_test' || $action === 'set_official') {
                        $wantTest = ($action === 'set_test');
                        $isCurrentTest = ((int)$student['is_test_account'] === 1);
                        if ($wantTest === $isCurrentTest) {
                            $skipped++;
                            continue;
                        }
                        $res = $classificationService->setTestAccount($userId, $wantTest, $actorId, $batchId);
                        if ($res['changed']) {
                            $succeeded++;
                        } else {
                            $skipped++;
                        }

                    } elseif ($action === 'generate_credentials') {
                        $hasPassword = !empty($student['password']) || !empty($student['password_hash']);
                        $hasUsername = !empty($student['username']);
                        if ($hasUsername && $hasPassword) {
                            $skipped++;
                            continue;
                        }

                        $code = trim((string)($student['student_code'] ?? ''));
                        $generatedUsername = $this->generateUniqueUsername($userId, $code);
                        $generatedPassword = SecurePasswordGenerator::generate();

                        $encryptedPassword = encryptPasswordForUser($generatedPassword, $userId);
                        $passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);

                        $this->db->prepare("UPDATE users SET username = ?, password = ?, password_hash = ? WHERE id = ?")
                            ->execute([$generatedUsername, $encryptedPassword, $passwordHash, $userId]);

                        $details = [
                            'source' => 'bulk_generate_credentials',
                            'username_assigned' => true,
                            'password_configured' => true
                        ];
                        $logged = ActivityLog::log('update', 'student_account', $userId, (string)$student['name'], $details, ['batch_id' => $batchId]);
                        if ($logged !== true) {
                            throw new RuntimeException('تعذر تسجيل إنشاء بيانات الدخول في التدقيق.');
                        }

                        $credentials[] = [
                            'student_code' => $code,
                            'name' => (string)$student['name'],
                            'username' => $generatedUsername,
                            'password' => $generatedPassword,
                        ];
                        $succeeded++;

                    } elseif ($action === 'reset_passwords') {
                        $generatedPassword = SecurePasswordGenerator::generate();
                        $encryptedPassword = encryptPasswordForUser($generatedPassword, $userId);
                        $passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);

                        $username = trim((string)($student['username'] ?? ''));
                        if ($username === '') {
                            $code = trim((string)($student['student_code'] ?? ''));
                            $username = $this->generateUniqueUsername($userId, $code);
                            $this->db->prepare("UPDATE users SET username = ?, password = ?, password_hash = ? WHERE id = ?")
                                ->execute([$username, $encryptedPassword, $passwordHash, $userId]);
                        } else {
                            $this->db->prepare("UPDATE users SET password = ?, password_hash = ? WHERE id = ?")
                                ->execute([$encryptedPassword, $passwordHash, $userId]);
                        }

                        $details = [
                            'source' => 'bulk_reset_passwords',
                            'password_reset' => true
                        ];
                        $logged = ActivityLog::log('update', 'student_account', $userId, (string)$student['name'], $details, ['batch_id' => $batchId]);
                        if ($logged !== true) {
                            throw new RuntimeException('تعذر تسجيل إعادة تعيين كلمة المرور في التدقيق.');
                        }

                        $credentials[] = [
                            'student_code' => trim((string)($student['student_code'] ?? '')),
                            'name' => (string)$student['name'],
                            'username' => $username,
                            'password' => $generatedPassword,
                        ];
                        $succeeded++;
                    }
                } catch (Throwable $e) {
                    if ($onError === 'stop') {
                        throw $e;
                    }
                    $this->db->exec('ROLLBACK TO SAVEPOINT bulk_student_account_item');
                    $failed++;
                } finally {
                    $this->db->exec('RELEASE SAVEPOINT bulk_student_account_item');
                }
            }

            if ($succeeded === 0 && $failed > 0 && $onError === 'stop') {
                throw new RuntimeException('فشلت الإجراءات الجماعية على الحسابات المحددة.');
            }

            $this->db->commit();

            $msgParts = [];
            if ($succeeded > 0) $msgParts[] = "تمت العملية بنجاح على {$succeeded} حساب.";
            if ($skipped > 0) $msgParts[] = "تم تجاوز {$skipped} حساب لتطابق الحالة دون تغيير.";
            if ($failed > 0) $msgParts[] = "فشل التنفيذ على {$failed} حساب.";

            return [
                'succeeded' => $succeeded,
                'skipped' => $skipped,
                'failed' => $failed,
                'batch_id' => $batchId,
                'credentials' => $credentials,
                'message' => implode(' ', $msgParts) ?: 'تمت معالجة الطلب.'
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Exports credentials for given student IDs into a array format.
     */
    private function exportCredentials(array $userIds): array
    {
        if ($userIds === []) {
            return ['succeeded' => 0, 'skipped' => 0, 'failed' => 0, 'batch_id' => '', 'credentials' => [], 'message' => 'لا توجد حسابات للتصدير.'];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "SELECT u.id, u.name, u.username, u.password, sp.student_code
                FROM users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                WHERE u.role = 'student' AND u.deleted_at IS NULL AND u.id IN ({$placeholders})
                ORDER BY u.name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($userIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $credentials = [];
        foreach ($rows as $row) {
            $rawPass = (string)($row['password'] ?? '');
            $decrypted = '';
            if ($rawPass !== '' && !str_starts_with($rawPass, '$2y$') && !str_starts_with($rawPass, '$argon')) {
                try {
                    $decrypted = decryptPasswordForUser($rawPass, (int)$row['id']) ?: 'غير قابل للاسترجاع';
                } catch (Throwable $e) {
                    $decrypted = 'غير قابل للاسترجاع';
                }
            } else {
                $decrypted = $rawPass !== '' ? 'محمي بواسطة Hash' : 'غير مهيأ';
            }

            $credentials[] = [
                'student_code' => (string)($row['student_code'] ?? ''),
                'name' => (string)$row['name'],
                'username' => (string)($row['username'] ?? ''),
                'password' => $decrypted,
            ];
        }

        return [
            'succeeded' => count($credentials),
            'skipped' => 0,
            'failed' => 0,
            'batch_id' => '',
            'credentials' => $credentials,
            'message' => 'تم استخراج بيانات الدخول لتصديرها.'
        ];
    }

    private function generateUniqueUsername(int $userId, string $code): string
    {
        $base = $code !== '' ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $code)) : 'student' . $userId;
        if ($base === '' || strlen($base) < 3) {
            $base = 'std' . $userId;
        }

        $candidate = $base;
        $counter = 1;
        while (true) {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
            $stmt->execute([$candidate, $userId]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
            $candidate = $base . $counter;
            $counter++;
        }
    }

}
