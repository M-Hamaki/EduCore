<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/staff.php');
$mapper = (string) file_get_contents($root . '/src/Modules/Staff/StaffProfileRequestMapper.php');
$command = (string) file_get_contents($root . '/src/Modules/Staff/StaffProfileCommandService.php');

$checks = [
    'page_builds_mapper_once' => substr_count($page, 'new StaffProfileRequestMapper(') === 1,
    'command_maps_add_and_edit' => substr_count(
        $command,
        '$this->mapper->map($input, $allowedDepartments)'
    ) === 2,
    'page_no_longer_duplicates_profile_fields' => strpos($page, '$profileFields = [') === false,
    'mapper_does_not_read_superglobals' => strpos($mapper, '$_POST') === false
        && strpos($mapper, '$_FILES') === false
        && strpos($mapper, '$_SESSION') === false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
