<?php

require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';

/**
 * كلاس إدارة الأعوام الدراسية
 *
 * المصدر الموحّد لكل ما يخص "العام الدراسي الحالي/المختار".
 * كل صفحات النظام تستخدم هذا الكلاس بدل قراءة settings.academic_year مباشرة.
 */
class AcademicYear
{
    private static bool $requestSwitchHandled = false;

    /**
     * كل الأعوام الدراسية.
     * @return array<int,array{id:int,name:string,start_date:?string,end_date:?string,is_active:int,status:string,notes:?string}>
     */
    public static function getAll(PDO $db, bool $activeOnly = false): array
    {
        if (!self::tableExists($db, 'academic_years')) {
            return [];
        }
        $where = $activeOnly ? "WHERE status = 'active'" : '';
        $stmt = $db->query("SELECT * FROM academic_years {$where} ORDER BY is_active DESC, name DESC");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** العام النشط في النظام (واحد فقط). */
    public static function getActive(PDO $db): ?array
    {
        if (!self::tableExists($db, 'academic_years')) {
            return null;
        }
        self::ensureDefault($db);
        $stmt = $db->query("SELECT * FROM academic_years WHERE is_active = 1 AND status = 'active' ORDER BY id DESC LIMIT 1");
        $year = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        return $year ?: null;
    }

    /**
     * العام "الحالي" كما يراه المستخدم: إذا اختار عاماً في السيشن يُرجعه،
     * وإلا يُرجع العام النشط. ويحفظ اختياره في $_SESSION.
     */
    public static function getCurrent(PDO $db): ?array
    {
        if (!self::tableExists($db, 'academic_years')) {
            return null;
        }
        self::ensureDefault($db);
        if (self::roleUsesActiveYearOnly()) {
            $active = self::getActive($db);
            if (session_status() === PHP_SESSION_ACTIVE) {
                if ($active) {
                    $_SESSION['academic_year_id'] = (int) $active['id'];
                } else {
                    unset($_SESSION['academic_year_id']);
                }
            }
            return $active;
        }
        self::handleRequestSwitch($db);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionYearId = isset($_SESSION['academic_year_id']) ? (int) $_SESSION['academic_year_id'] : 0;
            if ($sessionYearId > 0) {
                $stmt = $db->prepare("SELECT * FROM academic_years WHERE id = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$sessionYearId]);
                $year = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($year) {
                    return $year;
                }
                // العام المخزّن في السيشن لم يعد صالحاً -> احذفه وعُد للنشط
                unset($_SESSION['academic_year_id']);
            }
        }
        $active = self::getActive($db);
        if ($active && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['academic_year_id'] = (int) $active['id'];
        }
        return $active;
    }

    /** معرّف العام الحالي (مختار أو نشط)، 0 إذا لم يوجد. */
    public static function currentId(PDO $db): int
    {
        $year = self::getCurrent($db);
        return $year ? (int) $year['id'] : 0;
    }

    /** اسم العام الحالي. */
    public static function currentName(PDO $db): string
    {
        $year = self::getCurrent($db);
        return $year ? (string) $year['name'] : '';
    }

    /** حفظ اختيار المستخدم للعام (تصفّح فقط، لا يغيّر العام النشط). */
    public static function setCurrent(PDO $db, int $yearId): void
    {
        if (self::roleUsesActiveYearOnly()) {
            $active = self::getActive($db);
            if (session_status() === PHP_SESSION_ACTIVE && $active) {
                $_SESSION['academic_year_id'] = (int) $active['id'];
            }
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE && $yearId > 0) {
            $_SESSION['academic_year_id'] = $yearId;
        }
    }

    /** الأخصائي يعمل دائماً على العام النشط ولا يملك استعراض أعوام أخرى. */
    public static function roleUsesActiveYearOnly(?string $role = null): bool
    {
        $role = $role ?? (session_status() === PHP_SESSION_ACTIVE ? (string) ($_SESSION['role'] ?? '') : '');
        if ($role === 'specialist') {
            return true;
        }
        return class_exists('Utilities', false)
            && Utilities::getAdministrativeRoleFamily($role) === 'specialist';
    }

    /**
     * يلتقط تبديل العام من شريط الهيدر مبكراً داخل مصدر العام الموحد.
     * هذا مهم للصفحات التي تحسب بياناتها قبل تضمين admin_header.php.
     */
    private static function handleRequestSwitch(PDO $db): void
    {
        if (self::$requestSwitchHandled) {
            return;
        }
        self::$requestSwitchHandled = true;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (self::roleUsesActiveYearOnly()) {
            return;
        }

        $requestedYearId = isset($_GET['switch_academic_year']) && is_numeric($_GET['switch_academic_year'])
            ? (int) $_GET['switch_academic_year']
            : 0;
        if ($requestedYearId <= 0) {
            return;
        }

        $stmt = $db->prepare("SELECT id FROM academic_years WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$requestedYearId]);
        if (!$stmt->fetchColumn()) {
            return;
        }

        $_SESSION['academic_year_id'] = $requestedYearId;

        $syncFlag = 'ay_synced_' . $requestedYearId;
        if (empty($_SESSION[$syncFlag]) && self::tableExists($db, 'student_enrollments')) {
            try {
                self::syncUsersClassForYear($db, $requestedYearId);
                $_SESSION[$syncFlag] = true;
            } catch (Throwable $e) {
                // المزامنة طبقة توافق فقط، ولا يجب أن تمنع عرض الصفحة.
            }
        }
    }

    /**
     * إنشاء عام دراسي جديد.
     * @return int معرّف العام الجديد
     */
    public static function create(PDO $db, string $name, ?string $startDate = null, ?string $endDate = null, ?string $notes = null): int
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            throw new InvalidArgumentException('اسم العام الدراسي مطلوب.');
        }
        $startDate = self::normalizeDate($startDate, 'تاريخ بداية العام الدراسي');
        $endDate = self::normalizeDate($endDate, 'تاريخ نهاية العام الدراسي');
        self::assertDateRange($startDate, $endDate);
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            self::assertNameAvailable($db, $name, null);
            $stmt = $db->prepare("INSERT INTO academic_years (name, start_date, end_date, status, notes) VALUES (?, ?, ?, 'active', ?)");
            $stmt->execute([$name, $startDate, $endDate, self::normalizeNotes($notes)]);
            $id = (int) $db->lastInsertId();
            $after = self::findById($db, $id);
            if (!$after) throw new RuntimeException('Academic year could not be reloaded after creation.');
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordInsert(
                'academic_year', 'academic_years', $id, $name, $after, 'إنشاء عام دراسي'
            );
            if ($ownsTransaction) $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * تعديل بيانات عام دراسي (الاسم + التواريخ + الملاحظات).
     * يتحقق من عدم تكرار الاسم، ويُحدّث الإعداد النصّي إن كان نشطاً.
     * @return array بيانات العام قبل التعديل (للسجل)
     */
    public static function update(PDO $db, int $yearId, string $name, ?string $startDate = null, ?string $endDate = null, ?string $notes = null): array
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            throw new InvalidArgumentException('اسم العام الدراسي مطلوب.');
        }
        $startDate = self::normalizeDate($startDate, 'تاريخ بداية العام الدراسي');
        $endDate = self::normalizeDate($endDate, 'تاريخ نهاية العام الدراسي');
        self::assertDateRange($startDate, $endDate);

        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            $currentStmt = $db->prepare("SELECT * FROM academic_years WHERE id = ? FOR UPDATE");
            $currentStmt->execute([$yearId]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new InvalidArgumentException('العام الدراسي المطلوب غير موجود.');
            self::assertNameAvailable($db, $name, $yearId);
            $stmt = $db->prepare("UPDATE academic_years SET name = ?, start_date = ?, end_date = ?, notes = ? WHERE id = ?");
            $stmt->execute([$name, $startDate, $endDate, self::normalizeNotes($notes), $yearId]);

            if ((int) $current['is_active'] === 1) {
                self::saveSetting($db, 'academic_year', $name, 'العام الدراسي الحالي');
            }
            $after = self::findById($db, $yearId);
            if (!$after) throw new RuntimeException('Academic year could not be reloaded after update.');
            if ($current != $after) {
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordUpdate(
                    'academic_year', 'academic_years', $yearId, $name, $current, $after, 'تعديل عام دراسي'
                );
            }
            if ($ownsTransaction) $db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }

