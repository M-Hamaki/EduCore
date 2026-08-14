<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/classes/Ajax/DataTableActionResponder.php';
$assetPath = $root . '/assets/js/datatable-state.js';
$asset = is_file($assetPath) ? (string) file_get_contents($assetPath) : '';
$dashboardCss = (string) file_get_contents($root . '/assets/css/premium-dashboard.css');
$staffAccounts = (string) file_get_contents($root . '/admin/staff_accounts.php');
$staffSingleModals = (string) file_get_contents($root . '/includes/staff_single_modals.php');
$studentsPage = (string) file_get_contents($root . '/admin/students.php');
$studentListView = (string) file_get_contents($root . '/src/Modules/Students/Presentation/list_view.php');
$studentListPresenter = (string) file_get_contents($root . '/src/Modules/Students/Presentation/StudentListDataTablePresenter.php');
$studentProfileForm = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_form.php');
$actionResponder = (string) file_get_contents($root . '/classes/Ajax/DataTableActionResponder.php');
$adminHeader = (string) file_get_contents($root . '/includes/admin_header.php');
$teacherHeader = (string) file_get_contents($root . '/includes/teacher_header.php');
$specialistHeader = (string) file_get_contents($root . '/includes/specialist_header.php');
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
$datatableAuditCommands = $composer['scripts']['datatable-state-audit'] ?? null;
$agents = (string) file_get_contents($root . '/AGENTS.md');
$ajaxNegotiationPasses = DataTableActionResponder::accepts(
    ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
    ['datatable_ajax' => '1']
);
$ajaxNegotiationRejectsOrdinaryRequest = !DataTableActionResponder::accepts(
    ['HTTP_X_REQUESTED_WITH' => '', 'HTTP_ACCEPT' => 'text/html'],
    ['datatable_ajax' => '1']
);

$standaloneTeacherPages = [
    'teacher/exam_results.php',
    'teacher/evaluations.php',
    'teacher/lesson_archive.php',
];

$standaloneCoverage = true;
foreach ($standaloneTeacherPages as $relativePath) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    $statePosition = strpos($source, 'assets/js/datatable-state.js');
    $dataTablesPosition = strpos($source, 'jquery.dataTables.min.js');
    $standaloneCoverage = $standaloneCoverage
        && $statePosition !== false
        && $dataTablesPosition !== false
        && $statePosition < $dataTablesPosition;
}

$embeddedInitializerOwners = [
    'src/Modules/Students/Presentation/list_view.php' => 'admin/students.php',
    'src/Modules/Staff/Presentation/page_scripts.php' => 'admin/staff.php',
    'classes/Presentation/ClassLists/page_scripts.php' => 'admin/class_lists.php',
];
$uncoveredEmbeddedInitializers = [];
foreach ($embeddedInitializerOwners as $fragmentPath => $ownerPath) {
    $fragmentSource = (string) file_get_contents($root . '/' . $fragmentPath);
    $ownerSource = (string) file_get_contents($root . '/' . $ownerPath);
    $ownerUsesSharedHeader = strpos($ownerSource, 'admin_header.php') !== false
        || strpos($ownerSource, 'teacher_header.php') !== false
        || strpos($ownerSource, 'specialist_header.php') !== false;
    if (preg_match('/(?:\.DataTable\s*\(|AdminServerSideTable\.init\s*\()/', $fragmentSource) !== 1
        || strpos($ownerSource, basename($fragmentPath)) === false
        || !$ownerUsesSharedHeader) {
        $uncoveredEmbeddedInitializers[] = $fragmentPath . '=>' . $ownerPath;
    }
}

