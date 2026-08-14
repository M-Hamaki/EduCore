<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/** @return string */
function architectureDocsRead(string $root, string $relativePath): string
{
    $path = $root . '/' . $relativePath;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

$requiredFiles = [
    'AGENTS.md',
    'README.md',
    'docs/architecture.md',
    'docs/PASSWORD_SECURITY.md',
    'docs/project-structure.md',
    'docs/architecture-decisions.md',
    'docs/coding-rules.md',
    'docs/ai-change-checklist.md',
    '.specify/memory/constitution.md',
    '.specify/templates/plan-template.md',
    '.specify/templates/spec-template.md',
    '.specify/templates/tasks-template.md',
];

$contents = [];
foreach ($requiredFiles as $relativePath) {
    $contents[$relativePath] = architectureDocsRead($root, $relativePath);
}

$agents = $contents['AGENTS.md'];
$readme = $contents['README.md'];
$architecture = $contents['docs/architecture.md'];
$passwordSecurity = $contents['docs/PASSWORD_SECURITY.md'];
$constitution = $contents['.specify/memory/constitution.md'];
$planTemplate = $contents['.specify/templates/plan-template.md'];
$specTemplate = $contents['.specify/templates/spec-template.md'];
$tasksTemplate = $contents['.specify/templates/tasks-template.md'];
$composer = json_decode(architectureDocsRead($root, 'composer.json'), true);
$qualityWorkflow = architectureDocsRead($root, '.github/workflows/quality.yml');
$changeChecklist = $contents['docs/ai-change-checklist.md'];

$checks = [
    'required_documents_exist' => !in_array('', $contents, true),
    'agents_is_canonical' => strpos($agents, 'single authoritative project-instruction source') !== false,
    'agents_has_architecture_governance' => strpos($agents, '## Architecture Governance — MANDATORY') !== false,
    'agents_has_modular_monolith_target' => stripos($agents, 'pragmatic modular monolith') !== false,
    'agents_requires_architecture_audit' => strpos($agents, 'composer architecture-audit') !== false,
    'agents_requires_cross_module_contracts' => strpos(
        $agents,
        'Cross-module behavior MUST use a documented service/query contract'
    ) !== false,
    'agents_documents_audit_limit' => strpos($agents, 'path-level') !== false
        && strpos($agents, 'does not prove that debt inside an already-baselined file did not grow') !== false,
    'agents_documents_mission_and_protected_workflows' => strpos(
        $agents,
        '### Project Mission And Protected Workflows'
    ) !== false && strpos($agents, 'Authentication, authorization, sessions, assessment/grades') !== false,
    'agents_documents_git_safety_and_done' => strpos($agents, '### Mandatory Change Process And Git Safety') !== false
        && strpos($agents, '### Definition Of Done') !== false
        && strpos($agents, 'git diff --check') !== false,
    'agents_requires_future_write_contract' => strpos(
        $agents,
        '### Future Write, Audit, Undo, And Draft Contract — MANDATORY'
    ) !== false
        && strpos($agents, 'AUDIT_REVIEW_REQUIRED=0') !== false
        && strpos($agents, 'assets/js/form-safety.js') !== false
        && strpos($agents, 'composer quality') !== false,
    'agents_requires_datatable_state_contract' => strpos(
        $agents,
        '## DataTables Action Return State — MANDATORY'
    ) !== false
        && strpos($agents, 'assets/js/datatable-state.js') !== false
        && strpos($agents, 'composer datatable-state-audit') !== false,
    'runtime_matches_composer' => ($composer['require']['php'] ?? null) === '>=8.0'
        && strpos($agents, 'PHP 8.0+') !== false,
    'readme_has_ai_assistant_section' => strpos($readme, '## AI Coding Assistants') !== false,
    'readme_states_hash_first_auth' => strpos($readme, 'بقرار hash-first') !== false,
    'readme_does_not_claim_complete_csrf' => strpos($readme, 'CSRF Token** في كل النماذج') === false,
    'readme_states_reveal_risk' => strpos($readme, 'reveal الإدارية دينًا أمنيًا') !== false,
    'password_security_states_hash_authority' => strpos($passwordSecurity, 'إذا وجد hash فهو المصدر الوحيد') !== false,
    'password_security_documents_legacy_cutover' => strpos($passwordSecurity, 'PASSWORD_LEGACY_LOGIN_ENABLED=true') !== false,
    'readme_states_current_csrf_audit_and_limit' => strpos(
        $readme,
        'صفر مرشح CSRF غير مراجع'
    ) !== false && strpos($readme, 'heuristic وpath-level') !== false,
    'architecture_links_target_and_current_state' => stripos($architecture, 'modular monolith') !== false
        && stripos($architecture, 'page controller') !== false,
    'project_structure_conditions_psr4' => strpos(
        $contents['docs/project-structure.md'],
        'عند اعتماد دفعة استخراج فعلية تضيف PSR-4'
    ) !== false,
    'constitution_is_ratified' => strpos($constitution, '**Version**: 1.0.0') !== false
        && strpos($constitution, '`AGENTS.md` has highest instruction precedence') !== false,
    'constitution_has_no_template_tokens' => preg_match('/\[[A-Z][A-Z0-9_]+\]/', $constitution) === 0,
    'plan_template_has_real_gates' => strpos($planTemplate, '**Canonical context**') !== false
        && strpos($planTemplate, 'composer architecture-audit') !== false
        && strpos($planTemplate, '## Scope Boundaries *(mandatory)*') !== false
        && strpos($planTemplate, 'Owner / Approval') !== false,
    'spec_template_has_impact_section' => strpos(
        $specTemplate,
        '## Compatibility, Security, and Data Impact *(mandatory)*'
    ) !== false
        && strpos($specTemplate, '## Scope And Non-Scope *(mandatory)*') !== false,
    'tasks_template_has_project_paths' => strpos($tasksTemplate, 'database/migrations/') !== false
        && strpos($tasksTemplate, 'composer architecture-audit') !== false
        && strpos($tasksTemplate, 'src/models/') === false
        && strpos($tasksTemplate, 'OPTIONAL - only if tests requested') === false
        && strpos($tasksTemplate, 'deploy only when the user explicitly authorizes') !== false,
    'documentation_audit_is_composer_gate' => ($composer['scripts']['documentation-audit'] ?? null)
        === '@php tests/architecture_documentation_test.php'
        && strpos($readme, 'composer documentation-audit') !== false,
    'future_safety_gates_are_in_quality' => ($composer['scripts']['audit-write-coverage'] ?? null)
        === '@php tools/audit_write_coverage.php'
        && ($composer['scripts']['admin-ui-audit'] ?? null) === '@php tools/audit_admin_ui.php'
        && ($composer['scripts']['datatable-state-audit'] ?? null) === [
            '@php tests/datatable_state_persistence_contract_test.php',
            'node tests/datatable_state_behavior_test.js',
        ]
        && in_array('@audit-write-coverage', $composer['scripts']['quality'] ?? [], true)
        && in_array('@admin-ui-audit', $composer['scripts']['quality'] ?? [], true)
        && in_array('@datatable-state-audit', $composer['scripts']['quality'] ?? [], true),
    'ci_enforces_canonical_quality_gate' => strpos($qualityWorkflow, 'pull_request:') !== false
        && strpos($qualityWorkflow, 'composer quality') !== false,
    'change_checklist_covers_future_writes' => strpos($changeChecklist, 'composer audit-write-coverage') !== false
        && strpos($changeChecklist, 'AUDIT_REVIEW_REQUIRED=0') !== false
        && strpos($changeChecklist, 'composer quality') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
