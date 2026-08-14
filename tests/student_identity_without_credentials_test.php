<?php

require_once __DIR__ . '/bootstrap_test_database.php';
require_once __DIR__ . '/../classes/user.php';

$db = educoreTestDatabase();
$_SESSION['user_id'] = 987654326;
$_SESSION['name'] = 'Student Identity Test';
$_SESSION['role'] = 'admin';
$results = [];
$studentId = 0;

$db->beginTransaction();
try {
    $token = 'credential-free-' . bin2hex(random_bytes(6));
    $insert = $db->prepare(
        "INSERT INTO users (name, username, password, role, class_id)
         VALUES (?, NULL, NULL, 'student', NULL)"
    );
    $insert->execute([$token]);
    $studentId = (int) $db->lastInsertId();

    $profileReader = new User($db);
    $profileReader->id = $studentId;
    $results['credential_free_read'] = $profileReader->readOneWithoutCredentials()
        && $profileReader->name === $token
        && $profileReader->role === 'student'
        && $profileReader->username === null
        && $profileReader->password === null;

    $profileReader->name = $token . '-updated';
    $profileReader->class_id = null;
    $results['credential_free_update'] = $profileReader->updateStudentIdentity();

    $verify = $db->prepare(
        'SELECT name, username, password FROM users WHERE id = ? AND role = ?'
    );
    $verify->execute([$studentId, 'student']);
    $stored = $verify->fetch(PDO::FETCH_ASSOC);
    $results['credentials_untouched'] = $stored
        && $stored['name'] === $token . '-updated'
        && $stored['username'] === null
        && $stored['password'] === null;

    $genericReader = new User($db);
    $genericReader->id = $studentId;
    $results['generic_read_null_safe'] = $genericReader->readOne()
        && $genericReader->password === '';
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$failed = array_keys(array_filter($results, static function ($passed) {
    return !$passed;
}));

foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit($failed ? 1 : 0);
