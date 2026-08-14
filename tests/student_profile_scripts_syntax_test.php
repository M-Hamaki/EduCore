<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$page_action = 'edit';
$formUserId = 17;
$editStudent = (object) ['id' => 17];
$guardiansList = [['guardian_name' => 'ولي أمر اختبار']];
$relationshipLabels = ['father' => 'الأب', 'mother' => 'الأم'];
$isEditing = true;
$studentProfile = ['first_name_ar' => 'طالب'];
$oldFormInput = [];
$editExtraPhones = [
    ['type' => 'mobile', 'number' => '01000000000', 'note' => 'اختبار'],
    ['type' => 'landline', 'number' => '0200000000', 'note' => 'اختبار'],
];
$editExtraData = [['label' => 'بيان اختبار', 'value' => 'قيمة اختبار']];
$guardianExtraPhones = [[['type' => 'mobile', 'number' => '01111111111', 'note' => 'اختبار']]];
$guardianExtraData = [[['label' => 'بيان ولي الأمر', 'value' => 'قيمة']]];
$nationalityOptions = ['مصري'];
$allRelLabels = ['brother' => 'أخ', 'sister' => 'أخت'];
$studentDataScope = 'current';
$error_message = null;
$studentsBasePage = 'students.php';
$backQuery = '?student_scope=current';
$classes = [];

ob_start();
require $root . '/src/Modules/Students/Presentation/profile_scripts.php';
$rendered = (string) ob_get_clean();

if (!preg_match_all('/<script>(.*?)<\/script>/s', $rendered, $matches) || empty($matches[1])) {
    throw new RuntimeException('Student profile scripts did not render.');
}

$javascript = implode("\n", $matches[1]);
if (substr_count($javascript, "const studentProfileForm = document.getElementById('studentProfileForm');") !== 1) {
    throw new RuntimeException('Student profile form binding must have one global declaration.');
}
if (strpos($javascript, 'studentProfileModalInstance.show();') === false) {
    throw new RuntimeException('Student edit modal auto-open behavior is missing.');
}

$temporaryBase = tempnam(sys_get_temp_dir(), 'educore-student-js-');
$temporaryFile = $temporaryBase === false ? false : $temporaryBase . '.js';
if ($temporaryFile === false
    || !@rename($temporaryBase, $temporaryFile)
    || file_put_contents($temporaryFile, $javascript) === false
) {
    if ($temporaryBase !== false) {
        @unlink($temporaryBase);
    }
    throw new RuntimeException('Unable to create the temporary JavaScript syntax fixture.');
}

$pipes = [];
$process = proc_open(
    ['node', '--check', $temporaryFile],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (!is_resource($process)) {
    @unlink($temporaryFile);
    throw new RuntimeException('Node.js is required to validate student profile JavaScript syntax.');
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
@unlink($temporaryFile);

if ($exitCode !== 0) {
    throw new RuntimeException('Student profile JavaScript syntax failed: ' . trim($stderr ?: $stdout));
}

echo "Student profile JavaScript syntax test passed.\n";
