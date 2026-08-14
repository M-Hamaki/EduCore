<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/tools/audit_write_coverage.php');
$classifications = require $root . '/tools/audit_write_coverage_classifications.php';
$assessmentBatchPage = (string) file_get_contents($root . '/admin/assessment_scheme_batch.php');
$assessmentFamilyPage = (string) file_get_contents($root . '/admin/assessment_scheme_family.php');
$assessmentBatchService = (string) file_get_contents($root . '/classes/AssessmentSchemeBatchService.php');
$staffOrganizationService = (string) file_get_contents($root . '/src/Modules/Staff/Application/Organization/StaffOrganizationService.php');

$checks = [
    'scanner_is_read_only' => strpos($source, 'file_put_contents') === false
        && strpos($source, 'unlink(') === false,
    'scanner_covers_role_and_internal_write_surfaces' => strpos($source, "'admin', 'api', 'ajax', 'teacher', 'student'") !== false
        && strpos($source, "'classes', 'src'") !== false,
    'scanner_detects_sql_writes' => strpos($source, 'INSERT') !== false
        && strpos($source, 'DELETE') !== false
        && strpos($source, 'UPDATE') !== false,
    'scanner_detects_audit_and_undo_calls' => strpos($source, 'ActivityLog::') !== false
        && strpos($source, 'recordCompositeUpdate') !== false
        && strpos($source, 'recordEvent') !== false
        && strpos($source, 'UndoManager::') !== false,
    'reviewed_classifications_are_explicit' => !empty($classifications)
        && count(array_filter($classifications, static function (array $classification): bool {
            return in_array($classification['type'] ?? '', ['delegated', 'false_positive'], true)
                && trim((string)($classification['owner'] ?? '')) !== ''
                && trim((string)($classification['reason'] ?? '')) !== ''
                && trim((string)($classification['evidence'] ?? '')) !== '';
        })) === count($classifications),
    'scanner_keeps_classifications_separate_from_declared_audit' => strpos($source, "'delegated_files'") !== false
        && strpos($source, "'false_positive_files'") !== false
        && strpos($source, "'review_required_files'") !== false,
    'scanner_supports_scoped_area_review' => strpos($source, "'--area='") !== false
        && strpos($source, 'Unknown audit coverage area') !== false,
    'scanner_reports_limitations' => strpos($source, "'limitations'") !== false
        && strpos($source, 'not proof') !== false,
    'scanner_fails_closed_for_unreviewed_writes' => strpos(
        $source,
        '$exitCode = $report[\'review_required_files\'] > 0 ? 1 : 0;'
    ) !== false
        && substr_count($source, 'exit($exitCode);') >= 3,
    'read_only_scope_resolver_is_explicitly_classified' => ($classifications['classes/AssessmentSchemeScopeResolver.php']['type'] ?? null) === 'false_positive'
        && strpos((string) ($classifications['classes/AssessmentSchemeScopeResolver.php']['reason'] ?? ''), 'FOR UPDATE') !== false,
    'assessment_scheme_batch_is_delegated_to_an_audited_owner' => ($classifications['admin/assessment_scheme_batch.php']['type'] ?? null) === 'delegated'
        && strpos($assessmentBatchPage, 'AssessmentSchemeBatchService') !== false
        && strpos($assessmentBatchPage, '$service->create(') !== false
        && strpos($assessmentBatchService, 'new AuditService($this->db)') !== false
        && substr_count($assessmentBatchService, '$audit->recordInsert(') >= 4,
    'assessment_scheme_family_is_delegated_to_an_audited_owner' => ($classifications['admin/assessment_scheme_family.php']['type'] ?? null) === 'delegated'
        && strpos($assessmentFamilyPage, 'AssessmentSchemeBatchService') !== false
        && strpos($assessmentFamilyPage, '$service->updateFamilyConfiguration(') !== false
        && strpos($assessmentFamilyPage, '$service->activateFamily(') !== false,
    'staff_organization_adapter_is_delegated_to_an_audited_owner' => ($classifications['src/Modules/Staff/Infrastructure/Organization/PdoStaffOrganizationRepository.php']['type'] ?? null) === 'delegated'
        && substr_count($staffOrganizationService, '$this->repository->transactional(') >= 6
        && substr_count($staffOrganizationService, '$this->audit->recordEvent(') >= 6,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
