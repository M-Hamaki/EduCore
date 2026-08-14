<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$staffPage = (string)file_get_contents($root . '/admin/staff_accounts.php');
$studentPage = (string)file_get_contents($root . '/admin/student_accounts.php');
$tableQuery = (string)file_get_contents($root . '/classes/AccountListDataTableQuery.php');
$service = (string)file_get_contents($root . '/src/Modules/Accounts/AccountCredentialCsvService.php');

$failures = [];
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$assertContains("update_login_credentials", $staffPage, 'Staff login credentials need an independent write action.');
$assertContains("update_role_access", $staffPage, 'Staff role and scope need an independent write action.');
$assertContains('openRoleAccessModal', $tableQuery, 'Staff rows need a separate role/access action button.');
$assertContains('openCredentialsModal', $tableQuery, 'Staff rows need a separate credentials action button.');
$assertContains("action=export_credentials", $staffPage, 'Staff accounts need a CSV export action.');
$assertContains("action=export_credentials", $studentPage, 'Student accounts need a CSV export action.');
$assertContains("name=\"accounts_file\"", $staffPage, 'Staff accounts need a CSV import upload.');
$assertContains("name=\"accounts_file\"", $studentPage, 'Student accounts need a CSV import upload.');
$assertContains("require_once '../src/Modules/Accounts/AccountCredentialCsvService.php';", $staffPage, 'Staff accounts must load the credential CSV service even when Composer metadata is stale.');
$assertContains("require_once '../src/Modules/Accounts/AccountCredentialCsvService.php';", $studentPage, 'Student accounts must load the credential CSV service even when Composer metadata is stale.');
$assertContains('namespace EduCore\\Modules\\Accounts;', $service, 'The credential CSV service namespace must match both account pages.');
$assertContains('FileUploadGuard::validate', $service, 'Account CSV uploads must use FileUploadGuard.');
$assertContains('beginTransaction', $service, 'Credential imports must be atomic.');
$assertContains("'password'", $service, 'Credential exports must contain the recoverable current password column.');
$assertContains("'password_export_status'", $service, 'Credential exports must explain passwords that cannot be recovered.');
$assertContains('decryptPasswordForUser', $staffPage, 'Staff exports must use the per-user password decoder.');
$assertContains('decryptPasswordForUser', $studentPage, 'Student exports must use the per-user password decoder.');
$assertContains("'new_password'", $service, 'The reusable CSV needs a blank new-password input column.');
$assertContains("'passwords_included' => true", $staffPage, 'Staff export audit must state that passwords are included.');
$assertContains("'passwords_included' => true", $studentPage, 'Student export audit must state that passwords are included.');
$assertContains("'sensitive_export' => true", $staffPage, 'Staff password export must be classified as sensitive.');
$assertContains("'sensitive_export' => true", $studentPage, 'Student password export must be classified as sensitive.');
$assertContains('$exportLogged', $staffPage, 'Staff password export must fail closed when its audit record cannot be written.');
$assertContains('$exportLogged', $studentPage, 'Student password export must fail closed when its audit record cannot be written.');
$assertContains("Cache-Control: no-store", $staffPage, 'Staff password exports must disable browser caching.');
$assertContains("Cache-Control: no-store", $studentPage, 'Student password exports must disable browser caching.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Account credential transfer contract passed." . PHP_EOL;
