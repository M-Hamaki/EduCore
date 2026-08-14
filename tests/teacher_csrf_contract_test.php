<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jsonEndpoints = [
    'teacher/ajax/ai_chat.php',
    'teacher/ajax/generate_all_answer_keys.php',
    'teacher/ajax/generate_all_models.php',
    'teacher/ajax/generate_answer_key.php',
    'teacher/ajax/generate_single_model.php',
    'teacher/ajax/grade_essays.php',
    'teacher/ajax/lesson_notifications.php',
    'teacher/ajax/publish_exam.php',
    'teacher/ajax/save_mindmap.php',
    'teacher/ajax/search_images.php',
];
$checks = [];
foreach ($jsonEndpoints as $relativePath) {
    $source = (string)file_get_contents($root . '/' . $relativePath);
    $guard = strpos($source, 'requireCsrfToken();');
    $database = strpos($source, '$database = new Database();');
    $key = str_replace(['/', '.php'], ['_', ''], $relativePath);
    $checks[$key . '_guard_before_database'] = $guard !== false
        && ($database === false || $guard < $database);
}

$pageForms = [
    'teacher/attendance.php' => 1,
    'teacher/index.php' => 1,
    'teacher/lesson_archive.php' => 1,
    'teacher/training.php' => 1,
    'teacher/training_course.php' => 2,
];
foreach ($pageForms as $relativePath => $expectedCount) {
    $source = (string)file_get_contents($root . '/' . $relativePath);
    preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>.*?<\/form>/is', $source, $matches);
    $forms = $matches[0] ?? [];
    $tokenized = array_filter($forms, static fn (string $form): bool => strpos($form, 'csrfField()') !== false
        || strpos($form, 'name="csrf_token"') !== false);
    $key = str_replace(['/', '.php'], ['_', ''], $relativePath);
    $checks[$key . '_all_forms_tokenized'] = count($forms) === $expectedCount
        && count($tokenized) === $expectedCount;
    $checks[$key . '_guard_present'] = strpos($source, 'requireCsrfPost();') !== false;
}

$lessonPrep = (string)file_get_contents($root . '/teacher/lesson_prep.php');
$lessonPrep .= (string)file_get_contents($root . '/classes/Presentation/LessonPrep/scripts_part_one.php');
$lessonPrep .= (string)file_get_contents($root . '/classes/Presentation/LessonPrep/scripts_part_two.php');
$lessonView = (string)file_get_contents($root . '/teacher/lesson_view.php');
// أزيلت أداة "البحث عن صور من الإنترنت" وأداة "توليد صور بالذكاء الاصطناعي" من تبويب
// المواد البصرية بناءً على طلب المستخدم؛ انخفض عدد استدعاءات json_encode لـ csrf_token بمقدار
// واحد في كلٍ من lesson_prep و lesson_view (كانت searchWebImages تستخدمها).
$checks['lesson_prep_json_calls_send_tokens'] = substr_count($lessonPrep, 'csrf_token: <?php echo json_encode') >= 6;
$checks['lesson_view_json_calls_send_tokens'] = substr_count($lessonView, 'csrf_token: <?php echo json_encode') >= 5;

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
exit($failed ? 1 : 0);
