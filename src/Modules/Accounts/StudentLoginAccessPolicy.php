<?php

declare(strict_types=1);

namespace EduCore\Modules\Accounts;

use PDO;

/** Central policy for deciding whether a student account may enter the portal. */
final class StudentLoginAccessPolicy
{
    public const DEFAULT_DISABLED_MESSAGE = 'حسابك معطل حالياً. يرجى التواصل مع إدارة المدرسة.';

    public function __construct(private PDO $db)
    {
    }

    /** @return array{allowed:bool,code:?string,message:?string} */
    public function decisionForUserId(int $userId): array
    {
        $reasonSelect = $this->columnExists('users', 'login_disabled_reason')
            ? 'login_disabled_reason'
            : 'NULL AS login_disabled_reason';
        $stmt = $this->db->prepare("SELECT id, role, status, {$reasonSelect} FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (string) ($user['role'] ?? '') !== 'student') {
            return ['allowed' => false, 'code' => 'not_student', 'message' => self::DEFAULT_DISABLED_MESSAGE];
        }

        $terminalStatus = $this->terminalStatus($userId, (string) ($user['status'] ?? ''));
        if ($terminalStatus !== null) {
            $messages = [
                'graduated' => 'تم تخرجك من المدرسة. لا يمكنك تسجيل الدخول.',
                'transferred' => 'تم نقلك من المدرسة. لا يمكنك تسجيل الدخول.',
                'discontinued' => 'تم إنهاء قيدك بالمدرسة. لا يمكنك تسجيل الدخول.',
            ];
            return ['allowed' => false, 'code' => $terminalStatus, 'message' => $messages[$terminalStatus]];
        }

        if ((string) ($user['status'] ?? '') !== 'active') {
            $reason = trim((string) ($user['login_disabled_reason'] ?? ''));
            return [
                'allowed' => false,
                'code' => 'inactive',
                'message' => $reason !== '' ? $reason : self::DEFAULT_DISABLED_MESSAGE,
            ];
        }

        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    public function hasTerminalAcademicStatus(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT status FROM users WHERE id = ? AND role = 'student' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$userId]);
        $status = $stmt->fetchColumn();
        return $status !== false && $this->terminalStatus($userId, (string) $status) !== null;
    }

    private function terminalStatus(int $userId, string $accountStatus): ?string
    {
        if ($accountStatus === 'graduated') {
            return 'graduated';
        }

        if ($this->tableExists('student_profiles')) {
            $stmt = $this->db->prepare("SELECT enrollment_status FROM student_profiles WHERE user_id = ? AND enrollment_status IN ('graduated', 'transferred', 'discontinued', 'withdrawn') LIMIT 1");
            $stmt->execute([$userId]);
            $status = (string) ($stmt->fetchColumn() ?: '');
            if ($status !== '') {
                return $status === 'withdrawn' ? 'discontinued' : $status;
            }
        }

        if ($this->tableExists('student_enrollments') && $this->tableExists('academic_years')) {
            $stmt = $this->db->prepare("SELECT CASE
                    WHEN se.academic_status = 'graduated' OR se.enrollment_status = 'graduated' THEN 'graduated'
                    WHEN se.enrollment_status = 'withdrawn' THEN 'discontinued'
                    ELSE se.enrollment_status
                END
                FROM student_enrollments se
                INNER JOIN academic_years ay ON ay.id = se.academic_year_id
                WHERE se.student_id = ? AND ay.is_active = 1
                  AND (se.academic_status = 'graduated' OR se.enrollment_status IN ('graduated', 'transferred', 'discontinued', 'withdrawn'))
                ORDER BY se.id DESC LIMIT 1");
            $stmt->execute([$userId]);
            $status = (string) ($stmt->fetchColumn() ?: '');
            if (in_array($status, ['graduated', 'transferred', 'discontinued'], true)) {
                return $status;
            }
        }

        return null;
    }

    private function tableExists(string $table): bool
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        }
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->db->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }
}
