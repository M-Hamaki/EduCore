<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/StaffAcademicScopeService.php';

if (!class_exists('StaffAcademicScopeService', false)) {
    class_alias(\EduCore\Modules\Staff\StaffAcademicScopeService::class, 'StaffAcademicScopeService');
}
