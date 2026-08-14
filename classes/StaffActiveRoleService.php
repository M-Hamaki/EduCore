<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/StaffActiveRoleService.php';

if (!class_exists('StaffActiveRoleService', false)) {
    class_alias(\EduCore\Modules\Staff\StaffActiveRoleService::class, 'StaffActiveRoleService');
}
