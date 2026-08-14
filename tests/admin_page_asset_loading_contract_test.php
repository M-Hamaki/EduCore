<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$footer = (string) file_get_contents($root . '/includes/admin_footer.php');
$header = (string) file_get_contents($root . '/includes/admin_header.php');
$students = (string) file_get_contents($root . '/admin/students.php');
$staff = (string) file_get_contents($root . '/admin/staff.php');
$studentList = (string) file_get_contents($root . '/src/Modules/Students/Presentation/list_view.php');
$staffScripts = (string) file_get_contents($root . '/src/Modules/Staff/Presentation/page_scripts.php');
$dashboardScript = (string) file_get_contents($root . '/assets/js/premium-dashboard.js');
$dashboardStyles = (string) file_get_contents($root . '/assets/css/premium-dashboard.css');
$pushInit = (string) file_get_contents($root . '/includes/push_init.php');
$pushScript = (string) file_get_contents($root . '/assets/js/push-notifications.js');
$dataTableDefaults = (string) file_get_contents($root . '/assets/js/datatables-ar.js');
$teacherFooter = (string) file_get_contents($root . '/includes/teacher_footer.php');

$checks = [
    'footer_preserves_asset_defaults' => strpos($footer, "'datatables' => true") !== false
        && strpos($footer, "'sortable' => true") !== false
        && strpos($footer, "'instant_attachment_upload' => true") !== false
        && strpos($footer, "'dashboard_sortable' => true") !== false,
    'footer_excludes_prohibited_sweetalert' => stripos($footer, 'sweetalert') === false,
    'footer_guards_optional_assets' => strpos($footer, "if (\$adminAssetOptions['datatables'])") !== false
        && strpos($footer, "if (\$adminAssetOptions['sortable'])") !== false
        && strpos($footer, "if (\$adminAssetOptions['instant_attachment_upload'])") !== false
        && strpos($footer, "if (\$adminAssetOptions['dashboard_sortable'])") !== false,
    'header_preconnects_shared_external_origins' => strpos($header, 'rel="preconnect" href="https://cdn.jsdelivr.net"') !== false
        && strpos($header, 'rel="preconnect" href="https://cdn.datatables.net"') !== false
        && strpos($header, 'rel="preconnect" href="https://cdnjs.cloudflare.com"') !== false
        && strpos($header, 'rel="preconnect" href="https://fonts.googleapis.com"') !== false
        && strpos($header, 'rel="preconnect" href="https://fonts.gstatic.com"') !== false
        && strpos($header, 'rel="preconnect" href="https://code.jquery.com"') !== false,
    'students_disable_unused_assets' => strpos($students, "'sweetalert' => false") !== false
        && strpos($students, "'datatables' => \$page_action !== 'view'") !== false
        && strpos($students, "'sortable' => false") !== false
        && strpos($students, "'dashboard_sortable' => false") !== false,
    'students_keep_upload_helper_for_forms' => strpos(
        $students,
        "'instant_attachment_upload' => !\$studentProfilePendingMode && (\$page_action === 'add' || \$page_action === 'edit')"
    ) !== false,
    'staff_disable_unused_assets' => strpos($staff, "'sweetalert' => false") !== false
        && strpos($staff, "'datatables' => \$action !== 'view'") !== false
        && strpos($staff, "'sortable' => false") !== false
        && strpos($staff, "'dashboard_sortable' => false") !== false,
    'staff_server_table_helper_is_list_only' => strpos($staffScripts, 'if ($staffServerSide)') !== false
        && strpos($staffScripts, 'admin-server-side-table.js') !== false,
    'staff_keep_upload_helper_for_forms' => strpos(
        $staff,
        "'instant_attachment_upload' => \$action === 'add' || \$action === 'edit'"
    ) !== false,
    'all_tables_load_50_row_defaults_with_all_option' => strpos($footer, "asset_url('../assets/js/datatables-ar.js')") !== false
        && strpos($teacherFooter, "asset_url('../assets/js/datatables-ar.js')") !== false
        && strpos($dataTableDefaults, 'pageLength: 50') !== false
        && strpos($dataTableDefaults, '500, -1') !== false
        && strpos($dataTableDefaults, "500, 'الكل'") !== false
        && strpos($studentList, 'dtOptions: { pageLength: 50 }') !== false
        && strpos($staffScripts, 'dtOptions: { pageLength: 50 }') !== false,
    'reduced_motion_skips_decorative_animation' => strpos(
        $dashboardScript,
        "matchMedia('(prefers-reduced-motion: reduce)').matches"
    ) !== false
        && strpos($dashboardStyles, '@media (prefers-reduced-motion: reduce)') !== false
        && strpos($dashboardStyles, '.admin-page .animate-up') !== false,
    'push_worker_uses_deployment_base_path' => strpos($pushInit, 'request_app_base_path()') !== false
        && strpos($pushInit, 'window.PUSH_BASE_URL = <?php echo json_encode($pushBasePath') !== false,
    'push_feedback_uses_shared_bootstrap_alert' => strpos($pushScript, "typeof showAlert === 'function'") !== false
        && strpos($pushScript, 'Swal') === false,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "Admin page asset loading contract test passed.\n";
