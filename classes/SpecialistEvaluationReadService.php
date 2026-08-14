<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/BehaviorEvaluation/SpecialistEvaluationReadService.php';
if (!class_exists('SpecialistEvaluationReadService', false)) {
    class_alias(\EduCore\Modules\BehaviorEvaluation\SpecialistEvaluationReadService::class, 'SpecialistEvaluationReadService');
}
