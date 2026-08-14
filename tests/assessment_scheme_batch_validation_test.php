<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/AssessmentSchemeBatchService.php';

function assessment_scheme_expect_invalid(callable $callback): bool
{
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        return true;
    }
    return false;
}

$db = new PDO('sqlite::memory:');
$annualPolicy = new AssessmentAnnualPolicyService($db);
$batchService = new AssessmentSchemeBatchService($db);
$normalizeSettings = new ReflectionMethod(AssessmentSchemeBatchService::class, 'normalizeSettings');
$normalizeSettings->setAccessible(true);

$checks = [
    'annual_weights_reject_non_numeric_values' => assessment_scheme_expect_invalid(
        static fn() => $annualPolicy->assertWeightsAreValid([1 => '50', 2 => 'not-a-number'])
    ),
    'annual_weight_total_rejects_non_numeric_values' => !$annualPolicy->weightsTotalIsValid([1 => '100', 2 => 'invalid']),
    'scheme_total_rejects_numeric_prefixes' => assessment_scheme_expect_invalid(
        static fn() => $normalizeSettings->invoke($batchService, ['total_grade' => '100abc', 'pass_grade' => '50'], 'خطة اختبار')
    ),
    'scheme_pass_grade_rejects_non_numeric_values' => assessment_scheme_expect_invalid(
        static fn() => $normalizeSettings->invoke($batchService, ['total_grade' => '100', 'pass_grade' => 'invalid'], 'خطة اختبار')
    ),
    'valid_numeric_strings_remain_supported' => (static function () use ($normalizeSettings, $batchService): bool {
        $settings = $normalizeSettings->invoke($batchService, ['total_grade' => '100.5', 'pass_grade' => '50.25'], 'خطة اختبار');
        return $settings['total_grade'] === 100.5 && $settings['pass_grade'] === 50.25;
    })(),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
