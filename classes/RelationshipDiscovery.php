<?php

class RelationshipDiscovery
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private static function normalizeName($value): string
    {
        $value = str_replace(
            ['أ', 'إ', 'آ', 'ى', 'ة'],
            ['ا', 'ا', 'ا', 'ي', 'ه'],
            trim((string)$value)
        );

        $value = preg_replace('/\s+/u', ' ', $value) ?: '';
        $placeholders = ['', 'null', 'none', 'n/a', '-', '--', '0', 'غير معروف', 'غير محدد'];
        return in_array(mb_strtolower($value, 'UTF-8'), $placeholders, true) ? '' : $value;
    }

    private static function pairKey(int $firstId, int $secondId): string
    {
        return min($firstId, $secondId) . ':' . max($firstId, $secondId);
    }

    public function discover(): array
    {
        $students = $this->db->query(
            "SELECT u.id, u.name, sp.gender, sp.student_code,
                    sp.second_name_ar, sp.third_name_ar, sp.family_name_ar,
                    c.name AS class_name
             FROM users u
             LEFT JOIN student_profiles sp ON sp.user_id = u.id
             LEFT JOIN classes c ON c.id = u.class_id
             WHERE u.role = 'student' AND u.status = 'active'
               AND NOT EXISTS (SELECT 1 FROM student_profiles esp WHERE esp.user_id=u.id AND esp.enrollment_status <> 'enrolled')
             ORDER BY u.name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $studentsById = [];
        $fatherGroups = [];
        $cousinGroups = [];
        foreach ($students as $student) {
            $id = (int)$student['id'];
            $studentsById[$id] = $student;
            $father = self::normalizeName($student['second_name_ar']);
            $grandfather = self::normalizeName($student['third_name_ar']);
            $family = self::normalizeName($student['family_name_ar']);

            // اقتراح الأشقاء من الاسم يحتاج السلسلة الثلاثية كاملة؛ اسمان شائعان لا يكفيان.
            if ($father !== '' && $grandfather !== '' && $family !== '') {
                $fatherGroups[$father . '|' . $grandfather . '|' . $family][] = $student;
            }
            if ($grandfather !== '' && $family !== '') {
                $cousinGroups[$grandfather . '|' . $family][] = $student;
            }
        }

        $existingSiblings = $this->existingPairs('student_siblings', 'student_id', 'sibling_id');
        $existingKinships = $this->existingPairs('student_kinships', 'student_id', 'relative_id');

        $motherGroups = [];
        $motherRows = $this->db->query(
            "SELECT student_id, guardian_name, national_id
             FROM student_guardians
             WHERE LOWER(TRIM(relationship)) = 'mother'"
        );
        foreach ($motherRows as $guardian) {
            $studentId = (int)$guardian['student_id'];
            if (!isset($studentsById[$studentId])) {
                continue;
            }

            $nationalId = preg_replace('/\D+/', '', (string)$guardian['national_id']);
            $motherName = self::normalizeName($guardian['guardian_name']);
            $nameParts = array_values(array_filter(explode(' ', $motherName)));
            $key = strlen($nationalId) >= 10
                ? 'nid:' . $nationalId
                : (count($nameParts) >= 3 ? 'name:' . $motherName : '');

            if ($key !== '') {
                $studentData = $studentsById[$studentId];
                $studentData['mother_name'] = trim($guardian['guardian_name']);
                if (strlen($nationalId) >= 10) {
                    $studentData['mother_national_id'] = $nationalId;
                }
                $motherGroups[$key][$studentId] = $studentData;
            }
        }

        $siblings = array_merge(
            $this->siblingPairs($fatherGroups, $existingSiblings, 'father', 'تطابق اسم الأب والجد/العائلة'),
            $this->siblingPairs($motherGroups, $existingSiblings, 'mother', 'تطابق بيانات الأم')
        );

        return [
            'siblings' => $siblings,
            'kinships' => $this->cousinPairs($cousinGroups, $existingKinships, $existingSiblings),
        ];
    }

    private function existingPairs(string $table, string $firstColumn, string $secondColumn): array
    {
        $pairs = [];
        foreach ($this->db->query("SELECT {$firstColumn}, {$secondColumn} FROM {$table}") as $row) {
            $pairs[self::pairKey((int)$row[0], (int)$row[1])] = true;
        }
        return $pairs;
    }

    private function siblingPairs(array $groups, array $existing, string $basis, string $reason): array
    {
        $pairs = [];
        foreach ($groups as $members) {
            $members = array_values($members);
            for ($i = 0; $i < count($members); $i++) {
                for ($j = $i + 1; $j < count($members); $j++) {
                    $key = self::pairKey((int)$members[$i]['id'], (int)$members[$j]['id']);
                    if (!isset($existing[$key])) {
                        $pairs[] = [
                            'basis' => $basis,
                            'reason' => $reason,
                            'confidence' => $basis === 'mother' ? 'high' : 'medium',
                            'members' => [$members[$i], $members[$j]],
                        ];
                    }
                }
            }
        }
        return $pairs;
    }

    private function cousinPairs(array $groups, array $existingKinships, array $existingSiblings): array
    {
        $pairs = [];
        foreach ($groups as $members) {
            $members = array_values($members);
            for ($i = 0; $i < count($members); $i++) {
                for ($j = $i + 1; $j < count($members); $j++) {
                    $firstFather = self::normalizeName($members[$i]['second_name_ar']);
                    $secondFather = self::normalizeName($members[$j]['second_name_ar']);
                    $key = self::pairKey((int)$members[$i]['id'], (int)$members[$j]['id']);

                    if ($firstFather === '' || $secondFather === '' || $firstFather === $secondFather) {
                        continue;
                    }
                    if (isset($existingKinships[$key]) || isset($existingSiblings[$key])) {
                        continue;
                    }

                    $pairs[] = [
                        'basis' => 'paternal_cousins',
                        'reason' => 'تطابق اسم الجد والعائلة مع اختلاف اسم الأب',
                        'confidence' => 'candidate',
                        'members' => [$members[$i], $members[$j]],
                    ];
                }
            }
        }
        return $pairs;
    }
}
