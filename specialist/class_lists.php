<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/utilities.php';
require_once dirname(__DIR__) . '/includes/session_config.php';

Utilities::validateSession('specialist');
header('Location: ../admin/class_lists.php');
exit;

