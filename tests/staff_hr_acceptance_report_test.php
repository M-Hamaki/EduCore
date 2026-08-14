<?php

declare(strict_types=1);

require_once __DIR__ . '/../tools/staff_hr_acceptance_report.php';

$root = dirname(__DIR__);
$input = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'staff_hr_acceptance_report_' . bin2hex(random_bytes(6));
$output = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private'
    . DIRECTORY_SEPARATOR . 'staff-hr-acceptance' . DIRECTORY_SEPARATOR . 'report-test-' . bin2hex(random_bytes(6));
mkdir($input, 0770, true);
$cleanup = static function (string $path) use (&$cleanup): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? $cleanup($target) : unlink($target);
    }
    rmdir($path);
};

try {
    file_put_contents($input . DIRECTORY_SEPARATOR . 'results.json', json_encode([
        'dataset_id' => 'staff_hr_acceptance_v1',
        'dataset_version' => '2026.08.11-1',
        'results' => [[
            'dataset_id' => 'staff_hr_acceptance_v1',
            'dataset_version' => '2026.08.11-1',
            'scenario_id' => 'Q01',
            'persona' => 'super_admin',
            'status' => 'passed',
            'password' => 'must-not-survive',
            'evidence' => ['references' => ['screenshots/Q01.png'], 'cookie' => 'PHPSESSID=secret'],
        ]],
    ], JSON_THROW_ON_ERROR));
    $report = (new StaffHrAcceptanceReport())->generate($input, $output, '2026-08-11T12:00:00Z');
    $json = (string) file_get_contents($output . DIRECTORY_SEPARATOR . 'evidence-index.json');
    $markdown = (string) file_get_contents($output . DIRECTORY_SEPARATOR . 'result-report.md');
    $checks = [
        'writes_redacted_json_index' => str_contains($json, '[REDACTED]') && !str_contains($json, 'must-not-survive'),
        'writes_arabic_markdown_report' => str_contains($markdown, 'تقرير قبول منظومة شؤون العاملين'),
        'counts_pass_and_missing_scenarios' => $report['index']['counts']['passed'] === 1
            && $report['index']['counts']['missing'] === 32,
        'retains_only_safe_relative_reference' => str_contains($markdown, 'screenshots/Q01.png'),
        'uses_deterministic_report_time' => $report['index']['generated_at'] === '2026-08-11T12:00:00Z',
    ];
    foreach ($checks as $name => $passed) {
        echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    }
    exit(in_array(false, $checks, true) ? 1 : 0);
} finally {
    $cleanup($input);
    $cleanup($output);
}
