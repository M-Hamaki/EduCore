<?php

$root = dirname(__DIR__);
require_once $root . '/config/ai_prompts.php';

$page = (string) file_get_contents($root . '/config/ai_prompts.php');
$trait = (string) file_get_contents($root . '/config/AIPrompts/ContentPrompts.php');
$reflection = new ReflectionClass(AIPrompts::class);
$methodNames = array_map(
    static fn(ReflectionMethod $method): string => $method->getName(),
    $reflection->getMethods()
);

$requiredMethods = [
    'getLessonPrepPrompt', 'buildPhasesJson', 'buildAdditionalElementsJson',
    'getAdditionalInstructions', 'getQuestionBankPrompt', 'getVisualMaterialsPrompt',
    'getImageExtractionPrompt', 'getPDFExtractionPrompt', 'getClassActivitiesPrompt',
    'getMindMapPrompt', 'getLessonSummaryPrompt', 'buildFullPrompt',
    'getCustomContentPrompt', 'getEducationalStoriesPrompt', 'getPowerPointSlidesPrompt',
];

$checks = [
    'all_prompt_methods_preserved' => count(array_diff($requiredMethods, $methodNames)) === 0,
    'entrypoint_loads_content_trait' => strpos($page, "AIPrompts/ContentPrompts.php") !== false
        && strpos($page, 'use AIPromptsContentTrait;') !== false,
    'content_methods_are_trait_owned' => strpos($trait, 'function getLessonSummaryPrompt') !== false
        && strpos($trait, 'function getPowerPointSlidesPrompt') !== false,
    'lesson_prompt_contract' => str_contains(AIPrompts::getLessonPrepPrompt('ar', 45), '45'),
    'full_prompt_appends_content' => str_starts_with(AIPrompts::buildFullPrompt('X', 'TEST'), 'X')
        && str_ends_with(AIPrompts::buildFullPrompt('X', 'TEST'), 'TEST'),
    'powerpoint_limit_is_embedded' => str_contains(AIPrompts::getPowerPointSlidesPrompt('en', 7), '7'),
    'entrypoint_below_large_file_limit' => substr_count($page, "\n") + 1 < 2000,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
