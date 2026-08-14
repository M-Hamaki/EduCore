<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/src/Modules/Staff/StaffBiometricIdentityService.php';
require_once $root . '/src/Modules/Staff/Presentation/StaffProfileErrorPresenter.php';

use EduCore\Modules\Staff\StaffBiometricIdentityService;
use EduCore\Modules\Staff\Presentation\StaffProfileErrorPresenter;

$mapper = (string)file_get_contents(
    $root . '/src/Modules/Staff/StaffProfileRequestMapper.php'
);
$store = (string)file_get_contents($root . '/classes/UserProfileStore.php');
$command = (string)file_get_contents(
    $root . '/src/Modules/Staff/StaffProfileCommandService.php'
);
$devicePage = (string)file_get_contents($root . '/admin/biometric_devices.php');
$deviceAjax = (string)file_get_contents(
    $root . '/admin/ajax/biometric_device_actions.php'
);
$staffPage = (string)file_get_contents($root . '/admin/staff.php');
$profileForm = (string)file_get_contents(
    $root . '/src/Modules/Staff/Presentation/profile_form.php'
);
$migration = (string)file_get_contents(
    $root . '/database/migrations/20260730_staff_profile_data_consistency.php'
);
$identityMigration = (string)file_get_contents(
    $root . '/database/migrations/20260730_staff_profile_identity_separation.php'
);

$duplicate = new PDOException(
    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '10' for key 'uk_biometric_id'"
);
$duplicate->errorInfo = ['23000', 1062, 'Duplicate'];
$duplicateMessage = StaffProfileErrorPresenter::saveMessage($duplicate, 'test');

$checks = [
    'blank_identifier_normalizes_to_null' =>
        StaffBiometricIdentityService::normalize('  ') === null,
    'identifier_is_trimmed' =>
        StaffBiometricIdentityService::normalize('  123  ') === '123',
    'mapper_uses_shared_normalization' =>
        str_contains($mapper, "StaffBiometricIdentityService::normalize("),
    'profile_read_does_not_fallback_to_legacy_code' =>
        !str_contains($store, "AS legacy_biometric_id")
        && !str_contains($store, "COALESCE(NULLIF(TRIM(sp.biometric_id), ''), NULLIF(TRIM(u.employee_code), ''))"),
    'profile_save_validates_independent_biometric' =>
        str_contains($command, 'assertAvailableWithinTransaction(')
        && !str_contains($command, 'synchronizeWithinTransaction('),
    'internal_code_is_system_generated_and_readonly' =>
        str_contains($profileForm, 'name="employee_code"')
        && str_contains($profileForm, 'dir="ltr" readonly')
        && str_contains($command, "\$profile['employee_code'] = \$this->users->generateEmployeeCode();"),
    'device_writes_use_staff_owned_service' =>
        str_contains($devicePage, 'synchronizeWithinTransaction(')
        && str_contains($deviceAjax, 'synchronizeWithinTransaction('),
    'duplicate_error_is_localized' =>
        $duplicateMessage === 'رقم البصمة مستخدم بالفعل لعامل آخر.'
        && !str_contains($duplicateMessage, 'SQLSTATE'),
    'staff_page_escapes_safe_message' =>
        str_contains(
            $staffPage,
            "htmlspecialchars((string)\$error_message, ENT_QUOTES, 'UTF-8')"
        ),
    'migration_preserves_custom_values_without_biometric_backfill' =>
        !str_contains($migration, 'SET sp.biometric_id = NULLIF(TRIM(u.employee_code)')
        && str_contains($migration, 'MODIFY `{$column}` VARCHAR'),
    'corrective_migration_separates_identifiers' =>
        str_contains($identityMigration, 'SET sp.biometric_id = NULL')
        && str_contains($identityMigration, "employee_code NOT REGEXP '^E[0-9]{8}$'")
        && !str_contains($identityMigration, 'UPDATE users SET employee_code'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
