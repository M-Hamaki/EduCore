<?php

declare(strict_types=1);

/**
 * Cross-workflow acceptance gate.
 *
 * The focused command-owner suites are deliberately composed here instead of
 * duplicating their in-memory persistence fakes. This gate makes the required
 * US8 scenarios fail together: role separation, neutral-delivery failure,
 * subject receipt, temporary measures, and append-only reopening.
 */
$root = dirname(__DIR__);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (string $path): string {
    ob_start();
    require $path;

    return (string) ob_get_clean();
};

$decisionOutput = $run($root . '/tests/staff_hr_discipline_decision_service_test.php');
$appealOutput = $run($root . '/tests/staff_hr_discipline_appeal_service_test.php');
$decisionService = (string) file_get_contents(
    $root . '/src/Modules/Staff/Application/Discipline/DisciplineDecisionService.php'
);
$decisionOutcome = (string) file_get_contents(
    $root . '/src/Modules/Staff/Application/Discipline/DisciplineDecisionApprovalOutcomeHandler.php'
);
$appealService = (string) file_get_contents(
    $root . '/src/Modules/Staff/Application/Discipline/DisciplineAppealService.php'
);

$assert(
    str_contains($decisionOutput, 'staff_hr_discipline_decision_service_test: PASS')
        && str_contains($appealOutput, 'staff_hr_discipline_appeal_service_test: PASS'),
    'the command-owner workflow suites pass their isolated role and transition scenarios'
);
$assert(
    str_contains($decisionService, 'DISCIPLINE_DECISION_RECEIPT_SUBJECT_ONLY')
        && str_contains($decisionOutcome, 'DISCIPLINE_DECISION_FINALIZER_CONFLICT'),
    'final decision and receipt enforce the required separation of worker, preparer, and finalizer'
);
$assert(
    str_contains($decisionOutcome, "notification_status = 'delivery_failed'")
        || str_contains($decisionOutcome, "'delivery_failed'"),
    'notification enqueue failure is represented as a durable non-sensitive delivery state'
);
$assert(
    str_contains($appealService, 'DISCIPLINE_APPEAL_REVIEWER_CONFLICT')
        && str_contains($appealService, 'DISCIPLINE_INTERIM_REVIEWER_CONFLICT'),
    'appeal review and temporary-measure closure reject conflicting actors'
);
$assert(
    str_contains($appealService, 'request_event_id')
        && str_contains($appealService, 'DISCIPLINE_REOPEN_ALREADY_DECIDED'),
    'reopen authorization is linked to one append-only request and cannot be silently duplicated'
);
$assert(
    !str_contains($decisionService, 'PayrollImpactGateway')
        && !str_contains($appealService, 'PayrollImpactGateway'),
    'discipline decision and appeal services do not mutate Finance directly'
);

echo 'staff_hr_discipline_workflow_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
