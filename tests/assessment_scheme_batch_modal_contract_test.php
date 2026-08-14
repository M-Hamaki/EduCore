<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$listPage = (string) file_get_contents($root . '/admin/assessment_schemes.php');
$batchPage = (string) file_get_contents($root . '/admin/assessment_scheme_batch.php');
$familyPage = (string) file_get_contents($root . '/admin/assessment_scheme_family.php');
$batchUi = (string) file_get_contents($root . '/assets/js/assessment-scheme-batch-form.js');

$checks = [
    'batch_creation_uses_bootstrap_modal' => strpos($listPage, 'data-bs-target="#assessmentSchemeBatchModal"') !== false
        && strpos($listPage, 'id="assessmentSchemeBatchModal"') !== false
        && strpos($listPage, 'modal-dialog-scrollable') !== false,
    'modal_reuses_server_rendered_batch_form_without_iframe' => strpos($listPage, "fetch('assessment_scheme_batch.php?embed=1'") !== false
        && strpos($listPage, 'assessmentSchemeBatchContent') !== false
        && strpos($listPage, '<iframe') === false,
    'preview_and_create_stay_in_modal_until_success_redirect' => strpos($listPage, "form.getAttribute('action')") !== false
        && strpos($listPage, "data.set(submitter.name, submitter.value)") !== false
        && strpos($listPage, 'window.location.assign(payload.redirect)') !== false
        && strpos($batchPage, 'scheme_batch_is_ajax()') !== false
        && strpos($batchPage, "'redirect' => \$redirectUrl") !== false,
    'preview_signature_is_required_and_invalidated_after_changes' => strpos($batchPage, 'scheme_batch_preview_signature') !== false
        && strpos($batchPage, 'hash_equals($previewSignature, $submittedSignature)') !== false
        && strpos($batchPage, 'name="preview_signature"') !== false
        && strpos($batchPage, 'data-batch-preview') !== false
        && strpos($batchUi, 'function invalidatePreview()') !== false
        && strpos($batchUi, 'signature.remove();') !== false,
    'modal_requests_are_abortable_and_preserve_form_on_failure' => strpos($listPage, 'new AbortController()') !== false
        && strpos($listPage, "modalElement.addEventListener('hidden.bs.modal'") !== false
        && strpos($listPage, "modalBody.prepend(errorMarkup(batchRequestErrorMessage(error, 'submit')))") !== false,
    'database_errors_are_not_rendered_verbatim' => strpos($batchPage, 'function scheme_batch_public_error') !== false
        && strpos($batchPage, '$cursor instanceof PDOException') !== false
        && strpos($batchPage, 'لم يتم حفظ أي تغييرات جزئية') !== false
        && strpos($familyPage, 'function scheme_family_public_error') !== false
        && strpos($listPage, 'function schemes_public_error') !== false
        && strpos($listPage, '$_SESSION[\'error_message\'] = schemes_public_error($e);') !== false,
    'legacy_batch_url_opens_modal_on_list_page' => strpos($batchPage, "header('Location: assessment_schemes.php?open_batch=1')") !== false
        && strpos($listPage, "currentUrl.searchParams.get('open_batch') === '1'") !== false,
    'shared_batch_ui_initializes_standalone_and_modal_forms' => strpos($batchPage, 'assessment-scheme-batch-form.js') !== false
        && strpos($batchUi, 'window.initAssessmentSchemeBatchForm') !== false
        && strpos($batchUi, "root.querySelector('#assessmentSchemeBatchForm')") !== false,
    'batch_selector_matches_term_stage_grade_and_class_contract' => strpos($batchPage, 'batch-term-card') !== false
        && strpos($batchPage, 'id="batchSelectAllTerms"') !== false
        && strpos($batchPage, 'id="batchSelectAllGrades"') !== false
        && strpos($batchPage, 'select-assignment-stage-btn') !== false
        && strpos($batchPage, 'assignment-grade-scope-badge') !== false
        && strpos($batchPage, 'name="scopes[<?php echo $gradeId; ?>][all_classes]"') !== false
        && strpos($batchPage, 'name="scopes[<?php echo $gradeId; ?>][class_ids][]"') !== false,
    'batch_selector_synchronizes_all_selection_layers' => strpos($batchUi, 'function syncTerms()') !== false
        && strpos($batchUi, 'function syncAcademicScope()') !== false
        && strpos($batchUi, "input.disabled = gradeSelected;") !== false
        && strpos($batchUi, "gradeInput.checked = false;") !== false
        && strpos($batchUi, "selectedClasses.length + ' فصول'") !== false
        && strpos($batchUi, 'إلغاء تحديد المرحلة') !== false
        && strpos($batchUi, 'syncAnnualWeights(') !== false
        && strpos($batchUi, 'invalidatePreview();') !== false,
    'terms_precede_annual_policy_and_control_its_eligibility' => strpos($batchPage, 'id="batchSelectAllTerms"') < strpos($batchPage, 'id="annualEnabled"')
        && strpos($batchPage, 'id="annualEligibilityHelp"') !== false
        && strpos($batchPage, "count(\$selectedTermIds) < 2 ? 'disabled' : ''") !== false
        && strpos($batchUi, 'selectedIds.length >= 2') !== false
        && strpos($batchUi, 'annualEnabled.disabled = !annualIsEligible;') !== false,
    'annual_weights_are_visible_validated_and_totalled_live' => strpos($batchPage, 'id="annualWeightSummary"') !== false
        && strpos($batchPage, 'id="annualWeightTotal"') !== false
        && strpos($batchUi, 'function distributeAnnualWeights(inputs)') !== false
        && strpos($batchUi, 'function updateAnnualWeightSummary(selectedInputs)') !== false
        && strpos($batchUi, 'input.setCustomValidity(validationMessage);') !== false
        && strpos($batchUi, "Math.abs(total - 100) <= 0.001") !== false,
    'batch_selector_does_not_add_local_css_or_nested_scroll_owner' => stripos($batchPage, '<style') === false
        && strpos($batchPage, 'assignment-scope-list') === false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
