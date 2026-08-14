<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use AcademicYear;
use PDO;
use PDOException;

final class StudentListReadRepository
{
    private const REQUIRED_PROJECTED_FIELDS = [
        'id', 'name', 'status', 'class_name', 'student_code', 'enrollment_status', 'academic_status', 'national_id',
    ];

    private const FIELD_SELECTS = [
        'id' => 'u.id', 'name' => 'u.name', 'username' => 'u.username', 'class_id' => 'u.class_id',
        'status' => 'u.status', 'class_name' => 'c.name AS class_name',
        'grade_name' => 'g.grade_name AS grade_name', 'stage_name' => 's.stage_name AS stage_name',
        'profile_image_id' => "(SELECT MAX(sa.id) FROM student_attachments sa WHERE sa.user_id = u.id AND sa.label = 'الصورة الشخصية') AS profile_image_id",
        'student_code' => 'sp.student_code', 'enrollment_status' => 'sp.enrollment_status',
        'national_id' => 'sp.national_id', 'birth_date' => 'sp.birth_date', 'gender' => 'sp.gender',
        'religion' => 'sp.religion', 'city_area' => 'sp.city_area', 'phone_emergency' => 'sp.phone_emergency',
        'enrollment_date' => 'sp.enrollment_date', 'nationality' => 'sp.nationality',
        'birth_place' => 'sp.birth_place', 'ministry_code' => 'sp.ministry_code',
        'previous_school' => 'sp.previous_school', 'phone_mobile' => 'sp.phone_mobile',
        'phone_home' => 'sp.phone_home', 'passport_number' => 'sp.passport_number',
        'address_current' => 'sp.address_current', 'blood_type' => 'sp.blood_type',
        'insurance_number' => 'sp.insurance_number', 'insurance_start_date' => 'sp.insurance_start_date',
        'insurance_end_date' => 'sp.insurance_end_date', 'health_status' => 'sp.health_status',
        'chronic_diseases' => 'sp.chronic_diseases', 'allergies' => 'sp.allergies',
        'disabilities' => 'sp.disabilities', 'medications' => 'sp.medications',
        'treatment_plan' => 'sp.treatment_plan', 'previous_medical_reports' => 'sp.previous_medical_reports',
        'emergency_medical_notes' => 'sp.emergency_medical_notes', 'psychological_notes' => 'sp.psychological_notes',
        'first_name_en' => 'sp.first_name_en', 'second_name_en' => 'sp.second_name_en',
        'third_name_en' => 'sp.third_name_en', 'fourth_name_en' => 'sp.fourth_name_en',
        'family_name_en' => 'sp.family_name_en', 'age_years' => 'sp.age_years',
        'first_name_ar' => 'sp.first_name_ar', 'second_name_ar' => 'sp.second_name_ar',
        'third_name_ar' => 'sp.third_name_ar', 'fourth_name_ar' => 'sp.fourth_name_ar',
        'family_name_ar' => 'sp.family_name_ar',
        'age_months' => 'sp.age_months', 'age_days' => 'sp.age_days',
        'age_reference_date' => 'sp.age_reference_date', 'extra_phones' => 'sp.extra_phones',
        'extra_data' => 'sp.extra_data', 'notes' => 'sp.notes',
        'transfer_destination' => 'setr.destination AS transfer_destination',
        'external_transfer_date' => 'setr.transfer_date AS external_transfer_date',
        'external_transfer_reason' => 'setr.reason AS external_transfer_reason',
        'external_transfer_notes' => 'setr.notes AS external_transfer_notes',
        'father_name' => 'fa.guardian_name AS father_name', 'father_mobile' => 'fa.phone_primary AS father_mobile',
        'father_relationship' => 'fa.relationship AS father_relationship',
        'father_national_id' => 'fa.national_id AS father_national_id', 'father_landline' => 'fa.phone_landline AS father_landline',
        'father_email' => 'fa.email AS father_email', 'father_address' => 'fa.address AS father_address',
        'father_qualification' => 'fa.qualification AS father_qualification', 'father_job' => 'fa.job_title AS father_job',
        'father_employer' => 'fa.employer AS father_employer', 'father_work_phone' => 'fa.work_phone AS father_work_phone',
        'father_birth_date' => 'fa.birth_date AS father_birth_date', 'father_birth_place' => 'fa.birth_place AS father_birth_place',
        'father_religion' => 'fa.religion AS father_religion',
        'father_nationality' => 'fa.nationality AS father_nationality', 'father_passport' => 'fa.passport_number AS father_passport',
        'father_extra_phones' => 'fa.extra_phones AS father_extra_phones', 'father_extra_data' => 'fa.extra_data AS father_extra_data',
        'mother_name' => 'mo.guardian_name AS mother_name', 'mother_mobile' => 'mo.phone_primary AS mother_mobile',
        'mother_relationship' => 'mo.relationship AS mother_relationship',
        'mother_national_id' => 'mo.national_id AS mother_national_id', 'mother_landline' => 'mo.phone_landline AS mother_landline',
        'mother_email' => 'mo.email AS mother_email', 'mother_address' => 'mo.address AS mother_address',
        'mother_qualification' => 'mo.qualification AS mother_qualification', 'mother_job' => 'mo.job_title AS mother_job',
        'mother_employer' => 'mo.employer AS mother_employer', 'mother_work_phone' => 'mo.work_phone AS mother_work_phone',
        'mother_birth_date' => 'mo.birth_date AS mother_birth_date', 'mother_birth_place' => 'mo.birth_place AS mother_birth_place',
        'mother_religion' => 'mo.religion AS mother_religion',
        'mother_nationality' => 'mo.nationality AS mother_nationality', 'mother_passport' => 'mo.passport_number AS mother_passport',
        'mother_extra_phones' => 'mo.extra_phones AS mother_extra_phones', 'mother_extra_data' => 'mo.extra_data AS mother_extra_data',
        'siblings_count' => '(SELECT COUNT(*) FROM student_siblings ss_cnt WHERE ss_cnt.student_id = u.id) AS siblings_count',
        'siblings_info' => "(SELECT GROUP_CONCAT(CONCAT(sib_u.name, '||', COALESCE(sib_c.name, '—')) ORDER BY sib_u.name SEPARATOR ';;') FROM student_siblings ss_info JOIN users sib_u ON sib_u.id = ss_info.sibling_id AND sib_u.deleted_at IS NULL LEFT JOIN classes sib_c ON sib_c.id = sib_u.class_id WHERE ss_info.student_id = u.id) AS siblings_info",
    ];

