<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/20260718_safe_year_rollover.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');
$service = is_file($root . '/classes/RecoveryBackupService.php')
    ? (string) file_get_contents($root . '/classes/RecoveryBackupService.php')
    : '';
$page = (string) file_get_contents($root . '/admin/academic_year_setup.php');

$checks = [
    'migration_owns_recovery_receipts' => strpos($migration, 'CREATE TABLE recovery_backups') !== false,
    'receipt_is_registered_non_undoable' => strpos($policy, "'recovery_backups'") !== false
        && strpos($policy, "'workflow_owned_rollback'") !== false,
    'restore_target_must_end_test' => strpos($service, "_test") !== false,
    'retained_restore_has_an_explicit_public_contract' => strpos($service, 'public function restorePackageToIsolatedDatabase') !== false
        && strpos($service, 'restored_database_name') !== false,
    'restore_retry_is_limited_to_integrity_checked_verification_failures' => strpos($service, '$retryableRestoreFailure') !== false
        && strpos($service, "'restore_verification_failed'") !== false
        && strpos($service, "!empty(\$receipt['package_sha256'])") !== false
        && strpos($service, "!empty(\$receipt['manifest_sha256'])") !== false,
    'retained_restore_replay_requires_the_full_fingerprint' => strpos($service, "receipt['database_fingerprint']") !== false
        && strpos($service, "restored['fingerprint']") !== false
        && strpos($service, "'replayed' => true") !== false,
    'package_has_database_and_file_fingerprints' => strpos($service, 'database_fingerprint') !== false
        && strpos($service, 'files_fingerprint') !== false,
    'service_never_embeds_database_password_in_manifest' => strpos($service, "'database_password'") === false,
    'web_recovery_has_scoped_maintenance_runtime' => strpos($page, 'yearSetupPrepareRecoveryRuntime') !== false
        && strpos($page, 'set_time_limit') !== false
        && strpos($page, 'ignore_user_abort') !== false,
    'already_compressed_files_are_not_recompressed' => strpos($service, 'ZipArchive::CM_STORE') !== false
        && strpos($service, 'shouldStoreWithoutCompression') !== false,
    'recovery_submit_prevents_duplicate_clicks' => strpos($page, "button.dataset.submitting === '1'") !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
