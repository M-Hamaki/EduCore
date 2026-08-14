<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/utilities.php';
require_once __DIR__ . '/../includes/session_config.php';

Utilities::validateSession('admin');

// Compatibility only: guest-service settings were removed. Material
// visibility and downloadability are managed by the existing materials owner.
header('Location: materials_center.php', true, 302);
exit;
