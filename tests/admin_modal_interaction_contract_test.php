<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$main = (string) file_get_contents($root . '/assets/js/main.js');
$footer = (string) file_get_contents($root . '/includes/admin_footer.php');
$undoToast = (string) file_get_contents($root . '/assets/js/undo-toast.js');
$unified = (string) file_get_contents($root . '/assets/css/admin-unified.css');
$buttons = (string) file_get_contents($root . '/assets/css/buttons.css');
$studentForm = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_form.php');
$staffForm = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/profile_form.php');

$checks = [
    'nested_confirmation_hides_parent_first' => strpos($main, 'parentModal.hide();') !== false
        && strpos($main, "parentModalElement.addEventListener('hidden.bs.modal', showConfirmationModal") !== false,
    'nested_confirmation_restores_parent' => strpos($main, 'function restoreParentModal()') !== false
        && strpos($main, "modalElement.addEventListener('hidden.bs.modal', restoreParentModal") !== false,
    'responsive_fullscreen_modal_is_not_draggable' => strpos($main, "dialog.classList.contains('modal-fullscreen-lg-down')") !== false,
    'undo_keyboard_supports_arabic_layout' => strpos($undoToast, "['z', 'Z', 'ئ'].includes(event.key)") !== false,
    'undo_state_can_be_refreshed_after_ajax' => strpos($undoToast, 'window.checkUndoState = function (forceShow)') !== false
        && strpos($footer, 'undo_toast.php') !== false,
    'profile_scroll_styles_are_centralized' => strpos($unified, '.student-profile-tab-scroll') !== false
        && strpos($unified, '.staff-profile-tab-scroll') !== false,
    'cancel_links_follow_modal_order' => strpos($unified, '[data-modal-cancel]') !== false
        && strpos($studentForm, 'data-modal-cancel') !== false
        && strpos($staffForm, 'data-modal-cancel') !== false,
    'button_style_has_single_owner' => strpos($buttons, '.btn-profile-danger') !== false
        && strpos($unified, '.btn-profile-danger') === false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
