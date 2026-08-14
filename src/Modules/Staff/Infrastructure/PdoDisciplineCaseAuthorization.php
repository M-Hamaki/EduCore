<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use PDO;

/** Live authorization boundary for the protected discipline command services. */
final class PdoDisciplineCaseAuthorization implements DisciplineCaseAuthorization
{
    public function __construct(private PDO $db)
    {
    }

    public function assertCanAct(int $actorId, string $action, ?array $case, DateTimeImmutable $atInstant): void
    {
        if ($actorId <= 0 || trim($action) === '') {
            throw new DomainException('DISCIPLINE_ACCESS_DENIED');
        }
        $subjectActions = ['submit_appeal', 'request_interim_measure', 'request_reopen'];
        if (in_array($action, $subjectActions, true)
            && (int) ($case['subject_staff_user_id'] ?? 0) === $actorId) {
            return;
        }
        $statement = $this->db->prepare(
            "SELECT COUNT(*) FROM user_role_assignments
             WHERE user_id = ? AND status = 'active' AND role_key IN ('admin', 'super_admin')"
        );
        $statement->execute([$actorId]);
        if ((int) $statement->fetchColumn() < 1) {
            throw new DomainException('DISCIPLINE_ACCESS_DENIED');
        }
    }
}
