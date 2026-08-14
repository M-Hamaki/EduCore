<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Modules/Accounts/AccountBulkSelection.php';
require_once __DIR__ . '/../src/Modules/Staff/StaffAccountBulkCommandService.php';

use EduCore\Modules\Accounts\AccountBulkSelection;
use EduCore\Modules\Staff\StaffAccountBulkCommandService;

final class StaffAccountBulkServiceTest
{
    public static function run(): void
    {
        $_SESSION['user_id'] = 999;
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, username TEXT, role TEXT, status TEXT, is_supervisor INTEGER, password TEXT, password_hash TEXT, deleted_at TEXT)");
        $pdo->exec("CREATE TABLE staff_profiles (id INTEGER PRIMARY KEY, user_id INTEGER, employee_code TEXT)");
        $pdo->exec("CREATE TABLE user_roles (id INTEGER PRIMARY KEY, user_id INTEGER, role_key TEXT, is_primary INTEGER)");
        $pdo->exec("CREATE TABLE user_role_assignments (id INTEGER PRIMARY KEY, user_id INTEGER, role_key TEXT, is_primary INTEGER, status TEXT, assigned_by INTEGER, created_at TEXT, updated_at TEXT)");
        $pdo->exec("CREATE TABLE staff_roles (role_key TEXT PRIMARY KEY, role_name TEXT, portal_type TEXT, base_role_key TEXT, status TEXT, is_active INTEGER)");
        $pdo->exec("INSERT INTO staff_roles (role_key, role_name, portal_type, base_role_key, status, is_active) VALUES ('teacher', 'معلم', 'teacher', 'teacher', 'active', 1), ('specialist', 'أخصائي', 'specialist', 'specialist', 'active', 1), ('admin', 'مدير', 'admin', 'admin', 'active', 1), ('super_admin', 'مدير نظام أعلى', 'super_admin', 'super_admin', 'active', 1)");
        $pdo->exec("CREATE TABLE staff_academic_scopes (id INTEGER PRIMARY KEY, user_id INTEGER, academic_year_id INTEGER, role_key TEXT, grade_id INTEGER, class_id INTEGER)");
        $pdo->exec("CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT, user_role TEXT, action TEXT, target_type TEXT, target_id INTEGER, target_name TEXT, details TEXT, ip_address TEXT, request_id TEXT, batch_id TEXT, result TEXT, route TEXT, user_agent TEXT, undo_log_id INTEGER, created_at TEXT)");
        $pdo->exec("CREATE TABLE undo_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action_type TEXT, table_name TEXT, record_id INTEGER, old_data TEXT, new_data TEXT, description TEXT, page_url TEXT, batch_id TEXT, request_id TEXT, can_undo INTEGER, failure_reason TEXT, status TEXT, created_at TEXT)");
        $pdo->exec("CREATE TABLE recycle_bin (id INTEGER PRIMARY KEY AUTOINCREMENT, undo_log_id INTEGER, deleted_by INTEGER, table_name TEXT, record_id INTEGER, record_data TEXT, description TEXT, expires_at TEXT)");

        $service = new StaffAccountBulkCommandService($pdo);

        // Test 1: Role protection - cannot assign admin/super_admin via bulk
        $pdo->exec("INSERT INTO users (id, name, username, role, status, is_supervisor) VALUES (1, 'معلم أ', 'teacher1', 'teacher', 'active', 0)");
        $pdo->exec("INSERT INTO staff_profiles (id, user_id, employee_code) VALUES (1, 1, 'EMP1001')");
        $pdo->exec("INSERT INTO user_roles (id, user_id, role_key, is_primary) VALUES (1, 1, 'teacher', 1)");
        $pdo->exec("INSERT INTO user_role_assignments (user_id, role_key, is_primary, status) VALUES (1, 'teacher', 1, 'active')");

        $selection = new AccountBulkSelection('selected', [1]);

        $validRoles = ['teacher' => 'معلم', 'specialist' => 'أخصائي', 'admin' => 'مدير', 'super_admin' => 'مدير نظام أعلى'];

