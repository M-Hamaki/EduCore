<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$query = http_build_query($_GET);
header('Location: student_numbers_reports.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
