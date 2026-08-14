<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/PublicPortal/Domain/IntroVisitPolicy.php';

use EduCore\Modules\PublicPortal\Domain\IntroVisitPolicy;

$now = 1786428000;
$policy = new IntroVisitPolicy(15 * 24 * 60 * 60);
assert($policy->shouldShow(null, false, false, false, false, $now));
assert(!$policy->shouldShow((string) ($now - 3600), false, false, false, false, $now));
assert($policy->shouldShow((string) ($now - (15 * 24 * 60 * 60)), false, false, false, false, $now));
assert(!$policy->shouldShow(null, false, true, false, false, $now));
assert(!$policy->shouldShow(null, true, false, false, false, $now));
assert(!$policy->shouldShow(null, false, false, true, false, $now));
assert(!$policy->shouldShow(null, false, false, false, true, $now));
assert($policy->normalizeDestination('https://attacker.example') === 'portal');
assert($policy->routeForDestination('materials') === 'materials.php?skip_intro=1');

echo "PUBLIC_PORTAL_DOMAIN_TEST_PASSED\n";
