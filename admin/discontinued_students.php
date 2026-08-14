<?php

declare(strict_types=1);

/**
 * Compatible entrypoint for students whose enrollment status in the current
 * academic year is "discontinued". The shared student page remains the write
 * owner so permissions, CSRF, audit, and profile behavior stay centralized.
 */
define('STUDENT_DATA_SCOPE', 'discontinued');
require __DIR__ . '/students.php';
