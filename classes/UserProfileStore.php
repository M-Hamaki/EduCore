<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
require_once __DIR__ . '/StaffEmploymentLifecycleService.php';

final class UserProfileStore
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    // ==========================================
    // نظام الموارد البشرية - Staff Profiles
    // ==========================================

    /**
     * جلب بيانات الملف الشخصي للموظف
     */
    public function getStaffProfile($userId)
    {
        $stmt = $this->conn->prepare("SELECT * FROM staff_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * إنشاء أو تحديث الملف الشخصي للموظف
     */
    public function saveStaffProfile($userId, $data)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $beforeProfile = $ownsTransaction ? $this->fetchByUserId('staff_profiles', (int)$userId, true) : [];
        $beforeUser = $ownsTransaction ? $this->fetchById('users', (int)$userId, true) : [];
        // فحص وجود سجل
        $check = $this->conn->prepare("SELECT * FROM staff_profiles WHERE user_id = ?");
        $check->execute([$userId]);
        $exists = $check->fetch(PDO::FETCH_ASSOC);
        $data = array_merge($exists ?: [], $data);
        $data['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($data['job_title'] ?? null);
        $profileName = trim((string) ($data['full_name_ar'] ?? ''));

        $fields = [
            'employee_code',
            'biometric_id',
            'ministry_code',
            'full_name_ar',
            'full_name_en',
            'national_id',
            'passport_number',
            'birth_date',
            'birth_place',
            'gender',
            'religion',
            'nationality',
            'address_detail',
            'city_area',
            'phone_mobile',
            'phone_home',
            'phone_emergency',
            'extra_phones',
            'extra_data',
            'extra_employment_data',
            'emergency_contact_name',
            'email_personal',
            'marital_status',
            'military_status',
            'public_service_status',
            'number_of_children',
            'blood_type',
            'hire_date',
            'job_title',
            'department',
            'job_grade',
            'contract_type',
            'contract_start',
            'contract_end',
            'current_work_status',
            'current_status_reason',
            'current_status_effective_date',
            'first_hire_date',
            'latest_hire_date',
            'last_working_day',
            'can_rehire',
            'last_job_movement_date',
            'admin_notes',
            'qualification',
            'qualification_year',
            'qualification_university',
            'specialization',
            'other_qualifications',
            'training_courses',
            'years_of_experience',
            'work_history',
            'promotions',
            'status_history',
            'insurance_number',
            'insurance_start_date',
            'insurance_end_date',
            'treatment_plan',
            'health_issues',
            'health_status',
            'chronic_diseases',
            'allergies',
            'disabilities',
            'medications',
            'previous_medical_reports',
            'emergency_medical_notes',
            'psychological_notes',
            'notes',
            'basic_salary',
            'allowance_transport',
            'allowance_housing',
            'other_allowances_data',
            'other_deductions_data',
            'deduction_insurance',
            'deduction_tax',
            'net_salary',
            'financial_notes',
            'advances_data',
            'profile_image'
        ];

        // فلترة البيانات المرسلة فقط
        $filtered = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $val = $data[$f];
                // تحويل القيم الفارغة لـ NULL للحقول DATE/YEAR
                if (in_array($f, ['birth_date', 'hire_date', 'contract_start', 'contract_end', 'current_status_effective_date', 'first_hire_date', 'latest_hire_date', 'last_working_day', 'last_job_movement_date', 'insurance_start_date', 'insurance_end_date', 'qualification_year']) && $val === '') {
                    $val = null;
                }
                if ($f === 'number_of_children' && $val === '') {
                    $val = 0;
                }
                if ($f === 'biometric_id' && trim((string)$val) === '') {
                    $val = null;
                }
                // تحويل الحقول الرقمية الفارغة لـ NULL
                if (
                    in_array($f, [
                        'basic_salary',
                        'allowance_transport',
                        'allowance_housing',
                        'deduction_insurance',
                        'deduction_tax',
                        'net_salary',
                        'years_of_experience'
                    ]) && $val === ''
                ) {
                    $val = null;
                }
                $filtered[$f] = $val;
            }
        }

        if (empty($filtered)) {
            if ($ownsTransaction) $this->conn->rollBack();
            return false;
        }

        if ($exists) {
            // تحديث
            $setParts = [];
            $params = [];
            foreach ($filtered as $k => $v) {
                $setParts[] = "`$k` = ?";
                $params[] = $v;
            }
            $params[] = $userId;
            $sql = "UPDATE staff_profiles SET " . implode(', ', $setParts) . " WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $saved = $stmt->execute($params);
        } else {
            // إنشاء
            $filtered['user_id'] = $userId;
            $cols = array_keys($filtered);
            $placeholders = array_fill(0, count($cols), '?');
            $sql = "INSERT INTO staff_profiles (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $this->conn->prepare($sql);
            $saved = $stmt->execute(array_values($filtered));
        }
        if ($saved && $profileName !== '') {
            $nameStmt = $this->conn->prepare('UPDATE users SET name = ? WHERE id = ?');
            $saved = $nameStmt->execute([$profileName, $userId]);
        }
        if ($saved && $ownsTransaction) {
            $afterProfile = $this->fetchByUserId('staff_profiles', (int)$userId, false);
            $afterUser = $this->fetchById('users', (int)$userId, false);
            $this->auditProfileSave('staff_profile', 'staff_profiles', (int)$userId, $beforeProfile, $afterProfile, $beforeUser, $afterUser);
            $this->conn->commit();
        } elseif ($ownsTransaction) {
            $this->conn->rollBack();
        }
        return $saved;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * جلب كل العاملين مع بيانات الملف الشخصي
     */
    public function readAllStaffWithProfiles()
    {
        $query = "SELECT u.id, u.name, u.username, u.password, u.role, u.is_supervisor, u.status,
                          sp.employee_code,
                          NULLIF(TRIM(sp.biometric_id), '') AS biometric_id,
                          sp.ministry_code,
                          sp.full_name_ar, sp.national_id, sp.phone_mobile, sp.job_title,
                          sp.department, sp.hire_date, sp.qualification, sp.contract_type,
                          sp.current_work_status, sp.current_status_effective_date,
                          sp.passport_number, sp.birth_date, sp.birth_place, sp.gender, sp.religion,
                          sp.nationality, sp.address_detail, sp.city_area, sp.phone_home, sp.phone_emergency,
                          sp.email_personal, sp.emergency_contact_name, sp.marital_status,
                          sp.military_status, sp.public_service_status, sp.number_of_children,
                          sp.qualification_year, sp.qualification_university, sp.specialization, sp.years_of_experience,
                          sp.blood_type, sp.insurance_number, sp.insurance_start_date, sp.insurance_end_date,
                          sp.health_status, sp.chronic_diseases, sp.allergies, sp.disabilities,
                          sp.medications, sp.treatment_plan, sp.previous_medical_reports,
                          sp.emergency_medical_notes, sp.psychological_notes, sp.notes,
                          GROUP_CONCAT(DISTINCT
                               CASE WHEN u.role = 'specialist' THEN sc_c.name
                                    ELSE uca_c.name END
                              ORDER BY CASE WHEN u.role = 'specialist' THEN sc_c.name ELSE uca_c.name END
                              SEPARATOR ', ') as class_names,
                         GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as subject_names
                  FROM users u
                  LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                  LEFT JOIN user_class_access uca ON u.id = uca.user_id AND u.role = 'teacher'
                  LEFT JOIN classes uca_c ON uca.class_id = uca_c.id
                  LEFT JOIN specialist_active_classes sc ON u.id = sc.specialist_id AND u.role = 'specialist'
                  LEFT JOIN classes sc_c ON sc.class_id = sc_c.id
                  LEFT JOIN teacher_subjects ts ON u.id = ts.teacher_id AND u.role = 'teacher'
                  LEFT JOIN subjects s ON ts.subject_id = s.id
                  WHERE COALESCE(u.role, '') NOT IN ('admin', 'student')
                    AND (sp.user_id IS NOT NULL OR u.role IN ('teacher', 'specialist'))
                  GROUP BY u.id
                  ORDER BY u.name";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        $staff = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // ملاحظة أداء: لا نُفك تشفير كلمة المرور لكل صف في قائمة العرض —
            // كلمة المرور غير مستخدمة في عرض القائمة، وفك التشفير عملية مكلفة تتكرر لكل موظف.
            unset($row['password']);
            $row['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null);
            $staff[] = $row;
        }

        return $staff;
    }

    public function readStaffWithProfilesPaginated(int $limit, int $offset, int &$totalCount, array $filters = [], string $orderBy = 'name', string $orderDirection = 'asc'): array
    {
        $where = "COALESCE(u.role, '') NOT IN ('admin', 'student') AND (sp.user_id IS NOT NULL OR u.role IN ('teacher', 'specialist'))";
        $biometricExpression = "NULLIF(TRIM(sp.biometric_id), '')";
        $params = [];
        $jobTitle = trim((string) ($filters['job_title'] ?? ''));
        $jobTitles = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($filters['job_titles'] ?? [])
        ))));
        if ($jobTitles === [] && $jobTitle !== '') {
            $jobTitles = StaffEmploymentLifecycleService::jobTitleFilterValues($jobTitle);
        }
        $invalidJobTitleFilter = $jobTitle !== '' && $jobTitles === [];
        $force = trim((string) ($filters['force'] ?? ''));
        $workStatus = trim((string) ($filters['work_status'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        if ($jobTitles) {
            $jobTitlePlaceholders = [];
            foreach ($jobTitles as $index => $value) {
                $placeholder = ':job_title_' . $index;
                $jobTitlePlaceholders[] = $placeholder;
                $params[$placeholder] = $value;
            }
            $where .= ' AND sp.job_title IN (' . implode(', ', $jobTitlePlaceholders) . ')';
        } elseif ($invalidJobTitleFilter) {
            $where .= ' AND 1 = 0';
        }
        if ($force !== '') { $where .= " AND FIND_IN_SET(:force, REPLACE(sp.department, ', ', ','))"; $params[':force'] = $force; }
        if (in_array($workStatus, ['on_duty', 'off_duty'], true)) { $where .= ' AND COALESCE(sp.current_work_status, \'on_duty\') = :work_status'; $params[':work_status'] = $workStatus; }
        if ($search !== '') {
            $where .= " AND (u.name LIKE :staff_search OR sp.full_name_ar LIKE :staff_search OR sp.employee_code LIKE :staff_search OR {$biometricExpression} LIKE :staff_search OR sp.national_id LIKE :staff_search OR sp.job_title LIKE :staff_search OR sp.phone_mobile LIKE :staff_search)";
            $params[':staff_search'] = '%' . $search . '%';
        }

        $count = $this->conn->prepare("SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN staff_profiles sp ON u.id = sp.user_id WHERE {$where}");
        $count->execute($params);
        $totalCount = (int) $count->fetchColumn();
        if ($limit <= 0) return [];

        $orderMap = ['id' => 'u.id', 'biometric_id' => $biometricExpression, 'employee_code' => 'sp.employee_code', 'name' => 'u.name', 'job_title' => 'sp.job_title', 'phone_mobile' => 'sp.phone_mobile', 'national_id' => 'sp.national_id', 'current_work_status' => 'sp.current_work_status'];
        $orderSql = $orderMap[$orderBy] ?? 'u.name';
        $direction = strtolower($orderDirection) === 'desc' ? 'DESC' : 'ASC';
        $sql = "SELECT u.id, u.name, u.username, u.role, u.is_supervisor, u.status,
                       sp.employee_code, {$biometricExpression} AS biometric_id,
                       sp.ministry_code, sp.full_name_ar, sp.full_name_en,
                       sp.national_id, sp.phone_mobile, sp.job_title, sp.department, sp.job_grade,
                       sp.hire_date, sp.qualification, sp.contract_type, sp.contract_start, sp.contract_end,
                       sp.current_work_status, sp.current_status_reason, sp.current_status_effective_date,
                       sp.first_hire_date, sp.latest_hire_date, sp.last_working_day, sp.can_rehire,
                       sp.last_job_movement_date,
                       sp.passport_number, sp.birth_date, sp.birth_place, sp.gender, sp.religion, sp.nationality, sp.address_detail, sp.city_area, sp.phone_home, sp.phone_emergency, sp.email_personal, sp.emergency_contact_name, sp.marital_status, sp.military_status, sp.public_service_status, sp.number_of_children,
                       sp.qualification_year, sp.qualification_university, sp.specialization, sp.years_of_experience, sp.blood_type, sp.insurance_number, sp.insurance_start_date, sp.insurance_end_date, sp.health_status, sp.chronic_diseases, sp.allergies, sp.disabilities, sp.medications, sp.treatment_plan, sp.previous_medical_reports, sp.emergency_medical_notes, sp.psychological_notes, sp.notes,
                       sp.admin_notes, sp.extra_phones, sp.extra_data, sp.extra_employment_data,
                       sp.other_qualifications, sp.training_courses, sp.work_history, sp.profile_image,
                       (SELECT COUNT(*) FROM staff_status_history ssh WHERE ssh.user_id = u.id) AS status_history_count,
                       (SELECT COUNT(*) FROM staff_job_movements sjm WHERE sjm.user_id = u.id) AS job_movements_count,
                       (SELECT COUNT(*) FROM staff_attachments sa WHERE sa.user_id = u.id) AS attachment_count
                FROM users u
                LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                WHERE {$where}
                ORDER BY {$orderSql} {$direction}, u.id ASC
                LIMIT :limit OFFSET :offset";
        $statement = $this->conn->prepare($sql);
        foreach ($params as $name => $value) $statement->bindValue($name, $value, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null);
        }
        unset($row);
        return $rows;
    }

    public function getStaffListFilterOptions(): array
    {
        $statement = $this->conn->query("SELECT DISTINCT sp.job_title, sp.department FROM users u JOIN staff_profiles sp ON sp.user_id = u.id WHERE COALESCE(u.role, '') NOT IN ('admin', 'student')");
        $jobTitles = [];
        $forces = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $jobTitle = StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null);
            if ($jobTitle !== null) $jobTitles[$jobTitle] = true;
            foreach (array_filter(array_map('trim', explode(',', (string) ($row['department'] ?? '')))) as $force) $forces[$force] = true;
        }
        $jobTitles = array_keys($jobTitles);
        $forces = array_keys($forces);
        sort($jobTitles, SORT_NATURAL | SORT_FLAG_CASE);
        sort($forces, SORT_NATURAL | SORT_FLAG_CASE);
        return ['job_titles' => $jobTitles, 'forces' => $forces];
    }

    /**
     * حذف صورة الموظف
     */
    public function deleteStaffProfileImage($userId)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $stmt = $this->conn->prepare("SELECT * FROM staff_profiles WHERE user_id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['profile_image'])) {
            $this->conn->prepare("UPDATE staff_profiles SET profile_image = NULL WHERE user_id = ?")->execute([$userId]);
            if ($ownsTransaction) {
                (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordEvent(
                    'delete', 'staff_profile_image', (int)$userId, 'صورة موظف #' . (int)$userId,
                    ['file_name_hash' => hash('sha256', (string)$row['profile_image']), 'direct_undo_available' => false]
                );
            }
        }
        if ($ownsTransaction) $this->conn->commit();
        if ($ownsTransaction && $row && !empty($row['profile_image'])) {
            $path = __DIR__ . '/../uploads/staff/' . $row['profile_image'];
            if (file_exists($path)) unlink($path);
        }
        return ($row && !empty($row['profile_image'])) ? (string) $row['profile_image'] : null;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    // ====================================================================
    // نظام بيانات الطلاب التفصيلية
    // ====================================================================

    /**
     * تطبيع الاسم العربي للبحث وكشف التشابه
     * إزالة التشكيل، توحيد الهمزات، ى→ي، ة↔ه (للبحث فقط)
     */
    public static function normalizeArabicName($name)
    {
        if (empty($name))
            return '';
        // إزالة التشكيل
        $name = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $name);
        // توحيد الهمزات: أ إ آ ء → ا
        $name = str_replace(['أ', 'إ', 'آ', 'ء'], 'ا', $name);
        // ى → ي
        $name = str_replace('ى', 'ي', $name);
        // ة → ه
        $name = str_replace('ة', 'ه', $name);
        // إزالة المسافات المتكررة
        $name = preg_replace('/\s+/', ' ', trim($name));
        return $name;
    }

    /**
     * توليد مفتاح البحث من الاسم الخماسي
     */
    public static function buildSearchKey($first, $second, $third, $fourth, $family)
    {
        $parts = array_filter([$first, $second, $third, $fourth, $family], function ($v) {
            return !empty(trim($v ?? ''));
        });
        return self::normalizeArabicName(implode(' ', $parts));
    }

    /**
     * حساب العمر اعتبارًا من 1 أكتوبر
     */
    public static function calculateAgeFromOctober($birthDate)
    {
        if (empty($birthDate))
            return null;
        $birth = new \DateTime($birthDate);
        $now = new \DateTime();
        $year = (int) $now->format('Y');
        $ref = new \DateTime("$year-10-01");
        $diff = $birth->diff($ref);
        if ($diff->invert)
            return ['years' => 0, 'months' => 0, 'days' => 0, 'ref' => $ref->format('Y-m-d')];
        return [
            'years' => $diff->y,
            'months' => $diff->m,
            'days' => $diff->d,
            'ref' => $ref->format('Y-m-d')
        ];
    }

    /**
     * حساب العمر الحالي من تاريخ الميلاد دون تخزين قيمة مشتقة في قاعدة البيانات.
     *
     * @return array|null years, months, days, reference, is_future
     */
    public static function calculateCurrentAge($birthDate)
    {
        if (empty($birthDate)) {
            return null;
        }

        try {
            $birth = new \DateTimeImmutable((string) $birthDate);
        } catch (\Exception $e) {
            return null;
        }

        $reference = new \DateTimeImmutable('today');
        $birth = $birth->setTime(0, 0, 0);
        if ($birth > $reference) {
            return [
                'years' => 0,
                'months' => 0,
                'days' => 0,
                'reference' => $reference->format('Y-m-d'),
                'is_future' => true,
            ];
        }

        $diff = $birth->diff($reference);
        return [
            'years' => $diff->y,
            'months' => $diff->m,
            'days' => $diff->d,
            'reference' => $reference->format('Y-m-d'),
            'is_future' => false,
        ];
    }

    /**
     * توليد كود طالب فريد
     */
    public function generateStudentCode()
    {
        $year = date('Y');
        $stmt = $this->conn->prepare("SELECT MAX(CAST(SUBSTRING(student_code, 6) AS UNSIGNED)) as max_num FROM student_profiles WHERE student_code LIKE ?");
        $prefix = "S{$year}%";
        $stmt->execute([$prefix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $next = ($row['max_num'] ?? 0) + 1;
        return 'S' . $year . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * توليد كود موظف فريد (E2025XXXX)
     */
    public function generateEmployeeCode()
    {
        $year = date('Y');
        $sql = "SELECT employee_code
                FROM staff_profiles
                WHERE employee_code LIKE ?
                ORDER BY employee_code DESC
                LIMIT 1";
        if ($this->conn->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->conn->prepare($sql);
        $prefix = "E{$year}%";
        $stmt->execute([$prefix]);
        $lastCode = (string)($stmt->fetchColumn() ?: '');
        $next = ((int)substr($lastCode, 5)) + 1;
        return 'E' . $year . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * توليد كود معلم خارجي فريد (X2025XXXX)
     */
    public function generateTeacherCode()
    {
        $year = date('Y');
        $stmt = $this->conn->prepare("SELECT MAX(CAST(SUBSTRING(teacher_code, 6) AS UNSIGNED)) as max_num FROM external_teachers WHERE teacher_code LIKE ?");
        $prefix = "X{$year}%";
        $stmt->execute([$prefix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $next = ($row['max_num'] ?? 0) + 1;
        return 'X' . $year . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * جلب بيانات الطالب التفصيلية
     */
    public function getStudentProfile($userId)
    {
        $stmt = $this->conn->prepare("SELECT * FROM student_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * حفظ/تحديث بيانات الطالب التفصيلية
     */
    public function saveStudentProfile($userId, $data)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $beforeProfile = $ownsTransaction ? $this->fetchByUserId('student_profiles', (int)$userId, true) : [];
        $beforeUser = $ownsTransaction ? $this->fetchById('users', (int)$userId, true) : [];
        $check = $this->conn->prepare("SELECT * FROM student_profiles WHERE user_id = ?");
        $check->execute([$userId]);
        $exists = $check->fetch(PDO::FETCH_ASSOC);

        $data = array_merge($exists ?: [], $data);
        $profileName = User::joinNameParts([
            $data['first_name_ar'] ?? null,
            $data['second_name_ar'] ?? null,
            $data['third_name_ar'] ?? null,
            $data['fourth_name_ar'] ?? null,
            $data['family_name_ar'] ?? null,
        ]);
        if ($profileName !== '') {
            $data['search_key_ar'] = self::normalizeArabicName($profileName);
        }
        $englishName = User::joinNameParts([
            $data['first_name_en'] ?? null,
            $data['second_name_en'] ?? null,
            $data['third_name_en'] ?? null,
            $data['fourth_name_en'] ?? null,
            $data['family_name_en'] ?? null,
        ]);
        if ($englishName !== '') {
            $data['search_key_en'] = mb_strtolower($englishName, 'UTF-8');
        }
        if (!empty($data['birth_date'])) {
            $age = self::calculateAgeFromOctober($data['birth_date']);
            if ($age) {
                $data['age_years'] = $age['years'];
                $data['age_months'] = $age['months'];
                $data['age_days'] = $age['days'];
                $data['age_reference_date'] = $age['ref'];
            }
        }

        $fields = [
            'student_code',
            'ministry_code',
             'grade_id',
            'first_name_ar',
            'second_name_ar',
            'third_name_ar',
            'fourth_name_ar',
            'family_name_ar',
            'first_name_en',
            'second_name_en',
            'third_name_en',
            'fourth_name_en',
            'family_name_en',
            'search_key_ar',
            'search_key_en',
            'birth_date',
            'birth_place',
            'national_id',
            'nationality',
            'passport_number',
            'religion',
            'gender',
            'city_area',
            'address_current',
            'phone_mobile',
            'phone_home',
            'phone_emergency',
            'extra_phones',
            'extra_data',
            'age_years',
            'age_months',
            'age_days',
            'age_reference_date',
            'enrollment_date',
            'enrollment_status',
            'previous_school',
            'notes',
            'health_status',
            'chronic_diseases',
            'allergies',
            'blood_type',
            'disabilities',
            'medications',
            'psychological_notes',
            'previous_medical_reports',
            'insurance_number',
            'insurance_start_date',
            'insurance_end_date',
            'treatment_plan',
            'emergency_medical_notes'
        ];

        $filtered = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $val = $data[$f];
                if (in_array($f, ['birth_date', 'enrollment_date', 'age_reference_date', 'insurance_start_date', 'insurance_end_date']) && $val === '') {
                    $val = null;
                }
                if (in_array($f, ['age_years', 'age_months', 'age_days']) && $val === '') {
                    $val = null;
                }
                if ($f === 'grade_id') {
                    $val = ($val === '' || $val === null) ? null : (int) $val;
                }
                $filtered[$f] = $val;
            }
        }

        if (empty($filtered)) {
            if ($ownsTransaction) $this->conn->rollBack();
            return false;
        }

        if ($exists) {
            $setParts = [];
            $params = [];
            foreach ($filtered as $k => $v) {
                $setParts[] = "`$k` = ?";
                $params[] = $v;
            }
            $params[] = $userId;
            $sql = "UPDATE student_profiles SET " . implode(', ', $setParts) . " WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $saved = $stmt->execute($params);
        } else {
            $filtered['user_id'] = $userId;
            $cols = array_keys($filtered);
            $placeholders = array_fill(0, count($cols), '?');
            $sql = "INSERT INTO student_profiles (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $this->conn->prepare($sql);
            $saved = $stmt->execute(array_values($filtered));
        }
        if ($saved && $profileName !== '') {
            $nameStmt = $this->conn->prepare("UPDATE users SET name = ? WHERE id = ? AND role = 'student'");
            $saved = $nameStmt->execute([$profileName, $userId]);
        }
        if ($saved && $ownsTransaction) {
            $afterProfile = $this->fetchByUserId('student_profiles', (int)$userId, false);
            $afterUser = $this->fetchById('users', (int)$userId, false);
            $this->auditProfileSave('student_profile', 'student_profiles', (int)$userId, $beforeProfile, $afterProfile, $beforeUser, $afterUser);
            $this->conn->commit();
        } elseif ($ownsTransaction) {
            $this->conn->rollBack();
        }
        return $saved;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * جلب أولياء أمور طالب
     */
    public function getStudentGuardians($studentId)
    {
        $stmt = $this->conn->prepare("SELECT * FROM student_guardians WHERE student_id = ? ORDER BY is_primary DESC, id ASC");
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * حفظ ولي أمر (إضافة أو تحديث)
     */
    public function saveStudentGuardian($data)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) {
            $this->conn->beginTransaction();
        }

        try {
        $fields = [
            'student_id',
            'guardian_name',
            'national_id',
            'birth_date',
            'birth_place',
            'passport_number',
            'nationality',
            'religion',
            'qualification',
            'address',
            'email',
            'phone_primary',
            'phone_secondary',
            'phone_landline',
            'phone_emergency',
            'relationship',
            'relationship_other',
            'job_title',
            'employer',
            'work_phone',
            'is_primary',
            'extra_phones',
            'extra_data'
        ];

        $filtered = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $val = $data[$f];
                if ($f === 'birth_date' && $val === '')
                    $val = null;
                if ($f === 'is_primary')
                    $val = (int) $val;
                $filtered[$f] = $val;
            }
        }

        if (empty($filtered)) {
            if ($ownsTransaction) {
                $this->conn->rollBack();
            }
            return false;
        }

        if (!empty($data['id'])) {
            $guardianId = (int) $data['id'];
            $before = $ownsTransaction ? $this->fetchById('student_guardians', $guardianId, true) : [];
            // تحديث
            $setParts = [];
            $params = [];
            foreach ($filtered as $k => $v) {
                $setParts[] = "`$k` = ?";
                $params[] = $v;
            }
            $params[] = $guardianId;
            $sql = "UPDATE student_guardians SET " . implode(', ', $setParts) . " WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $saved = $stmt->execute($params);
        } else {
            // إضافة
            $cols = array_keys($filtered);
            $placeholders = array_fill(0, count($cols), '?');
            $sql = "INSERT INTO student_guardians (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $this->conn->prepare($sql);
            $saved = $stmt->execute(array_values($filtered));
            $guardianId = (int) $this->conn->lastInsertId();
            $before = [];
        }

        if ($saved && $ownsTransaction) {
            $after = $this->fetchById('student_guardians', $guardianId, false);
            $this->auditSingleRowChange(
                'student_guardian',
                'student_guardians',
                $guardianId,
                'ولي أمر #' . $guardianId,
                $before,
                $after
            );
            $this->conn->commit();
        } elseif ($ownsTransaction) {
            $this->conn->rollBack();
        }

        return $saved;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $error;
        }
    }

    /**
     * حذف ولي أمر
     */
    public function deleteStudentGuardian($guardianId, $studentId)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) {
            $this->conn->beginTransaction();
        }

        try {
            $before = $ownsTransaction ? $this->fetchById('student_guardians', (int) $guardianId, true) : [];
            if ($before && (int) ($before['student_id'] ?? 0) !== (int) $studentId) {
                if ($ownsTransaction) {
                    $this->conn->rollBack();
                }
                return false;
            }

            $stmt = $this->conn->prepare("DELETE FROM student_guardians WHERE id = ? AND student_id = ?");
            $deleted = $stmt->execute([$guardianId, $studentId]);
            if ($deleted && $ownsTransaction && $before) {
                $this->auditSingleRowChange(
                    'student_guardian',
                    'student_guardians',
                    (int) $guardianId,
                    'ولي أمر #' . (int) $guardianId,
                    $before,
                    []
                );
            }
            if ($ownsTransaction) {
                $this->conn->commit();
            }
            return $deleted;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $error;
        }
    }

    /**
     * البحث عن أشقاء محتملين (بناءً على تشابه اسم الأب والجد والعائلة)
     * يبحث في student_profiles وأيضاً في users.name للطلاب بدون ملف تفصيلي
     */
    public function findPotentialSiblings($userId, $secondNameAr, $thirdNameAr, $familyNameAr)
    {
        $normalSecond = self::normalizeArabicName($secondNameAr);
        $normalThird = self::normalizeArabicName($thirdNameAr);
        $normalFamily = self::normalizeArabicName($familyNameAr);

        if (empty($normalSecond) && empty($normalFamily))
            return [];

        // البحث في student_profiles و users.name معاً (LEFT JOIN لتشمل الطلاب بدون ملف تفصيلي)
        $sql = "SELECT sp.first_name_ar, sp.second_name_ar, sp.third_name_ar, sp.fourth_name_ar,
                       sp.family_name_ar, sp.student_code, sp.search_key_ar,
                       u.name as user_name, u.id as uid, c.name as class_name
                FROM users u
                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                LEFT JOIN classes c ON u.class_id = c.id
                WHERE u.id != ?
                AND u.role = 'student' AND u.status = 'active'";
        $params = [$userId];

        // استبعاد الأشقاء المربوطين بالفعل
        $sql .= " AND u.id NOT IN (SELECT sibling_id FROM student_siblings WHERE student_id = ?)";
        $params[] = $userId;

        // بحث بالعائلة والأب في كلا المصدرين
        $conditions = [];
        if (!empty($normalFamily)) {
            $conditions[] = "sp.search_key_ar LIKE ?";
            $conditions[] = "u.name LIKE ?";
            $params[] = "%{$normalFamily}%";
            $params[] = "%{$familyNameAr}%";
        }
        if (!empty($normalSecond)) {
            $conditions[] = "sp.search_key_ar LIKE ?";
            $conditions[] = "u.name LIKE ?";
            $params[] = "%{$normalSecond}%";
            $params[] = "%{$secondNameAr}%";
        }
        if (!empty($conditions)) {
            $sql .= " AND (" . implode(" OR ", $conditions) . ")";
        }
        $sql .= " LIMIT 30";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ترتيب بالتشابه
        $results = [];
        foreach ($candidates as $c) {
            $score = 0;

            // إذا كان لديه ملف تفصيلي، نقارن حقول الأسماء
            $cSecond = self::normalizeArabicName($c['second_name_ar'] ?? '');
            $cThird = self::normalizeArabicName($c['third_name_ar'] ?? '');
            $cFamily = self::normalizeArabicName($c['family_name_ar'] ?? '');

            // إذا لم يكن له ملف تفصيلي، نحاول تقسيم الاسم من users.name
            if (empty($cSecond) && empty($cFamily) && !empty($c['user_name'])) {
                $nameParts = explode(' ', trim($c['user_name']));
                $c['first_name_ar'] = $nameParts[0] ?? '';
                $cSecond = self::normalizeArabicName($nameParts[1] ?? '');
                $cThird = self::normalizeArabicName($nameParts[2] ?? '');
                if (count($nameParts) >= 5) {
                    $cFamily = self::normalizeArabicName($nameParts[4] ?? '');
                    $c['fourth_name_ar'] = $nameParts[3] ?? '';
                } elseif (count($nameParts) === 4) {
                    $cFamily = self::normalizeArabicName($nameParts[3] ?? '');
                } elseif (count($nameParts) === 3) {
                    $cFamily = self::normalizeArabicName($nameParts[2] ?? '');
                    $cThird = '';
                }
                $c['second_name_ar'] = $nameParts[1] ?? '';
                $c['family_name_ar'] = end($nameParts) ?: '';
            }

            if (!empty($normalFamily) && !empty($cFamily) && $normalFamily === $cFamily)
                $score += 40;
            if (!empty($normalSecond) && !empty($cSecond) && $normalSecond === $cSecond)
                $score += 35;
            if (!empty($normalThird) && !empty($cThird) && $normalThird === $cThird)
                $score += 25;

            // تشابه جزئي (Levenshtein)
            if (!empty($normalSecond) && !empty($cSecond) && $normalSecond !== $cSecond) {
                $lev = levenshtein($normalSecond, $cSecond);
                if ($lev <= 2)
                    $score += (20 - $lev * 5);
            }
            if (!empty($normalFamily) && !empty($cFamily) && $normalFamily !== $cFamily) {
                $lev = levenshtein($normalFamily, $cFamily);
                if ($lev <= 2)
                    $score += (15 - $lev * 5);
            }

            if ($score >= 30) {
                $c['similarity_score'] = $score;
                $results[] = $c;
            }
        }

        usort($results, function ($a, $b) {
            return $b['similarity_score'] - $a['similarity_score']; });
        return array_slice($results, 0, 10);
    }

    /**
     * البحث التلقائي عن صلات القرابة المحتملة (أبناء عم / عمة)
     */
    public function findPotentialKinship($userId, $secondNameAr, $thirdNameAr, $familyNameAr)
    {
        $normalSecond = self::normalizeArabicName($secondNameAr);
        $normalThird = self::normalizeArabicName($thirdNameAr);
        $normalFamily = self::normalizeArabicName($familyNameAr);

        if (empty($normalFamily) && empty($normalSecond)) {
            return [];
        }

        $sql = "SELECT sp.first_name_ar, sp.second_name_ar, sp.third_name_ar, sp.fourth_name_ar,
                       sp.family_name_ar, sp.gender, sp.student_code, sp.search_key_ar,
                       u.name as user_name, u.id as uid, c.name as class_name
                FROM users u
                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                LEFT JOIN classes c ON u.class_id = c.id
                WHERE u.id != ?
                AND u.role = 'student' AND u.status = 'active'
                AND u.id NOT IN (SELECT relative_id FROM student_kinships WHERE student_id = ?)";

        $params = [$userId, $userId];

        $conditions = [];
        if (!empty($normalFamily)) {
            $conditions[] = "sp.search_key_ar LIKE ?";
            $conditions[] = "u.name LIKE ?";
            $params[] = "%{$normalFamily}%";
            $params[] = "%{$familyNameAr}%";
        }
        if (!empty($conditions)) {
            $sql .= " AND (" . implode(" OR ", $conditions) . ")";
        }
        $sql .= " LIMIT 40";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($candidates as $c) {
            $cFamily = self::normalizeArabicName($c['family_name_ar'] ?? '');
            $cSecond = self::normalizeArabicName($c['second_name_ar'] ?? '');

            if (empty($cFamily) && !empty($c['user_name'])) {
                $parts = explode(' ', trim($c['user_name']));
                $cFamily = self::normalizeArabicName(end($parts) ?: '');
            }

            $matchType = null;
            $score = 0;
            $kinshipLabel = '';

            if (!empty($normalFamily) && !empty($cFamily) && $normalFamily === $cFamily) {
                if (empty($normalSecond) || empty($cSecond) || $normalSecond !== $cSecond) {
                    $matchType = 'paternal';
                    $score = 65;
                    $kinshipLabel = ($c['gender'] === 'female') ? 'ابنة عم / عمة' : 'ابن عم / عمة';
                }
            }

            if ($score >= 50 && $matchType !== null) {
                $c['match_type'] = $matchType;
                $c['similarity_score'] = $score;
                $c['kinship_label'] = $kinshipLabel;
                $results[] = $c;
            }
        }

        usort($results, function ($a, $b) {
            return $b['similarity_score'] - $a['similarity_score'];
        });

        return array_slice($results, 0, 10);
    }

    /**
     * البحث اليدوي عن طلاب لربطهم كأشقاء (بحث بالاسم)
     */
    public function searchStudentsForSibling($userId, $searchTerm)
    {
        $searchTerm = trim($searchTerm);
        if (empty($searchTerm))
            return [];

        $normalizedSearch = self::normalizeArabicName($searchTerm);

        $sql = "SELECT sp.first_name_ar, sp.second_name_ar, sp.third_name_ar, sp.fourth_name_ar,
                       sp.family_name_ar, sp.student_code,
                       u.name as user_name, u.id as uid, c.name as class_name
                FROM users u
                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                LEFT JOIN classes c ON u.class_id = c.id
                WHERE u.id != ?
                AND u.role = 'student' AND u.status = 'active'
                AND u.id NOT IN (SELECT sibling_id FROM student_siblings WHERE student_id = ?)
                AND (u.name LIKE ? OR sp.search_key_ar LIKE ? OR sp.student_code LIKE ?)
                ORDER BY u.name
                LIMIT 15";

        $params = [$userId, $userId, "%{$searchTerm}%", "%{$normalizedSearch}%", "%{$searchTerm}%"];

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * ربط أشقاء
     */
    public function linkSiblings($studentId, $siblingId, $relationship = 'brother')
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) {
            $this->conn->beginTransaction();
        }
        try {
            $before = $ownsTransaction ? $this->fetchSiblingPair((int) $studentId, (int) $siblingId, true) : [];
            // ربط ثنائي الاتجاه
            $stmt = $this->conn->prepare("INSERT IGNORE INTO student_siblings (student_id, sibling_id, relationship, confirmed) VALUES (?, ?, ?, 1)");
            $stmt->execute([$studentId, $siblingId, $relationship]);
            $stmt->execute([$siblingId, $studentId, $relationship]);
            if ($ownsTransaction) {
                $after = $this->fetchSiblingPair((int) $studentId, (int) $siblingId, false);
                $this->auditRowSetReplacement(
                    'student_sibling_link',
                    (int) $studentId,
                    'ربط أشقاء #' . (int) $studentId . ' و#' . (int) $siblingId,
                    'student_siblings',
                    $before,
                    $after
                );
                $this->conn->commit();
            }
            return true;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $error;
        }
    }

    /**
     * إلغاء ربط أشقاء
     */
    public function unlinkSiblings($studentId, $siblingId)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) {
            $this->conn->beginTransaction();
        }

        try {
            $before = $ownsTransaction ? $this->fetchSiblingPair((int) $studentId, (int) $siblingId, true) : [];
            $stmt = $this->conn->prepare("DELETE FROM student_siblings WHERE (student_id = ? AND sibling_id = ?) OR (student_id = ? AND sibling_id = ?)");
            $deleted = $stmt->execute([$studentId, $siblingId, $siblingId, $studentId]);
            if ($deleted && $ownsTransaction && $before) {
                $this->auditRowSetReplacement(
                    'student_sibling_link',
                    (int) $studentId,
                    'إلغاء ربط أشقاء #' . (int) $studentId . ' و#' . (int) $siblingId,
                    'student_siblings',
                    $before,
                    []
                );
            }
            if ($ownsTransaction) {
                $this->conn->commit();
            }
            return $deleted;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $error;
        }
    }

    /**
     * جلب أشقاء الطالب
     */
    public function getStudentSiblings($studentId)
    {
        $stmt = $this->conn->prepare("
            SELECT ss.*, u.name as sibling_name, u.id as sibling_user_id,
                   sp.first_name_ar, sp.family_name_ar, sp.student_code,
                   c.name as class_name
            FROM student_siblings ss
            JOIN users u ON ss.sibling_id = u.id
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE ss.student_id = ?
            ORDER BY u.name
        ");
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * تسجيل حركة نقل طالب
     */
    public function logStudentTransfer($studentId, $fromClassId, $toClassId, $reason = '', $transferredBy = null)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) {
            $this->conn->beginTransaction();
        }

        try {
            $stmt = $this->conn->prepare("INSERT INTO student_transfers (student_id, from_class_id, to_class_id, transfer_date, reason, transferred_by) VALUES (?, ?, ?, CURDATE(), ?, ?)");
            $saved = $stmt->execute([$studentId, $fromClassId, $toClassId, $reason, $transferredBy]);
            if ($saved && $ownsTransaction) {
                $transferId = (int) $this->conn->lastInsertId();
                $after = $this->fetchById('student_transfers', $transferId, false);
                $this->auditSingleRowChange(
                    'student_transfer',
                    'student_transfers',
                    $transferId,
                    'نقل طالب #' . (int) $studentId,
                    [],
                    $after
                );
                $this->conn->commit();
            } elseif ($ownsTransaction) {
                $this->conn->rollBack();
            }
            return $saved;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $error;
        }
    }

    /**
     * جلب سجل حركة الطالب
     */
    public function getStudentTransfers($studentId)
    {
        $stmt = $this->conn->prepare("
            SELECT st.*, cf.name as from_class, ct.name as to_class, u.name as transferred_by_name
            FROM student_transfers st
            LEFT JOIN classes cf ON st.from_class_id = cf.id
            LEFT JOIN classes ct ON st.to_class_id = ct.id
            LEFT JOIN users u ON st.transferred_by = u.id
            WHERE st.student_id = ?
            ORDER BY st.transfer_date DESC
        ");
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the student's annual academic path from student_enrollments.
     */
    public function getStudentAcademicHistory($studentId)
    {
        $stmt = $this->conn->prepare("
            SELECT se.*,
                   ay.name AS academic_year,
                   s.stage_name,
                   g.grade_name AS to_grade,
                   c.name AS to_class,
                   d.decision_source,
                   d.applied_at AS decision_applied_at
            FROM student_enrollments se
            LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
            LEFT JOIN stages s ON s.id = se.stage_id
            LEFT JOIN grades g ON g.id = se.grade_id
            LEFT JOIN classes c ON c.id = se.class_id
            LEFT JOIN student_promotion_decisions d ON d.id = se.promotion_decision_id
            WHERE se.student_id = ?
            ORDER BY ay.start_date ASC, ay.name ASC, se.id ASC
        ");
        $stmt->execute([(int) $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $history = [];
        $previous = null;
        foreach ($rows as $row) {
            $status = (string) ($row['enrollment_status'] ?? 'enrolled');
            if ($status === 'graduated') {
                $status = 'enrolled';
            } elseif ($status === 'withdrawn') {
                $status = 'discontinued';
            }
            $promotionType = (string) ($row['academic_status'] ?? '');

            if ($promotionType === 'graduated' || ($row['enrollment_status'] ?? '') === 'graduated') {
                $promotionType = 'graduated';
            } elseif (in_array($promotionType, ['new', 'promoted', 'retained'], true)) {
                // Explicit annual status is authoritative.
            } elseif ($previous && (int)($previous['grade_id'] ?? 0) === (int)($row['grade_id'] ?? 0)) {
                $promotionType = 'retained';
            } elseif ($previous) {
                $promotionType = 'promoted';
            } else {
                $promotionType = 'enrolled';
            }

            $history[] = [
                'id' => $row['id'],
                'academic_year' => $row['academic_year'],
                'promotion_type' => $promotionType,
                'from_grade' => $previous['to_grade'] ?? null,
                'from_class' => $previous['to_class'] ?? null,
                'stage_name' => $row['stage_name'] ?? null,
                'to_grade' => $row['to_grade'],
                'to_class' => $row['to_class'],
                'enrollment_status' => $status,
                'academic_status' => $promotionType,
                'graduation_year' => $row['graduation_year'] ?? null,
                'created_at' => $row['decision_applied_at'] ?? $row['created_at'] ?? ($row['enrollment_date'] ?? null),
                'promoted_by_name' => match ((string) ($row['decision_source'] ?? '')) {
                    'rule' => 'تهيئة العام — قاعدة الصف',
                    'manual' => 'تهيئة العام — قرار يدوي',
                    'system' => 'تهيئة العام — النظام',
                    default => empty($row['source_enrollment_id']) ? 'التسجيل الأولي' : 'التسجيل السنوي',
                },
                'reversed_by_name' => null,
                'is_reversed' => 0,
                'notes' => $row['notes'] ?? null,
            ];

            $previous = $history[count($history) - 1];
            $previous['grade_id'] = $row['grade_id'];
        }

        return array_reverse($history);
    }

    /**
     * جلب جميع الطلاب مع بياناتهم التفصيلية
     */
    public function getStudentsWithProfiles($classId = null)
    {
        $sql = "SELECT u.id, u.name, u.username, u.password, u.status, u.class_id,
                       sp.student_code, sp.first_name_ar, sp.family_name_ar, sp.national_id as profile_national_id,
                       sp.birth_date, sp.gender, sp.enrollment_date,
                       c.name as class_name,
                       COALESCE(SUM(CASE WHEN e.is_positive = 1 THEN et.points ELSE -et.points END), 0) as total_points
                FROM users u
                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                LEFT JOIN classes c ON u.class_id = c.id
                LEFT JOIN evaluations e ON u.id = e.student_id
                LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                WHERE u.role = 'student'";
        $params = [];
        if ($classId) {
            $sql .= " AND u.class_id = ?";
            $params[] = $classId;
        }
        $sql .= " GROUP BY u.id ORDER BY u.name";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $students = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['password'] = decryptPasswordForUser((string)($row['password'] ?? ''), (int)$row['id']);
            $students[] = $row;
        }
        return $students;
    }

    private function fetchByUserId(string $table, int $userId, bool $lock): array
    {
        $sql = "SELECT * FROM `{$table}` WHERE user_id = ?" . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchById(string $table, int $id, bool $lock): array
    {
        $sql = "SELECT * FROM `{$table}` WHERE id = ?" . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchSiblingPair(int $studentId, int $siblingId, bool $lock): array
    {
        $sql = 'SELECT * FROM student_siblings '
            . 'WHERE (student_id = ? AND sibling_id = ?) OR (student_id = ? AND sibling_id = ?) '
            . 'ORDER BY id' . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$studentId, $siblingId, $siblingId, $studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function auditProfileSave(
        string $entityType,
        string $profileTable,
        int $userId,
        array $beforeProfile,
        array $afterProfile,
        array $beforeUser,
        array $afterUser
    ): void {
        $service = new \EduCore\Modules\Operations\Audit\AuditService($this->conn);
        $name = ($entityType === 'student_profile' ? 'ملف طالب #' : 'ملف موظف #') . $userId;

        if (!$beforeProfile && $afterProfile) {
            $batchId = \UndoManager::newBatchId();
            $service->recordReplacement(
                $entityType,
                $userId,
                $name,
                [],
                [[
                    'table' => $profileTable,
                    'record_id' => $afterProfile['id'] ?? $userId,
                    'snapshot' => $afterProfile,
                    'description' => 'إنشاء ' . $name,
                ]],
                ['profile_created' => true],
                $batchId
            );
            if ($beforeUser != $afterUser) {
                $service->recordCompositeUpdate(
                    $entityType,
                    $userId,
                    $name,
                    [[
                        'table' => 'users',
                        'record_id' => $userId,
                        'before' => $beforeUser,
                        'after' => $afterUser,
                        'description' => 'مزامنة اسم المستخدم للملف #' . $userId,
                    ]],
                    ['profile_name_synchronized' => true],
                    $batchId
                );
            }
            return;
        }

        $items = [[
            'table' => $profileTable,
            'record_id' => $afterProfile['id'] ?? ($beforeProfile['id'] ?? $userId),
            'before' => $beforeProfile,
            'after' => $afterProfile,
            'description' => 'تحديث ' . $name,
        ]];
        if ($beforeUser != $afterUser) {
            $items[] = [
                'table' => 'users',
                'record_id' => $userId,
                'before' => $beforeUser,
                'after' => $afterUser,
                'description' => 'مزامنة اسم المستخدم للملف #' . $userId,
            ];
        }
        $service->recordCompositeUpdate(
            $entityType,
            $userId,
            $name,
            $items,
            ['profile_created' => false]
        );
    }

    private function auditSingleRowChange(
        string $entityType,
        string $table,
        int $recordId,
        string $name,
        array $before,
        array $after
    ): void {
        $service = new \EduCore\Modules\Operations\Audit\AuditService($this->conn);
        if ($before && $after) {
            $service->recordCompositeUpdate(
                $entityType,
                $recordId,
                $name,
                [[
                    'table' => $table,
                    'record_id' => $recordId,
                    'before' => $before,
                    'after' => $after,
                    'description' => 'تحديث ' . $name,
                ]],
                []
            );
            return;
        }

        $deleted = $before ? [[
            'table' => $table,
            'record_id' => $recordId,
            'snapshot' => $before,
            'description' => 'حذف ' . $name,
        ]] : [];
        $inserted = $after ? [[
            'table' => $table,
            'record_id' => $recordId,
            'snapshot' => $after,
            'description' => 'إنشاء ' . $name,
        ]] : [];
        $service->recordReplacement($entityType, $recordId, $name, $deleted, $inserted, []);
    }

    private function auditRowSetReplacement(
        string $entityType,
        int $recordId,
        string $name,
        string $table,
        array $beforeRows,
        array $afterRows
    ): void {
        $beforeById = [];
        foreach ($beforeRows as $row) {
            $beforeById[(string) ($row['id'] ?? '')] = $row;
        }
        $afterById = [];
        foreach ($afterRows as $row) {
            $afterById[(string) ($row['id'] ?? '')] = $row;
        }

        $deleted = [];
        foreach (array_diff_key($beforeById, $afterById) as $id => $row) {
            $deleted[] = [
                'table' => $table,
                'record_id' => (int) $id,
                'snapshot' => $row,
                'description' => $name,
            ];
        }
        $inserted = [];
        foreach (array_diff_key($afterById, $beforeById) as $id => $row) {
            $inserted[] = [
                'table' => $table,
                'record_id' => (int) $id,
                'snapshot' => $row,
                'description' => $name,
            ];
        }

        (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordReplacement(
            $entityType,
            $recordId,
            $name,
            $deleted,
            $inserted,
            []
        );
    }
}
