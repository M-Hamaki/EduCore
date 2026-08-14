<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Attendance/SpecialistAttendanceReadService.php';
if (!class_exists('SpecialistAttendanceReadService', false)) {
    class_alias(\EduCore\Modules\Attendance\SpecialistAttendanceReadService::class, 'SpecialistAttendanceReadService');
}
