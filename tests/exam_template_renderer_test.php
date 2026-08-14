<?php

require_once __DIR__ . '/../classes/ExamGenerator.php';

$generator = new ExamGenerator('ar');
$generator->setQuestions([
    'multiple_choice' => [[
        'question' => 'ما ناتج ١ + ١؟',
        'options' => ['١', '٢', '٣', '٤'],
        'correct_answer' => 1,
    ]],
]);
$generator->setMCCount(1);
$generator->setTFCount(0);
$generator->setModelsCount(1);
$generator->setDuration(35);
$generator->setPassingPercentage(60);
$generator->setAntiCheatEnabled(false);
$generator->setStudentInfoEnabled(false);
$generator->setTheme('ocean');
$html = $generator->generateExamHTML('اختبار الفصل');

$checks = [
    'html_generated' => is_string($html) && $html !== '',
    'title_preserved' => strpos($html, 'اختبار الفصل') !== false,
    'question_shape_preserved' => strpos($html, 'ما ناتج ١ + ١؟') !== false,
    'language_and_direction_preserved' => strpos($html, '<html lang="ar" dir="rtl"') !== false,
    'theme_preserved' => strpos($html, 'data-theme="ocean"') !== false,
    'duration_preserved' => strpos($html, '35:00') !== false,
    'anti_cheat_preserved' => strpos($html, 'const ANTI_CHEAT_ENABLED = false;') !== false,
    'student_info_preserved' => strpos($html, 'const STUDENT_INFO_ENABLED = false;') !== false,
    'actual_count_preserved' => $generator->getActualQuestionCount() === 1,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
