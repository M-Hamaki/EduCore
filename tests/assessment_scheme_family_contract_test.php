<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$batchPage = (string) file_get_contents($root . '/admin/assessment_scheme_batch.php');
$familyPage = (string) file_get_contents($root . '/admin/assessment_scheme_family.php');
$listPage = (string) file_get_contents($root . '/admin/assessment_schemes.php');
$batchService = (string) file_get_contents($root . '/classes/AssessmentSchemeBatchService.php');
$scopeResolver = (string) file_get_contents($root . '/classes/AssessmentSchemeScopeResolver.php');
$annualPolicy = (string) file_get_contents($root . '/classes/AssessmentAnnualPolicyService.php');
$readinessService = (string) file_get_contents($root . '/classes/AssessmentSchemeReadinessService.php');
$bulkService = (string) file_get_contents($root . '/classes/AssessmentBulkActionService.php');
$componentsPage = (string) file_get_contents($root . '/admin/assessment_components.php');
$engine = (string) file_get_contents($root . '/classes/AssessmentEngine.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260809_assessment_scheme_families.php');
$scopeIdentityMigration = (string) file_get_contents($root . '/database/migrations/20260809_assessment_scheme_scope_identity.php');

$checks = [
    'admin_surfaces_validate_auth_and_csrf' => strpos($batchPage, "Utilities::validateSession('admin')") !== false
        && strpos($batchPage, 'requireCsrfPost();') !== false
        && strpos($familyPage, "Utilities::validateSession('admin')") !== false
        && strpos($familyPage, 'requireCsrfPost();') !== false,
    'batch_supports_terms_grades_and_explicit_classes' => strpos($batchPage, 'name="term_ids[]"') !== false
        && strpos($batchPage, 'name="scopes[') !== false
        && strpos($batchPage, '[class_ids][]"') !== false
        && strpos($batchPage, "foreach (['counts_in_total', 'scale_template_components', 'enable_excused_absence', 'rounding_enabled', 'annual_enabled'] as \$checkboxField)") !== false,
    'grouped_writes_are_transactional_and_audited' => strpos($batchService, '$this->db->beginTransaction();') !== false
        && strpos($batchService, 'new AuditService($this->db)') !== false
        && strpos($batchService, 'UndoManager::newBatchId()') !== false
        && strpos($batchService, 'private function familyRequestKey(') !== false
        && strpos($batchService, "':plan:' . \$encoded") !== false
        && strpos($batchService, 'legacy_family_request_keys') !== false,
    'disabled_family_can_be_reactivated_atomically' => strpos($batchService, "SET archived_at = NULL") !== false
        && strpos($batchService, 'public function activate(int $academicYearId, array $schemeIds, ?string $batchId = null)') !== false
        && strpos($familyPage, '$allArchived') !== false
        && strpos($familyPage, 'data-bs-target="#activateFamilyModal"') !== false,
    'grouped_scope_without_rows_fails_closed' => strpos($scopeResolver, 'if ($familyId !== false && $familyId !== null)') !== false
        && strpos($scopeResolver, 'return [];') !== false,
    'family_annual_policy_requires_multiple_positive_terms' => strpos($annualPolicy, '$positiveWeightCount >= 2') !== false,
    'readiness_updates_are_atomic_and_audited' => strpos($readinessService, '$ownsTransaction = !$this->db->inTransaction();') !== false
        && strpos($readinessService, 'new AuditService($this->db)') !== false
        && strpos($readinessService, '$this->db->rollBack();') !== false,
    'component_mutations_refresh_scheme_readiness' => strpos($componentsPage, "require_once '../classes/AssessmentSchemeReadinessService.php';") !== false
        && substr_count($componentsPage, 'AssessmentSchemeReadinessService($db)') >= 4
        && strpos($componentsPage, 'if ($db->inTransaction())') !== false
        && strpos($componentsPage, 'new \\EduCore\\Modules\\Operations\\Audit\\AuditService($db)') !== false
        && strpos($componentsPage, 'UndoManager::newBatchId()') !== false
        && strpos($componentsPage, "sch.status AS scheme_status") !== false
        && strpos($componentsPage, "=== 'active'") !== false
        && strpos($bulkService, "if (\$entity === 'component')") !== false
        && strpos($bulkService, 'assertComponentsAreWritable') !== false
        && strpos($bulkService, '$readiness->refresh($schemeId, $batchId, true);') !== false,
    'active_and_historical_component_sets_are_immutable' => strpos($engine, "=== 'active'") !== false
        && strpos($engine, 'assertComponentsHaveNoOperationalDependencies') !== false
        && strpos($engine, 'AssessmentSchemeReadinessService($this->db)') !== false,
    'list_uses_dynamic_family_annual_policy' => strpos($listPage, '$annualPolicyByFamily') !== false
        && strpos($listPage, 'assessment_annual_policy_terms') !== false
        && strpos($listPage, '$annualWeightLabels') !== false,
    'scheme_lifecycle_cannot_bypass_readiness_or_history_guards' => strpos($listPage, '$expectedStatus =') !== false
        && strpos($listPage, 'غيّر حالة الخطة من زر التفعيل أو التعطيل') !== false
        && strpos($listPage, 'schemes_count_dependencies($db, $schemeId) > 0') !== false,
    'migration_adds_family_scope_readiness_and_annual_policy' => strpos($migration, 'assessment_scheme_families') !== false
        && strpos($migration, 'assessment_scheme_scopes') !== false
        && strpos($migration, 'scope_identity') !== false
        && strpos($migration, 'readiness_status') !== false
        && strpos($migration, "DEFAULT 'legacy'") !== false
        && strpos($migration, 'assessment_annual_policy_terms') !== false
        && strpos($scopeIdentityMigration, 'legacy_readiness_review') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
