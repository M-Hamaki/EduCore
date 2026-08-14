<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\DisciplineCaseAdminReadRepository;
use PDO;

/**
 * PDO implementation of the deliberately non-sensitive case index.
 *
 * It never selects incident narrative, evidence, decision reason, appeal
 * reason, confidential attachment data, or Finance references.
 */
final class PdoDisciplineCaseAdminReadRepository implements DisciplineCaseAdminReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function listSummaries(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $statement = $this->db->prepare(
            'SELECT c.id, c.case_no, c.status, c.confidentiality_level,
                    c.opened_at, c.closed_at,
                    CASE WHEN c.confidentiality_level = \'normal\'
                        THEN c.classification ELSE NULL END AS classification_display,
                    CASE WHEN c.confidentiality_level = \'normal\'
                        THEN COALESCE(NULLIF(sp.full_name_ar, \'\'), u.name)
                        ELSE NULL END AS subject_display_name,
                    (SELECT COUNT(*) FROM staff_discipline_investigations i WHERE i.case_id = c.id) AS investigation_count,
                    (SELECT COUNT(*) FROM staff_discipline_decisions d WHERE d.case_id = c.id) AS decision_count,
                    (SELECT COUNT(*) FROM staff_discipline_appeals a WHERE a.case_id = c.id) AS appeal_count
             FROM staff_discipline_cases c
             LEFT JOIN users u ON u.id = c.subject_staff_user_id
             LEFT JOIN staff_profiles sp ON sp.user_id = c.subject_staff_user_id
             WHERE ' . $where . '
             ORDER BY c.opened_at DESC, c.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countSummaries(array $filters): int
    {
        [$where, $params] = $this->where($filters);
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM staff_discipline_cases c WHERE ' . $where
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function summaryById(int $caseId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT c.id, c.case_no, c.status, c.confidentiality_level,
                    c.opened_at, c.closed_at,
                    CASE WHEN c.confidentiality_level = \'normal\'
                        THEN c.classification ELSE NULL END AS classification_display,
                    CASE WHEN c.confidentiality_level = \'normal\'
                        THEN COALESCE(NULLIF(sp.full_name_ar, \'\'), u.name)
                        ELSE NULL END AS subject_display_name,
                    (SELECT COUNT(*) FROM staff_discipline_investigations i WHERE i.case_id = c.id) AS investigation_count,
                    (SELECT COUNT(*) FROM staff_discipline_decisions d WHERE d.case_id = c.id) AS decision_count,
                    (SELECT COUNT(*) FROM staff_discipline_appeals a WHERE a.case_id = c.id) AS appeal_count
             FROM staff_discipline_cases c
             LEFT JOIN users u ON u.id = c.subject_staff_user_id
             LEFT JOIN staff_profiles sp ON sp.user_id = c.subject_staff_user_id
             WHERE c.id = ? AND c.confidentiality_level = \'normal\''
        );
        $statement->execute([$caseId]);
        $summary = $statement->fetch(PDO::FETCH_ASSOC);

        return $summary === false ? null : $summary;
    }

    /** @param array{status?:string,confidentiality_level?:string,date_from?:string,date_to?:string} $filters @return array{0:string,1:list<string>} */
    private function where(array $filters): array
    {
        // This compatible index has no granular case-visibility grant yet.
        // Fail closed: restricted cases are available only through their
        // future explicitly authorized detail workflow.
        $where = ["c.confidentiality_level = 'normal'"];
        $params = [];
        if (isset($filters['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['confidentiality_level'])) {
            $where[] = 'c.confidentiality_level = ?';
            $params[] = $filters['confidentiality_level'];
        }
        if (isset($filters['date_from'])) {
            $where[] = 'c.opened_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00.000000';
        }
        if (isset($filters['date_to'])) {
            $where[] = 'c.opened_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $filters['date_to'];
        }

        return [implode(' AND ', $where), $params];
    }
}
