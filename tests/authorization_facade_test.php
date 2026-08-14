<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/AuthorizationFacade.php';

$allowCustom = static fn(string $role): bool => $role === 'registrar';
$denyCustom = static fn(string $role): bool => false;

$results = [
    'admin_allowed_as_admin' => AuthorizationFacade::allowsRequiredRole(['role' => 'admin'], 'admin', $denyCustom),
    'super_admin_allowed_as_admin' => AuthorizationFacade::allowsRequiredRole(['role' => 'super_admin'], 'admin', $denyCustom),
    'custom_admin_role_delegates_page_decision' => AuthorizationFacade::allowsRequiredRole(['role' => 'registrar'], 'admin', $allowCustom),
    'custom_admin_role_denied_without_page' => !AuthorizationFacade::allowsRequiredRole(['role' => 'registrar'], 'admin', $denyCustom),
    'teacher_allowed_as_teacher' => AuthorizationFacade::allowsRequiredRole(['role' => 'teacher'], 'teacher'),
    'specialist_allowed_as_specialist' => AuthorizationFacade::allowsRequiredRole(['role' => 'specialist'], 'specialist'),
    'student_denied_admin' => !AuthorizationFacade::allowsRequiredRole(['role' => 'student'], 'admin', $denyCustom),
    'external_teacher_denied_teacher_portal' => !AuthorizationFacade::allowsRequiredRole(['role' => 'external_teacher'], 'teacher'),
    'supervisor_teacher_mode_effective_teacher' => AuthorizationFacade::allowsRequiredRole([
        'role' => 'supervisor', 'active_mode' => 'teacher',
    ], 'teacher'),
    'supervisor_mode_is_not_specialist' => !AuthorizationFacade::allowsRequiredRole([
        'role' => 'supervisor', 'active_mode' => 'supervisor',
    ], 'specialist'),
    'teacher_supervisor_mode_is_not_specialist' => !AuthorizationFacade::allowsRequiredRole([
        'role' => 'teacher', 'is_supervisor' => 1, 'active_mode' => 'supervisor',
    ], 'specialist'),
    'supervisor_without_mode_not_specialist' => !AuthorizationFacade::allowsRequiredRole([
        'role' => 'supervisor',
    ], 'specialist'),
    'admin_page_has_full_access' => AuthorizationFacade::allowsAdminPage('admin', 'anything.php', null),
    'custom_role_allowed_list' => AuthorizationFacade::allowsAdminPage('registrar', 'students.php', ['index.php', 'students.php']),
    'custom_role_page_denied' => !AuthorizationFacade::allowsAdminPage('registrar', 'staff.php', ['index.php', 'students.php']),
    'unknown_role_denied' => !AuthorizationFacade::allowsAdminPage('unknown', 'index.php', []),
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
