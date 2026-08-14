<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/staff.php');
$view = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/profile_view.php');
$form = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/profile_form.php');
$formScripts = (string) file_get_contents(
    $root . '/src/Modules/Staff/Presentation/profile_form_scripts.php'
);
$list = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/list_view.php');
$scripts = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/page_scripts.php');

$checks = [
    'page_includes_all_fragments' => strpos($page, 'src/Modules/Staff/Presentation/profile_view.php') !== false
        && strpos($page, 'src/Modules/Staff/Presentation/profile_form.php') !== false
        && strpos($page, 'src/Modules/Staff/Presentation/list_view.php') !== false
        && strpos($page, 'src/Modules/Staff/Presentation/page_scripts.php') !== false,
    'page_no_longer_owns_profile_form' => strpos($page, 'id="staffForm"') === false,
    'page_no_longer_owns_list' => strpos($page, 'قائمة الموظفين الرئيسية') === false,
    'view_branch_self_contained' => strpos($view, "if (\$action === 'view'") !== false
        && str_ends_with(trim($view), '<?php endif; ?>'),
    'form_branch_self_contained' => strpos(
        $form,
        "if (\$action === 'add' || \$action === 'edit'):"
    ) !== false && str_ends_with(trim($form), '<?php endif; ?>'),
    'form_contract_preserved' => strpos($form, 'id="staffForm"') !== false
        && strpos($form, 'csrfField()') !== false
        && substr_count($form, 'data-bs-target="#pane-') === 5,
    'form_scripts_split_and_preserved' => strpos(
        $form,
        "require __DIR__ . '/profile_form_scripts.php';"
    ) !== false && strpos($formScripts, 'const staffForm =') !== false
        && strpos($formScripts, 'statusHistoryData') !== false,
    'list_branch_self_contained' => str_starts_with(
        trim($list),
        "<?php if (\$action !== 'view'):"
    ) && str_ends_with(trim($list), '<?php endif; ?>'),
    'list_has_no_database_query' => strpos($list, '->prepare(') === false
        && strpos($list, 'readAllStaffWithProfiles(') === false,
    'scripts_preserved' => strpos($scripts, 'function openDeleteStaffModal(') !== false
        && strpos($scripts, 'function applyColumnVisibility(') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
