<?php

$scriptPath = __DIR__ . '/../src/Modules/Staff/Presentation/profile_form_scripts.php';
$source = file_get_contents($scriptPath);

if ($source === false) {
    fwrite(STDERR, "Unable to read staff profile modal script.\n");
    exit(1);
}

$promotionStart = strpos($source, "document.getElementById('promotions_container')?.addEventListener('click'");
$promotionEnd = $promotionStart === false ? false : strpos($source, 'renderPromotions();', $promotionStart);
$promotionDeleteBlock = ($promotionStart === false || $promotionEnd === false)
    ? ''
    : substr($source, $promotionStart, $promotionEnd - $promotionStart);

$expectations = [
    'inline_delete_defaults_to_profile_restore' => strpos(
        $source,
        "modalEl.dataset.restoreProfileAfterConfirm = options && options.restoreProfileAfterConfirm === false"
    ) !== false,
    'confirm_preserves_restore_for_local_deletes' => strpos(
        $source,
        "if (modalEl && modalEl.dataset.restoreProfileAfterConfirm === 'false')"
    ) !== false,
    'attachment_delete_uses_ajax_and_restores_profile' => strpos(
        $source,
        "action: 'delete'"
    ) !== false && strpos(
        $source,
        "}, { restoreProfileAfterConfirm: false });"
    ) === false,
    'job_movement_delete_uses_default_restore' => strpos(
        $promotionDeleteBlock,
        "confirmStaffInlineDelete('هل تريد حذف سجل الحركة الوظيفية هذا؟'"
    ) !== false && strpos($promotionDeleteBlock, 'restoreProfileAfterConfirm: false') === false,
];

$failed = [];
foreach ($expectations as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