        try {
            $service->execute('assign_roles', $selection, $validRoles, 999, 1, 'super_admin', [
                'role_keys' => ['super_admin'],
                'role_mode' => 'add'
            ], 'stop');
            assert(false, 'Should prevent assigning super_admin in bulk');
        } catch (InvalidArgumentException $e) {
            assert(true);
        }

        // Test 2: Cannot strip super_admin from active super_admin user
        $pdo->exec("INSERT INTO users (id, name, username, role, status, is_supervisor) VALUES (2, 'مدير أصل', 'admin1', 'super_admin', 'active', 0)");
        $pdo->exec("INSERT INTO staff_profiles (id, user_id, employee_code) VALUES (2, 2, 'EMP1002')");
        $pdo->exec("INSERT INTO user_roles (id, user_id, role_key, is_primary) VALUES (2, 2, 'super_admin', 1)");
        $pdo->exec("INSERT INTO user_role_assignments (user_id, role_key, is_primary, status) VALUES (2, 'super_admin', 1, 'active')");

        $selectionSelf = new AccountBulkSelection('selected', [2]);
        $resSelf = $service->execute('activate', $selectionSelf, $validRoles, 999, 2, 'super_admin');
        assert($resSelf['skipped'] === 1); // Skipped because target is current session user

        // Test 3: Add new role (teacher -> teacher + specialist)
        $resAdd = $service->execute('assign_roles', $selection, $validRoles, 999, 999, 'super_admin', [
            'role_keys' => ['specialist'],
            'role_mode' => 'add'
        ], 'stop');
        assert($resAdd['succeeded'] === 1);

        $roles = $pdo->query("SELECT role_key FROM user_role_assignments WHERE user_id = 1 AND status = 'active' ORDER BY role_key")->fetchAll(PDO::FETCH_COLUMN);
        assert(in_array('teacher', $roles, true) && in_array('specialist', $roles, true));

        // Test 4: Set supervisor flag for teacher
        $resSup = $service->execute('set_supervisor', $selection, $validRoles, 999, 999, 'super_admin', [
            'is_supervisor' => 1
        ], 'stop');
        assert($resSup['succeeded'] === 1);
        $supVal = (int)$pdo->query("SELECT is_supervisor FROM users WHERE id = 1")->fetchColumn();
        assert($supVal === 1);

        // Test 5: Configured credentials must never be overwritten by "generate missing credentials".
        $pdo->exec("INSERT INTO users (id, name, username, role, status, is_supervisor, password, password_hash)
            VALUES (3, 'معلم مهيأ', 'configured_teacher', 'teacher', 'active', 0, 'encrypted-value', 'existing-hash')");
        $pdo->exec("INSERT INTO staff_profiles (id, user_id, employee_code) VALUES (3, 3, 'EMP1003')");
        $pdo->exec("INSERT INTO user_role_assignments (user_id, role_key, is_primary, status) VALUES (3, 'teacher', 1, 'active')");
        $configuredSelection = new AccountBulkSelection('selected', [3]);
        $configuredResult = $service->execute(
            'generate_credentials',
            $configuredSelection,
            $validRoles,
            999,
            999,
            'super_admin'
        );
        assert($configuredResult['skipped'] === 1);
        $configuredRow = $pdo->query("SELECT username, password, password_hash FROM users WHERE id = 3")->fetch(PDO::FETCH_ASSOC);
        assert($configuredRow['username'] === 'configured_teacher');
        assert($configuredRow['password'] === 'encrypted-value');
        assert($configuredRow['password_hash'] === 'existing-hash');

        // Test 6: A normal admin cannot export or reset a super-admin's credentials.
        foreach (['export_credentials', 'reset_passwords'] as $protectedAction) {
            try {
                $service->execute(
                    $protectedAction,
                    $selectionSelf,
                    $validRoles,
                    999,
                    1,
                    'admin'
                );
                assert(false, "{$protectedAction} must reject a normal admin targeting a super-admin");
            } catch (RuntimeException $e) {
                assert(str_contains($e->getMessage(), 'مدير النظام الأعلى'));
            }
        }

        echo "STAFF_ACCOUNT_BULK_SERVICE_TEST_PASSED\n";
    }
}

StaffAccountBulkServiceTest::run();
