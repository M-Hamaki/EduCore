<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../config/env_loader.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Students\StudentOperationalGuard;
use EduCore\Modules\Transport\Application\LegacyStudentBusAssignmentService;
use EduCore\Modules\Transport\Infrastructure\PdoStudentBusAssignmentRepository;
use EduCore\Modules\Transport\Infrastructure\PdoTransportTransactionManager;

$options = getopt('', ['database:']);
$testDb = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb) || $testDb === 'educore') {
    fwrite(STDERR, "FAILED: --database must name an isolated *_test database.\n");
    exit(1);
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=' . $testDb . ';charset=utf8mb4',
        (string) env('DB_USER', 'root'),
        (string) env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY, name VARCHAR(200), role VARCHAR(40), status VARCHAR(30),
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS buses (
            id INT PRIMARY KEY, bus_number VARCHAR(80), status VARCHAR(30)
        ) ENGINE=InnoDB"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS student_bus_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            bus_id INT NULL,
            backup_bus_id INT NULL,
            notes TEXT NULL,
            academic_year_id INT NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_test_student_bus_year (student_id, academic_year_id)
        ) ENGINE=InnoDB"
    );
    $migration = require __DIR__ . '/../database/migrations/20260726_transport_bus_assignment_archive.php';
    $migration($db);
    $columns = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'student_bus_assignments'
           AND COLUMN_NAME IN ('status', 'archived_at', 'archived_by')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($columns) === 3, 'transport migration upgrades the legacy assignment table with archive metadata');
    $db->beginTransaction();
    $yearId = 998028;
    $firstStudent = 998028;
    $secondStudent = 998029;
    $firstBus = 998028;
    $secondBus = 998029;
    foreach ([[$firstStudent, 'طالب حافلة أول'], [$secondStudent, 'طالب حافلة ثان']] as [$id, $name]) {
        $db->prepare(
            "INSERT INTO users (id, name, role, status, deleted_at)
             VALUES (?, ?, 'student', 'active', NULL)
             ON DUPLICATE KEY UPDATE name = VALUES(name), role = 'student', status = 'active', deleted_at = NULL"
        )->execute([$id, $name]);
    }
    foreach ([[$firstBus, 'BUS-1'], [$secondBus, 'BUS-2']] as [$id, $number]) {
        $db->prepare(
            "INSERT INTO buses (id, bus_number, status)
             VALUES (?, ?, 'active')
             ON DUPLICATE KEY UPDATE bus_number = VALUES(bus_number), status = 'active'"
        )->execute([$id, $number]);
    }

    $audit = new class implements AuditEventWriter {
        public array $events = [];
        public function recordEvent(
            string $action,
            ?string $entityType,
            mixed $recordId,
            ?string $name,
            array $details = [],
            array $context = []
        ): void {
            $this->events[] = [$action, $entityType, $recordId, $details];
        }
    };
    $service = new LegacyStudentBusAssignmentService(
        new PdoStudentBusAssignmentRepository($db),
        new StudentOperationalGuard($db),
        new PdoTransportTransactionManager($db),
        $audit,
        $yearId
    );
    $service->assign($firstStudent, $firstBus, null, 'تعيين أول', 701);
    $active = $db->query(
        'SELECT * FROM student_bus_assignments
         WHERE student_id = ' . $firstStudent . ' AND academic_year_id = ' . $yearId
    )->fetch(PDO::FETCH_ASSOC);
    $assert((string) $active['status'] === 'active' && (int) $active['bus_id'] === $firstBus, 'bus assignment is stored by the Transport-owned service');

    $service->assign($firstStudent, null, null, '', 701);
    $archived = $db->query(
        'SELECT * FROM student_bus_assignments
         WHERE student_id = ' . $firstStudent . ' AND academic_year_id = ' . $yearId
    )->fetch(PDO::FETCH_ASSOC);
    $assert((string) $archived['status'] === 'archived' && $archived['archived_at'] !== null, 'unassignment archives instead of deleting the row');
    $assert((int) $db->query(
        'SELECT COUNT(*) FROM student_bus_assignments
         WHERE student_id = ' . $firstStudent . ' AND academic_year_id = ' . $yearId
    )->fetchColumn() === 1, 'archived assignment remains recoverable');

    $service->assign($firstStudent, $firstBus, null, 'إعادة تفعيل', 701);
    $bulkRolledBack = false;
    try {
        $service->bulkAssign([
            'student_ids' => [$firstStudent, $secondStudent],
            'bus_ids' => [$secondBus, 999999],
            'backup_bus_ids' => ['', ''],
            'notes_arr' => ['تغيير يجب التراجع عنه', 'غير صالح'],
        ], 701);
    } catch (InvalidArgumentException) {
        $bulkRolledBack = true;
    }
    $firstAfterFailure = $db->query(
        'SELECT bus_id, notes FROM student_bus_assignments
         WHERE student_id = ' . $firstStudent . ' AND academic_year_id = ' . $yearId
    )->fetch(PDO::FETCH_ASSOC);
    $assert($bulkRolledBack, 'invalid member aborts the whole bus-assignment batch');
    $assert((int) $firstAfterFailure['bus_id'] === $firstBus && (string) $firstAfterFailure['notes'] === 'إعادة تفعيل', 'bulk bus assignments roll back atomically');
    $assert(
        count($audit->events) === 3
            && $audit->events[1][0] === 'archive'
            && $audit->events[1][1] === 'student_bus_assignment',
        'every committed assignment mutation uses the shared audit contract'
    );

    $db->rollBack();
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s).\n");
    exit(1);
}

echo "Finance transport assignment archive integration test PASSED on {$testDb}.\n";
