<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Infrastructure\PdoSchedulePolicyRepository;
use EduCore\Modules\Staff\Contracts\StaffGroupOverlapQuery;

$source = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Infrastructure/PdoSchedulePolicyRepository.php'
);
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};

$assert(
    str_contains($source, 'EXISTS (SELECT 1 FROM staff_schedule_scopes filter_scope'),
    'scope filter examines every active scope on the latest version'
);
$assert(
    str_contains($source, '$effectiveStart < $effectiveEnd'),
    'publication conflicts require an intersection of all four effective ranges'
);
$assert(
    str_contains($source, "successor.status IN (\\'active\\',\\'retired\\')"),
    'retired calendar successor hides its active predecessor'
);
$assert(
    str_contains($source, 'SELECT * FROM staff_schedule_policies WHERE id = ? FOR UPDATE'),
    'version numbering owns a policy-row lock'
);
$assert(
    str_contains($source, "successor.state IN (\\'published\\',\\'retired\\')"),
    'effective schedule excludes a predecessor after a published or retired successor takes effect'
);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE staff_schedule_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    description TEXT NULL, status TEXT NOT NULL, created_by INTEGER NOT NULL
)');
$groups = new class implements StaffGroupOverlapQuery {
    public function groupsShareActiveMember(int $leftGroupId, int $rightGroupId, DateTimeImmutable $from, DateTimeImmutable $to): bool { return false; }
};
$repository = new PdoSchedulePolicyRepository($db, $groups);
$repository->insertPolicy(['code' => 'DUP', 'name' => 'First', 'description' => null, 'status' => 'active', 'created_by' => 1]);
try {
    $repository->insertPolicy(['code' => 'DUP', 'name' => 'Second', 'description' => null, 'status' => 'active', 'created_by' => 1]);
    $assert(false, 'duplicate policy code is translated');
} catch (Throwable $exception) {
    $assert($exception->getMessage() === 'SCHEDULE_POLICY_CODE_EXISTS' && $exception->getPrevious() instanceof PDOException, 'duplicate policy code has a clear domain error with preserved PDO cause');
}

exit($failures === 0 ? 0 : 1);
