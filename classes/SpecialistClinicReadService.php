<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Clinic/SpecialistClinicReadService.php';
if (!class_exists('SpecialistClinicReadService', false)) {
    class_alias(\EduCore\Modules\Clinic\SpecialistClinicReadService::class, 'SpecialistClinicReadService');
}
