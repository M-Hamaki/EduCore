<?php

declare(strict_types=1);

/**
 * Retired specialist-shell compatibility adapter.
 *
 * Specialist pages now use the exact shared admin shell. Keeping this include
 * as a thin adapter prevents legacy includes from reviving a second header or
 * sidebar implementation while old routes are being removed safely.
 */
require_once __DIR__ . '/admin_header.php';
