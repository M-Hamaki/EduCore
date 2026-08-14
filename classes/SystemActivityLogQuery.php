<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/Operations/Audit/SystemActivityLogQuery.php';

if (!class_exists('SystemActivityLogQuery', false)) {
    class_alias(\EduCore\Modules\Operations\Audit\SystemActivityLogQuery::class, 'SystemActivityLogQuery');
}
