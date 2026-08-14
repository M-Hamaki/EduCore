<?php

declare(strict_types=1);

namespace EduCore\Modules\Accounts;

use Closure;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Imports account login data and exports recoverable current passwords only
 * when an explicit per-user password decoder is injected by an authorized page.
 * Role, status, and academic-scope changes deliberately remain outside this service.
 */
final class AccountCredentialCsvService
{
    private Closure $passwordEncoder;
    private Closure $auditWriter;
    private ?Closure $targetGuard;
    private ?Closure $passwordDecoder;

    public function __construct(
        PDO $db,
        callable $passwordEncoder,
        callable $auditWriter,
        ?callable $targetGuard = null,
        ?callable $passwordDecoder = null
    )
    {
        $this->db = $db;
        $this->passwordEncoder = Closure::fromCallable($passwordEncoder);
        $this->auditWriter = Closure::fromCallable($auditWriter);
        $this->targetGuard = $targetGuard !== null ? Closure::fromCallable($targetGuard) : null;
        $this->passwordDecoder = $passwordDecoder !== null ? Closure::fromCallable($passwordDecoder) : null;
    }

    private PDO $db;

    /**
     * @param array<string,mixed> $file
     * @param array{manageable_roles?:list<string>} $options
     * @return array{updated:int,skipped:int,batch_id:string}
     */
    public function import(array $file, string $accountType, array $options = []): array
    {
        $accountType = $this->assertAccountType($accountType);
        $validated = \FileUploadGuard::validate($file, [
            'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        ], 2 * 1024 * 1024);

        $identifierHeader = $accountType === 'student' ? 'student_code' : 'employee_code';
        $rows = $this->readCsv((string)$validated['tmp_name'], $identifierHeader);
        if ($rows === []) {
            throw new InvalidArgumentException('ملف الاستيراد لا يحتوي على صفوف بيانات.');
        }

        $batchId = bin2hex(random_bytes(16));
        $updated = 0;
        $skipped = 0;
        $seenIdentifiers = [];
        $seenUsernames = [];
        $manageableRoles = array_values(array_unique(array_map('strval', $options['manageable_roles'] ?? [])));

        $this->db->beginTransaction();
        try {
            foreach ($rows as $rowNumber => $row) {
                $displayRow = $rowNumber + 2;
                try {
                    $identifier = $this->decodeCsvCell((string)($row[$identifierHeader] ?? ''));
                    $submittedUsername = $this->decodeCsvCell((string)($row['username'] ?? ''));
                    $newPassword = trim((string)($row['new_password'] ?? ''));

                    if ($identifier === '') {
                        throw new InvalidArgumentException('كود الحساب مطلوب.');
                    }
                    if ($submittedUsername === '' && $newPassword === '') {
                        $skipped++;
                        continue;
                    }
                    $identifierKey = mb_strtolower($identifier, 'UTF-8');
                    if (isset($seenIdentifiers[$identifierKey])) {
                        throw new InvalidArgumentException('الكود مكرر داخل الملف.');
                    }
                    $seenIdentifiers[$identifierKey] = true;

                    $target = $accountType === 'student'
                        ? $this->lockStudent($identifier)
                        : $this->lockStaff($identifier, $manageableRoles);
                    if ($this->targetGuard !== null) {
                        ($this->targetGuard)($target, $accountType);
                    }

                    if ($accountType === 'staff' && ($target['role'] ?? null) === null) {
                        if ($submittedUsername === '' && $newPassword === '') {
                            $skipped++;
                            continue;
                        }
                        throw new InvalidArgumentException('عيّن دور بوابة للعامل أولًا من زر الأدوار والصلاحيات.');
                    }

                    $currentUsername = trim((string)($target['username'] ?? ''));
                    $wantedUsername = $submittedUsername !== '' ? $submittedUsername : $currentUsername;
                    $hasPassword = !empty($target['password']) || !empty($target['password_hash']);

                    if ($wantedUsername === '' || mb_strlen($wantedUsername) < 3) {
                        throw new InvalidArgumentException('اسم المستخدم مطلوب ويجب ألا يقل عن 3 أحرف.');
                    }
                    if ($newPassword !== '' && mb_strlen($newPassword) < 4) {
                        throw new InvalidArgumentException('كلمة المرور الجديدة يجب ألا تقل عن 4 أحرف.');
                    }
                    if ($newPassword === '' && !$hasPassword) {
                        throw new InvalidArgumentException('الحساب غير مهيأ؛ أدخل كلمة مرور جديدة.');
                    }

                    $usernameKey = mb_strtolower($wantedUsername, 'UTF-8');
                    if (isset($seenUsernames[$usernameKey]) && $seenUsernames[$usernameKey] !== (int)$target['id']) {
                        throw new InvalidArgumentException('اسم المستخدم مكرر داخل الملف.');
                    }
                    $seenUsernames[$usernameKey] = (int)$target['id'];

                    $duplicate = $this->db->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
                    $duplicate->execute([$wantedUsername, (int)$target['id']]);
                    if ($duplicate->fetchColumn()) {
                        throw new InvalidArgumentException('اسم المستخدم مستخدم لحساب آخر.');
                    }

                    $updates = [];
                    $params = [];
                    $changes = [];
                    if ($wantedUsername !== $currentUsername) {
                        $updates[] = 'username = ?';
                        $params[] = $wantedUsername;
                        $changes['username'] = ['old' => $currentUsername, 'new' => $wantedUsername];
                    }
                    if ($newPassword !== '') {
                        $updates[] = 'password = ?';
                        $params[] = ($this->passwordEncoder)($newPassword, (int)$target['id']);
                        $updates[] = 'password_hash = ?';
                        $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
                        $changes['password_configured'] = ['old' => $hasPassword, 'new' => true];
                    }

                    if ($updates === []) {
                        $skipped++;
                        continue;
                    }
                    $params[] = (int)$target['id'];
                    $this->db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

                    $entityType = $accountType === 'student' ? 'student_account' : 'staff_account';
                    $logged = ($this->auditWriter)(
                        $entityType,
                        (int)$target['id'],
                        (string)$target['name'],
                        ['source' => 'account_credentials_csv_import', 'changes' => $changes],
                        $batchId
                    );
                    if ($logged !== true) {
                        throw new RuntimeException('تعذر تسجيل التعديل في سجل التدقيق.');
                    }
                    $updated++;
                } catch (Throwable $error) {
                    $message = ($error instanceof InvalidArgumentException || ($error instanceof RuntimeException && !($error instanceof \PDOException)))
                        ? $error->getMessage()
                        : 'تعذر تحديث بيانات الحساب بسبب خطأ داخلي.';
                    throw new RuntimeException('السطر ' . $displayRow . ': ' . $message, 0, $error);
                }
            }

            if ($updated === 0) {
                throw new InvalidArgumentException('لم يتضمن الملف أي تعديل قابل للتطبيق.');
            }
            $this->db->commit();
            return ['updated' => $updated, 'skipped' => $skipped, 'batch_id' => $batchId];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{headers:list<string>,rows:list<list<string>>} */
    public function exportStudents(): array
    {
        $stmt = $this->db->query(
            "SELECT u.id, sp.student_code, u.name, u.username, u.password, u.password_hash, u.status,
                    (u.username IS NOT NULL AND (u.password IS NOT NULL OR u.password_hash IS NOT NULL)) AS configured
             FROM users u
             LEFT JOIN student_profiles sp ON sp.user_id = u.id
             WHERE u.role = 'student' AND u.deleted_at IS NULL
             ORDER BY u.name ASC"
        );
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$password, $passwordExportStatus] = $this->exportPassword($row);
            $rows[] = [
                (string)($row['student_code'] ?? ''),
                (string)$row['name'],
                (string)($row['username'] ?? ''),
                $password,
                $passwordExportStatus,
                '',
                (string)$row['status'],
                (int)$row['configured'] === 1 ? 'configured' : 'unconfigured',
            ];
        }
        return [
            'headers' => ['student_code', 'name', 'username', 'password', 'password_export_status', 'new_password', 'status', 'configured'],
            'rows' => $rows,
        ];
    }

    /**
     * @param list<string> $manageableRoles
     * @param list<string> $visibleRoles
     * @return array{headers:list<string>,rows:list<list<string>>}
     */
    public function exportStaff(array $manageableRoles, array $visibleRoles, bool $includeEmployees): array
    {
        $manageableRoles = array_values(array_unique(array_map('strval', $manageableRoles)));
        $visibleRoles = array_values(array_intersect(array_unique(array_map('strval', $visibleRoles)), $manageableRoles));
        $clauses = [];
        $params = [];
        if ($visibleRoles !== []) {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM user_role_assignments ura_filter
                WHERE ura_filter.user_id = u.id
                  AND ura_filter.status = \'active\'
                  AND ura_filter.role_key IN (' . implode(',', array_fill(0, count($visibleRoles), '?')) . ')
            )';
            $params = array_merge($params, $visibleRoles);
        }
        if ($includeEmployees) {
            $clauses[] = "(u.role IS NULL OR u.role = 'employee' OR EXISTS (
                SELECT 1 FROM user_role_assignments ura_employee
                WHERE ura_employee.user_id = u.id
                  AND ura_employee.status = 'active'
                  AND ura_employee.role_key = 'employee'
            ))";
        }
        if ($clauses === []) {
            return [
                'headers' => ['employee_code', 'name', 'username', 'password', 'password_export_status', 'new_password', 'role', 'status', 'configured'],
                'rows' => [],
            ];
        }

