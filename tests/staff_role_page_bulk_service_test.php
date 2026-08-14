<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Modules/Staff/StaffRolePageBulkCommandService.php';

use EduCore\Modules\Staff\StaffRolePageBulkCommandService;

final class StaffRolePageBulkServiceTest
{
    public static function run(): void
    {
        $_SESSION['user_id'] = 999;

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE staff_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, role_key TEXT, role_name TEXT, base_role_key TEXT, status TEXT, is_active INTEGER)");
        $pdo->exec("CREATE TABLE staff_role_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, role_key TEXT, page_name TEXT, UNIQUE(role_key, page_name))");
        $pdo->exec("CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT, user_role TEXT, action TEXT, target_type TEXT, target_id INTEGER, target_name TEXT, details TEXT, ip_address TEXT, request_id TEXT, batch_id TEXT, result TEXT, route TEXT, user_agent TEXT, undo_log_id INTEGER, created_at TEXT)");
        $pdo->exec("CREATE TABLE undo_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action_type TEXT, table_name TEXT, record_id INTEGER, old_data TEXT, new_data TEXT, description TEXT, page_url TEXT, batch_id TEXT, request_id TEXT, can_undo INTEGER, failure_reason TEXT, status TEXT, created_at TEXT)");
        $pdo->exec("CREATE TABLE recycle_bin (id INTEGER PRIMARY KEY AUTOINCREMENT, undo_log_id INTEGER, deleted_by INTEGER, table_name TEXT, record_id INTEGER, record_data TEXT, description TEXT, expires_at TEXT)");

        $pdo->exec("INSERT INTO staff_roles (role_key, role_name, base_role_key, status, is_active) VALUES ('specialist_senior', 'أخصائي أول', 'specialist', 'active', 1)");
        $pdo->exec("INSERT INTO staff_role_pages (role_key, page_name) VALUES ('specialist_senior', 'specialist_dashboard.php')");

        $service = new StaffRolePageBulkCommandService($pdo);

        // Test 1: Dry run add pages
        $resDry = $service->execute('add', ['specialist_senior'], '', ['specialist_requests.php'], 'super_admin', true);
        assert(isset($resDry['preview']['specialist_senior']));
        assert(in_array('specialist_requests.php', $resDry['preview']['specialist_senior']['added'], true));

        // Verify DB was NOT mutated during dry run
        $pagesBefore = $pdo->query("SELECT page_name FROM staff_role_pages WHERE role_key = 'specialist_senior' ORDER BY page_name")->fetchAll(PDO::FETCH_COLUMN);
        assert($pagesBefore === ['specialist_dashboard.php']);

        // Test 2: Commit add page
        $resAdd = $service->execute('add', ['specialist_senior'], '', ['specialist_requests.php'], 'super_admin', false);
        assert($resAdd['updated'] === 1);

        $pagesAfterAdd = $pdo->query("SELECT page_name FROM staff_role_pages WHERE role_key = 'specialist_senior' ORDER BY page_name")->fetchAll(PDO::FETCH_COLUMN);
        assert(in_array('specialist_requests.php', $pagesAfterAdd, true));

        // Test 3: Mandatory page protection - try removing mandatory page 'specialist_dashboard.php'
        $resRemove = $service->execute('remove', ['specialist_senior'], '', ['specialist_dashboard.php'], 'super_admin', false);
        assert(isset($resRemove['updated']));
        // Mandatory page MUST NOT be removed
        $pagesAfterRemove = $pdo->query("SELECT page_name FROM staff_role_pages WHERE role_key = 'specialist_senior' ORDER BY page_name")->fetchAll(PDO::FETCH_COLUMN);
        assert(in_array('specialist_dashboard.php', $pagesAfterRemove, true));

        // Test 4: Missing source roles fail closed instead of reducing targets to mandatory pages.
        try {
            $service->execute(
                'copy_from',
                ['specialist_senior'],
                'missing_source_role',
                [],
                'super_admin',
                true
            );
            assert(false, 'Missing source role must be rejected');
        } catch (InvalidArgumentException $e) {
            assert(str_contains($e->getMessage(), 'المصدر'));
        }

        echo "STAFF_ROLE_PAGE_BULK_SERVICE_TEST_PASSED\n";
    }
}

StaffRolePageBulkServiceTest::run();
