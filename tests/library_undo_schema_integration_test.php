<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/SchemaReadinessGuard.php';
require_once dirname(__DIR__) . '/classes/UndoManager.php';

$db = educoreTestDatabase();
$guard = new SchemaReadinessGuard($db);
$guard->assertColumns('library_fines', ['loan_id', 'student_id', 'amount', 'reason', 'paid', 'paid_at', 'notes']);
$guard->assertColumns(
    'library_books',
    ['author', 'category', 'isbn', 'copies_total', 'copies_available', 'location', 'status', 'notes']
);
UndoManager::setDb($db);

echo "library_undo_schema_ready:PASS\n";
