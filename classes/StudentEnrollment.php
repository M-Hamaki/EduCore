<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Students/bootstrap.php';

if (!class_exists('StudentEnrollment', false)) {
    class_alias(\EduCore\Modules\Students\StudentEnrollment::class, 'StudentEnrollment');
}
