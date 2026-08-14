<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/LessonPrepPageContext.php';

$db = new PDO('sqlite::memory:');
$context = LessonPrepPageContext::load(
    $db,
    static fn(PDO $pdo): string => 'configured',
    static fn(PDO $pdo): array => [
        ['id' => 1, 'template_type' => 'brand_template'],
        ['id' => 2, 'template_type' => 'design', 'pptx_local_path' => '/tmp/template.pptx'],
        ['id' => 3, 'template_type' => 'design'],
    ],
    static fn(PDO $pdo): array => [['id' => 4]]
);

$fallback = LessonPrepPageContext::load(
    $db,
    static function (PDO $pdo): string { throw new RuntimeException('api'); },
    static function (PDO $pdo): array { throw new RuntimeException('canva'); },
    static function (PDO $pdo): array { throw new RuntimeException('ppt'); }
);

$checks = [
    'api_key' => $context['has_api_key'] === true,
    'canva_filter' => array_column($context['canva_templates'], 'id') === [1, 2],
    'internal_templates' => array_column($context['internal_ppt_templates'], 'id') === [4],
    'failure_fallback' => $fallback === [
        'has_api_key' => false,
        'canva_templates' => [],
        'internal_ppt_templates' => [],
    ],
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
