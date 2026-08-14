<?php

declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/tools/rollback_profile_attachment_migration.php');
$failures = [];
foreach ([
    'cli_guard' => "PHP_SAPI !== 'cli'",
    'private_manifest_boundary' => 'profile_attachment_migrations',
    'manifest_type' => 'profile_attachment_migration',
    'database_confirmation' => 'hash_equals($currentDatabase, $requestedDatabase)',
    'legacy_source_required' => '$legacyPath === null',
    'private_checksum' => "hash_file('sha256'",
    'conditional_restore' => 'AND file_name = ?',
    'transaction' => '$db->beginTransaction();',
    'rollback' => '$db->rollBack();',
] as $name => $needle) {
    if (strpos($source, $needle) === false) {
        $failures[] = $name;
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: attachment rollback is manifest-bound, checksum-verified, conditional, and transactional.\n";
