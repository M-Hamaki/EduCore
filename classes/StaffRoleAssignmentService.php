<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/StaffRoleAssignmentService.php';

if (!class_exists('StaffRoleAssignmentService', false)) {
    class_alias(\EduCore\Modules\Staff\StaffRoleAssignmentService::class, 'StaffRoleAssignmentService');
}
