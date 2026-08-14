<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$footers = [
    'admin' => $root . '/includes/admin_footer.php',
    'teacher' => $root . '/includes/teacher_footer.php',
    'specialist_shared_admin' => $root . '/includes/admin_footer.php',
];

$failed = [];
foreach ($footers as $role => $path) {
    $source = (string) file_get_contents($path);
    $passed = strpos($source, 'form-safety.js') !== false
        && strpos($source, 'undo_toast.php') !== false;
    echo $role . '_footer_loads_form_safety_and_undo:' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $role;
    }
}

$specialistFooterAdapter = (string) file_get_contents($root . '/includes/specialist_footer.php');
$specialistFooterRetired = strpos($specialistFooterAdapter, "require_once __DIR__ . '/admin_footer.php'") !== false
    && strpos($specialistFooterAdapter, 'form-safety.js') === false
    && strpos($specialistFooterAdapter, 'undo_toast.php') === false;
echo 'specialist_footer_is_thin_admin_adapter:' . ($specialistFooterRetired ? 'PASS' : 'FAIL') . PHP_EOL;
if (!$specialistFooterRetired) $failed[] = 'specialist_footer_adapter';

$unsharedRoleFiles = array_merge(
    glob($root . '/student/*.php') ?: [],
    glob($root . '/supervisor/*.php') ?: [],
    glob($root . '/external/*.php') ?: []
);
$unprotectedDataForms = [];
foreach ($unsharedRoleFiles as $page) {
    $source = (string) file_get_contents($page);
    if (!preg_match_all('/<form\b[^>]*method=["\']?post["\']?[^>]*>(.*?)<\/form>/is', $source, $forms)) continue;
    foreach ($forms[1] as $formBody) {
        preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $formBody, $controls);
        $eligible = array_filter($controls[0], static function (string $control): bool {
            return stripos($control, '<input') !== 0
                || !preg_match('/type=["\']?(?:hidden|password|submit|button|file)["\']?/i', $control);
        });
        if (count($eligible) >= 3 && strpos($source, 'form-safety.js') === false) $unprotectedDataForms[] = $page;
    }
}
$unsharedRolesSafe = !$unprotectedDataForms;
echo 'roles_without_shared_footer_have_no_eligible_data_forms:' . ($unsharedRolesSafe ? 'PASS' : 'FAIL') . PHP_EOL;
if (!$unsharedRolesSafe) $failed[] = 'unshared_role_data_forms';

$formSafety = (string) file_get_contents($root . '/assets/js/form-safety.js');
$undoToast = (string) file_get_contents($root . '/assets/js/undo-toast.js');
$sharedToast = (string) file_get_contents($root . '/includes/undo_toast.php');
$behaviorChecks = [
    'draft_keys_distinguish_multiple_forms' => strpos($formSafety, "identity + ':' + index") !== false,
    'modal_close_does_not_discard_draft' => strpos($formSafety, "hidden.bs.modal") === false,
    'draft_flow_uses_no_browser_confirm' => strpos($formSafety, 'window.confirm(') === false,
    'undo_ui_escapes_server_text' => strpos($undoToast, 'textContent =') !== false
        && strpos($undoToast, 'body.innerHTML') === false,
    'undo_post_sends_csrf' => strpos($undoToast, "'X-CSRF-TOKEN': token") !== false,
    'shared_undo_markup_is_single_owned_component' => strpos($sharedToast, 'id="undoToast"') !== false
        && strpos($sharedToast, 'undo-toast.js') !== false,
];
foreach ($behaviorChecks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}

exit($failed ? 1 : 0);
