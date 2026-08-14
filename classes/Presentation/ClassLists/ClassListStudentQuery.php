<?php

final class ClassListStudentQuery
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    public function fetchByClassIds(int $yearId, array $classIds, string $sortOrder = 'ar_alpha'): array
    {
        $classIds = array_values(array_unique(array_filter(
            array_map('intval', $classIds),
            static fn(int $classId): bool => $classId > 0
        )));
        $studentsByClass = array_fill_keys($classIds, []);
        if ($classIds === []) {
            return $studentsByClass;
        }

        $orderBy = $this->orderBy($sortOrder);
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        if ($yearId > 0) {
            $stmt = $this->db->prepare("
                SELECT se.class_id AS list_class_id, u.id, u.name, sp.student_code, sp.gender,
                       CONCAT_WS(' ', sp.first_name_en, sp.second_name_en, sp.third_name_en, sp.fourth_name_en, sp.family_name_en) AS name_en
                FROM users u
                JOIN student_enrollments se ON se.student_id = u.id
                    AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                WHERE se.class_id IN ($placeholders) AND u.role = 'student' AND u.status = 'active'
                ORDER BY se.class_id, $orderBy
            ");
            $stmt->execute(array_merge([$yearId], $classIds));
        } else {
            $stmt = $this->db->prepare("
                SELECT u.class_id AS list_class_id, u.id, u.name, sp.student_code, sp.gender,
                       CONCAT_WS(' ', sp.first_name_en, sp.second_name_en, sp.third_name_en, sp.fourth_name_en, sp.family_name_en) AS name_en
                FROM users u
                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                WHERE u.class_id IN ($placeholders)
                  AND u.role = 'student'
                  AND u.status = 'active'
                  AND COALESCE(sp.enrollment_status, 'enrolled') = 'enrolled'
                ORDER BY u.class_id, $orderBy
            ");
            $stmt->execute($classIds);
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
            $classId = (int) ($student['list_class_id'] ?? 0);
            unset($student['list_class_id']);
            if (isset($studentsByClass[$classId])) {
                $studentsByClass[$classId][] = $student;
            }
        }
        return $studentsByClass;
    }

    private function orderBy(string $sortOrder): string
    {
        return match ($sortOrder) {
            'en_alpha' => "CASE WHEN name_en IS NULL OR name_en = '' THEN 1 ELSE 0 END, name_en ASC, u.name ASC",
            'ar_female_first' => "CASE WHEN sp.gender = 'female' THEN 0 ELSE 1 END, u.name ASC",
            'ar_male_first' => "CASE WHEN sp.gender = 'male' THEN 0 ELSE 1 END, u.name ASC",
            'en_female_first' => "CASE WHEN sp.gender = 'female' THEN 0 ELSE 1 END, CASE WHEN name_en IS NULL OR name_en = '' THEN 1 ELSE 0 END, name_en ASC, u.name ASC",
            'en_male_first' => "CASE WHEN sp.gender = 'male' THEN 0 ELSE 1 END, CASE WHEN name_en IS NULL OR name_en = '' THEN 1 ELSE 0 END, name_en ASC, u.name ASC",
            default => 'u.name ASC',
        };
    }
}
