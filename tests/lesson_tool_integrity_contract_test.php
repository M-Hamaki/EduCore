<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$generateLesson = $read('teacher/ajax/generate_lesson.php');
$generateExam = $read('teacher/ajax/generate_exam_only.php');
$generateQuestionBank = $read('teacher/ajax/generate_qbank_only.php');
$generatePowerPoint = $read('teacher/ajax/generate_powerpoint_only.php');
$regenerate = $read('teacher/ajax/regenerate_section.php');
$questionBankExport = $read('teacher/ajax/export_qbank.php');
$lessonDownload = $read('teacher/lesson_download.php');
$sharedDownload = $read('shared_lesson_download.php');
$sharedLesson = $read('shared_lesson.php');
$displayScript = $read('assets/js/lesson_display.js');
$prepScript = $read('classes/Presentation/LessonPrep/scripts_part_two.php');
$lessonPrepPage = $read('teacher/lesson_prep.php');
$lessonViewPage = $read('teacher/lesson_view.php');
$auditService = $read('src/Modules/Operations/Audit/AuditService.php');
$lessonGenerator = $read('classes/LessonGenerator.php');
$examGenerator = $read('classes/ExamGenerator.php');
$examRenderer = $read('classes/ExamTemplateRenderer.php');
$singleModel = $read('teacher/ajax/generate_single_model.php');
$allModels = $read('teacher/ajax/generate_all_models.php');
$singleAnswerKey = $read('teacher/ajax/generate_answer_key.php');
$allAnswerKeys = $read('teacher/ajax/generate_all_answer_keys.php');

$temporaryUploadEndpoints = [
    $generateLesson,
    $generateExam,
    $generateQuestionBank,
    $generatePowerPoint,
];

