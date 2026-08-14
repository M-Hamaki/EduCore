<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use ActivityLog;
use EduCore\Modules\Accounts\AccountBulkSelection;
use EduCore\Modules\Accounts\SecurePasswordGenerator;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use StaffAcademicScopeService;
use StaffRoleAssignmentService;
use StaffRoleCapabilityResolver;
use SystemAdministratorRoleService;
use Throwable;
use UndoManager;

require_once dirname(__DIR__, 3) . '/config/encryption.php';
require_once dirname(__DIR__, 3) . '/classes/ActivityLog.php';
require_once dirname(__DIR__, 3) . '/classes/UndoManager.php';
require_once dirname(__DIR__, 3) . '/classes/StaffRoleAssignmentService.php';
require_once dirname(__DIR__, 3) . '/classes/StaffRoleCapabilityResolver.php';
require_once dirname(__DIR__, 3) . '/classes/SystemAdministratorRoleService.php';
require_once dirname(__DIR__, 3) . '/classes/StaffAcademicScopeService.php';
require_once __DIR__ . '/../Accounts/SecurePasswordGenerator.php';

/**
 * Handles bulk commands on staff accounts, enforce role exclusivity, system administrator protections,
 * academic scopes, and audit tracking.
 */
final class StaffAccountBulkCommandService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Executes bulk action command on staff accounts.
     *
     * @param string $action 'assign_roles'|'set_supervisor'|'activate'|'deactivate'|'generate_credentials'|'reset_passwords'|'export_credentials'
     * @param AccountBulkSelection $selection Target selection
     * @param array<string,mixed> $validRoles Available portal roles map/list
     * @param int $academicYearId Current academic year ID
     * @param int $actorId Current admin user ID
     * @param string $activeRole Current admin session active role
     * @param array<string,mixed> $params Specific action options (role_mode, role_keys, primary_role_key, is_supervisor, scope_mode, stage_ids, grade_ids, class_ids, etc.)
     * @param string $onError 'stop' or 'skip'
     * @return array{succeeded:int,skipped:int,failed:int,batch_id:string,credentials:array<int,array{employee_code:string,name:string,username:string,password:string}>,message:string}
     */
    public function execute(
        string $action,
        AccountBulkSelection $selection,
        array $validRoles,
        int $academicYearId,
        int $actorId,
        string $activeRole,
        array $params = [],
        string $onError = 'stop'
    ): array {
        $validActions = ['assign_roles', 'set_supervisor', 'activate', 'deactivate', 'generate_credentials', 'reset_passwords', 'export_credentials'];
        if (!in_array($action, $validActions, true)) {
            throw new InvalidArgumentException('الإجراء الجماعي المطلوب غير معروف.');
        }
        if (!in_array($onError, ['stop', 'skip'], true)) {
            throw new InvalidArgumentException('وضع التعامل مع الأخطاء غير صالح.');
        }

        $userIds = $selection->resolveStaffUserIds($this->db, $validRoles, 0);
        if ($userIds === []) {
            return [
                'succeeded' => 0,
                'skipped' => 0,
                'failed' => 0,
                'batch_id' => '',
                'credentials' => [],
                'message' => 'لا توجد حسابات عاملين مطابقة للتنفيذ عليها.'
            ];
        }

        $assignmentService = new StaffRoleAssignmentService($this->db);
        $capabilityResolver = new StaffRoleCapabilityResolver($this->db);
        $sysAdminService = new SystemAdministratorRoleService($this->db);
        $scopeService = new StaffAcademicScopeService($this->db);
        if ($action === 'export_credentials') {
            $this->assertCredentialTargetsAllowed($userIds, $actorId, $activeRole, $sysAdminService);
            $result = $this->exportCredentials($userIds);
            ActivityLog::setDb($this->db);
            $logged = ActivityLog::log('export', 'staff_account', null, 'تصدير جماعي لبيانات دخول العاملين', [
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

            $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $lockClause = $isSqlite ? '' : ' FOR UPDATE';

            foreach ($userIds as $userId) {
                $this->db->exec('SAVEPOINT bulk_staff_account_item');
                try {
                    $stmt = $this->db->prepare("SELECT u.id, u.name, u.username, u.password, u.password_hash, u.role, u.status, u.is_supervisor, sp.employee_code FROM users u INNER JOIN staff_profiles sp ON sp.user_id = u.id WHERE u.id = ? AND u.deleted_at IS NULL LIMIT 1" . $lockClause);
                    $stmt->execute([$userId]);
                    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$staff) {
                        if ($onError === 'stop') {
                            throw new RuntimeException("حساب العامل غير موجود: ID {$userId}");
                        }
                        $failed++;
                        continue;
                    }

                    // Safeguard 1: Cannot deactivate current user or change current user's login credentials/active role in bulk
                    if ($userId === $actorId && ($action === 'deactivate' || $action === 'generate_credentials' || $action === 'reset_passwords')) {
                        if ($onError === 'stop') {
                            throw new InvalidArgumentException('لا يمكن تعطيل حساب الجلسة الحالي أو تعديل بيانات دخوله ضمن عملية جماعية.');
                        }
                        $skipped++;
                        continue;
                    }

                    // Safeguard 2: Admin / Super Admin roles cannot be assigned or removed in bulk
                    $currentRoles = $assignmentService->roleKeysForUser($userId, true);
                    if (($currentRoles === []
                            || (count($currentRoles) === 1
                                && $currentRoles[0] === StaffRoleAssignmentService::EMPLOYEE_ROLE))
                        && in_array($action, ['generate_credentials', 'reset_passwords'], true)) {
                        if ($onError === 'stop') {
                            throw new InvalidArgumentException(
                                "الحساب «{$staff['name']}» موظف بدون بوابة؛ عيّن له دور بوابة قبل إنشاء بيانات الدخول."
                            );
                        }
                        $skipped++;
                        continue;
                    }
                    if (array_intersect(['admin', 'super_admin'], $currentRoles) !== []
                        && in_array($action, ['generate_credentials', 'reset_passwords'], true)) {
                        $this->assertCredentialTargetsAllowed([$userId], $actorId, $activeRole, $sysAdminService);
                    }
                    if (in_array('admin', $currentRoles, true) || in_array('super_admin', $currentRoles, true)) {
                        if ($action === 'assign_roles' || $action === 'deactivate') {
                            if ($onError === 'stop') {
                                throw new InvalidArgumentException("الحساب «{$staff['name']}» يحمل دور مدير نظام ولا يمكن تعديله أو تعطيله جماعياً.");
                            }
                            $skipped++;
                            continue;
                        }
                    }

                    if ($action === 'assign_roles') {
                        $roleMode = (string)($params['role_mode'] ?? '');
                        if (!in_array($roleMode, ['add', 'remove', 'replace'], true)) {
                            throw new InvalidArgumentException('يجب اختيار وضع تطبيق الأدوار: إضافة، إزالة، أو استبدال.');
                        }

                        $targetRoleKeys = is_array($params['role_keys'] ?? null) ? array_values(array_map('strval', $params['role_keys'])) : [];
                        // Enforce protection against admin / super_admin in bulk target roles
                        if (in_array('admin', $targetRoleKeys, true) || in_array('super_admin', $targetRoleKeys, true)) {
                            throw new InvalidArgumentException('تعيين دور مدير النظام أو مدير النظام الأعلى يظل إجراءً فردياً حصرياً.');
                        }

                        $newRoleKeys = [];
                        if ($roleMode === 'add') {
                            $newRoleKeys = array_values(array_unique(array_merge($currentRoles, $targetRoleKeys)));
                        } elseif ($roleMode === 'remove') {
                            $newRoleKeys = array_values(array_diff($currentRoles, $targetRoleKeys));
                            if ($newRoleKeys === []) {
                                $newRoleKeys = [StaffRoleAssignmentService::EMPLOYEE_ROLE];
                            }
                        } else { // replace
                            $newRoleKeys = $targetRoleKeys !== [] ? $targetRoleKeys : [StaffRoleAssignmentService::EMPLOYEE_ROLE];
                        }

                        // Protect current user's active session role from removal
                        if ($userId === $actorId && !in_array($activeRole, $newRoleKeys, true)) {
                            if ($onError === 'stop') {
                                throw new InvalidArgumentException('لا يمكن إزالة دور الجلسة النشط للمستخدم الحالي ضمن عملية جماعية.');
                            }
                            $skipped++;
                            continue;
                        }

                        // Employee role exclusivity rule
                        if (in_array(StaffRoleAssignmentService::EMPLOYEE_ROLE, $newRoleKeys, true) && count($newRoleKeys) > 1) {
                            // If employee is combined, filter out employee if portal roles exist, or default to employee
                            $portalKeys = array_values(array_diff($newRoleKeys, [StaffRoleAssignmentService::EMPLOYEE_ROLE]));
                            $newRoleKeys = $portalKeys !== [] ? $portalKeys : [StaffRoleAssignmentService::EMPLOYEE_ROLE];
                        }

                        $primaryRoleKey = (string)($params['primary_role_key'] ?? '');
                        if ($primaryRoleKey === '' || !in_array($primaryRoleKey, $newRoleKeys, true)) {
                            $primaryRoleKey = in_array((string)$staff['role'], $newRoleKeys, true) ? (string)$staff['role'] : $newRoleKeys[0];
                        }

                        $removedRoleKeys = array_values(array_diff($currentRoles, $newRoleKeys));
                        $assignmentService->replaceRoles($userId, $newRoleKeys, $primaryRoleKey, $actorId, $batchId);
                        foreach ($removedRoleKeys as $removedRoleKey) {
                            if ($capabilityResolver->requiresAcademicScope($removedRoleKey)) {
                                $scopeService->removeRoleAssignments(
                                    $userId,
                                    $removedRoleKey,
                                    $actorId,
                                    'إزالة نطاق دور أُلغي ضمن عملية جماعية',
                                    $batchId
                                );
                            }
                        }

                        // Handle Academic Scope Application if provided
                        $scopeMode = (string)($params['scope_mode'] ?? '');
                        $scopeRoleKeys = is_array($params['scope_role_keys'] ?? null)
                            ? array_values(array_unique(array_map('strval', $params['scope_role_keys'])))
                            : [];
                        $legacyScopeRoleKey = trim((string)($params['scope_role_key'] ?? ''));
                        if ($scopeRoleKeys === [] && $legacyScopeRoleKey !== '') {
                            $scopeRoleKeys = [$legacyScopeRoleKey];
                        }
                        if ($scopeMode !== '' && $scopeRoleKeys !== []) {
                            $gradeIds = is_array($params['grade_ids'] ?? null) ? array_map('intval', $params['grade_ids']) : [];
                            $classIds = is_array($params['class_ids'] ?? null) ? array_map('intval', $params['class_ids']) : [];
                            if (!in_array($scopeMode, ['replace', 'merge', 'remove'], true)) {
                                throw new InvalidArgumentException('طريقة تطبيق النطاق الأكاديمي غير صالحة.');
                            }
                            foreach ($scopeRoleKeys as $scopeRoleKey) {
                                if (!in_array($scopeRoleKey, $newRoleKeys, true)
                                    || !$capabilityResolver->requiresAcademicScope($scopeRoleKey)) {
                                    continue;
                                }
                                $currentScope = $scopeService->scope($userId, $academicYearId, $scopeRoleKey);
                                $nextGradeIds = $gradeIds;
                                $nextClassIds = $classIds;
                                if ($scopeMode === 'merge') {
                                    $nextGradeIds = array_values(array_unique(array_merge($currentScope['grade_ids'], $gradeIds)));
                                    $nextClassIds = array_values(array_unique(array_merge($currentScope['explicit_class_ids'], $classIds)));
                                } elseif ($scopeMode === 'remove') {
                                    $nextGradeIds = array_values(array_diff($currentScope['grade_ids'], $gradeIds));
                                    $nextClassIds = array_values(array_diff($currentScope['explicit_class_ids'], $classIds));
                                }
                                if ($nextGradeIds === [] && $nextClassIds === []) {
                                    throw new InvalidArgumentException(
                                        "يجب أن يبقى للدور «{$scopeRoleKey}» صف أو فصل واحد على الأقل في العام الحالي."
                                    );
                                }
                                $scopeService->replaceAssignments(
                                    $userId,
                                    $academicYearId,
                                    $nextGradeIds,
                                    $nextClassIds,
                                    $actorId,
                                    $scopeRoleKey,
                                    $batchId
                                );
                            }
                        }

                        $succeeded++;

                    } elseif ($action === 'set_supervisor') {
                        $wantSupervisor = !empty($params['is_supervisor']) ? 1 : 0;
                        if (!in_array('teacher', $currentRoles, true)) {
                            // is_supervisor ONLY works when teacher role is present
                            $skipped++;
                            continue;
                        }
                        if ((int)$staff['is_supervisor'] === $wantSupervisor) {
                            $skipped++;
                            continue;
                        }
                        $this->db->prepare("UPDATE users SET is_supervisor = ? WHERE id = ?")->execute([$wantSupervisor, $userId]);
                        $logged = ActivityLog::log('update', 'staff_account', $userId, (string)$staff['name'], [
                            'source' => 'bulk_set_supervisor',
                            'is_supervisor' => ['old' => (int)$staff['is_supervisor'], 'new' => $wantSupervisor]
                        ], ['batch_id' => $batchId]);
                        if ($logged !== true) {
                            throw new RuntimeException('تعذر تسجيل تغيير صفة المشرف في سجل التدقيق.');
                        }
                        $succeeded++;

                    } elseif ($action === 'activate' || $action === 'deactivate') {
                        $targetStatus = ($action === 'activate') ? 'active' : 'inactive';
                        if ((string)$staff['status'] === $targetStatus) {
                            $skipped++;
                            continue;
                        }

                        if ($targetStatus === 'active' && $currentRoles === []) {
                            if ($onError === 'stop') {
                                throw new InvalidArgumentException("الحساب «{$staff['name']}» لا يملك دورًا نشطًا ولا يمكن تفعيله قبل تعيين دور.");
                            }
                            $skipped++;
                            continue;
                        }
                        if (array_intersect(['admin', 'super_admin'], $currentRoles) !== []) {
                            $sysAdminService->assertStatusChangeAllowed(
                                $actorId,
                                $userId,
                                (string)$staff['role'],
                                (string)$staff['status'],
                                $targetStatus,
                                $activeRole
                            );
                        }

                        $this->db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$targetStatus, $userId]);
                        $logged = ActivityLog::log('update', 'staff_account', $userId, (string)$staff['name'], [
                            'source' => 'bulk_status_change',
                            'status' => ['old' => (string)$staff['status'], 'new' => $targetStatus],
                        ], ['batch_id' => $batchId]);
                        if ($logged !== true) {
                            throw new RuntimeException('تعذر تسجيل تغيير حالة الحساب في سجل التدقيق.');
                        }
                        $succeeded++;

                    } elseif ($action === 'generate_credentials') {
                        $hasPassword = !empty($staff['username'])
                            && (!empty($staff['password']) || !empty($staff['password_hash']));
                        if ($hasPassword) {
                            $skipped++;
                            continue;
                        }

                        $code = trim((string)($staff['employee_code'] ?? ''));
                        $generatedUsername = $this->generateUniqueUsername($userId, $code);
                        $generatedPassword = SecurePasswordGenerator::generate();

                        $encryptedPassword = encryptPasswordForUser($generatedPassword, $userId);
                        $passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);

                        $this->db->prepare("UPDATE users SET username = ?, password = ?, password_hash = ? WHERE id = ?")
                            ->execute([$generatedUsername, $encryptedPassword, $passwordHash, $userId]);

                        $logged = ActivityLog::log('update', 'staff_account', $userId, (string)$staff['name'], [
                            'source' => 'bulk_generate_credentials',
                            'username_assigned' => true,
                            'password_configured' => true
                        ], ['batch_id' => $batchId]);
                        if ($logged !== true) {
                            throw new RuntimeException('تعذر تسجيل إنشاء بيانات الدخول في سجل التدقيق.');
                        }

                        $credentials[] = [
                            'employee_code' => $code,
                            'name' => (string)$staff['name'],
                            'username' => $generatedUsername,
                            'password' => $generatedPassword,
                        ];
                        $succeeded++;

                    } elseif ($action === 'reset_passwords') {
                        $generatedPassword = SecurePasswordGenerator::generate();
                        $encryptedPassword = encryptPasswordForUser($generatedPassword, $userId);
                        $passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);

                        $username = trim((string)($staff['username'] ?? ''));
                        if ($username === '') {
                            $code = trim((string)($staff['employee_code'] ?? ''));
                            $username = $this->generateUniqueUsername($userId, $code);
                            $this->db->prepare("UPDATE users SET username = ?, password = ?, password_hash = ? WHERE id = ?")
                                ->execute([$username, $encryptedPassword, $passwordHash, $userId]);
                        } else {
                            $this->db->prepare("UPDATE users SET password = ?, password_hash = ? WHERE id = ?")
                                ->execute([$encryptedPassword, $passwordHash, $userId]);
                        }

                        $logged = ActivityLog::log('update', 'staff_account', $userId, (string)$staff['name'], [
                            'source' => 'bulk_reset_passwords',
                            'password_reset' => true
                        ], ['batch_id' => $batchId]);
                        if ($logged !== true) {
                            throw new RuntimeException('تعذر تسجيل إعادة تعيين كلمة المرور في سجل التدقيق.');
                        }

                        $credentials[] = [
                            'employee_code' => trim((string)($staff['employee_code'] ?? '')),
                            'name' => (string)$staff['name'],
                            'username' => $username,
                            'password' => $generatedPassword,
                        ];
                        $succeeded++;
                    }

                } catch (Throwable $e) {
                    if ($onError === 'stop') {
                        throw $e;
                    }
                    $this->db->exec('ROLLBACK TO SAVEPOINT bulk_staff_account_item');
                    $failed++;
                } finally {
                    $this->db->exec('RELEASE SAVEPOINT bulk_staff_account_item');
                }
            }

            if ($succeeded === 0 && $failed > 0 && $onError === 'stop') {
                throw new RuntimeException('فشلت الإجراءات الجماعية على حسابات العاملين المحددة.');
            }

            $this->db->commit();

            $msgParts = [];
            if ($succeeded > 0) $msgParts[] = "تمت العملية بنجاح على {$succeeded} حساب.";
            if ($skipped > 0) $msgParts[] = "تم تجاوز {$skipped} حساب لتطابق الحالة أو عدم استيفاء الشروط.";
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

    /** @param array<int,int> $userIds */
    private function assertCredentialTargetsAllowed(
        array $userIds,
        int $actorId,
        string $activeRole,
        SystemAdministratorRoleService $sysAdminService
    ): void {
        if ($userIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT user_id
             FROM user_role_assignments
             WHERE status = 'active'
               AND role_key IN ('admin', 'super_admin')
               AND user_id IN ({$placeholders})"
        );
        $stmt->execute($userIds);
        $systemAccountIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($systemAccountIds === []) {
            return;
        }
        $sysAdminService->assertActorCanManage($actorId, $activeRole);
        if (in_array($actorId, $systemAccountIds, true)) {
            throw new InvalidArgumentException(
                'لا يمكن تصدير أو إعادة تعيين بيانات دخول حساب الجلسة الإداري الحالي ضمن عملية جماعية.'
            );
        }
    }

    private function exportCredentials(array $userIds): array
    {
        if ($userIds === []) {
            return ['succeeded' => 0, 'skipped' => 0, 'failed' => 0, 'batch_id' => '', 'credentials' => [], 'message' => 'لا توجد حسابات للتصدير.'];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "SELECT u.id, u.name, u.username, u.password, sp.employee_code
                FROM users u
                INNER JOIN staff_profiles sp ON sp.user_id = u.id
                WHERE u.deleted_at IS NULL AND u.id IN ({$placeholders})
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
                'employee_code' => (string)($row['employee_code'] ?? ''),
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
        $base = $code !== '' ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $code)) : 'staff' . $userId;
        if ($base === '' || strlen($base) < 3) {
            $base = 'stf' . $userId;
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
