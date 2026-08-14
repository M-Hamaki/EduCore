<?php

$root = dirname(__DIR__);
$generator = (string) file_get_contents($root . '/classes/ExamGenerator.php');
$renderer = (string) file_get_contents($root . '/classes/ExamTemplateRenderer.php');

$checks = [
    'generator_constructs_renderer' => strpos(
        $generator,
        'new ExamTemplateRenderer()'
    ) !== false,
    'public_generation_api_preserved' => strpos(
        $generator,
        'public function generateExamHTML('
    ) !== false && strpos($generator, 'public function generateSingleModelHTML(') !== false,
    'private_template_facade_preserved' => strpos(
        $generator,
        'private function getExamTemplate('
    ) !== false,
    'template_delegates' => strpos($generator, '$this->templateRenderer->render(') !== false,
    'renderer_has_no_generator_state_access' => strpos($renderer, '$this->duration') === false
        && strpos($renderer, '$this->language') === false
        && strpos($renderer, '$this->theme') === false,
    'renderer_keeps_models_contract' => strpos($renderer, 'const MODELS = {$modelsJson};') !== false,
    'renderer_keeps_security_flags' => strpos(
        $renderer,
        'const ANTI_CHEAT_ENABLED = {$antiCheatJs};'
    ) !== false && strpos(
        $renderer,
        'const STUDENT_INFO_ENABLED = {$studentInfoJs};'
    ) !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