        $sql = "SELECT u.id, sp.employee_code, u.name, u.username, u.password, u.password_hash, u.role, u.status,
                       COALESCE(NULLIF((
                           SELECT GROUP_CONCAT(ura.role_key ORDER BY ura.is_primary DESC, ura.role_key SEPARATOR ',')
                           FROM user_role_assignments ura
                           WHERE ura.user_id = u.id AND ura.status = 'active'
                       ), ''), u.role, 'employee') AS assigned_roles,
                       (u.username IS NOT NULL AND (u.password IS NOT NULL OR u.password_hash IS NOT NULL)) AS configured
                FROM users u
                INNER JOIN staff_profiles sp ON sp.user_id = u.id
                WHERE u.deleted_at IS NULL AND (" . implode(' OR ', $clauses) . ')
                ORDER BY u.name ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$password, $passwordExportStatus] = $this->exportPassword($row);
            $rows[] = [
                (string)($row['employee_code'] ?? ''),
                (string)$row['name'],
                (string)($row['username'] ?? ''),
                $password,
                $passwordExportStatus,
                '',
                (string)($row['assigned_roles'] ?? $row['role'] ?? 'employee'),
                (string)$row['status'],
                (int)$row['configured'] === 1 ? 'configured' : 'unconfigured',
            ];
        }
        return [
            'headers' => ['employee_code', 'name', 'username', 'password', 'password_export_status', 'new_password', 'role', 'status', 'configured'],
            'rows' => $rows,
        ];
    }

    /** @param array<string,mixed> $row @return array{0:string,1:string} */
    private function exportPassword(array $row): array
    {
        $stored = (string)($row['password'] ?? '');
        $hasHash = trim((string)($row['password_hash'] ?? '')) !== '';
        if ($stored === '') {
            return ['', $hasHash ? 'not_recoverable' : 'not_configured'];
        }
        if ($this->passwordDecoder === null) {
            throw new RuntimeException('Password export requires an explicit password decoder.');
        }

        try {
            $plaintext = (string)($this->passwordDecoder)($stored, (int)($row['id'] ?? 0));
        } catch (Throwable $error) {
            error_log('Account password export decryption failed for user ' . (int)($row['id'] ?? 0) . ': ' . $error->getMessage());
            return ['', 'not_recoverable'];
        }

        return $plaintext !== '' ? [$plaintext, 'available'] : ['', 'not_recoverable'];
    }

    /** @return list<array<string,string>> */
    private function readCsv(string $path, string $identifierHeader): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('تعذر قراءة ملف الاستيراد.');
        }
        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                return [];
            }
            $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
            $delimiterCounts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
            arsort($delimiterCounts);
            $delimiter = (string)array_key_first($delimiterCounts);
            $headers = array_map([$this, 'normalizeHeader'], str_getcsv($firstLine, $delimiter));
            if (count(array_unique($headers)) !== count($headers)) {
                throw new InvalidArgumentException('ملف الاستيراد يحتوي على أسماء أعمدة مكررة.');
            }
            if (!in_array($identifierHeader, $headers, true) || !in_array('username', $headers, true) || !in_array('new_password', $headers, true)) {
                throw new InvalidArgumentException('رؤوس الملف غير صحيحة. استخدم ملف التصدير من الصفحة دون تغيير أسماء الأعمدة.');
            }

            $rows = [];
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (count($rows) >= 2000) {
                    throw new InvalidArgumentException('الملف يتجاوز الحد الأقصى وهو 2000 حساب في العملية الواحدة.');
                }
                $values = array_map(static fn($value): string => trim((string)$value), $values);
                if (count(array_filter($values, static fn(string $value): bool => $value !== '')) === 0) {
                    continue;
                }
                $values = array_pad($values, count($headers), '');
                $rows[] = array_combine($headers, array_slice($values, 0, count($headers))) ?: [];
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header));
    }

    private function decodeCsvCell(string $value): string
    {
        $value = trim($value);
        return preg_match("/^'[=+\-@]/u", $value) ? substr($value, 1) : $value;
    }

    /** @return array<string,mixed> */
    private function lockStudent(string $studentCode): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, u.username, u.password, u.password_hash, u.role
             FROM users u
             INNER JOIN student_profiles sp ON sp.user_id = u.id
             WHERE sp.student_code = ? AND u.role = 'student' AND u.deleted_at IS NULL
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$studentCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('لم يتم العثور على طالب بهذا الكود.');
        }
        return $row;
    }

    /** @param list<string> $manageableRoles @return array<string,mixed> */
    private function lockStaff(string $employeeCode, array $manageableRoles): array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.name, u.username, u.password, u.password_hash, u.role
             FROM users u
             INNER JOIN staff_profiles sp ON sp.user_id = u.id
             WHERE sp.employee_code = ? AND u.deleted_at IS NULL
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$employeeCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('لم يتم العثور على عامل بهذا الكود.');
        }
        if ($row['role'] !== null && !in_array((string)$row['role'], $manageableRoles, true)) {
            throw new InvalidArgumentException('هذا الحساب خارج الأدوار المسموح بإدارتها.');
        }
        return $row;
    }

    private function assertAccountType(string $accountType): string
    {
        if (!in_array($accountType, ['student', 'staff'], true)) {
            throw new InvalidArgumentException('نوع الحساب المطلوب غير صالح.');
        }
        return $accountType;
    }
}
