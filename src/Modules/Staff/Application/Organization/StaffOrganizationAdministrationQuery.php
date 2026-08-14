<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Organization;

use DomainException;
use EduCore\Modules\Staff\Contracts\StaffOrganizationAdministrationReadRepository;
use EduCore\Modules\Staff\Contracts\StaffOrganizationRepository;
use InvalidArgumentException;

/**
 * Presentation-safe organization read boundary. The page never receives a
 * PDO handle or chooses an authority claim from the browser.
 */
final class StaffOrganizationAdministrationQuery
{
    public function __construct(
        private StaffOrganizationRepository $authorization,
        private StaffOrganizationAdministrationReadRepository $reader
    ) {
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function forAdministrator(int $actorUserId, int $limit = 80): array
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('STAFF_ORG_ACTOR_INVALID');
        }
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('STAFF_ORG_READ_LIMIT_INVALID');
        }
        if (!$this->authorization->actorCanManageOrganization($actorUserId)) {
            throw new DomainException('STAFF_ORG_ACTOR_FORBIDDEN');
        }

        return $this->reader->dashboard($limit);
    }
}
