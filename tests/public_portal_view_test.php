<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/PublicPortal/Application/GetPublicPortalView.php';

use EduCore\Modules\PublicPortal\Application\GetPublicPortalView;

$view = (new GetPublicPortalView())->execute();

assert($view === ['materials_url' => 'materials.php']);
assert(!array_key_exists('guest_url', $view));
assert(!array_key_exists('services', $view));

echo "PUBLIC_PORTAL_VIEW_TEST_PASSED\n";
