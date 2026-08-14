<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Modules/Accounts/AccountBulkSelection.php';
require_once __DIR__ . '/../src/Modules/Accounts/StudentAccountBulkCommandService.php';

use EduCore\Modules\Accounts\AccountBulkSelection;
use EduCore\Modules\Accounts\StudentAccountBulkCommandService;

final class StudentAccountBulkServiceTest
{
    public static function run(): void
    {
        // Mock PDO for dry logic checks
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, username TEXT, role TEXT, status TEXT, login_disabled_reason TEXT, login_disabled_at TEXT, login_disabled_by INTEGER, password TEXT, password_hash TEXT, is_test_account INTEGER, deleted_at TEXT)");
        $pdo->exec("CREATE TABLE student_profiles (id INTEGER PRIMARY KEY, user_id INTEGER, student_code TEXT, enrollment_status TEXT)");
        $pdo->exec("CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT, user_role TEXT, action TEXT, target_type TEXT, target_id INTEGER, target_name TEXT, details TEXT, ip_address TEXT, academic_year_id INTEGER, request_id TEXT, batch_id TEXT, result TEXT, route TEXT, user_agent TEXT, undo_log_id INTEGER, created_at TEXT)");
        $pdo->exec("CREATE TABLE undo_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action_type TEXT, table_name TEXT, record_id INTEGER, old_data TEXT, new_data TEXT, description TEXT, page_url TEXT, batch_id TEXT, request_id TEXT, can_undo INTEGER, failure_reason TEXT, status TEXT, created_at TEXT)");
        $pdo->exec("CREATE TABLE recycle_bin (id INTEGER PRIMARY KEY AUTOINCREMENT, undo_log_id INTEGER, deleted_by INTEGER, table_name TEXT, record_id INTEGER, record_data TEXT, description TEXT, expires_at TEXT)");
        $_SESSION['user_id'] = 1;
        $_SESSION['name'] = 'مدير النظام';
        $_SESSION['role'] = 'admin';

        $service = new StudentAccountBulkCommandService($pdo);

        // Test 1: Invalid action throws InvalidArgumentException
        try {
            $selection = new AccountBulkSelection('selected', [1]);
            $service->execute('invalid_action', $selection, 1, 1);
            assert(false, 'Should throw InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            assert(true);
        }

        // Test 2: Execute on empty selection returns 0 succeeded
        $selection = new AccountBulkSelection('selected', [999]);
        $res = $service->execute('activate', $selection, 1, 1, 'skip');
        assert($res['succeeded'] === 0);
        assert($res['skipped'] === 0);

        // Test 3: Generate credentials for unconfigured student
        $pdo->exec("INSERT INTO users (id, name, username, role, status, password, password_hash, is_test_account) VALUES (1, 'طالب جديد', NULL, 'student', 'active', NULL, NULL, 0)");
        $pdo->exec("INSERT INTO student_profiles (id, user_id, student_code) VALUES (1, 1, 'STU1001')");

        $selection = new AccountBulkSelection('selected', [1]);
        $res = $service->execute('generate_credentials', $selection, 1, 1);
        assert($res['succeeded'] === 1);
        assert(count($res['credentials']) === 1);
        assert($res['credentials'][0]['student_code'] === 'STU1001');
        assert(!empty($res['credentials'][0]['username']));
        assert(strlen($res['credentials'][0]['password']) >= 12);
        assert((bool)preg_match('/[a-z]/', $res['credentials'][0]['password']));
        assert((bool)preg_match('/[A-Z]/', $res['credentials'][0]['password']));
        assert((bool)preg_match('/[0-9]/', $res['credentials'][0]['password']));
        assert((bool)preg_match('/[^a-zA-Z0-9]/', $res['credentials'][0]['password']));

        // Verify password hash was created
        $userRow = $pdo->query("SELECT username, password, password_hash FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        assert(!empty($userRow['username']));
        assert(!empty($userRow['password_hash']));
        assert(str_starts_with($userRow['password_hash'], '$2y$'));

        // Test 4: A custom reason is stored exactly and activation clears the current reason.
        $pdo->exec("INSERT INTO users (id, name, username, role, status, password, password_hash, is_test_account)
            VALUES (2, 'طالب للتراجع', 'rollback_student', 'student', 'active', 'encrypted', 'hash', 0)");
        $pdo->exec("INSERT INTO student_profiles (id, user_id, student_code) VALUES (2, 2, 'STU1002')");
        $disabled = $service->execute('deactivate', new AccountBulkSelection('selected', [2]), 1, 1, 'skip', 'سبب خاص كتبه المدير');
        assert($disabled['succeeded'] === 1);
        $disabledRow = $pdo->query("SELECT status, login_disabled_reason, login_disabled_by FROM users WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
        assert($disabledRow['status'] === 'inactive');
        assert($disabledRow['login_disabled_reason'] === 'سبب خاص كتبه المدير');
        assert((int) $disabledRow['login_disabled_by'] === 1);

        $activated = $service->execute('activate', new AccountBulkSelection('selected', [2]), 1, 1);
        assert($activated['succeeded'] === 1);
        $activatedRow = $pdo->query("SELECT status, login_disabled_reason FROM users WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
        assert($activatedRow['status'] === 'active');
        assert($activatedRow['login_disabled_reason'] === null);

        // Test 5: A status batch is atomic even when the caller requests skip mode.
        $pdo->exec("INSERT INTO users (id, name, username, role, status, password, password_hash, is_test_account)
            VALUES (3, 'طالب أول', 'atomic_one', 'student', 'active', 'encrypted', 'hash', 0),
                   (4, 'طالب ثان', 'atomic_two', 'student', 'active', 'encrypted', 'hash', 0)");
        $pdo->exec("CREATE TRIGGER fail_student_bulk_audit
            BEFORE INSERT ON activity_logs
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END");
        try {
            $service->execute('deactivate', new AccountBulkSelection('selected', [3, 4]), 1, 1, 'skip');
            assert(false, 'Status batches must fail atomically when audit persistence fails.');
        } catch (RuntimeException $e) {
            assert(true);
        }
        $pdo->exec("DROP TRIGGER fail_student_bulk_audit");
        assert((int) $pdo->query("SELECT COUNT(*) FROM users WHERE id IN (3, 4) AND status = 'active'")->fetchColumn() === 2);

        echo "STUDENT_ACCOUNT_BULK_SERVICE_TEST_PASSED\n";
    }
}

StudentAccountBulkServiceTest::run();
