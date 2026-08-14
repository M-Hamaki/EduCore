<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/relationship_discovery.php');
$store = (string) file_get_contents($root . '/classes/UserProfileStore.php');

$auth = strpos($page, "Utilities::validateSession('admin')");
$database = strpos($page, '$db = (new Database())->getConnection();');
$post = strpos($page, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
$csrf = strpos($page, 'requireCsrfPost();', $post ?: 0);
$candidateValidation = strpos($page, '$candidate = $candidateMap[$kind][$candidateKey] ?? null;');

$results = [
    'auth_precedes_database' => $auth !== false && $database !== false && $auth < $database,
    'csrf_precedes_post_writes' => $post !== false && $csrf !== false && $candidateValidation !== false
        && $post < $csrf && $csrf < $candidateValidation,
    'submitted_pair_revalidated_against_discovery' => strpos($page, 'discovery_candidate_map($data)') !== false
        && strpos($page, 'الاقتراح غير صالح أو تغيرت البيانات') !== false,
    'sibling_link_and_audit_are_atomic' => strpos($page, 'ActivityLog::setDb($db);') !== false
        && strpos($page, '$db->beginTransaction();') < strpos($page, '$user->linkSiblings(')
        && strpos($page, 'if (!$logged)') !== false,
    'bidirectional_link_is_atomic_when_called_alone' => strpos($store, '$ownsTransaction = !$this->conn->inTransaction();') !== false
        && strpos($store, '$stmt->execute([$studentId, $siblingId, $relationship]);') !== false
        && strpos($store, '$stmt->execute([$siblingId, $studentId, $relationship]);') !== false,
    'redirect_tab_is_allowlisted' => strpos($page, "['siblings_father', 'siblings_mother', 'kinships']") !== false,
    'every_review_form_has_csrf' => substr_count($page, '<form method="post"') === substr_count($page, 'name="csrf_token"'),
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
