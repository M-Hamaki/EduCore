<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$runner = (string) file_get_contents($root . '/tools/run_migrations.php');
$legacyAi = (string) file_get_contents($root . '/database/migrations/20260624_ai_lesson_powerpoint.php');
$legacyCanva = (string) file_get_contents($root . '/database/migrations/20260625_canva_integration.php');

$checks = [
    'migration_require_is_scope_isolated' => strpos(
        $runner,
        '$migration = (static function (string $migrationFile) {'
    ) !== false && strpos($runner, 'return require $migrationFile;') !== false,
    'runner_records_basename_after_include' => strpos($runner, '$name = basename($file);') !== false
        && strpos($runner, "execute([\$name])") !== false,
    'legacy_ai_contains_colliding_name_variable' => strpos($legacyAi, 'foreach ($columns as $name') !== false,
    'legacy_canva_contains_colliding_name_variable' => strpos($legacyCanva, 'foreach ($tables as $name') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
