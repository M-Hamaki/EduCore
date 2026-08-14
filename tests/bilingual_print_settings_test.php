<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/print_template.php';

$profile = (string) file_get_contents(dirname(__DIR__) . '/admin/school_profile.php');
$template = (string) file_get_contents(dirname(__DIR__) . '/includes/print_template.php');

$requiredKeys = [
    'school_name_en',
    'educational_directorate_en',
    'educational_administration_en',
    'school_director_en',
    'kg_director_en',
    'primary_director_en',
    'prep_sec_director_en',
    'student_affairs_officer_en',
    'transport_movement_officer_en',
    'general_secretary_en',
    'accounts_manager_en',
];

$results = [
    'unknown_value_has_stable_fallback' => translate_setting_to_en('custom', 'Unmapped Value') === 'Unmapped Value',
    'known_stage_translates' => translate_text_to_en('المرحلة الابتدائية') === 'Primary Stage',
    'print_api_keeps_backward_compatible_defaults' => strpos(
        $template,
        "function print_header_html(\$title = '', \$subtitle = '', \$lang = 'ar', \$showPrintDate = true)"
    ) !== false,
    'custom_fields_keep_bilingual_pairs' => strpos($profile, "'name_en' => \$cNameEn") !== false
        && strpos($profile, "'value_en' => \$cValEn") !== false,
];

foreach ($requiredKeys as $key) {
    $results['profile_and_print_support_' . $key] = strpos($profile, $key) !== false
        && strpos($template, $key) !== false;
}

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
