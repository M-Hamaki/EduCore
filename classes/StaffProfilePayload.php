<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/bootstrap.php';

if (!class_exists('StaffProfilePayload', false)) {
    class_alias(\EduCore\Modules\Staff\StaffProfilePayload::class, 'StaffProfilePayload');
}