    public function __construct(
        private PDO $db,
        private string $tableName = 'users'
    ) {}

    public function fetch($class_id = null, $allowed_class_ids = null, $limit = 100, $offset = 0, &$totalCount = 0, $grade_id = null, $stage_id = null, $scope = 'current', $account_status = null, $searchTerm = null, $orderBy = 'name', $orderDirection = 'asc', ?array $selectedFields = null)
    {
        require_once dirname(__DIR__, 3) . '/classes/AcademicYear.php';
        $yearId = AcademicYear::currentId($this->db);
        $useEnrollments = ($yearId > 0);

        $where = "u.role = 'student' AND u.deleted_at IS NULL";
        $countParams = [];
        $dataParams = [];

        if ($useEnrollments && $scope === 'graduates') {
            $where .= " AND (se.academic_status = 'graduated' OR se.enrollment_status = 'graduated')";
        } elseif ($useEnrollments && $scope === 'transferred') {
            $where .= " AND se.enrollment_status = 'transferred'";
        } elseif ($useEnrollments && $scope === 'discontinued') {
            $where .= " AND se.enrollment_status IN ('discontinued', 'withdrawn')";
        } elseif ($useEnrollments) {
            $where .= " AND se.enrollment_status = 'enrolled' AND se.academic_status <> 'graduated'";
        } elseif ($scope === 'graduates') {
            $where .= " AND COALESCE(sp.enrollment_status, IF(u.status = 'graduated', 'graduated', 'enrolled')) = 'graduated'";
        } elseif ($scope === 'transferred') {
            $where .= " AND COALESCE(sp.enrollment_status, 'enrolled') = 'transferred'";
        } elseif ($scope === 'discontinued') {
            $where .= " AND COALESCE(sp.enrollment_status, 'enrolled') IN ('discontinued', 'withdrawn')";
        } else {
            $where .= " AND COALESCE(sp.enrollment_status, IF(u.status = 'graduated', 'graduated', 'enrolled')) = 'enrolled'";
        }

        if (in_array($account_status, ['active', 'inactive', 'graduated'], true)) {
            $where .= " AND u.status = :account_status";
            $countParams[':account_status'] = $account_status;
            $dataParams[':account_status'] = $account_status;
        }
        $searchTerm = trim((string) $searchTerm);
        if ($searchTerm !== '') { $where .= " AND (u.name LIKE :student_search OR sp.student_code LIKE :student_search OR sp.national_id LIKE :student_search OR c.name LIKE :student_search)"; $countParams[':student_search']='%'.$searchTerm.'%'; $dataParams[':student_search']='%'.$searchTerm.'%'; }

        if ($useEnrollments) {
            if ($class_id) {
                if (is_array($class_id)) {
                    $classIds = array_map('intval', $class_id);
                    if (!empty($classIds)) {
                        $where .= " AND se.class_id IN (" . implode(',', $classIds) . ")";
                    }
                } else {
                    $where .= " AND se.class_id = :class_id";
                    $countParams[':class_id'] = (int) $class_id;
                    $dataParams[':class_id'] = (int) $class_id;
                }
            }
            if ($allowed_class_ids !== null) {
                $allowedIds = array_values(array_unique(array_filter(array_map('intval', (array) $allowed_class_ids))));
                $where .= $allowedIds === []
                    ? ' AND 1 = 0'
                    : " AND se.class_id IN (" . implode(',', $allowedIds) . ")";
            }
            if ($grade_id) {
                if (is_array($grade_id)) {
                    $gradeIds = array_map('intval', $grade_id);
                    if (!empty($gradeIds)) {
                        $where .= " AND se.grade_id IN (" . implode(',', $gradeIds) . ")";
                    }
                } else {
                    $where .= ' AND se.grade_id = :grade_id';
                    $countParams[':grade_id'] = (int) $grade_id;
                    $dataParams[':grade_id'] = (int) $grade_id;
                }
            }
            if ($stage_id) {
                if (is_array($stage_id)) {
                    $stageIds = array_map('intval', $stage_id);
                    if (!empty($stageIds)) {
                        $where .= " AND se.stage_id IN (" . implode(',', $stageIds) . ")";
                    }
                } else {
                    $where .= ' AND se.stage_id = :stage_id';
                    $countParams[':stage_id'] = (int) $stage_id;
                    $dataParams[':stage_id'] = (int) $stage_id;
                }
            }
            $where .= " AND se.academic_year_id = :year_id";
            $countParams[':year_id'] = $yearId;
            $dataParams[':year_id'] = $yearId;

            $enrollJoin = "JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = :year_id
                           LEFT JOIN classes c ON c.id = se.class_id
                           LEFT JOIN grades g ON g.id = se.grade_id
                           LEFT JOIN stages s ON s.id = se.stage_id";
        } else {
            if ($class_id) {
                if (is_array($class_id)) {
                    $classIds = array_map('intval', $class_id);
                    if (!empty($classIds)) {
                        $where .= " AND u.class_id IN (" . implode(',', $classIds) . ")";
                    }
                } else {
                    $where .= " AND u.class_id = :class_id";
                    $countParams[':class_id'] = (int) $class_id;
                    $dataParams[':class_id'] = (int) $class_id;
                }
            }
            if ($allowed_class_ids !== null) {
                $allowedIds = array_values(array_unique(array_filter(array_map('intval', (array) $allowed_class_ids))));
                $where .= $allowedIds === []
                    ? ' AND 1 = 0'
                    : " AND u.class_id IN (" . implode(',', $allowedIds) . ")";
            }
            if ($grade_id) {
                if (is_array($grade_id)) {
                    $gradeIds = array_map('intval', $grade_id);
                    if (!empty($gradeIds)) {
                        $where .= " AND c.grade_id IN (" . implode(',', $gradeIds) . ")";
                    }
                } else {
                    $where .= ' AND c.grade_id = :grade_id';
                    $countParams[':grade_id'] = (int) $grade_id;
                    $dataParams[':grade_id'] = (int) $grade_id;
                }
            }
            if ($stage_id) {
                if (is_array($stage_id)) {
                    $stageIds = array_map('intval', $stage_id);
                    if (!empty($stageIds)) {
                        $where .= " AND g.stage_id IN (" . implode(',', $stageIds) . ")";
                    }
                } else {
                    $where .= ' AND g.stage_id = :stage_id';
                    $countParams[':stage_id'] = (int) $stage_id;
                    $dataParams[':stage_id'] = (int) $stage_id;
                }
            }
            $enrollJoin = "LEFT JOIN classes c ON c.id = u.class_id
                           LEFT JOIN grades g ON g.id = c.grade_id
                           LEFT JOIN stages s ON s.id = g.stage_id";
        }

        $countSql = "SELECT COUNT(*) FROM {$this->tableName} u
                     {$enrollJoin}
                     LEFT JOIN student_profiles sp ON sp.user_id = u.id
                     WHERE {$where}";
        $countStmt = $this->db->prepare($countSql);
        foreach ($countParams as $k => $v) {
            $countStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalCount = (int) $countStmt->fetchColumn();

        if ($limit <= 0) {
            return [];
        }

        $orderMap = [
            'id' => 'u.id', 'student_code' => 'sp.student_code', 'ministry_code' => 'sp.ministry_code',
            'national_id' => 'sp.national_id', 'name' => 'u.name', 'class_name' => 'c.name',
            'grade_name' => 'g.grade_name', 'stage_name' => 's.stage_name', 'status' => 'u.status',
            'enrollment_status' => $useEnrollments ? 'se.enrollment_status' : 'sp.enrollment_status',
            'academic_status' => $useEnrollments ? 'se.academic_status' : 'u.status',
            'birth_date' => 'sp.birth_date', 'gender' => 'sp.gender', 'religion' => 'sp.religion',
            'nationality' => 'sp.nationality', 'birth_place' => 'sp.birth_place',
            'passport_number' => 'sp.passport_number', 'city_area' => 'sp.city_area',
            'phone_mobile' => 'sp.phone_mobile', 'phone_home' => 'sp.phone_home',
            'phone_emergency' => 'sp.phone_emergency', 'enrollment_date' => 'sp.enrollment_date',
            'previous_school' => 'sp.previous_school', 'blood_type' => 'sp.blood_type',
            'insurance_number' => 'sp.insurance_number', 'transfer_destination' => 'setr.destination',
            'external_transfer_date' => 'setr.transfer_date',
        ];
        $orderSql=$orderMap[$orderBy] ?? 'u.name'; $orderDirection=strtolower((string)$orderDirection)==='desc'?'DESC':'ASC';
        $annualEnrollmentStatusSelect = $useEnrollments
            ? "CASE WHEN se.enrollment_status = 'withdrawn' THEN 'discontinued' WHEN se.enrollment_status = 'graduated' THEN 'enrolled' ELSE se.enrollment_status END AS enrollment_status"
            : 'sp.enrollment_status AS enrollment_status';
        $annualAcademicStatusSelect = $useEnrollments
            ? "CASE WHEN se.enrollment_status = 'graduated' THEN 'graduated' ELSE se.academic_status END AS academic_status"
            : "CASE WHEN COALESCE(sp.enrollment_status, '') = 'graduated' OR u.status = 'graduated' THEN 'graduated' ELSE 'new' END AS academic_status";
        $selectSql = $selectedFields === null
            ? "u.id, u.name, u.username, u.class_id, u.status, c.name AS class_name,
                           (SELECT MAX(sa.id) FROM student_attachments sa WHERE sa.user_id = u.id AND sa.label = 'الصورة الشخصية') AS profile_image_id,
                           sp.student_code, {$annualEnrollmentStatusSelect}, {$annualAcademicStatusSelect},
                           sp.national_id, sp.birth_date, sp.gender, sp.religion,
                           sp.city_area, sp.phone_emergency, sp.enrollment_date,
                           sp.nationality, sp.birth_place, sp.ministry_code, sp.previous_school,
                           sp.phone_mobile, sp.phone_home, sp.passport_number, sp.address_current,
                           sp.blood_type, sp.insurance_number, sp.insurance_start_date, sp.insurance_end_date,
                           sp.health_status, sp.chronic_diseases, sp.allergies, sp.disabilities,
                           sp.medications, sp.treatment_plan, sp.previous_medical_reports,
                           sp.emergency_medical_notes, sp.psychological_notes,
                           sp.first_name_en, sp.second_name_en, sp.third_name_en, sp.fourth_name_en, sp.family_name_en,
                           sp.age_years, sp.age_months, sp.age_days,
                           sp.extra_data, sp.notes,
                           setr.destination AS transfer_destination, setr.transfer_date AS external_transfer_date,
                           fa.guardian_name AS father_name, fa.phone_primary AS father_mobile,
                           fa.national_id AS father_national_id, fa.phone_landline AS father_landline,
                           fa.email AS father_email, fa.address AS father_address,
                           fa.qualification AS father_qualification, fa.job_title AS father_job,
                           fa.employer AS father_employer, fa.work_phone AS father_work_phone,
                           fa.birth_date AS father_birth_date, fa.religion AS father_religion,
                           fa.nationality AS father_nationality, fa.passport_number AS father_passport,
                           mo.guardian_name AS mother_name, mo.phone_primary AS mother_mobile,
                           mo.national_id AS mother_national_id, mo.phone_landline AS mother_landline,
                           mo.email AS mother_email, mo.address AS mother_address,
                           mo.qualification AS mother_qualification, mo.job_title AS mother_job,
                           mo.employer AS mother_employer, mo.work_phone AS mother_work_phone,
                           mo.birth_date AS mother_birth_date, mo.religion AS mother_religion,
                           mo.nationality AS mother_nationality, mo.passport_number AS mother_passport,
                           (SELECT COUNT(*) FROM student_siblings ss_cnt WHERE ss_cnt.student_id = u.id) AS siblings_count,
                           (SELECT GROUP_CONCAT(CONCAT(sib_u.name, '||', COALESCE(sib_c.name, '—')) ORDER BY sib_u.name SEPARATOR ';;')
                            FROM student_siblings ss_info
                            JOIN users sib_u ON sib_u.id = ss_info.sibling_id AND sib_u.deleted_at IS NULL
                            LEFT JOIN classes sib_c ON sib_c.id = sib_u.class_id
                            WHERE ss_info.student_id = u.id) AS siblings_info"
            : $this->projectedSelect($selectedFields, $scope, $useEnrollments);
        $dataSql = "SELECT {$selectSql}
                    FROM {$this->tableName} u
                    {$enrollJoin}
                    LEFT JOIN student_profiles sp ON u.id = sp.user_id
                    LEFT JOIN student_external_transfers setr ON setr.id = (
                        SELECT setr_latest.id
                        FROM student_external_transfers setr_latest
                        WHERE setr_latest.student_id = u.id
                        ORDER BY setr_latest.transfer_date DESC, setr_latest.id DESC
                        LIMIT 1
                    )
                    LEFT JOIN student_guardians fa ON fa.id = (
                        SELECT MIN(fa_latest.id)
                        FROM student_guardians fa_latest
                        WHERE fa_latest.student_id = u.id AND fa_latest.relationship = 'father'
                    )
                    LEFT JOIN student_guardians mo ON mo.id = (
                        SELECT MIN(mo_latest.id)
                        FROM student_guardians mo_latest
                        WHERE mo_latest.student_id = u.id AND mo_latest.relationship = 'mother'
                    )
                    WHERE {$where}
                    ORDER BY {$orderSql} {$orderDirection}, u.id ASC
                    LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($dataSql);
        foreach ($dataParams as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);

        try {
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $this->hydrateRelatedFields($rows, $selectedFields);
        } catch (PDOException $e) {
            error_log("Database error in getStudentsPaginated: " . $e->getMessage());
            $totalCount = 0;
            return [];
        }
    }

    private function projectedSelect(array $selectedFields, string $scope, bool $useEnrollments): string
    {
        $fields = array_merge(self::REQUIRED_PROJECTED_FIELDS, $selectedFields);
        if ($scope === 'transferred') {
            $fields[] = 'transfer_destination';
            $fields[] = 'external_transfer_date';
        }

        $selects = [];
        foreach (array_unique($fields) as $field) {
            if ($field === 'enrollment_status') {
                $selects[] = $useEnrollments
                    ? "CASE WHEN se.enrollment_status = 'withdrawn' THEN 'discontinued' WHEN se.enrollment_status = 'graduated' THEN 'enrolled' ELSE se.enrollment_status END AS enrollment_status"
                    : 'sp.enrollment_status AS enrollment_status';
                continue;
            }
            if ($field === 'academic_status') {
                $selects[] = $useEnrollments
                    ? "CASE WHEN se.enrollment_status = 'graduated' THEN 'graduated' ELSE se.academic_status END AS academic_status"
                    : "CASE WHEN COALESCE(sp.enrollment_status, '') = 'graduated' OR u.status = 'graduated' THEN 'graduated' ELSE 'new' END AS academic_status";
                continue;
            }
            if (isset(self::FIELD_SELECTS[$field])) {
                $selects[] = self::FIELD_SELECTS[$field];
            }
        }

        return implode(",\n                           ", $selects);
    }

    private function hydrateRelatedFields(array $rows, ?array $selectedFields): array
    {
        if ($rows === [] || $selectedFields === null) {
            return $rows;
        }

        $requested = array_fill_keys(array_map('strval', $selectedFields), true);
        $studentIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $rows
        ))));
        if ($studentIds === []) {
            return $rows;
        }

        $rowIndexes = [];
        foreach ($rows as $index => $row) {
            $rowIndexes[(int) $row['id']] = $index;
        }
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));

        if (isset($requested['other_guardians'])) {
            $stmt = $this->db->prepare(
                "SELECT * FROM student_guardians
                 WHERE student_id IN ({$placeholders})
                   AND relationship NOT IN ('father', 'mother')
                 ORDER BY student_id, id"
            );
            $stmt->execute($studentIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $guardian) {
                $studentId = (int) ($guardian['student_id'] ?? 0);
                if (isset($rowIndexes[$studentId])) {
                    $rows[$rowIndexes[$studentId]]['other_guardians_rows'][] = $guardian;
                }
            }
        }

        if (isset($requested['attachments'])) {
            $stmt = $this->db->prepare(
                "SELECT user_id, COALESCE(NULLIF(original_name, ''), NULLIF(label, ''), 'مرفق') AS display_name
                 FROM student_attachments
                 WHERE user_id IN ({$placeholders})
                 ORDER BY user_id, uploaded_at, id"
            );
            $stmt->execute($studentIds);
            $names = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $attachment) {
                $names[(int) $attachment['user_id']][] = (string) $attachment['display_name'];
            }
            foreach ($rowIndexes as $studentId => $index) {
                $rows[$index]['attachments'] = isset($names[$studentId]) ? implode('، ', $names[$studentId]) : null;
            }
        }

        if (isset($requested['kinships'])) {
            $stmt = $this->db->prepare(
                "SELECT sk.student_id, COALESCE(kt.name, 'صلة قرابة') AS kinship_name,
                        rel_u.name AS relative_name, sk.notes
                 FROM student_kinships sk
                 JOIN users rel_u ON rel_u.id = sk.relative_id AND rel_u.deleted_at IS NULL
                 LEFT JOIN kinship_types kt ON kt.id = sk.kinship_type_id
                 WHERE sk.student_id IN ({$placeholders})
                 ORDER BY sk.student_id, kt.name, rel_u.name"
            );
            $stmt->execute($studentIds);
            $kinships = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $kinship) {
                $value = (string) $kinship['kinship_name'] . ': ' . (string) $kinship['relative_name'];
                if (trim((string) ($kinship['notes'] ?? '')) !== '') {
                    $value .= ' (' . trim((string) $kinship['notes']) . ')';
                }
                $kinships[(int) $kinship['student_id']][] = $value;
            }
            foreach ($rowIndexes as $studentId => $index) {
                $rows[$index]['kinships'] = isset($kinships[$studentId]) ? implode('، ', $kinships[$studentId]) : null;
            }
        }

        if (isset($requested['academic_history'])) {
            $stmt = $this->db->prepare(
                "SELECT se.student_id, ay.name AS academic_year, se.enrollment_status, se.academic_status,
                        st.stage_name, g.grade_name, c.name AS class_name, se.enrollment_date
                 FROM student_enrollments se
                 LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
                 LEFT JOIN stages st ON st.id = se.stage_id
                 LEFT JOIN grades g ON g.id = se.grade_id
                 LEFT JOIN classes c ON c.id = se.class_id
                 WHERE se.student_id IN ({$placeholders})
                 ORDER BY se.student_id, ay.start_date, se.id"
            );
            $stmt->execute($studentIds);
            $history = [];
            $enrollmentLabels = ['enrolled' => 'مقيد', 'transferred' => 'منقول', 'discontinued' => 'منقطع', 'withdrawn' => 'منسحب', 'graduated' => 'خريج'];
            $academicLabels = ['new' => 'مستجد', 'promoted' => 'ناجح ومنقول', 'retained' => 'راسب', 'graduated' => 'خريج', 'pending' => 'قيد المراجعة'];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $entry) {
                $parts = array_values(array_filter([
                    trim((string) ($entry['academic_year'] ?? '')),
                    trim((string) ($entry['stage_name'] ?? '')),
                    trim((string) ($entry['grade_name'] ?? '')),
                    trim((string) ($entry['class_name'] ?? '')),
                    $enrollmentLabels[(string) ($entry['enrollment_status'] ?? '')] ?? trim((string) ($entry['enrollment_status'] ?? '')),
                    $academicLabels[(string) ($entry['academic_status'] ?? '')] ?? trim((string) ($entry['academic_status'] ?? '')),
                    trim((string) ($entry['enrollment_date'] ?? '')),
                ], static fn(string $value): bool => $value !== ''));
                if ($parts !== []) {
                    $history[(int) $entry['student_id']][] = implode(' - ', $parts);
                }
            }
            foreach ($rowIndexes as $studentId => $index) {
                $rows[$index]['academic_history'] = isset($history[$studentId]) ? implode(' | ', $history[$studentId]) : null;
            }
        }

        return $rows;
    }

    /**
     * Get students by multiple classes
     * @param array $class_ids Array of class IDs
     * @return array Array of students
     */
    public function getStudentsByClasses($class_ids)
    {
        if (empty($class_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($class_ids), '?'));

        $query = "SELECT u.id, u.name, u.username, u.class_id, u.status, u.created_at, c.name as class_name
                  FROM " . $this->tableName . " u
                  LEFT JOIN classes c ON u.class_id = c.id
                  WHERE u.role = 'student'
                  AND u.class_id IN ($placeholders)
                  ORDER BY c.name, u.name";

        $stmt = $this->db->prepare($query);
        $stmt->execute($class_ids);

        $students = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $students[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'username' => $row['username'],
                'class_id' => $row['class_id'],
                'class_name' => $row['class_name'],
                'status' => $row['status'],
                'created_at' => $row['created_at']
            ];
        }

        return $students;
    }

    /**
     * Get student by ID
     * @param int $student_id
     * @return array|null Student data or null if not found
     */
    public function getStudentById($student_id)
    {
        $query = "SELECT u.id, u.name, u.username, u.class_id, u.status, u.created_at, c.name as class_name
                  FROM " . $this->tableName . " u
                  LEFT JOIN classes c ON u.class_id = c.id
                  WHERE u.id = :student_id
                  AND u.role = 'student'";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'username' => $row['username'],
                'class_id' => $row['class_id'],
                'class_name' => $row['class_name'],
                'created_at' => $row['created_at']
            ];
        }

        return null;
    }
}
