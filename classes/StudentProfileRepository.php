<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Students/bootstrap.php';

if (!class_exists('StudentProfileRepository', false)) {
    class_alias(\EduCore\Modules\Students\StudentProfileRepository::class, 'StudentProfileRepository');
}
