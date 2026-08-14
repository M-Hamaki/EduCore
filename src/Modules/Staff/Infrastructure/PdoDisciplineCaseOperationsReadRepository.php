<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\DisciplineCaseOperationsReadRepository;
use PDO;

final class PdoDisciplineCaseOperationsReadRepository implements DisciplineCaseOperationsReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function forCaseIds(array $caseIds): array
    {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $statement = $this->db->prepare(
            'SELECT c.id, c.lock_version, c.subject_staff_user_id,
                    (SELECT d.id FROM staff_discipline_decisions d WHERE d.case_id = c.id ORDER BY d.decision_sequence DESC, d.id DESC LIMIT 1) AS decision_id,
                    (SELECT d.status FROM staff_discipline_decisions d WHERE d.case_id = c.id ORDER BY d.decision_sequence DESC, d.id DESC LIMIT 1) AS decision_status,
                    (SELECT e.id FROM staff_discipline_evidence e WHERE e.case_id = c.id AND e.status = \'verified\' ORDER BY e.id DESC LIMIT 1) AS evidence_id,
                    (SELECT m.id FROM staff_discipline_interim_measures m WHERE m.case_id = c.id ORDER BY m.id DESC LIMIT 1) AS interim_id,
                    (SELECT m.status FROM staff_discipline_interim_measures m WHERE m.case_id = c.id ORDER BY m.id DESC LIMIT 1) AS interim_status,
                    (SELECT m.lock_version FROM staff_discipline_interim_measures m WHERE m.case_id = c.id ORDER BY m.id DESC LIMIT 1) AS interim_lock_version,
                    (SELECT r.id FROM staff_discipline_reopen_events r WHERE r.case_id = c.id AND r.status = \'requested\' AND NOT EXISTS (SELECT 1 FROM staff_discipline_reopen_events rr WHERE rr.request_event_id = r.id) ORDER BY r.id DESC LIMIT 1) AS reopen_request_id
             FROM staff_discipline_cases c
             WHERE c.confidentiality_level = \'normal\' AND c.id IN (' . $placeholders . ')'
        );
        $statement->execute($caseIds);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['id']] = $row;
        }
        return $result;
    }
}
