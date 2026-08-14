<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/Staff/StaffRoleCapabilityResolver.php';

if (!class_exists('StaffRoleCapabilityResolver', false)) {
    class_alias(\EduCore\Modules\Staff\StaffRoleCapabilityResolver::class, 'StaffRoleCapabilityResolver');
}
