<?php

declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';

return [
    'unified_access_portal_enabled' => filter_var(
        env('UNIFIED_ACCESS_PORTAL_ENABLED', 'true'),
        FILTER_VALIDATE_BOOLEAN
    ),
    'teams_auto_sso_enabled' => filter_var(
        env('TEAMS_AUTO_SSO_ENABLED', 'true'),
        FILTER_VALIDATE_BOOLEAN
    ),
    'intro_interval_seconds' => 15 * 24 * 60 * 60,
];
