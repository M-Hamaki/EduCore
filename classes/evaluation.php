<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/BehaviorEvaluation/bootstrap.php';

if (!class_exists('Evaluation', false)) {
    class_alias(\EduCore\Modules\BehaviorEvaluation\Evaluation::class, 'Evaluation');
}
