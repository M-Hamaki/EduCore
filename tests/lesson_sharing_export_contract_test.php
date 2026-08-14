<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/LearningContent/LessonExportService.php';
require_once $root . '/src/Modules/LearningContent/LessonShareService.php';

use EduCore\Modules\LearningContent\LessonExportService;
use EduCore\Modules\LearningContent\LessonShareService;
use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

$shareService = (string) file_get_contents($root . '/src/Modules/LearningContent/LessonShareService.php');
$shareEndpoint = (string) file_get_contents($root . '/teacher/ajax/lesson_share.php');
$publicPage = (string) file_get_contents($root . '/shared_lesson.php');
$publicDownload = (string) file_get_contents($root . '/shared_lesson_download.php');
$publicExport = (string) file_get_contents($root . '/shared_lesson_export.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260726_ai_lesson_public_sharing.php');
$shareScript = (string) file_get_contents($root . '/assets/js/lesson-sharing.js');
$exportScript = (string) file_get_contents($root . '/assets/js/lesson-export.js');
$exportEndpoint = (string) file_get_contents($root . '/teacher/lesson_export.php');
$exportServiceSource = (string) file_get_contents(
    $root . '/src/Modules/LearningContent/LessonExportService.php'
);
$csrfExemptions = json_decode(
    (string) file_get_contents($root . '/tools/architecture_csrf_exemptions.json'),
    true
);
$prepPage = (string) file_get_contents($root . '/teacher/lesson_prep.php');
$viewPage = (string) file_get_contents($root . '/teacher/lesson_view.php');
$form = (string) file_get_contents($root . '/classes/Presentation/LessonPrep/form_part_two.php');
$prepScriptsOne = (string) file_get_contents($root . '/classes/Presentation/LessonPrep/scripts_part_one.php');
$prepScriptsTwo = (string) file_get_contents($root . '/classes/Presentation/LessonPrep/scripts_part_two.php');
$singleModelEndpoint = (string) file_get_contents($root . '/teacher/ajax/generate_single_model.php');
$allModelsEndpoint = (string) file_get_contents($root . '/teacher/ajax/generate_all_models.php');
$singleAnswerEndpoint = (string) file_get_contents($root . '/teacher/ajax/generate_answer_key.php');
$allAnswersEndpoint = (string) file_get_contents($root . '/teacher/ajax/generate_all_answer_keys.php');

$exporter = new LessonExportService();
$uniqueFragment = $exporter->prepareUniqueSections(
    '<section data-export-key="lesson_plan"><h1>التحضير</h1><p onclick="bad()" style="background:url(file:///secret)">محتوى أ</p><img src="file:///secret"></section>'
    . '<section data-export-key="lesson_plan"><h1>مكرر بالمفتاح</h1><p>محتوى ب</p></section>'
    . '<section data-export-key="question_bank"><h1>مكرر بالمحتوى</h1><p>محتوى أ</p></section>'
    . '<section data-export-key="custom_content"><h1>مخصص</h1><script>alert(1)</script><p>محتوى ج</p></section>'
);
$documentHtml = $exporter->buildDocument('درس اختبار', $uniqueFragment);
$pdfBytes = $exporter->renderPdf($documentHtml);
$decodedUniqueFragment = html_entity_decode($uniqueFragment, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$pdfArtifactPath = trim((string) (getenv('LESSON_EXPORT_PDF_ARTIFACT') ?: ''));
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with((string) $argument, '--pdf-artifact=')) {
        $pdfArtifactPath = trim(substr((string) $argument, 15));
    }
}
if ($pdfArtifactPath !== '') {
    $artifactDirectory = dirname($pdfArtifactPath);
    if (!is_dir($artifactDirectory) && !mkdir($artifactDirectory, 0775, true) && !is_dir($artifactDirectory)) {
        throw new RuntimeException('Unable to create the PDF artifact directory.');
    }
    if (file_put_contents($pdfArtifactPath, $pdfBytes) === false) {
        throw new RuntimeException('Unable to write the PDF artifact.');
    }
}

if (!defined('APP_URL')) {
    define('APP_URL', 'https://school.example.test/EduCore');
}
$validToken = str_repeat('a', 64);