$checks = [
    'standalone_exam_uses_named_result_arguments' =>
        strpos($generateExam, 'lessonId: $lessonId') !== false
        && strpos($generateExam, 'educationalStories: null') !== false
        && strpos($generateExam, 'examMcCount: $mcCount') !== false
        && strpos($generateExam, 'examTfCount: $tfCount') !== false
        && strpos($generateExam, 'examEssayCount: $essayCount') !== false,
    'standalone_persistence_failure_is_not_reported_as_success' =>
        strpos($generateExam, 'if (!$saved)') !== false
        && strpos($generateQuestionBank, 'if (!$saved)') !== false,
    'exam_model_contract_matches_generator_and_ui' =>
        strpos($generateLesson, "['shuffle', 'different']") !== false
        && strpos($generateExam, "['shuffle', 'different']") !== false
        && strpos($generateLesson, 'min(4, intval') !== false
        && strpos($generateExam, 'min(4, intval') !== false,
    'transient_uploads_are_validated_randomized_and_cleaned' =>
        array_reduce(
            $temporaryUploadEndpoints,
            static fn(bool $ok, string $source): bool => $ok
                && strpos($source, 'FileUploadGuard::validate') !== false
                && strpos($source, 'FileUploadGuard::randomFileName') !== false
                && strpos($source, 'register_shutdown_function') !== false
                && strpos($source, '@unlink($temporaryPath)') !== false,
            true
        ),
    'regeneration_releases_session_and_preserves_exam_boundary' =>
        strpos($regenerate, 'session_write_close();') !== false
        && strpos($regenerate, "&& !empty(\$lesson['exam_html'])") !== false
        && strpos($regenerate, "['shuffle', 'different']") !== false
        && strpos($regenerate, 'ANTI_CHEAT_ENABLED') !== false
        && strpos($regenerate, 'STUDENT_INFO_ENABLED') !== false,
    'generated_content_is_escaped_or_sanitized_before_rendering' =>
        substr_count($displayScript, 'escapeTextTree(window.generatedData.') >= 4
        && strpos($displayScript, 'sanitizeGeneratedHtml(item.content_html)') !== false
        && strpos($prepScript, 'window.sanitizeGeneratedHtml(item.content_html)') !== false
        && strpos($displayScript, 'safeHttpUrl(') !== false
        && strpos($displayScript, 'data-copy-text=') !== false,
    'custom_content_renderer_survives_stale_shared_script_cache' =>
        strpos($displayScript, 'window.safeIconClass = safeIconClass;') !== false
        && strpos($displayScript, 'window.safeColor = safeColor;') !== false
        && strpos($displayScript, 'window.sanitizeGeneratedHtml = sanitizeGeneratedHtml;') !== false
        && strpos($prepScript, "typeof window.safeIconClass === 'function'") !== false
        && strpos($prepScript, "typeof window.safeColor === 'function'") !== false
        && strpos($prepScript, "typeof window.sanitizeGeneratedHtml === 'function'") !== false
        && strpos($lessonPrepPage, 'lesson_display.js?v=1.2') !== false
        && strpos($lessonViewPage, 'lesson_display.js?v=1.2') !== false
        && strpos($sharedLesson, 'lesson_display.js?v=1.2') !== false,
    'post_save_render_errors_are_not_reported_as_connection_failures' =>
        strpos($prepScript, 'let lessonPersisted = false;') !== false
        && strpos($prepScript, 'lessonPersisted = Boolean(currentLessonId);') !== false
        && strpos($prepScript, "} else if (lessonPersisted) {") !== false
        && strpos($prepScript, "'تم حفظ الدرس وتعذر عرضه'") !== false,
    'private_and_public_downloads_are_not_cacheable' =>
        substr_count($lessonDownload, 'Cache-Control: private, no-store') >= 4
        && strpos($sharedDownload, 'Cache-Control: private, no-store') !== false
        && strpos($lessonDownload, 'realpath(dirname(__DIR__))') !== false
        && strpos($lessonDownload, 'str_starts_with($absolutePath, $projectRoot . DIRECTORY_SEPARATOR)') !== false,
    'powerpoint_errors_do_not_disclose_debug_details' =>
        strpos($generatePowerPoint, "'[DEBUG] '") === false
        && strpos($generatePowerPoint, 'basename($e->getFile())') === false,
    'external_teacher_can_export_owned_question_bank' =>
        strpos($questionBankExport, "['teacher', 'external_teacher']") !== false
        && strpos($questionBankExport, 'requireCsrfPost();') !== false,
    'public_share_contains_all_lesson_artifact_tabs_without_auth' =>
        strpos($sharedLesson, 'session_config.php') === false
        && strpos($sharedLesson, "'generated_prep'") !== false
        && strpos($sharedLesson, "'question_bank'") !== false
        && strpos($sharedLesson, "'visual_materials'") !== false
        && strpos($sharedLesson, "'class_activities'") !== false
        && strpos($sharedLesson, "'educational_stories'") !== false
        && strpos($sharedLesson, "'mind_maps'") !== false
        && strpos($sharedLesson, "'lesson_summary'") !== false
        && strpos($sharedLesson, "'custom_content'") !== false
        && strpos($sharedLesson, 'exam-preview') !== false
        && strpos($sharedLesson, 'powerpoint-preview') !== false,
    'audit_service_loads_its_interface_for_direct_consumers' =>
        strpos($auditService, "require_once __DIR__ . '/AuditEventWriter.php';") !== false,
    'explicit_empty_optional_sections_are_preserved' =>
        strpos($lessonGenerator, 'public function setSelectedSections($sections)') !== false
        && strpos($lessonGenerator, 'if (is_array($sections))') !== false
        && strpos($lessonGenerator, 'if (is_array($sections) && !empty($sections))') === false,
    'lesson_delete_is_owner_scoped_transactional_audited_and_file_safe' =>
        strpos($lessonGenerator, 'SELECT * FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE') !== false
        && strpos($lessonGenerator, "'delete',") !== false
        && strpos($lessonGenerator, 'deletePowerPointArtifact') !== false
        && strpos($lessonGenerator, "'storage/exports/lessons/'") !== false
        && strpos($lessonGenerator, "'storage/canva_templates/'") !== false,
    'exam_exports_reuse_saved_models_instead_of_reshuffling' =>
        strpos($examGenerator, 'extractPreparedModels') !== false
        && strpos($examGenerator, 'filterExamHtmlToModel') !== false
        && strpos($singleModel, 'filterExamHtmlToModel') !== false
        && strpos($allModels, "'from_saved_exam' => true") !== false
        && strpos($singleAnswerKey, 'setPreparedModels') !== false
        && strpos($allAnswerKeys, 'setPreparedModels') !== false,
    'exam_html_escapes_titles_and_script_breakout_payloads' =>
        strpos($examGenerator, 'JSON_HEX_TAG') !== false
        && strpos($examRenderer, '$safeTitle = htmlspecialchars') !== false
        && strpos($examRenderer, '<title>{$safeTitle}</title>') !== false
        && strpos($examRenderer, 'JSON_HEX_TAG') !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
