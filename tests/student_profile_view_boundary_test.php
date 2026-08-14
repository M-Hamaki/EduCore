<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/students.php');
$view = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_view.php');
$form = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_form.php');
$list = (string) file_get_contents($root . '/src/Modules/Students/Presentation/list_view.php');
$scripts = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_scripts.php');

$expectations = [
    'page_uses_internal_view' => strpos(
        $page,
        "require __DIR__ . '/../src/Modules/Students/Presentation/profile_view.php';"
    ) !== false,
    'page_no_longer_owns_profile_heading' => strpos($page, 'الملف الشخصي للطالب') === false,
    'page_uses_internal_form' => strpos(
        $page,
        "require __DIR__ . '/../src/Modules/Students/Presentation/profile_form.php';"
    ) !== false,
    'page_no_longer_owns_profile_form' => strpos($page, 'id="studentProfileForm"') === false,
    'page_uses_internal_list' => strpos(
        $page,
        "require __DIR__ . '/../src/Modules/Students/Presentation/list_view.php';"
    ) !== false,
    'page_no_longer_owns_list_surface' => strpos($page, 'class="admin-list-surface"') === false,
    'page_uses_internal_profile_scripts' => strpos(
        $page,
        "require __DIR__ . '/../src/Modules/Students/Presentation/profile_scripts.php';"
    ) !== false,
    'page_no_longer_owns_attachment_script' => strpos($page, 'function uploadStudentAttachment(') === false,
    'view_keeps_action_guard' => strpos(
        $view,
        "if (\$page_action === 'view' && \$viewStudent !== null):"
    ) !== false,
    'view_keeps_profile_heading' => strpos($view, 'الملف الشخصي للطالب') !== false,
    'view_keeps_edit_route' => strpos($view, '?action=edit&id=') !== false,
    'form_keeps_action_guard' => strpos(
        $form,
        "if (\$page_action === 'add' || \$page_action === 'edit'):"
    ) !== false,
    'form_keeps_profile_form' => strpos($form, 'id="studentProfileForm"') !== false,
    'form_keeps_csrf' => strpos($form, 'csrfField()') !== false,
    'list_keeps_branch_guard' => strpos(
        $list,
        "if (\$page_action !== 'view'"
    ) !== false,
    'list_keeps_surface' => strpos($list, 'class="admin-list-surface"') !== false,
    'list_keeps_archive_modal' => strpos($list, 'id="archiveStudentModal"') !== false
        && strpos($list, 'name="archive_student"') !== false,
    'scripts_keep_action_guard' => strpos(
        $scripts,
        "if (\$page_action === 'add' || \$page_action === 'edit'):"
    ) !== false,
    'scripts_keep_attachment_upload' => strpos($scripts, 'function uploadStudentAttachment(') !== false,
    'scripts_declare_profile_form_once' => substr_count(
        $scripts,
        "const studentProfileForm = document.getElementById('studentProfileForm');"
    ) === 1,
    'scripts_keep_edit_modal_auto_open' => strpos(
        $scripts,
        'studentProfileModalInstance.show();'
    ) !== false,
];

$failed = array_keys(array_filter($expectations, static function ($passed) {
    return !$passed;
}));

foreach ($expectations as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit($failed ? 1 : 0);