$uncoveredPages = [];
foreach (['', 'admin', 'teacher', 'student', 'specialist', 'supervisor'] as $directory) {
    $pageDirectory = $directory === '' ? $root : $root . '/' . $directory;
    foreach (glob($pageDirectory . '/*.php') ?: [] as $path) {
        $source = (string) file_get_contents($path);
        if (preg_match('/(?:\.DataTable\s*\(|AdminServerSideTable\.init\s*\()/', $source) !== 1) {
            continue;
        }

        $coveredBySharedHeader = strpos($source, 'admin_header.php') !== false
            || strpos($source, 'teacher_header.php') !== false
            || strpos($source, 'specialist_header.php') !== false;
        $coveredDirectly = strpos($source, 'assets/js/datatable-state.js') !== false;
        if (!$coveredBySharedHeader && !$coveredDirectly) {
            $uncoveredPages[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
        }
    }
}

$localStateOwners = [];
foreach (['admin', 'assets', 'includes', 'teacher', 'student', 'specialist', 'supervisor', 'classes', 'src'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'js'], true)) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_ends_with($path, '/assets/js/datatable-state.js')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (strpos($source, 'stateSaveCallback') !== false
            || strpos($source, 'stateLoadCallback') !== false) {
            $localStateOwners[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
        }
    }
}

