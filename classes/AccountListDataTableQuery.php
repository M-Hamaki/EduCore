<?php
declare(strict_types=1);

require_once __DIR__ . '/StaffEmploymentLifecycleService.php';

/**
 * Read-only account list query. Password ciphertext is never selected or sent
 * to DataTables; the audited reveal endpoint handles one account at a time.
 */
final class AccountListDataTableQuery
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{total:int,active:int,inactive:int,unconfigured:int} */
    public function studentSummary(int $academicYearId, array $filters): array
    {
        [$from, $where, $params] = $this->studentBase($academicYearId, $filters);
        return $this->summary($from, $where, $params, "u.username IS NULL OR (u.password IS NULL AND u.password_hash IS NULL)");
    }

    /** @return array{total:int,active:int,inactive:int,unconfigured:int,portal:int,employee:int} */
    public function staffSummary(array $validRoles, array $filters): array
    {
        [$from, $where, $params] = $this->staffBase($validRoles, $filters);
        $sql = "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END), 0) AS active,
                COALESCE(SUM(CASE WHEN u.status = 'inactive' THEN 1 ELSE 0 END), 0) AS inactive,
                COALESCE(SUM(CASE WHEN u.username IS NULL OR (u.password IS NULL AND u.password_hash IS NULL) THEN 1 ELSE 0 END), 0) AS unconfigured,
                COALESCE(SUM(CASE WHEN u.username IS NOT NULL AND (u.password IS NOT NULL OR u.password_hash IS NOT NULL) THEN 1 ELSE 0 END), 0) AS portal,
                COALESCE(SUM(CASE WHEN COALESCE(ura.is_employee_only, 1) = 1 THEN 1 ELSE 0 END), 0) AS employee
            FROM {$from} WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'active' => (int)($row['active'] ?? 0),
            'inactive' => (int)($row['inactive'] ?? 0),
            'unconfigured' => (int)($row['unconfigured'] ?? 0),
            'portal' => (int)($row['portal'] ?? 0),
            'employee' => (int)($row['employee'] ?? 0),
        ];
    }

    /** @return array{0:string,1:array<int,string>,2:array<int,mixed>} */
    public function studentSelectionQueryParts(int $academicYearId, array $filters): array
    {
        [$from, $where, $params] = $this->studentBase($academicYearId, $filters);
        $this->appendSelectionSearch(
            $where,
            $params,
            (string)($filters['search_value'] ?? ''),
            '(u.name LIKE ? OR u.username LIKE ? OR sp.student_code LIKE ? OR s.stage_name LIKE ? OR g.grade_name LIKE ? OR c.name LIKE ?)'
        );
        return [$from, $where, $params];
    }

    /** @return array{0:string,1:array<int,string>,2:array<int,mixed>} */
    public function staffSelectionQueryParts(array $validRoles, array $filters): array
    {
        [$from, $where, $params] = $this->staffBase($validRoles, $filters);
        $this->appendSelectionSearch(
            $where,
            $params,
            (string)($filters['search_value'] ?? ''),
            '(u.name LIKE ? OR u.username LIKE ? OR sp.employee_code LIKE ? OR sp.job_title LIKE ? OR sp.department LIKE ?)'
        );
        return [$from, $where, $params];
    }

    /** @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array<int,array<int,string>>} */
    public function loadStudents(array $request, int $academicYearId): array
    {
        [$from, $where, $params] = $this->studentBase($academicYearId, $request);
        $fields = 'u.id, u.name, u.username, u.status, se.enrollment_status, COALESCE(u.is_test_account, 0) AS is_test_account, (u.password IS NOT NULL AND u.password NOT LIKE \'$2y$%\' AND u.password NOT LIKE \'$argon%\') AS has_revealable, (u.password IS NOT NULL OR u.password_hash IS NOT NULL) AS has_any_password, sp.student_code, s.stage_name, g.grade_name, c.name AS class_name';
        $map = [2 => 'sp.student_code', 3 => 'u.name', 4 => 's.stage_name', 5 => 'g.grade_name', 6 => 'c.name', 7 => 'u.username', 9 => 'u.is_test_account', 10 => 'u.username', 11 => 'u.status'];
        return $this->load($request, $from, $where, $params, $fields, $map, '(u.name LIKE ? OR u.username LIKE ? OR sp.student_code LIKE ? OR s.stage_name LIKE ? OR g.grade_name LIKE ? OR c.name LIKE ?)', fn (array $row, int $n): array => $this->presentStudent($row, $n));
    }

    /** @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array<int,array<int,string>>} */
    public function loadStaff(
        array $request,
        array $validRoles,
        array $roleLabels,
        array $roleColors,
        int $currentUserId,
        bool $allowSelfRoleEdit = false
    ): array
    {
        [$from, $where, $params] = $this->staffBase($validRoles, $request);
        $fields = 'u.id, u.name, u.username, u.role, u.is_supervisor, u.status, ura.role_keys, ura.is_employee_only, (u.password IS NOT NULL AND u.password NOT LIKE \'$2y$%\' AND u.password NOT LIKE \'$argon%\') AS has_revealable, (u.password IS NOT NULL OR u.password_hash IS NOT NULL) AS has_any_password, sp.employee_code, sp.job_title, sp.department';
        $map = [2 => 'sp.employee_code', 3 => 'u.name', 4 => 'u.role', 5 => 'sp.job_title', 6 => 'u.username', 8 => 'u.username', 9 => 'u.status'];
        return $this->load($request, $from, $where, $params, $fields, $map, '(u.name LIKE ? OR u.username LIKE ? OR sp.employee_code LIKE ? OR sp.job_title LIKE ? OR sp.department LIKE ?)', fn (array $row, int $n): array => $this->presentStaff($row, $n, $roleLabels, $roleColors, $currentUserId, $allowSelfRoleEdit));
    }

    /** @return array{0:string,1:array<int,string>,2:array<int,mixed>} */
    private function studentBase(int $academicYearId, array $filters): array
    {
        $from = 'users u LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN student_enrollments se ON se.student_id = u.id LEFT JOIN grades g ON g.id = se.grade_id LEFT JOIN stages s ON s.id = se.stage_id LEFT JOIN classes c ON c.id = se.class_id';
        $where = ["u.role = 'student'", 'u.deleted_at IS NULL'];
        $params = [];
        if ($academicYearId > 0) {
            $where[] = '(se.academic_year_id = ? OR se.id IS NULL)';
            $params[] = $academicYearId;
        }

        $parseArray = function ($value): array {
            if (is_array($value)) {
                return array_values(array_filter(array_map('trim', $value), fn ($v) => $v !== ''));
            }
            if (is_string($value) && trim($value) !== '') {
                return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
            }
            return [];
        };

        foreach (['stage_id' => 'se.stage_id', 'grade_id' => 'se.grade_id', 'class_id' => 'se.class_id'] as $key => $column) {
            $vals = array_map('intval', array_filter($parseArray($filters[$key] ?? null), 'is_numeric'));
            if (!empty($vals)) {
                $placeholders = implode(',', array_fill(0, count($vals), '?'));
                $where[] = "{$column} IN ({$placeholders})";
                foreach ($vals as $val) {
                    $params[] = $val;
                }
            }
        }

        $statusVals = array_intersect($parseArray($filters['status'] ?? null), ['active', 'inactive', 'graduated', 'transferred', 'discontinued']);
        if (!empty($statusVals)) {
            $statusClauses = [];
            foreach ($statusVals as $st) {
                if ($st === 'active') {
                    $statusClauses[] = "(u.status = 'active' AND COALESCE(se.enrollment_status, 'enrolled') = 'enrolled' AND COALESCE(se.academic_status, 'new') <> 'graduated')";
                } elseif ($st === 'inactive') {
                    $statusClauses[] = "(u.status = 'inactive' AND COALESCE(se.enrollment_status, '') NOT IN ('transferred', 'discontinued', 'withdrawn', 'graduated'))";
                } elseif ($st === 'graduated') {
                    $statusClauses[] = "(u.status = 'graduated' OR se.academic_status = 'graduated' OR se.enrollment_status = 'graduated')";
                } elseif ($st === 'transferred') {
                    $statusClauses[] = "(se.enrollment_status = 'transferred' OR u.status = 'transferred')";
                } elseif ($st === 'discontinued') {
                    $statusClauses[] = "se.enrollment_status IN ('discontinued', 'withdrawn')";
                }
            }
            if (!empty($statusClauses)) {
                $where[] = '(' . implode(' OR ', $statusClauses) . ')';
            }
        }

        $configVals = array_intersect($parseArray($filters['configured'] ?? null), ['configured', 'unconfigured']);
        if (count($configVals) === 1) {
            if (reset($configVals) === 'configured') {
                $where[] = 'u.username IS NOT NULL AND (u.password IS NOT NULL OR u.password_hash IS NOT NULL)';
            } else {
                $where[] = '(u.username IS NULL OR (u.password IS NULL AND u.password_hash IS NULL))';
            }
        }

        $typeVals = array_intersect($parseArray($filters['account_type'] ?? null), ['official', 'test']);
        if (count($typeVals) === 1) {
            if (reset($typeVals) === 'test') {
                $where[] = 'COALESCE(u.is_test_account, 0) = 1';
            } else {
                $where[] = 'COALESCE(u.is_test_account, 0) = 0';
            }
        }

        if (!empty($filters['student_id'])) {
            $where[] = 'u.id = ?';
            $params[] = (int)$filters['student_id'];
        }

        $tab = (string)($filters['tab'] ?? 'enrolled');
        if ($tab === 'non_enrolled') {
            $where[] = "(se.academic_status = 'graduated' OR se.enrollment_status IN ('graduated', 'transferred', 'discontinued', 'withdrawn') OR u.status IN ('graduated', 'transferred'))";
        } else {
            $where[] = "(COALESCE(se.enrollment_status, 'enrolled') = 'enrolled' AND COALESCE(se.academic_status, 'new') <> 'graduated' AND u.status <> 'graduated')";
        }

        return [$from, $where, $params];
    }

    /** @return array{0:string,1:array<int,string>,2:array<int,mixed>} */
    private function staffBase(array $validRoles, array $filters): array
    {
        $roles = ['employee'];
        foreach ($validRoles as $key => $value) {
            $roleKey = is_int($key) ? (string)$value : (string)$key;
            if ($roleKey !== '') {
                $roles[] = $roleKey;
            }
        }
        $roles = array_values(array_unique($roles));
        $from = "users u
            INNER JOIN staff_profiles sp ON sp.user_id = u.id
            LEFT JOIN (
                SELECT user_id,
                       GROUP_CONCAT(role_key ORDER BY is_primary DESC, role_key SEPARATOR ',') AS role_keys,
                       CASE WHEN COUNT(*) = 1 AND MAX(role_key) = 'employee' THEN 1 ELSE 0 END AS is_employee_only
                FROM user_role_assignments
                WHERE status = 'active'
                GROUP BY user_id
            ) ura ON ura.user_id = u.id";
        $where = ["(u.role IS NULL OR u.role NOT IN ('student', 'external_teacher'))"];
        $params = [];
        if (!in_array('admin', $roles, true) && !in_array('super_admin', $roles, true)) {
            $where[] = "NOT EXISTS (
                SELECT 1 FROM user_role_assignments urasys
                WHERE urasys.user_id = u.id
                  AND urasys.status = 'active'
                  AND urasys.role_key IN ('admin', 'super_admin')
            )";
        }

        $parseArray = function ($value): array {
            if (is_array($value)) {
                return array_values(array_filter(array_map('trim', $value), fn ($v) => $v !== ''));
            }
            if (is_string($value) && trim($value) !== '') {
                return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
            }
            return [];
        };

        $tab = (string)($filters['tab'] ?? 'accounts');
        $accountGroups = array_values(array_unique(array_intersect(
            $parseArray($filters['account_group'] ?? null),
            ['academic', 'non_academic']
        )));
        if ($accountGroups === [] && $tab === 'academics') {
            $accountGroups[] = 'academic';
        } elseif ($accountGroups === [] && $tab === 'employees') {
            $accountGroups[] = 'non_academic';
        }
        $specialistFamilySql = "SELECT role_key FROM staff_roles WHERE base_role_key = 'specialist' AND status = 'active'";
        $academicMembershipSql = "SELECT 1 FROM user_role_assignments urat
            WHERE urat.user_id = u.id AND urat.status = 'active'
              AND (urat.role_key IN ('teacher', 'specialist') OR urat.role_key IN ({$specialistFamilySql}))";
        $accountGroup = count($accountGroups) === 1 ? $accountGroups[0] : '';
        if ($accountGroup === 'non_academic') {
            $where[] = "NOT EXISTS (
                {$academicMembershipSql}
            )";
        } elseif ($accountGroup === 'academic') {
            $where[] = "EXISTS (
                {$academicMembershipSql}
            )";
        }

        $roleVals = $parseArray($filters['role'] ?? null);
        if (!empty($roleVals)) {
            $roleClauses = [];
            foreach ($roleVals as $r) {
                if ($r === 'employee') {
                    $roleClauses[] = 'COALESCE(ura.is_employee_only, 1) = 1';
                } elseif (in_array($r, $roles, true)) {
                    $roleClauses[] = "EXISTS (SELECT 1 FROM user_role_assignments uraf WHERE uraf.user_id = u.id AND uraf.status = 'active' AND uraf.role_key = ?)";
                    $params[] = $r;
                }
            }
            if (!empty($roleClauses)) {
                $where[] = '(' . implode(' OR ', $roleClauses) . ')';
            }
        }

        $statusVals = array_intersect($parseArray($filters['status'] ?? null), ['active', 'inactive']);
        if (!empty($statusVals)) {
            $placeholdersStatus = implode(',', array_fill(0, count($statusVals), '?'));
            $where[] = "u.status IN ({$placeholdersStatus})";
            foreach ($statusVals as $st) {
                $params[] = $st;
            }
        }

        $accessVals = array_intersect($parseArray($filters['access'] ?? null), ['portal', 'employee', 'incomplete']);
        if (!empty($accessVals)) {
            $accessClauses = [];
            foreach ($accessVals as $acc) {
                if ($acc === 'portal') {
                    $accessClauses[] = '(u.username IS NOT NULL AND (u.password IS NOT NULL OR u.password_hash IS NOT NULL))';
                } elseif ($acc === 'employee') {
                    $accessClauses[] = 'COALESCE(ura.is_employee_only, 1) = 1';
                } elseif ($acc === 'incomplete') {
                    $accessClauses[] = '(u.username IS NULL OR (u.password IS NULL AND u.password_hash IS NULL))';
                }
            }
            if (!empty($accessClauses)) {
                $where[] = '(' . implode(' OR ', $accessClauses) . ')';
            }
        }

        return [$from, $where, $params];
    }

    /** @param array<int,string> $where @param array<int,mixed> $params @return array{total:int,active:int,inactive:int,unconfigured:int} */
    private function summary(string $from, array $where, array $params, string $unconfigured): array
    {
        $sql = "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END),0) AS active, COALESCE(SUM(CASE WHEN u.status = 'inactive' THEN 1 ELSE 0 END),0) AS inactive, COALESCE(SUM(CASE WHEN {$unconfigured} THEN 1 ELSE 0 END),0) AS unconfigured FROM {$from} WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['total' => (int)($row['total'] ?? 0), 'active' => (int)($row['active'] ?? 0), 'inactive' => (int)($row['inactive'] ?? 0), 'unconfigured' => (int)($row['unconfigured'] ?? 0)];
    }

    /** @param array<int,string> $where @param array<int,mixed> $params @param array<int,string> $map @param callable(array<string,mixed>,int):array<int,string> $presenter */
    private function load(array $request, string $from, array $where, array $params, string $fields, array $map, string $searchClause, callable $presenter): array
    {
        $draw = max(0, (int)($request['draw'] ?? 0));
        $start = max(0, (int)($request['start'] ?? 0));
        $wanted = (int)($request['length'] ?? 50);
        $length = $wanted === -1 ? PHP_INT_MAX : min(500, max(10, $wanted));
        $baseSql = implode(' AND ', $where);
        $search = trim((string)($request['search']['value'] ?? ''));
        $filteredWhere = $where;
        $filteredParams = $params;
        if ($search !== '') {
            $filteredWhere[] = $searchClause;
            $count = substr_count($searchClause, '?');
            for ($i = 0; $i < $count; $i++) $filteredParams[] = '%' . $search . '%';
        }
        $filteredSql = implode(' AND ', $filteredWhere);
        $total = $this->count($from, $baseSql, $params);
        $filtered = $search === '' ? $total : $this->count($from, $filteredSql, $filteredParams);
        $column = (int)($request['order'][0]['column'] ?? 2);
        $orderBy = $map[$column] ?? 'u.name';
        $direction = strtolower((string)($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $stmt = $this->db->prepare("SELECT {$fields} FROM {$from} WHERE {$filteredSql} ORDER BY {$orderBy} {$direction}, u.name ASC LIMIT ? OFFSET ?");
        $index = 1;
        foreach ($filteredParams as $param) $stmt->bindValue($index++, $param);
        $stmt->bindValue($index++, $length, PDO::PARAM_INT);
        $stmt->bindValue($index, $start, PDO::PARAM_INT);
        $stmt->execute();
        $data = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $offset => $row) $data[] = $presenter($row, $start + $offset + 1);
        return ['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $data];
    }

    /** @param array<int,string> $where @param array<int,mixed> $params */
    private function appendSelectionSearch(array &$where, array &$params, string $search, string $searchClause): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }
        $where[] = $searchClause;
        $placeholderCount = substr_count($searchClause, '?');
        for ($i = 0; $i < $placeholderCount; $i++) {
            $params[] = '%' . $search . '%';
        }
    }

    /** @param array<int,mixed> $params */
    private function count(string $from, string $where, array $params): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$from} WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** @param array<string,mixed> $row @return array<int,string> */
    private function presentStudent(array $row, int $number): array
    {
        $e = fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $id = (int)$row['id'];
        $name = $e($row['name']);
        $nameJson = $e(json_encode((string)$row['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $usernameJson = $e(json_encode((string)($row['username'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $configured = !empty($row['username']) && !empty($row['has_any_password']);
        $isTest = (int)($row['is_test_account'] ?? 0) === 1;
        $enrollStatus = (string)($row['enrollment_status'] ?? '');
        $userStatus = (string)($row['status'] ?? '');
        if ($enrollStatus === 'transferred' || $userStatus === 'transferred') {
            $statusBadge = 'bg-warning text-dark"><i class="fas fa-right-from-bracket me-1"></i>منقول من المدرسة';
        } elseif ($enrollStatus === 'graduated' || $userStatus === 'graduated') {
            $statusBadge = 'bg-secondary"><i class="fas fa-graduation-cap me-1"></i>خريج';
        } elseif ($userStatus === 'active') {
            $statusBadge = 'bg-success"><i class="fas fa-check-circle me-1"></i>مفعّل';
        } else {
            $statusBadge = 'bg-danger"><i class="fas fa-times-circle me-1"></i>معطّل';
        }
        $typeCell = $isTest ? '<span class="badge bg-warning text-dark"><i class="fas fa-flask me-1"></i>تجريبي</span>' : '<span class="badge bg-light text-dark border"><i class="fas fa-school me-1"></i>رسمي</span>';
        $configuredCell = $configured ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>مُهيّأ</span>' : '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>غير مُهيّأ</span>';
        $actions = '<button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل بيانات الدخول" onclick="openCredentialsModal(' . $id . ', ' . $usernameJson . ', ' . $nameJson . ')"><i class="fas fa-user-edit"></i></button>'
            . '<button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="' . ($isTest ? 'تحويل إلى حساب رسمي' : 'تحويل إلى حساب تجريبي') . '" onclick="openTestAccountModal(' . $id . ', ' . $nameJson . ', ' . ($isTest ? 'true' : 'false') . ')"><i class="fas fa-flask"></i></button>'
            . '<button type="button" class="btn btn-action-pills btn-services customize-services me-1" data-id="' . $id . '" data-name="' . $name . '" data-role="student" data-bs-toggle="tooltip" title="تخصيص الخدمات"><i class="fas fa-cogs"></i></button>'
            . '<button class="btn btn-action-pills ' . ($row['status'] === 'active' ? 'btn-deactivate' : 'btn-activate') . '" data-bs-toggle="tooltip" title="' . ($row['status'] === 'active' ? 'تعطيل' : 'تفعيل') . ' الحساب" onclick="openToggleModal(' . $id . ', ' . $nameJson . ', \'' . ($row['status'] === 'active' ? 'inactive' : 'active') . '\')"><i class="fas ' . ($row['status'] === 'active' ? 'fa-ban' : 'fa-check') . '"></i></button>';
        return [
            '<input type="checkbox" class="form-check-input row-select-cb" value="' . $id . '" aria-label="تحديد الطالب ' . $name . '">',
            (string)$number,
            $e($row['student_code'] ?? '-'),
            '<span class="fw-bold">' . $name . '</span>',
            !empty($row['stage_name']) ? $e($row['stage_name']) : '<span class="text-muted">—</span>',
            !empty($row['grade_name']) ? $e($row['grade_name']) : '<span class="text-muted">—</span>',
            !empty($row['class_name']) ? $e($row['class_name']) : '<span class="text-muted">—</span>',
            $this->usernameCell($row['username'] ?? null),
            $this->passwordCell($id, !empty($row['has_revealable']), !empty($row['has_any_password'])),
            $typeCell,
            $configuredCell,
            '<span class="badge ' . $statusBadge . '</span>',
            '<span class="admin-table-actions">' . $actions . '</span>'
        ];
    }

    /** @param array<string,mixed> $row @return array<int,string> */
    private function presentStaff(
        array $row,
        int $number,
        array $labels,
        array $colors,
        int $currentUserId,
        bool $allowSelfRoleEdit
    ): array
    {
        $e = fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $json = fn ($value): string => $e(json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $id = (int)$row['id'];
        $name = $e($row['name']);
        $jobTitle = StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null);
        $department = trim((string)($row['department'] ?? ''));
        $employmentParts = array_values(array_filter([$jobTitle, $department], static fn ($value): bool => trim((string)$value) !== ''));
        $employmentCell = $employmentParts
            ? implode(' <span class="text-muted">/</span> ', array_map($e, $employmentParts))
            : '<span class="text-muted">—</span>';
        $roleKeys = array_values(array_filter(array_map('trim', explode(',', (string)($row['role_keys'] ?? '')))));
        if ($roleKeys === []) {
            $roleKeys = [$row['role'] === null ? 'employee' : (string)$row['role']];
        }
        $primaryRole = $row['role'] === null ? $roleKeys[0] : (string)$row['role'];
        $isEmployee = count($roleKeys) === 1 && $roleKeys[0] === 'employee';
        $isConfigured = !empty($row['has_any_password']) && !empty($row['username']);
        $self = $id === $currentUserId;
        $isSupervisor = in_array('teacher', $roleKeys, true) && (int)($row['is_supervisor'] ?? 0) === 1;
        $roleBadges = [];
        foreach ($roleKeys as $roleKey) {
            $primaryIcon = $roleKey === $primaryRole && count($roleKeys) > 1
                ? '<i class="fas fa-star me-1" data-bs-toggle="tooltip" title="الدور الأساسي"></i>'
                : '';
            $roleBadges[] = '<span class="badge bg-' . $e($colors[$roleKey] ?? 'secondary') . '">'
                . $primaryIcon . $e($labels[$roleKey] ?? $roleKey) . '</span>';
        }
        $roleCell = implode(' ', $roleBadges)
            . ($isSupervisor ? ' <span class="badge bg-warning text-dark"><i class="fas fa-user-shield me-1"></i>مشرف</span>' : '');
        $statusCell = '<span class="badge ' . ($row['status'] === 'active' ? 'bg-success">مفعّل' : 'bg-danger">معطّل') . '</span>';
        $configuredCell = $isConfigured
            ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>مُهيّأ</span>'
            : '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>غير مُهيّأ</span>';
        $toggleBtn = '';
        if (!$self) {
            $isActive = $row['status'] === 'active';
            $newStatus = $isActive ? 'inactive' : 'active';
            $toggleTitle = $isActive ? 'تعطيل الحساب' : 'تفعيل الحساب';
            $toggleClass = $isActive ? 'btn-deactivate' : 'btn-activate';
            $toggleIcon  = $isActive ? 'fa-ban' : 'fa-check';
            $toggleBtn = '<button class="btn btn-action-pills ' . $toggleClass . ' ms-1" data-bs-toggle="tooltip" title="' . $toggleTitle . '" onclick="openToggleStatusModal(' . $id . ', ' . $json($row['name']) . ', \'' . $newStatus . '\')">'
                . '<i class="fas ' . $toggleIcon . '"></i></button>';
        }
        if ($self && $allowSelfRoleEdit && in_array('super_admin', $roleKeys, true)) {
            $actions = '<button class="btn btn-action-pills btn-services" data-bs-toggle="tooltip" title="تعديل أدوارك الثانوية" onclick="openRoleAccessModal(' . $id . ', ' . $json($row['name']) . ', ' . $json($roleKeys) . ', ' . $json($primaryRole) . ', ' . ($isSupervisor ? '1' : '0') . ', true)"><i class="fas fa-user-shield"></i></button>';
        } elseif ($self) {
            $actions = '<button class="btn btn-action-pills btn-deactivate" disabled title="حسابك الحالي"><i class="fas fa-lock"></i></button>';
        } else {
            $credentialsButton = '<button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل بيانات الدخول" onclick="openCredentialsModal(' . $id . ', ' . $json($row['username'] ?? '') . ', ' . $json($row['name']) . ')"><i class="fas fa-key"></i></button>';
            $roleButton = '<button class="btn btn-action-pills btn-services me-1" data-bs-toggle="tooltip" title="تحديد الأدوار والصلاحيات" onclick="openRoleAccessModal(' . $id . ', ' . $json($row['name']) . ', ' . $json($roleKeys) . ', ' . $json($primaryRole) . ', ' . ($isSupervisor ? '1' : '0') . ')"><i class="fas fa-user-shield"></i></button>';
            $actions = $credentialsButton . $roleButton . $toggleBtn;
        }
        return [
            '<input type="checkbox" class="form-check-input row-select-cb" value="' . $id . '" aria-label="تحديد العامل ' . $name . '">',
            (string)$number,
            $e($row['employee_code'] ?? '-'),
            '<span class="fw-bold">' . $name . ($self ? ' <span class="badge bg-info ms-1">أنت</span>' : '') . '</span>',
            $roleCell,
            $employmentCell,
            $this->usernameCell($row['username'] ?? null),
            $this->passwordCell($id, !empty($row['has_revealable']), !empty($row['has_any_password'])),
            $configuredCell,
            $statusCell,
            '<span class="admin-table-actions">' . $actions . '</span>',
        ];
    }

    private function passwordCell(int $id, bool $hasRevealable, bool $hasAnyPassword = false): string
    {
        if (!$hasRevealable && !$hasAnyPassword) {
            return '<span class="text-muted">—</span>';
        }
        if (!$hasRevealable && $hasAnyPassword) {
            // كلمة المرور مُعيَّنة لكن مخزّنة كـ hash فقط بدون تشفير — لا يمكن كشفها
            return '<span class="badge bg-secondary" data-bs-toggle="tooltip" title="كلمة المرور محمية ولا يمكن كشفها"><i class="fas fa-lock me-1"></i>محمية</span>';
        }
        return '<div class="glass-credential-chip">'
            . '<span class="glass-chip-code text-muted me-2 pwd-dots" id="pwd-dots-' . $id . '" style="letter-spacing: 2px;">••••••••</span>'
            . '<code class="glass-chip-code text-primary fw-bold me-2 d-none pwd-text dir-ltr" id="pwd-text-' . $id . '"></code>'
            . '<button type="button" class="glass-chip-btn reveal-password me-1" data-user-id="' . $id . '"><i class="fas fa-eye"></i></button>'
            . '<button type="button" class="glass-chip-btn copy-password" data-user-id="' . $id . '"><i class="fas fa-copy"></i></button>'
            . '</div>';
    }

    private function usernameCell(?string $username): string
    {
        if (empty($username)) {
            return '<span class="text-muted">—</span>';
        }
        $eVal = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        return '<div class="glass-credential-chip">'
            . '<code class="glass-chip-code me-2 dir-ltr">' . $eVal . '</code>'
            . '<button type="button" class="glass-chip-btn copy-username-btn" data-username="' . $eVal . '"><i class="fas fa-copy"></i></button>'
            . '</div>';
    }
}