        return $current;
    }

    /**
     * تقييم حذف عام دراسي دون تغيير أي بيانات.
     *
     * لا يسمح الحذف للعام النشط أو المقفل أو الوحيد، ولا لأي عام ترتبط به
     * سجلات عبر مفتاح أجنبي؛ وبذلك لا تعمل قواعد CASCADE أو SET NULL ضمنيًا
     * على تاريخ الطلاب أو التقييم أو المال.
     */
    public static function getDeletionAssessment(PDO $db, int $yearId): array
    {
        $year = self::findById($db, $yearId);
        if (!$year) {
            return [
                'can_delete' => false,
                'reason' => 'العام الدراسي غير موجود.',
                'reference_count' => 0,
                'reference_groups' => [],
            ];
        }
        $yearCount = (int) $db->query('SELECT COUNT(*) FROM academic_years')->fetchColumn();
        return self::buildDeletionAssessment($year, $yearCount, self::countReferences($db, $yearId));
    }

    /**
     * Build list-page deletion assessments without repeating the FK inventory
     * and one count query for every year/reference pair.
     *
     * @param array<int, array<string, mixed>> $years
     * @return array<int, array<string, mixed>>
     */
    public static function getDeletionAssessments(PDO $db, array $years): array
    {
        $yearsById = [];
        foreach ($years as $year) {
            $yearId = (int) ($year['id'] ?? 0);
            if ($yearId > 0) {
                $yearsById[$yearId] = $year;
            }
        }
        if ($yearsById === []) {
            return [];
        }

        $referencesByYear = self::countReferencesByYear($db, array_keys($yearsById));
        $yearCount = (int) $db->query('SELECT COUNT(*) FROM academic_years')->fetchColumn();
        $assessments = [];
        foreach ($yearsById as $yearId => $year) {
            $assessments[$yearId] = self::buildDeletionAssessment(
                $year,
                $yearCount,
                $referencesByYear[$yearId] ?? []
            );
        }
        return $assessments;
    }

    /**
     * حذف عام فارغ وغير نشط مع تسجيل عملية قابلة للتراجع.
     *
     * @return array لقطة العام المحذوفة
     */
    public static function delete(PDO $db, int $yearId): array
    {
        if ($yearId <= 0) {
            throw new InvalidArgumentException('معرّف العام الدراسي غير صالح.');
        }

        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) {
                $db->beginTransaction();
            }

            $yearsStmt = $db->query('SELECT * FROM academic_years ORDER BY id FOR UPDATE');
            $years = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);
            $year = null;
            foreach ($years as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === $yearId) {
                    $year = $candidate;
                    break;
                }
            }

            if (!$year) {
                throw new InvalidArgumentException('العام الدراسي المطلوب غير موجود.');
            }
            if ((int) ($year['is_active'] ?? 0) === 1) {
                throw new InvalidArgumentException('لا يمكن حذف العام الدراسي النشط. فعّل عاماً آخر أولاً.');
            }
            if ((int) ($year['locked'] ?? 0) === 1) {
                throw new InvalidArgumentException('لا يمكن حذف عام دراسي مقفل. افتح القفل أولاً إذا كان الحذف مقصوداً.');
            }
            if (count($years) <= 1) {
                throw new InvalidArgumentException('لا يمكن حذف العام الدراسي الوحيد في النظام.');
            }

            $referenceGroups = self::countReferences($db, $yearId);
            if (array_sum(array_column($referenceGroups, 'count')) > 0) {
                throw new InvalidArgumentException(self::formatReferenceBlocker($referenceGroups));
            }

            $deleteStmt = $db->prepare(
                'DELETE FROM academic_years
                 WHERE id = ? AND is_active = 0 AND locked = 0'
            );
            $deleteStmt->execute([$yearId]);
            if ($deleteStmt->rowCount() !== 1) {
                throw new RuntimeException('تعذر حذف العام الدراسي بعد التحقق من حالته.');
            }

            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordDelete(
                'academic_year',
                'academic_years',
                $yearId,
                (string) $year['name'],
                $year,
                'حذف عام دراسي فارغ وغير نشط'
            );

            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new InvalidArgumentException(
                    'لا يمكن حذف العام لأن بيانات مرتبطة به أضيفت أثناء تنفيذ الطلب. حدّث الصفحة وحاول مجدداً.'
                );
            }
            throw $e;
        }

        if (
            session_status() === PHP_SESSION_ACTIVE
            && (int) ($_SESSION['academic_year_id'] ?? 0) === $yearId
        ) {
            unset($_SESSION['academic_year_id']);
        }

        return $year;
    }

    /**
     * تعيين عام كعام نشط (وحيد). يُحدّث settings ويُزامن users.class_id.
     */
    public static function setActive(PDO $db, int $yearId): void
    {
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            $allStmt = $db->query('SELECT * FROM academic_years ORDER BY id FOR UPDATE');
            $beforeYears = $allStmt->fetchAll(PDO::FETCH_ASSOC);
            $year = null;
            foreach ($beforeYears as $candidate) {
                if ((int)$candidate['id'] === $yearId && (string)$candidate['status'] === 'active') {
                    $year = $candidate;
                    break;
                }
            }
            if (!$year) throw new InvalidArgumentException('العام الدراسي غير موجود أو غير مفعل.');
            $db->prepare("UPDATE academic_years SET is_active = CASE WHEN id = ? THEN 1 ELSE 0 END")->execute([$yearId]);
            self::saveSetting($db, 'academic_year', (string)$year['name'], 'العام الدراسي الحالي');
            self::syncUsersClassForYear($db, $yearId);
            $afterStmt = $db->query('SELECT * FROM academic_years ORDER BY id');
            $afterById = [];
            foreach ($afterStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $afterById[(int)$row['id']] = $row;
            $items = [];
            foreach ($beforeYears as $before) {
                $id = (int)$before['id'];
                $after = $afterById[$id] ?? [];
                if ($before != $after) {
                    $items[] = ['table' => 'academic_years', 'record_id' => $id, 'before' => $before, 'after' => $after, 'description' => 'تغيير العام الدراسي النشط'];
                }
            }
            if ($items) {
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordCompositeUpdate(
                    'academic_year', $yearId, (string)$year['name'], $items,
                    ['summary' => 'تعيين العام الدراسي النشط', 'affected_years' => count($items)]
                );
            }
            if ($ownsTransaction) $db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['academic_year_id'] = $yearId;
        }
    }

    /**
     * مزامنة users.class_id مع تسجيلات عام معيّن (طبقة توافق للصفحات القديمة).
     */
    public static function syncUsersClassForYear(PDO $db, int $yearId): void
    {
        $ownsTransaction = !$db->inTransaction();
        try {
        if ($ownsTransaction) $db->beginTransaction();
        $beforeStmt = $db->prepare("SELECT u.* FROM users u
            JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ?
            WHERE u.role = 'student' AND (
                NOT (u.class_id <=> se.class_id) OR
                ((se.academic_status = 'graduated' OR se.enrollment_status = 'graduated') AND u.status <> 'graduated') OR
                (se.enrollment_status IN ('transferred', 'discontinued', 'withdrawn') AND u.status <> 'inactive') OR
                (se.enrollment_status = 'enrolled' AND se.academic_status <> 'graduated' AND u.status = 'graduated')
            ) FOR UPDATE");
        $beforeStmt->execute([$yearId]);
        $beforeRows = $beforeStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$beforeRows) {
            if ($ownsTransaction) $db->commit();
            return;
        }
        $stmt = $db->prepare("UPDATE users u
            JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ?
            SET u.class_id = se.class_id,
                u.status = CASE
                    WHEN se.academic_status = 'graduated' OR se.enrollment_status = 'graduated' THEN 'graduated'
                    WHEN se.enrollment_status IN ('transferred', 'discontinued', 'withdrawn') THEN 'inactive'
                    WHEN se.enrollment_status = 'enrolled' AND se.academic_status <> 'graduated' AND u.status = 'graduated' THEN 'active'
                    ELSE u.status
                END
            WHERE u.role = 'student'");
        $stmt->execute([$yearId]);
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $beforeRows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $afterStmt = $db->prepare("SELECT * FROM users WHERE id IN ($placeholders)");
        $afterStmt->execute($ids);
        $afterById = [];
        foreach ($afterStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $afterById[(int)$row['id']] = $row;
        $items = [];
        foreach ($beforeRows as $before) {
            $id = (int)$before['id'];
            $after = $afterById[$id] ?? [];
            if ($before != $after) {
                $items[] = ['table' => 'users', 'record_id' => $id, 'before' => $before, 'after' => $after, 'description' => 'مزامنة فصل الطالب مع العام الدراسي'];
            }
        }
        if ($items) {
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordCompositeUpdate(
                'academic_year_student_sync', $yearId, 'مزامنة طلاب العام #' . $yearId, $items,
                ['summary' => 'مزامنة الفصول والحالات مع تسجيلات العام', 'student_count' => count($items)]
            );
        }
        if ($ownsTransaction) $db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** @return array<int, int> keyed by academic year ID. */
    public static function countEnrollmentsByYear(PDO $db, array $yearIds): array
    {
        $yearIds = array_values(array_unique(array_filter(
            array_map('intval', $yearIds),
            static fn(int $yearId): bool => $yearId > 0
        )));
        $counts = array_fill_keys($yearIds, 0);
        if ($yearIds === [] || !self::tableExists($db, 'student_enrollments')) {
            return $counts;
        }

        $placeholders = implode(',', array_fill(0, count($yearIds), '?'));
        $stmt = $db->prepare(
            "SELECT academic_year_id, COUNT(*) AS enrollment_count
             FROM student_enrollments
             WHERE academic_year_id IN ($placeholders)
             GROUP BY academic_year_id"
        );
        $stmt->execute($yearIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['academic_year_id']] = (int) $row['enrollment_count'];
        }
        return $counts;
    }

    public static function countEnrollments(PDO $db, int $yearId): int
    {
        return self::countEnrollmentsByYear($db, [$yearId])[$yearId] ?? 0;
    }

    /**
     * قفل عام دراسي (يمنع تعديل الحضور/الدرجات/التقييمات/الرسوم فيه).
     * لا يمكن قفل العام النشط.
     */
    public static function lock(PDO $db, int $yearId): void
    {
        self::setLocked($db, $yearId, true);
    }

    /** فتح قفل عام دراسي. */
    public static function unlock(PDO $db, int $yearId): void
    {
        self::setLocked($db, $yearId, false);
    }

    /** هل العام الحالي (كما يراه المستخدم) مقفل؟ */
    public static function isCurrentYearLocked(PDO $db): bool
    {
        $year = self::getCurrent($db);
        if (!$year) return false;
        return (int)($year['locked'] ?? 0) === 1;
    }

    public static function findById(PDO $db, int $yearId): ?array
    {
        if (!self::tableExists($db, 'academic_years')) {
            return null;
        }
        $stmt = $db->prepare("SELECT * FROM academic_years WHERE id = ? LIMIT 1");
        $stmt->execute([$yearId]);
        $year = $stmt->fetch(PDO::FETCH_ASSOC);
        return $year ?: null;
    }

    /** ضمان وجود عام افتراضي على الأقل (يُستدعى داخلياً). */
    public static function ensureDefault(PDO $db): void
    {
        if (!self::tableExists($db, 'academic_years')) {
            return;
        }
        if ((int) $db->query("SELECT COUNT(*) FROM academic_years")->fetchColumn() > 0) {
            return;
        }
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'academic_year' LIMIT 1");
        $stmt->execute();
        $name = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($name === '') {
            $year = (int) date('Y');
            $name = $year . '-' . ($year + 1);
        }
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            $db->prepare("INSERT INTO academic_years (name, is_active, status) VALUES (?, 1, 'active')")->execute([$name]);
            $id = (int)$db->lastInsertId();
            $after = self::findById($db, $id);
            if (!$after) throw new RuntimeException('Default academic year could not be reloaded.');
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordInsert(
                'academic_year', 'academic_years', $id, $name, $after, 'إنشاء العام الدراسي الافتراضي'
            );
            if ($ownsTransaction) $db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    // ----------------------- أدوات داخلية -----------------------

    private static function setLocked(PDO $db, int $yearId, bool $locked): void
    {
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            $stmt = $db->prepare('SELECT * FROM academic_years WHERE id = ? FOR UPDATE');
            $stmt->execute([$yearId]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new InvalidArgumentException('العام الدراسي غير موجود.');
            if ($locked && (int)$before['is_active'] === 1) {
                throw new InvalidArgumentException('لا يمكن قفل العام النشط. فعّل عاماً آخر أولاً.');
            }
            $db->prepare('UPDATE academic_years SET locked = ? WHERE id = ?')->execute([$locked ? 1 : 0, $yearId]);
            $after = self::findById($db, $yearId);
            if (!$after) throw new RuntimeException('Academic year could not be reloaded after lock change.');
            if ($before != $after) {
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordUpdate(
                    'academic_year', 'academic_years', $yearId, (string)$after['name'], $before, $after,
                    $locked ? 'قفل عام دراسي' : 'فتح قفل عام دراسي'
                );
            }
            if ($ownsTransaction) $db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function saveSetting(PDO $db, string $key, string $value, string $description): void
    {
        $stmt = $db->prepare('SELECT * FROM settings WHERE setting_key = ? FOR UPDATE');
        $stmt->execute([$key]);
        $before = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $db->prepare("INSERT INTO settings (setting_key, setting_value, description)
                      VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)")
            ->execute([$key, $value, $description]);
        $stmt = $db->prepare('SELECT * FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $after = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$after) throw new RuntimeException('Academic year setting could not be reloaded.');
        $id = $after['id'] ?? $key;
        $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);
        if ($before === null) {
            $audit->recordInsert('setting', 'settings', $id, $key, $after, 'إضافة إعداد العام الدراسي');
        } elseif ($before != $after) {
            $audit->recordUpdate('setting', 'settings', $id, $key, $before, $after, 'تعديل إعداد العام الدراسي');
        }
    }

    private static function normalizeName(string $name): string
    {
        return preg_replace('/\s+/', '', trim($name));
    }

    private static function normalizeDate(?string $date, string $label): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException($label . ' غير صالح.');
        }
        return $date;
    }

    private static function assertDateRange(?string $startDate, ?string $endDate): void
    {
        if ($startDate !== null && $endDate !== null && $startDate >= $endDate) {
            throw new InvalidArgumentException('يجب أن يسبق تاريخ بداية العام الدراسي تاريخ النهاية.');
        }
    }

    private static function normalizeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);
        return $notes === '' ? null : mb_substr($notes, 0, 500);
    }

    /**
     * حصر جميع المراجع المباشرة المعرّفة بمفاتيح أجنبية إلى academic_years.
     *
     * @return array<int, array{table:string,column:string,count:int}>
     */
    private static function countReferences(PDO $db, int $yearId): array
    {
        return self::countReferencesByYear($db, [$yearId])[$yearId] ?? [];
    }

    /**
     * @param array<int, int> $yearIds
     * @return array<int, array<int, array{table:string,column:string,count:int}>>
     */
    private static function countReferencesByYear(PDO $db, array $yearIds): array
    {
        $yearIds = array_values(array_unique(array_filter(
            array_map('intval', $yearIds),
            static fn(int $yearId): bool => $yearId > 0
        )));
        if ($yearIds === []) {
            return [];
        }

        // Static FK inventory — replaces the slow information_schema.KEY_COLUMN_USAGE
        // query (~4s on XAMPP). Update this list when a migration adds or removes
        // a foreign key referencing academic_years.id.
        $references = [
            ['TABLE_NAME' => 'academic_months',              'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'academic_terms',               'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'academic_weeks',               'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'academic_year_rollover_runs',  'COLUMN_NAME' => 'source_year_id'],
            ['TABLE_NAME' => 'academic_year_rollover_runs',  'COLUMN_NAME' => 'target_year_id'],
            ['TABLE_NAME' => 'assessment_schemes',           'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'assessment_scheme_families',   'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'assessment_student_locks',     'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'attendance',                   'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'classes',                      'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'class_rollover_mappings',      'COLUMN_NAME' => 'source_year_id'],
            ['TABLE_NAME' => 'class_rollover_mappings',      'COLUMN_NAME' => 'target_year_id'],
            ['TABLE_NAME' => 'evaluations',                  'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'fee_payments',                 'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'grade_audit_log',              'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'grade_promotion_rules',        'COLUMN_NAME' => 'source_year_id'],
            ['TABLE_NAME' => 'grade_promotion_rules',        'COLUMN_NAME' => 'target_year_id'],
            ['TABLE_NAME' => 'published_reports',            'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'report_windows',               'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'specialist_class_assignments', 'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'specialist_grade_assignments', 'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'staff_class_assignments',      'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'staff_grade_assignments',      'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_bus_assignments',      'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_change_requests',      'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_enrollments',          'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_external_transfers',   'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_fees',                 'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_fee_balances_history', 'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_grades',               'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_marks',                'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_other_discounts',      'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'student_promotion_decisions',  'COLUMN_NAME' => 'source_year_id'],
            ['TABLE_NAME' => 'student_promotion_decisions',  'COLUMN_NAME' => 'target_year_id'],
            ['TABLE_NAME' => 'student_transfers',            'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'subject_grade_assignments',    'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'teacher_subject_assignments',  'COLUMN_NAME' => 'academic_year_id'],
            ['TABLE_NAME' => 'timetable_entries',            'COLUMN_NAME' => 'academic_year_id'],
        ];

        $groupsByYear = array_fill_keys($yearIds, []);
        $placeholders = implode(',', array_fill(0, count($yearIds), '?'));
        foreach ($references as $reference) {
            $table = str_replace('`', '``', (string) $reference['TABLE_NAME']);
            $column = str_replace('`', '``', (string) $reference['COLUMN_NAME']);
            $countStmt = $db->prepare(
                'SELECT `' . $column . '` AS academic_year_id, COUNT(*) AS reference_count'
                . ' FROM `' . $table . '`'
                . ' WHERE `' . $column . '` IN (' . $placeholders . ')'
                . ' GROUP BY `' . $column . '`'
            );
            $countStmt->execute($yearIds);
            foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $yearId = (int) $row['academic_year_id'];
                $count = (int) $row['reference_count'];
                if ($yearId <= 0 || $count <= 0 || !isset($groupsByYear[$yearId])) {
                    continue;
                }
                $groupsByYear[$yearId][] = [
                    'table' => (string) $reference['TABLE_NAME'],
                    'column' => (string) $reference['COLUMN_NAME'],
                    'count' => $count,
                ];
            }
        }
        return $groupsByYear;
    }

    private static function buildDeletionAssessment(array $year, int $yearCount, array $referenceGroups): array
    {
        if ((int) ($year['is_active'] ?? 0) === 1) {
            return [
                'can_delete' => false,
                'reason' => 'لا يمكن حذف العام الدراسي النشط. فعّل عاماً آخر أولاً.',
                'reference_count' => 0,
                'reference_groups' => [],
            ];
        }
        if ((int) ($year['locked'] ?? 0) === 1) {
            return [
                'can_delete' => false,
                'reason' => 'لا يمكن حذف عام دراسي مقفل. افتح القفل أولاً إذا كان الحذف مقصوداً.',
                'reference_count' => 0,
                'reference_groups' => [],
            ];
        }
        if ($yearCount <= 1) {
            return [
                'can_delete' => false,
                'reason' => 'لا يمكن حذف العام الدراسي الوحيد في النظام.',
                'reference_count' => 0,
                'reference_groups' => [],
            ];
        }

        $referenceCount = array_sum(array_column($referenceGroups, 'count'));
        if ($referenceCount > 0) {
            return [
                'can_delete' => false,
                'reason' => self::formatReferenceBlocker($referenceGroups),
                'reference_count' => $referenceCount,
                'reference_groups' => $referenceGroups,
            ];
        }
        return [
            'can_delete' => true,
            'reason' => 'العام غير نشط ولا يحتوي على بيانات مرتبطة، ويمكن حذفه بأمان.',
            'reference_count' => 0,
            'reference_groups' => [],
        ];
    }

    private static function formatReferenceBlocker(array $referenceGroups): string
    {
        $labels = [
            'student_enrollments' => 'قيود الطلاب',
            'academic_year_rollover_runs' => 'تشغيلات تهيئة العام',
            'student_promotion_decisions' => 'قرارات ترحيل الطلاب',
            'grade_promotion_rules' => 'قواعد الترحيل',
            'class_rollover_mappings' => 'خرائط انتقال الفصول',
            'classes' => 'الفصول',
            'attendance' => 'الحضور',
            'student_grades' => 'درجات الطلاب',
            'student_marks' => 'نتائج التقييم',
            'fee_payments' => 'المدفوعات',
            'student_fees' => 'رسوم الطلاب',
        ];

        $summaries = [];
        $otherCount = 0;
        $otherSources = [];
        foreach ($referenceGroups as $group) {
            $table = (string) ($group['table'] ?? '');
            $count = (int) ($group['count'] ?? 0);
            if (isset($labels[$table])) {
                $summaries[] = $labels[$table] . ': ' . number_format($count);
                continue;
            }
            $otherCount += $count;
            $otherSources[$table] = true;
        }
        if ($otherCount > 0) {
            $summaries[] = 'بيانات تشغيلية أخرى: ' . number_format($otherCount)
                . ' في ' . number_format(count($otherSources)) . ' مصادر';
        }

        $details = implode('، ', array_slice($summaries, 0, 6));
        return 'لا يمكن حذف العام لوجود بيانات مرتبطة به'
            . ($details !== '' ? ' (' . $details . ')' : '')
            . '. أزل البيانات من مسارها الصحيح، أو استعد تهيئة العام أولاً إن كانت لم تُفعّل.';
    }

    /** التحقق من عدم وجود عام آخر بنفس الاسم. */
    private static function assertNameAvailable(PDO $db, string $name, ?int $excludeId): void
    {
        if ($excludeId !== null) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM academic_years WHERE name = ? AND id <> ?");
            $stmt->execute([$name, $excludeId]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM academic_years WHERE name = ?");
            $stmt->execute([$name]);
        }
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('يوجد عام دراسي آخر بنفس الاسم بالفعل.');
        }
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
}