$checks = [
    'shared_asset_exists' => $asset !== '',
    'central_action_return_contract' => strpos($asset, 'stateSave: true') !== false
        && strpos($asset, 'stateDuration: -1') !== false
        && strpos($asset, 'window.sessionStorage') !== false
        && strpos($asset, 'stateSaveCallback: rememberState') !== false
        && strpos($asset, 'stateLoadCallback: loadReturnState') !== false,
    'state_is_scoped_and_versioned' => strpos($asset, 'educore:datatable-return:v2:') !== false
        && strpos($asset, 'meta[name="csrf-token"]') !== false
        && strpos($asset, 'normalizedContext()') !== false
        && strpos($asset, 'schemaFingerprint(settings)') !== false,
    'state_is_one_shot_and_action_bound' => strpos($asset, "addEventListener('submit'") !== false
        && strpos($asset, "method !== 'post'") !== false
        && strpos($asset, 'formShouldCapture(form)') !== false
        && strpos($asset, 'captureReturnStates(form);') !== false
        && strpos($asset, 'removeStoredState(sessionStore, key, saved);') !== false
        && strpos($asset, 'data-datatable-return-url') !== false
        && strpos($asset, 'aliases: aliases') !== false
        && strpos($asset, 'expiresAt') !== false,
    'ordinary_navigation_does_not_persist' => strpos($asset, 'var liveStates = {};') !== false
        && strpos($asset, 'function rememberState(settings, data)') !== false
        && strpos($asset, 'state: safeState(data, settings)') !== false,
    'transient_selection_is_not_persisted' => strpos($asset, 'columns:') === false
        && strpos($asset, 'select:') === false,
    'explicit_opt_out_exists' => strpos($asset, "falseAttribute(table, 'data-state-save')") !== false
        && strpos($asset, 'data-datatable-return-state') !== false
        && strpos($asset, 'options.stateSave === false') !== false,
    'no_redundant_reset_control' => strpos($asset, 'data-datatable-state-reset') === false
        && strpos($asset, 'إعادة ضبط العرض') === false,
    'legacy_persistent_state_is_cleared' => strpos($asset, 'educore:datatable-state:v1:') !== false
        && strpos($asset, 'clearLegacyStates();') !== false,
    'logout_and_role_switch_clear_state' => strpos($asset, '(logout|select_role)') !== false
        && strpos($asset, 'clearAllStates();') !== false,
    'stable_row_action_context_is_restored' => strpos($asset, 'function rowContextForForm(form)') !== false
        && strpos($asset, 'function restoreRowContext(settings)') !== false
        && strpos($asset, "'data-datatable-return-row-field'") !== false
        && strpos($asset, "'init.dt.educoreReturn draw.dt.educoreReturn'") !== false,
    'full_page_row_action_journey_is_central' => strpos($asset, 'ACTION_JOURNEY_TTL_MS') !== false
        && strpos($asset, 'function linkQueryIdentities(link)') !== false
        && strpos($asset, 'function actionLinkReturnContexts(link)') !== false
        && strpos($asset, 'function actionLinkShouldCapture(link, event)') !== false
        && strpos($asset, 'function captureActionLinkState(link, rowContext)') !== false
        && strpos($asset, "falseAttribute(link, 'data-datatable-return')") !== false
        && strpos($asset, "identity.kind === 'query'") !== false,
    'row_return_is_accessible_and_motion_safe' => strpos($asset, 'scrollIntoView') !== false
        && strpos($asset, 'preventScroll: true') !== false
        && strpos($asset, "'(prefers-reduced-motion: reduce)'") !== false
        && strpos($asset, 'aria-live') !== false
        && strpos($dashboardCss, '.datatable-return-row-highlight') !== false
        && strpos($dashboardCss, '@media (prefers-reduced-motion: reduce)') !== false,
    'progressive_ajax_keeps_prg_fallback' => strpos($asset, 'function submitAjax(form, event)') !== false
        && strpos($asset, "body.set('datatable_ajax', '1')") !== false
        && strpos($asset, 'api.ajax.reload') !== false
        && strpos($asset, '}, false);') !== false
        && strpos($asset, "if (!formShouldCapture(form)) return false;") !== false
        && $ajaxNegotiationPasses
        && $ajaxNegotiationRejectsOrdinaryRequest,
    'staff_account_reference_flow_is_opted_in' => substr_count($staffAccounts, 'data-datatable-return-table="staffAccountsTable"') >= 2
        && strpos($staffAccounts, 'data-datatable-ajax="true"') !== false
        && strpos($staffAccounts, 'DataTableActionResponder.php') !== false
        && strpos($staffAccounts, '$isDataTableAjaxRequest') !== false
        && strpos($staffAccounts, '$staffAccountResponder->finish') !== false
        && strpos($actionResponder, 'public static function accepts(array $server, array $requestData)') !== false
        && strpos($actionResponder, 'public function __construct(bool $ajaxRequest') !== false
        && strpos($actionResponder, "'summary' => \$summary") !== false
        && strpos($staffSingleModals, 'data-datatable-ajax="manual"') !== false
        && strpos($staffSingleModals, 'data-datatable-return-row-field="user_id"') !== false,
    'enrolled_students_full_page_edit_is_covered' => strpos($studentsPage, "Presentation/list_view.php") !== false
        && strpos($studentsPage, "header(\"Location: \" . \$savedBasePage") !== false
        && strpos($studentListView, "id=\"studentsTable\"") !== false
        && strpos($studentListView, 'AdminServerSideTable.init({') !== false
        && strpos($studentListView, 'data-student-id="<?php echo (int) $student[\'id\']; ?>"') !== false
        && strpos($studentListPresenter, 'data-student-id="') !== false
        && strpos($studentListPresenter, '?action=edit&id=') !== false
        && strpos($studentProfileForm, '$studentsBasePage . $backQuery') !== false,
    'shared_headers_load_policy' => strpos($adminHeader, "asset_url('../assets/js/datatable-state.js')") !== false
        && strpos($teacherHeader, "asset_url('../assets/js/datatable-state.js')") !== false
        && strpos($specialistHeader, "require_once __DIR__ . '/admin_header.php'") !== false,
    'standalone_teacher_tables_load_policy_first' => $standaloneCoverage,
    'all_direct_php_initializers_are_covered' => $uncoveredPages === [],
    'all_embedded_php_initializers_are_covered' => $uncoveredEmbeddedInitializers === [],
    'no_page_local_state_owner' => $localStateOwners === [],
    'future_rule_is_documented' => strpos($agents, '## DataTables Action Return State — MANDATORY') !== false
        && strpos($agents, 'composer datatable-state-audit') !== false,
    'contract_is_in_quality_gate' => $datatableAuditCommands === [
            '@php tests/datatable_state_persistence_contract_test.php',
            'node tests/datatable_state_behavior_test.js',
        ]
        && in_array('@datatable-state-audit', $composer['scripts']['quality'] ?? [], true),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($uncoveredPages !== []) {
    echo 'UNCOVERED_DATATABLE_PAGES=' . implode(',', $uncoveredPages) . PHP_EOL;
}
if ($uncoveredEmbeddedInitializers !== []) {
    echo 'UNCOVERED_EMBEDDED_DATATABLE_INITIALIZERS=' . implode(',', $uncoveredEmbeddedInitializers) . PHP_EOL;
}
if ($localStateOwners !== []) {
    echo 'LOCAL_DATATABLE_STATE_OWNERS=' . implode(',', $localStateOwners) . PHP_EOL;
}

exit($failed ? 1 : 0);
