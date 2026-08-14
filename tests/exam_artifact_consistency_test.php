<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ExamGenerator.php';

$generator = new ExamGenerator('ar');
$generator->setQuestions([
    'multiple_choice' => [
        [
            'question' => 'السؤال الأول </script><script>window.bad=1</script>',
            'options' => ['ألف', 'باء', 'جيم', 'دال'],
            'correct_answer' => 1,
        ],
        [
            'question' => 'السؤال الثاني',
            'options' => ['واحد', 'اثنان', 'ثلاثة', 'أربعة'],
            'correct_answer' => 2,
        ],
    ],
    'true_false' => [
        ['statement' => 'العبارة الأولى', 'correct_answer' => true],
        ['statement' => 'العبارة الثانية', 'correct_answer' => false],
    ],
    'graduated' => [
        ['question' => 'فسر النتيجة', 'model_answer' => 'إجابة نموذجية'],
    ],
]);
$generator->setMCCount(2);
$generator->setTFCount(2);
$generator->setEssayCount(1);
$generator->setModelsCount(4);
$generator->setModelType('shuffle');

$fullExam = (string) $generator->generateExamHTML('<img src=x onerror=window.bad=2>');
$models = ExamGenerator::extractPreparedModels($fullExam);
$filteredB = (string) ExamGenerator::filterExamHtmlToModel($fullExam, 'B');
$filteredModels = ExamGenerator::extractPreparedModels($filteredB);

$answerGenerator = new ExamGenerator('ar');
$answerGenerator->setPreparedModels($models);
$answerB = (string) $answerGenerator->generateAnswerKeyHTML('B', 'اختبار');
$allAnswers = (string) $answerGenerator->generateAllAnswerKeysHTML('اختبار');

$firstMultipleChoice = null;
foreach ($models['B'] ?? [] as $question) {
    if (($question['type'] ?? '') === 'multiple_choice') {
        $firstMultipleChoice = $question;
        break;
    }
}
$expectedCorrect = $firstMultipleChoice
    ? (string) ($firstMultipleChoice['options'][$firstMultipleChoice['correct']] ?? '')
    : '';

$checks = [
    'all_saved_models_are_extractable' => array_keys($models) === ['A', 'B', 'C', 'D'],
    'single_model_export_contains_only_requested_saved_model' =>
        array_keys($filteredModels) === ['B']
        && ($filteredModels['B'] ?? null) === ($models['B'] ?? null)
        && strpos($filteredB, 'const SINGLE_MODEL = true;') !== false,
    'single_answer_key_matches_saved_model' =>
        $expectedCorrect !== ''
        && strpos($answerB, htmlspecialchars($expectedCorrect, ENT_QUOTES, 'UTF-8')) !== false,
    'all_answer_keys_include_every_saved_model' =>
        strpos($allAnswers, 'data-model="A"') !== false
        && strpos($allAnswers, 'data-model="B"') !== false
        && strpos($allAnswers, 'data-model="C"') !== false
        && strpos($allAnswers, 'data-model="D"') !== false,
    'exam_title_and_json_block_script_breakout' =>
        strpos($fullExam, '<img src=x onerror=window.bad=2>') === false
        && strpos($fullExam, '</script><script>window.bad=1</script>') === false
        && strpos($fullExam, '&lt;img src=x onerror=window.bad=2&gt;') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
