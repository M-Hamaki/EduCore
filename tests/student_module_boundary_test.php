<?php

$root = dirname(__DIR__);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
$moduleRoot = $root . '/src/Modules/Students';
$legacyClasses = [
    'StudentAttachmentService',
    'StudentBulkCreateService',
    'StudentDeletionService',
    'StudentArchiveService',
    'StudentArchiveQuery',
    'StudentOperationalGuard',
    'StudentEnrollment',
    'StudentEnrollmentService',
    'StudentGuardianService',
    'StudentListPageQuery',
    'StudentOperationLogQuery',
    'StudentProfileCommandService',
    'StudentProfileLifecycleService',
    'StudentProfilePageQuery',
    'StudentProfilePayload',
    'StudentProfileRepository',
    'StudentProfileRequestMapper',
    'StudentRelationshipService',
];

$implementationsAreNamespaced = true;
$legacyFilesAreAliases = true;
foreach ($legacyClasses as $class) {
    $implementation = (string) file_get_contents($moduleRoot . '/' . $class . '.php');
    $legacy = (string) file_get_contents($root . '/classes/' . $class . '.php');
    $implementationsAreNamespaced = $implementationsAreNamespaced
        && strpos($implementation, 'namespace EduCore\\Modules\\Students;') !== false;
    $legacyFilesAreAliases = $legacyFilesAreAliases
        && strpos($legacy, "class_alias(") !== false
        && strpos($legacy, 'vendor/autoload.php') !== false;
}

$page = (string) file_get_contents($root . '/admin/students.php');
$checks = [
    'composer_psr4_maps_src' => ($composer['autoload']['psr-4']['EduCore\\'] ?? null) === 'src/',
    'student_implementations_are_namespaced' => $implementationsAreNamespaced,
    'legacy_student_classes_are_aliases' => $legacyFilesAreAliases,
    'presentation_is_module_owned' => strpos(
        $page,
        "../src/Modules/Students/Presentation/profile_view.php"
    ) !== false,
    'legacy_presentation_directory_removed' => !is_dir($root . '/classes/Presentation/Students'),
    'src_is_http_protected' => is_file($root . '/src/.htaccess')
        && strpos((string) file_get_contents($root . '/src/.htaccess'), 'Require all denied') !== false,
    'module_documents_compatibility_and_rollback' => is_file($moduleRoot . '/README.md')
        && strpos((string) file_get_contents($moduleRoot . '/README.md'), 'Rollback') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
