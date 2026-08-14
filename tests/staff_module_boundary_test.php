<?php

$root = dirname(__DIR__);
$moduleRoot = $root . '/src/Modules/Staff';
$legacyClasses = [
    'StaffAttachmentService',
    'StaffDeletionService',
    'StaffListPageQuery',
    'StaffProfileCommandService',
    'StaffProfilePageQuery',
    'StaffProfilePayload',
    'StaffProfileRepository',
    'StaffProfileRequestMapper',
];

$implementationsAreNamespaced = true;
$legacyFilesAreAliases = true;
foreach ($legacyClasses as $class) {
    $implementation = (string) file_get_contents($moduleRoot . '/' . $class . '.php');
    $legacy = (string) file_get_contents($root . '/classes/' . $class . '.php');
    $implementationsAreNamespaced = $implementationsAreNamespaced
        && strpos($implementation, 'namespace EduCore\\Modules\\Staff;') !== false;
    $legacyFilesAreAliases = $legacyFilesAreAliases
        && strpos($legacy, 'class_alias(') !== false
        && strpos($legacy, 'src/Modules/Staff/bootstrap.php') !== false;
}

$page = (string) file_get_contents($root . '/admin/staff.php');
$checks = [
    'staff_implementations_are_namespaced' => $implementationsAreNamespaced,
    'legacy_staff_classes_are_aliases' => $legacyFilesAreAliases,
    'presentation_is_module_owned' => strpos(
        $page,
        '../src/Modules/Staff/Presentation/profile_view.php'
    ) !== false,
    'legacy_presentation_directory_removed' => !is_dir($root . '/classes/Presentation/Staff'),
    'module_documents_compatibility_and_rollback' => is_file($moduleRoot . '/README.md')
        && strpos((string) file_get_contents($moduleRoot . '/README.md'), 'Rollback') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