$checks = [
    'token_contract_is_256_bit_hex' => LessonShareService::isValidToken($validToken)
        && !LessonShareService::isValidToken(str_repeat('a', 63))
        && strpos($shareService, 'random_bytes(self::TOKEN_BYTES)') !== false,
    'absolute_public_url_uses_app_url' => LessonShareService::buildPublicUrl($validToken)
        === 'https://school.example.test/EduCore/shared_lesson.php?token=' . $validToken,
    'share_write_is_owner_scoped_transactional_and_audited' =>
        strpos($shareService, 'WHERE id = ? AND teacher_id = ?') !== false
        && strpos($shareService, 'FOR UPDATE') !== false
        && strpos($shareService, 'AuditService') !== false
        && strpos($shareService, "'token_rotated' => true") !== false
        && strpos($shareService, "'public_share_token' =>") === false,
    'share_endpoint_auth_csrf_and_allowlist' =>
        strpos($shareEndpoint, "['teacher', 'external_teacher']") !== false
        && strpos($shareEndpoint, 'requireCsrfPost();') !== false
        && strpos($shareEndpoint, "['status', 'enable', 'revoke']") !== false,
    'migration_is_additive_and_revocable' =>
        strpos($migration, 'public_share_token') !== false
        && strpos($migration, 'public_share_enabled_at') !== false
        && strpos($migration, 'public_share_revoked_at') !== false
        && strpos($migration, 'uq_ai_lessons_public_share_token') !== false,
    'audit_policy_is_registered_and_token_redacted' =>
        AuditPolicyRegistry::isRegisteredTable('ai_lessons')
        && !AuditPolicyRegistry::allowsDirectUndo('ai_lessons')
        && AuditPolicyRegistry::redact(['public_share_token' => $validToken])['public_share_token'] === '[REDACTED]',
    'public_page_has_no_auth_bootstrap_and_is_noindex' =>
        strpos($publicPage, 'session_config.php') === false
        && strpos($publicPage, 'findPublicLesson($token)') !== false
        && strpos($publicPage, 'noindex,nofollow,noarchive') !== false
        && strpos($publicPage, 'Content-Security-Policy') !== false
        && strpos($publicPage, 'sandbox="allow-scripts"') !== false
        && strpos($publicPage, 'allow-forms') === false,
    'public_download_rechecks_token_and_contains_path' =>
        strpos($publicDownload, 'findPublicLesson($token)') !== false
        && strpos($publicDownload, "str_starts_with(\$absolutePath, \$root . DIRECTORY_SEPARATOR)") !== false
        && strpos($publicDownload, "['exam', 'powerpoint']") !== false,
    'public_page_has_per_tab_and_full_export_controls' =>
        substr_count($publicPage, 'data-export-format=') >= 4
        && substr_count($publicPage, 'data-export-all-format=') === 4
        && strpos($publicPage, "data-export-target=\"<?php echo \$safeTarget; ?>\"") !== false
        && strpos($publicPage, "'endpoint' => 'shared_lesson_export.php'") !== false
        && strpos($publicPage, "'publicToken' => \$token") !== false
        && strpos($publicPage, 'lesson-export.js?v=2') !== false,
    'public_export_is_token_gated_rate_limited_and_read_only' =>
        strpos($publicExport, "(\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'") !== false
        && strpos($publicExport, 'LessonShareService::isValidToken($token)') !== false
        && strpos($publicExport, 'findPublicLesson($token)') !== false
        && strpos($publicExport, 'RateLimiter::hit($rateKey, 12, 60)') !== false
        && strpos($publicExport, 'createExportArtifact(') !== false
        && strpos($publicExport, 'session_config.php') === false
        && strpos($publicExport, 'csrf_token') === false
        && count(array_filter(
            is_array($csrfExemptions['exemptions'] ?? null) ? $csrfExemptions['exemptions'] : [],
            static fn (array $entry): bool =>
                ($entry['path'] ?? '') === 'shared_lesson_export.php'
                && ($entry['category'] ?? '') === 'read_only_post'
        )) === 1,
    'share_ui_is_available_after_generation_and_in_archive_view' =>
        strpos($form, "require __DIR__ . '/share_panel.php'") !== false
        && strpos($prepPage, 'lesson-sharing.js') !== false
        && strpos($viewPage, 'lessonSharePanel') !== false
        && strpos($shareScript, "request('enable')") !== false
        && strpos($shareScript, "request('revoke')") !== false
        && strpos($shareScript, 'root.shareLesson = shareCurrentLesson;') !== false
        && strpos($prepScriptsTwo, "window.location.pathname.replace('lesson_prep.php', 'lesson_view.php')") === false,
    'server_export_removes_duplicate_key_and_unsafe_markup' =>
        substr_count($uniqueFragment, 'data-export-key="lesson_plan"') === 1
        && substr_count($uniqueFragment, 'data-export-key="question_bank"') === 1
        && substr_count($uniqueFragment, 'data-export-key="custom_content"') === 1
        && stripos($uniqueFragment, '<script') === false
        && stripos($uniqueFragment, 'onclick=') === false
        && stripos($uniqueFragment, 'file:///') === false
        && strpos((string) file_get_contents($root . '/src/Modules/LearningContent/LessonExportService.php'), "'chroot'") !== false,
    'arabic_export_survives_dom_normalization' =>
        strpos($decodedUniqueFragment, 'التحضير') !== false
        && strpos($decodedUniqueFragment, 'محتوى أ') !== false
        && preg_match('/(?:Ø|Ù|Ã)/u', $decodedUniqueFragment) !== 1,
    'all_export_entrypoints_use_one_collector' =>
        strpos($exportScript, "root.exportTabToHtml = function (containerId)") !== false
        && strpos($exportScript, "root.exportTabToPdf = function (containerId)") !== false
        && strpos($exportScript, "root.exportTabToWord = function (containerId)") !== false
        && strpos($exportScript, "root.exportTabToPrint = function (containerId)") !== false
        && strpos($exportScript, "root.exportSelectedToHtml = function () { return run('html', selectedKeys()); }") !== false
        && strpos($exportScript, "root.exportFullLessonPdf = function () { return run('pdf', allAvailableKeys()); }") !== false
        && strpos($exportScript, "root.exportSelectedToPdf = function () { return run('pdf', selectedKeys()); }") !== false
        && strpos($exportScript, "root.exportSelectedToWord = function () { return run('word', selectedKeys()); }") !== false
        && strpos($exportScript, "root.exportSelectedToPrint = function () { return run('print', selectedKeys()); }") !== false
        && strpos($exportScript, "root.exportAllToHtml = function () { return run('html', allAvailableKeys()); }") !== false
        && strpos($exportScript, "root.exportAllToPdf = function () { return run('pdf', allAvailableKeys()); }") !== false
        && strpos($exportScript, "root.exportAllToWord = function () { return run('word', allAvailableKeys()); }") !== false
        && strpos($exportScript, "root.exportAllToPrint = function () { return run('print', allAvailableKeys()); }") !== false
        && strpos($exportScript, "root.exportContent = function (format) { return run(format, selectedKeys()); }") !== false
        && strpos($exportScript, 'dedupeSections(uniqueKeys(keys)') !== false
        && strpos($form, 'id="exportPdfBtn" onclick="exportAllToPdf()"') !== false
        && strpos($viewPage, 'onclick="exportAllToPdf()"') !== false,
    'every_content_tab_exports_only_its_container' =>
        strpos($prepScriptsTwo, "lessonPlan: ['lessonPlanContent', 'التحضير']") !== false
        && strpos($prepScriptsTwo, "questionBank: ['questionBankContent', 'بنك الأسئلة']") !== false
        && strpos($prepScriptsTwo, "visualMaterials: ['visualMaterialsContent', 'المواد البصرية']") !== false
        && strpos($prepScriptsTwo, "mindMaps: ['mindMapsContent', 'الخرائط الذهنية']") !== false
        && strpos($prepScriptsTwo, "classActivities: ['classActivitiesContent', 'الأنشطة الصفية']") !== false
        && strpos($prepScriptsTwo, "lessonSummary: ['lessonSummaryContent', 'ملخص الدرس']") !== false
        && strpos($prepScriptsTwo, "educationalStories: ['educationalStoriesContent', 'القصة التربوية']") !== false
        && strpos($prepScriptsTwo, "customContent: ['customContentArea', 'المحتوى المخصص']") !== false
        && strpos($prepScriptsTwo, "exportTabToPrint('\${containerId}')") !== false
        && strpos($exportScript, 'root.exportTabToPrint = function (containerId)') !== false,
    'exam_buttons_route_to_the_exact_requested_artifact' =>
        strpos($prepScriptsOne, "fetch('ajax/generate_single_model.php'") !== false
        && strpos($prepScriptsOne, "fetch('ajax/generate_all_models.php'") !== false
        && strpos($prepScriptsOne, "fetch('ajax/generate_answer_key.php'") !== false
        && strpos($prepScriptsOne, "fetch('ajax/generate_all_answer_keys.php'") !== false
        && strpos($singleModelEndpoint, 'generateSingleModelHTML') !== false
        && strpos($singleModelEndpoint, 'setModelsCount(1)') !== false
        && strpos($allModelsEndpoint, 'generateExamHTML') !== false
        && strpos($singleAnswerEndpoint, 'generateAnswerKeyHTML') !== false
        && strpos($allAnswersEndpoint, 'generateAllAnswerKeysHTML') !== false,
    'specialized_exports_route_to_exact_artifacts' =>
        strpos($prepScriptsTwo, "EduVisual.exportJSON(generatedData.mind_maps") !== false
        && strpos($viewPage, 'lesson_download.php?id=<?php echo $lessonId; ?>&type=powerpoint') !== false
        && strpos($publicDownload, "if (\$type === 'exam')") !== false
        && strpos($publicDownload, 'application/vnd.openxmlformats-officedocument.presentationml.presentation') !== false,
    'pdf_is_real_server_generated_pdf' =>
        strpos($exportEndpoint, "['html', 'word', 'pdf']") !== false
        && strpos($exportEndpoint, '$service->createExportArtifact(') !== false
        && strpos($exportEndpoint, "header('Content-Type: ' . \$artifact['content_type'])") !== false
        && strpos($exportScript, 'fetch(transport.endpoint') !== false
        && strpos($exportServiceSource, '$this->renderPdf($document)') !== false
        && strpos($exportServiceSource, 'utf8Glyphs') !== false
        && str_starts_with($pdfBytes, '%PDF-')
        && strlen($pdfBytes) > 1000,
    'both_lesson_pages_load_export_owner_last' =>
        strrpos($prepPage, 'lesson-export.js') > strrpos($prepPage, 'scripts_part_two.php')
        && strrpos($viewPage, 'lesson-export.js') > strrpos($viewPage, 'function exportContent(format)'),
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
