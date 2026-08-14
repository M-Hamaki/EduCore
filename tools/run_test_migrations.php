<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/tests/bootstrap_test_database.php';

// Establish and verify the isolated connection before the regular migration
// runner is loaded. The bootstrap also pins DB_NAME for all later connections.
educoreTestDatabase();
require __DIR__ . '/run_migrations.php';
