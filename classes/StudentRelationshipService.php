<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Modules/Students/bootstrap.php';

$studentRelationshipAliases = [
    \EduCore\Modules\Students\StudentKinshipLinkException::class => 'StudentKinshipLinkException',
    \EduCore\Modules\Students\StudentRelationshipGuardException::class => 'StudentRelationshipGuardException',
    \EduCore\Modules\Students\StudentRelationshipService::class => 'StudentRelationshipService',
];

foreach ($studentRelationshipAliases as $implementation => $legacyName) {
    if (!class_exists($legacyName, false)) {
        class_alias($implementation, $legacyName);
    }
}
