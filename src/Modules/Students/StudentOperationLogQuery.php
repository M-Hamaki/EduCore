<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use DateTimeImmutable;
use PDO;

/**
 * Read model for the dedicated student-affairs operation log.
 *
 * The scope combines explicit student-owned audit entity types with writes
 * performed through confirmed student-affairs routes.  This keeps generic
 * entities such as settings and undo events visible without leaking unrelated
 * administration activity into the page.
 */
final class StudentOperationLogQuery
{
    private const PER_PAGE = 50;

    /** @var array<int,string> */
    private const EXCLUDED_TARGET_TYPES = [
        'student_account',
        'users',
        'student_mark',
        'student_mark_class_move',
        'assessment_student_lock',
        'evaluation',
        'evaluation_type',
        'student_evaluation',
    ];

    /** @var array<string,string> */
    private const TARGET_LABELS = [
        'student' => 'الطالب',
        'student_profile' => 'ملف الطالب',
        'student_attachment' => 'مرفقات الطالب',
        'student_change_request' => 'طلب تعديل طالب',
        'student_enrollment' => 'القيد السنوي',
        'student_external_transfer' => 'النقل الخارجي',
        'student_transfer' => 'نقل الطالب',
        'student_guardian' => 'ولي أمر الطالب',
        'student_sibling' => 'شقيق الطالب',
        'student_sibling_link' => 'ربط الأشقاء',
        'student_kinship' => 'صلة قرابة',
        'student_relationship' => 'علاقات الطلاب',
        'kinship_type' => 'نوع صلة القرابة',
        'attendance' => 'حضور طالب',
        'attendance_class_day' => 'كشف حضور فصل',
        'student_graduation' => 'تخرج الطالب',
        'academic_year_student_sync' => 'مزامنة طلاب العام الدراسي',
        'sibling' => 'اعتماد رابط أشقاء',
        'setting' => 'إعدادات شؤون الطلاب',
        'settings' => 'إعدادات شؤون الطلاب',
    ];

    /** @var array<int,string> */
    private const OWNED_TARGET_TYPES = [
        'student',
        'student_profile',
        'student_attachment',
        'student_change_request',
        'student_enrollment',
        'student_external_transfer',
        'student_transfer',
        'student_guardian',
        'student_sibling',
        'student_sibling_link',
        'student_kinship',
        'student_relationship',
        'kinship_type',
        'attendance',
        'attendance_class_day',
        'student_graduation',
        'academic_year_student_sync',
        'sibling',
    ];

    /** @var array<int,string> */
    private const OWNED_ROUTES = [
        'students.php',
        'pending_operations.php',
        'new_students.php',
        'transferred_students.php',
        'discontinued_students.php',
        'graduate_students.php',
        'graduates.php',
        'student_archive.php',
        'student_data_completeness.php',
        'class_lists.php',
        'siblings.php',
        'relationship_discovery.php',
        'attendance.php',
        'export_students.php',
        'student_statistics.php',
        'calculation_tools.php',
        'ajax_students_datatable.php',
        'ajax_derived_students_datatable.php',
        'ajax_student_archive_datatable.php',
        'ajax_student_completeness.php',
        'student_operations.php',
    ];

    private PDO $db;
    private int $academicYearId;

    public function __construct(PDO $db, int $academicYearId)
    {
        $this->db = $db;
        $this->academicYearId = max(0, $academicYearId);
    }

    /** @return array<string,mixed> */
    public function load(array $input): array
    {
        $filters = $this->normalizeFilters($input);
        $page = max(1, (int) ($input['log_page'] ?? 1));
        [$whereSql, $params] = $this->where($filters);
        $whereSql = '(' . $whereSql . ') AND COALESCE(al.action, \'\') NOT LIKE ?';
        $params[] = 'evaluation_%';
        $whereSql .= ' AND COALESCE(al.target_type, \'\') NOT IN ('
            . implode(',', array_fill(0, count(self::EXCLUDED_TARGET_TYPES), '?')) . ')';
        array_push($params, ...self::EXCLUDED_TARGET_TYPES);
        $whereSql .= " AND COALESCE(ul.failure_reason, '') <> 'no_reversible_field_changes'";

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM activity_logs al LEFT JOIN undo_log ul ON ul.id = al.undo_log_id WHERE ' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $sql = 'SELECT al.*,
                    ul.id AS undo_id,
                    ul.user_id AS undo_user_id,
                    ul.action_type AS undo_action_type,
                    ul.table_name AS undo_table_name,
                    ul.description AS undo_description,
                    ul.batch_id AS undo_batch_id,
                    ul.can_undo,
                    ul.is_undone,
                    ul.undo_status,
                    ul.failure_reason AS undo_failure_reason,
                    ul.undone_by,
                    ul.undone_at
                FROM activity_logs al
                LEFT JOIN undo_log ul ON ul.id = al.undo_log_id
                WHERE ' . $whereSql . '
                ORDER BY al.created_at DESC, al.id DESC
                LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = $this->enrichPresentationContext($rows);

        [$scopeSql, $scopeParams] = $this->scopeWhere();
        $scopeSql = '(' . $scopeSql . ') AND COALESCE(al.action, \'\') NOT LIKE ?';
        $scopeParams[] = 'evaluation_%';
        $scopeSql .= ' AND COALESCE(al.target_type, \'\') NOT IN ('
            . implode(',', array_fill(0, count(self::EXCLUDED_TARGET_TYPES), '?')) . ')';
        array_push($scopeParams, ...self::EXCLUDED_TARGET_TYPES);
        $scopeSql .= " AND COALESCE(ul.failure_reason, '') <> 'no_reversible_field_changes'";
        $statsStmt = $this->db->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(al.action, '') NOT IN ('undo', 'redo') AND ul.id IS NOT NULL AND ul.can_undo = 1 AND ul.is_undone = 0 AND ul.undo_status = 'pending' THEN 1 ELSE 0 END) AS available,
                    SUM(CASE WHEN COALESCE(al.action, '') NOT IN ('undo', 'redo') AND ul.id IS NOT NULL AND (ul.is_undone = 1 OR ul.undo_status = 'completed') THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE
                        WHEN COALESCE(al.action, '') IN ('undo', 'redo') THEN 1
                        WHEN ul.id IS NULL THEN 1
                        WHEN NOT (ul.can_undo = 1 AND ul.is_undone = 0 AND ul.undo_status = 'pending')
                         AND NOT (ul.is_undone = 1 OR ul.undo_status = 'completed') THEN 1
                        ELSE 0
                    END) AS unavailable
               FROM activity_logs al
               LEFT JOIN undo_log ul ON ul.id = al.undo_log_id
              WHERE {$scopeSql}"
        );
        $statsStmt->execute($scopeParams);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PER_PAGE,
            'filters' => $filters,
            'action_options' => $this->studentAffairsActionOptions(),
            'type_options' => $this->studentAffairsTypeOptions(),
            'stats' => [
                'total' => (int) ($stats['total'] ?? 0),
                'available' => (int) ($stats['available'] ?? 0),
                'completed' => (int) ($stats['completed'] ?? 0),
                'unavailable' => (int) ($stats['unavailable'] ?? 0),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function findUndoableOperation(int $activityId, int $undoId): ?array
    {
        if ($activityId <= 0 || $undoId <= 0) {
            return null;
        }

        [$scopeSql, $scopeParams] = $this->scopeWhere();
        $scopeSql = '(' . $scopeSql . ') AND COALESCE(al.action, \'\') NOT LIKE ?';
        $scopeParams[] = 'evaluation_%';
        $scopeSql .= ' AND COALESCE(al.target_type, \'\') NOT IN ('
            . implode(',', array_fill(0, count(self::EXCLUDED_TARGET_TYPES), '?')) . ')';
        array_push($scopeParams, ...self::EXCLUDED_TARGET_TYPES);
        $stmt = $this->db->prepare(
            "SELECT al.id AS activity_id, al.target_name, al.target_type, al.action,
                    ul.*
               FROM activity_logs al
               INNER JOIN undo_log ul ON ul.id = al.undo_log_id
              WHERE {$scopeSql}
                AND al.id = ?
                AND ul.id = ?
                AND COALESCE(al.action, '') NOT IN ('undo', 'redo')
                AND ul.can_undo = 1
                AND ul.is_undone = 0
                AND ul.undo_status = 'pending'
              LIMIT 1"
        );
        $stmt->execute(array_merge($scopeParams, [$activityId, $undoId]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findRedoableOperation(int $activityId, int $undoId): ?array
    {
        if ($activityId <= 0 || $undoId <= 0) {
            return null;
        }

        [$scopeSql, $scopeParams] = $this->scopeWhere();
        $scopeSql = '(' . $scopeSql . ') AND COALESCE(al.action, \'\') NOT LIKE ?';
        $scopeParams[] = 'evaluation_%';
        $scopeSql .= ' AND COALESCE(al.target_type, \'\') NOT IN ('
            . implode(',', array_fill(0, count(self::EXCLUDED_TARGET_TYPES), '?')) . ')';
        array_push($scopeParams, ...self::EXCLUDED_TARGET_TYPES);
        $stmt = $this->db->prepare(
            "SELECT al.id AS activity_id, al.target_name, al.target_type, al.action,
                    ul.*
               FROM activity_logs al
               INNER JOIN undo_log ul ON ul.id = al.undo_log_id
              WHERE {$scopeSql}
                AND al.id = ?
                AND ul.id = ?
                AND COALESCE(al.action, '') NOT IN ('undo', 'redo')
                AND ul.can_undo = 1
                AND ul.is_undone = 1
                AND ul.undo_status = 'completed'
              LIMIT 1"
        );
        $stmt->execute(array_merge($scopeParams, [$activityId, $undoId]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function targetLabel(?string $targetType): string
    {
        $targetType = trim((string) $targetType);
        return self::TARGET_LABELS[$targetType] ?? ($targetType !== '' ? $targetType : 'عملية عامة');
    }

    public static function undoState(array $row): string
    {
        if (in_array((string) ($row['action'] ?? ''), ['undo', 'redo'], true)) {
            return 'unavailable';
        }
        if (empty($row['undo_id'])) {
            return 'unavailable';
        }
        if ((int) ($row['is_undone'] ?? 0) === 1 || ($row['undo_status'] ?? '') === 'completed') {
            return 'completed';
        }
        if ((int) ($row['can_undo'] ?? 0) === 1 && ($row['undo_status'] ?? '') === 'pending') {
            return 'available';
        }
        return 'unavailable';
    }

    public static function redoReason(array $row): string
    {
        return self::undoState($row) === 'completed'
            ? 'يمكن إعادة تنفيذ العملية بعد التحقق من عدم وجود تعارض'
            : 'هذه العملية ليست في حالة تسمح بإعادة التنفيذ';
    }

    public static function undoReason(array $row): string
    {
        $state = self::undoState($row);
        if ($state === 'available') {
            return 'يمكن التراجع عن هذه العملية بأمان';
        }
        if ($state === 'completed') {
            return 'تم التراجع عن هذه العملية بالفعل';
        }
        if (empty($row['undo_id'])) {
            return 'لا يمكن التراجع تلقائيًا؛ لا توجد نسخة آمنة لاستعادة البيانات.';
        }
        $reason = (string) ($row['undo_failure_reason'] ?? $row['failure_reason'] ?? '');
        $messages = [
            'no_reversible_field_changes' => 'لم تتغير البيانات، لذلك لا يوجد شيء يحتاج إلى تراجع.',
            'workflow_owned_rollback' => 'يتم التراجع من الإجراء الأصلي لأنه مسؤول عن بيانات مترابطة.',
            'reversal_required' => 'هذه العملية تحتاج إجراء عكس مخصصًا ولا يمكن إلغاؤها مباشرة.',
            'credential_snapshot_excluded' => 'لا يمكن استعادة بيانات الدخول تلقائيًا حفاظًا على الأمان.',
            'unregistered_entity' => 'لا توجد آلية استعادة معتمدة لهذا النوع من البيانات.',
        ];
        return $messages[$reason] ?? 'لا يمكن التراجع تلقائيًا؛ العملية مرتبطة ببيانات أخرى وتحتاج إجراءً مخصصًا.';
    }

    /** @return array{summary:string,subject:string,context:string,technical_reference:string} */
    public static function operationPresentation(array $row): array
    {
        $targetType = (string) ($row['target_type'] ?? '');
        $action = strtolower((string) ($row['action'] ?? ''));
        $targetLabel = self::targetLabel($targetType);
        $targetName = trim((string) ($row['target_name'] ?? ''));
        $details = !empty($row['details']) ? json_decode((string) $row['details'], true) : null;
        $details = is_array($details) ? $details : [];

        $technicalParts = [];
        if (!empty($row['id'])) $technicalParts[] = 'سجل النشاط #' . (int) $row['id'];
        if (!empty($row['target_id'])) $technicalParts[] = 'مرجع البيانات #' . (int) $row['target_id'];

        if ($targetType === 'student_enrollment') {
            $studentId = (int) ($row['display_student_id'] ?? 0);
            if ($studentId <= 0 && preg_match('/#(\d+)/u', $targetName, $matches)) {
                $studentId = (int) $matches[1];
            }
            $studentName = trim((string) ($row['display_student_name'] ?? ''));
            $studentCode = trim((string) ($row['display_student_code'] ?? ''));
            $subject = $studentName !== '' ? $studentName : ($studentId > 0 ? 'الطالب رقم ' . $studentId : 'طالب');
            if ($studentCode !== '') $subject .= ' · ' . $studentCode;

            $phrases = [
                'create' => 'تم إنشاء القيد السنوي للطالب', 'insert' => 'تم إنشاء القيد السنوي للطالب',
                'update' => 'تم تحديث القيد السنوي للطالب', 'delete' => 'تم حذف القيد السنوي للطالب',
                'undo' => 'تم التراجع عن تغيير القيد السنوي للطالب',
                'redo' => 'تمت إعادة تنفيذ تغيير القيد السنوي للطالب',
            ];
            $summary = ($phrases[$action] ?? 'تم تنفيذ إجراء على القيد السنوي للطالب') . ' «' . $subject . '»';
            $contextParts = [];
            foreach (['display_academic_year_name' => 'العام', 'display_grade_name' => 'الصف', 'display_class_name' => 'الفصل'] as $key => $label) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') $contextParts[] = $label . ': ' . $value;
            }
            $changedLabels = self::changedFieldLabels($details);
            if ($changedLabels !== []) $contextParts[] = 'التغييرات: ' . implode('، ', $changedLabels);
            if ($studentId > 0) $technicalParts[] = 'رقم الطالب #' . $studentId;

            return [
                'summary' => $summary,
                'subject' => $subject,
                'context' => $contextParts !== [] ? implode(' · ', $contextParts) : 'بيانات القيد المرتبطة بالعام الدراسي',
                'technical_reference' => implode(' · ', $technicalParts),
            ];
        }

        if ($targetType === 'academic_year_student_sync') {
            $studentCount = (int) ($details['student_count'] ?? $details['affected_student_count'] ?? 0);
            $yearName = trim((string) ($row['display_academic_year_name'] ?? ''));
            return [
                'summary' => $studentCount > 0
                    ? 'تمت مزامنة بيانات القيد السنوي لـ ' . number_format($studentCount) . ' طالبًا'
                    : 'تمت مزامنة بيانات القيد السنوي للطلاب',
                'subject' => $yearName !== '' ? 'العام الدراسي ' . $yearName : 'العام الدراسي',
                'context' => 'مطابقة الفصول وحالات القيد والدراسة مع سجلات العام الدراسي',
                'technical_reference' => implode(' · ', $technicalParts),
            ];
        }

        $subject = $targetName !== '' ? $targetName : $targetLabel;
        $phrases = [
            'create' => 'تمت إضافة', 'insert' => 'تمت إضافة', 'update' => 'تم تعديل',
            'delete' => 'تم حذف', 'archive' => 'تمت أرشفة', 'restore' => 'تمت استعادة',
            'link' => 'تم ربط', 'unlink' => 'تم إلغاء ربط', 'import' => 'تم استيراد',
            'export' => 'تم تصدير', 'undo' => 'تم التراجع عن', 'redo' => 'تمت إعادة تنفيذ',
            'status_change' => 'تم تغيير حالة', 'settings' => 'تم تحديث',
        ];
        $summary = ($phrases[$action] ?? 'تم تنفيذ إجراء على') . ' ' . $targetLabel;
        if ($subject !== $targetLabel) $summary .= ' «' . $subject . '»';
        $context = trim((string) ($details['summary'] ?? $row['undo_description'] ?? ''));

        return [
            'summary' => $summary,
            'subject' => $subject,
            'context' => $context !== '' ? $context : 'العملية محفوظة للمراجعة في سجل شؤون الطلاب',
            'technical_reference' => implode(' · ', $technicalParts),
        ];
    }

    /** @return array<int,string> */
    private static function changedFieldLabels(array $details): array
    {
        $changes = $details['changes'] ?? [];
        if (!is_array($changes)) return [];
        $labels = [
            'stage_id' => 'المرحلة', 'grade_id' => 'الصف', 'class_id' => 'الفصل',
            'enrollment_status' => 'حالة القيد', 'academic_status' => 'الحالة الدراسية',
            'graduation_year' => 'عام التخرج', 'enrollment_date' => 'تاريخ القيد',
            'is_repeater' => 'حالة الإعادة', 'repeat_count' => 'عدد مرات الإعادة',
        ];
        $result = [];
        foreach (array_keys($changes) as $field) {
            if (isset($labels[$field])) $result[] = $labels[$field];
        }
        return array_values(array_unique($result));
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function enrichPresentationContext(array $rows): array
    {
        if ($rows === []) return $rows;

        $enrollmentIds = [];
        $studentIds = [];
        $yearIds = [];
        foreach ($rows as $row) {
            $targetType = (string) ($row['target_type'] ?? '');
            $targetId = (int) ($row['target_id'] ?? 0);
            if ($targetType === 'student_enrollment') {
                if ($targetId > 0) $enrollmentIds[$targetId] = $targetId;
                if (preg_match('/#(\d+)/u', (string) ($row['target_name'] ?? ''), $matches)) {
                    $studentIds[(int) $matches[1]] = (int) $matches[1];
                }
            } elseif ($targetType === 'academic_year_student_sync' && $targetId > 0) {
                $yearIds[$targetId] = $targetId;
            }
        }

        $enrollments = [];
        if ($enrollmentIds !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
                $stmt = $this->db->prepare(
                    'SELECT se.id, se.student_id, u.name AS student_name, sp.student_code,
                            ay.name AS academic_year_name, g.grade_name, c.name AS class_name
                       FROM student_enrollments se
                       LEFT JOIN users u ON u.id = se.student_id
                       LEFT JOIN student_profiles sp ON sp.user_id = se.student_id
                       LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
                       LEFT JOIN grades g ON g.id = se.grade_id
                       LEFT JOIN classes c ON c.id = se.class_id
                      WHERE se.id IN (' . $placeholders . ')'
                );
                $stmt->execute(array_values($enrollmentIds));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $context) {
                    $enrollments[(int) $context['id']] = $context;
                    $studentId = (int) ($context['student_id'] ?? 0);
                    if ($studentId > 0) $studentIds[$studentId] = $studentId;
                }
            } catch (\Throwable $exception) {
                // Losing optional display context must not hide the immutable audit row.
            }
        }

        $students = [];
        if ($studentIds !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                $stmt = $this->db->prepare(
                    'SELECT u.id, u.name AS student_name, sp.student_code
                       FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id
                      WHERE u.id IN (' . $placeholders . ')'
                );
                $stmt->execute(array_values($studentIds));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
                    $students[(int) $student['id']] = $student;
                }
            } catch (\Throwable $exception) {
                // The student number in the audit target remains as a safe fallback.
            }
        }

        $years = [];
        if ($yearIds !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count($yearIds), '?'));
                $stmt = $this->db->prepare('SELECT id, name FROM academic_years WHERE id IN (' . $placeholders . ')');
                $stmt->execute(array_values($yearIds));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $year) {
                    $years[(int) $year['id']] = (string) $year['name'];
                }
            } catch (\Throwable $exception) {
                // Keep generic academic-year wording when the related year is unavailable.
            }
        }

        foreach ($rows as &$row) {
            $targetType = (string) ($row['target_type'] ?? '');
            $targetId = (int) ($row['target_id'] ?? 0);
            if ($targetType === 'student_enrollment') {
                $context = $enrollments[$targetId] ?? [];
                $studentId = (int) ($context['student_id'] ?? 0);
                if ($studentId <= 0 && preg_match('/#(\d+)/u', (string) ($row['target_name'] ?? ''), $matches)) {
                    $studentId = (int) $matches[1];
                }
                $student = $students[$studentId] ?? [];
                $row['display_student_id'] = $studentId ?: null;
                $row['display_student_name'] = $context['student_name'] ?? $student['student_name'] ?? null;
                $row['display_student_code'] = $context['student_code'] ?? $student['student_code'] ?? null;
                $row['display_academic_year_name'] = $context['academic_year_name'] ?? null;
                $row['display_grade_name'] = $context['grade_name'] ?? null;
                $row['display_class_name'] = $context['class_name'] ?? null;
            } elseif ($targetType === 'academic_year_student_sync') {
                $row['display_academic_year_name'] = $years[$targetId] ?? null;
            }
        }
        unset($row);

        return $rows;
    }

    /** @return array<mixed> */
    private function studentAffairsActionOptions(): array
    {
        $options = [];
        foreach ($this->actionOptions() as $key => $label) {
            $value = is_int($key) ? (string) $label : (string) $key;
            if (str_starts_with($value, 'evaluation_')) {
                continue;
            }
            $options[$key] = $label;
        }

        return $options;
    }

    /** @return array<mixed> */
    private function studentAffairsTypeOptions(): array
    {
        $options = [];
        foreach ($this->typeOptions() as $key => $label) {
            $value = is_int($key) ? (string) $label : (string) $key;
            if (in_array($value, self::EXCLUDED_TARGET_TYPES, true)) {
                continue;
            }
            $options[$key] = $label;
        }

        return $options;
    }

    /** @return array<string,string> */
    private function normalizeFilters(array $input): array
    {
        $action = mb_substr(trim((string) ($input['log_action'] ?? '')), 0, 50);
        $targetType = mb_substr(trim((string) ($input['log_type'] ?? '')), 0, 80);
        $undoState = (string) ($input['undo_state'] ?? '');
        $tab = (string) ($input['log_tab'] ?? '');
        if ($tab === 'undone' || $undoState === 'completed') {
            $tab = 'undone';
            $undoState = '';
        } else {
            $tab = 'active';
        }
        if (!in_array($undoState, ['', 'available', 'unavailable'], true)) {
            $undoState = '';
        }

        $dateFrom = $this->normalizeDate((string) ($input['log_from'] ?? ''));
        $dateTo = $this->normalizeDate((string) ($input['log_to'] ?? ''));
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'action' => $action,
            'target_type' => $targetType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'search' => mb_substr(trim((string) ($input['log_search'] ?? '')), 0, 120),
            'undo_state' => $undoState,
            'tab' => $tab,
        ];
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function where(array $filters): array
    {
        [$scopeSql, $params] = $this->scopeWhere();
        $where = [$scopeSql];

        if ($filters['action'] !== '') {
            $where[] = 'al.action = ?';
            $params[] = $filters['action'];
        }
        if ($filters['target_type'] !== '') {
            $where[] = 'al.target_type = ?';
            $params[] = $filters['target_type'];
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'al.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'al.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if ($filters['search'] !== '') {
            $where[] = '(al.user_name LIKE ? OR al.target_name LIKE ? OR al.details LIKE ?)';
            $search = '%' . $filters['search'] . '%';
            array_push($params, $search, $search, $search);
        }

        if ($filters['tab'] === 'undone') {
            $where[] = "COALESCE(al.action, '') NOT IN ('undo', 'redo')";
            $where[] = "ul.id IS NOT NULL AND (ul.is_undone = 1 OR ul.undo_status = 'completed')";
        } else {
            $where[] = "(COALESCE(al.action, '') IN ('undo', 'redo') OR ul.id IS NULL OR NOT (ul.is_undone = 1 OR ul.undo_status = 'completed'))";
        }

        if ($filters['undo_state'] === 'available') {
            $where[] = "ul.id IS NOT NULL AND ul.can_undo = 1 AND ul.is_undone = 0 AND ul.undo_status = 'pending'";
        } elseif ($filters['undo_state'] === 'unavailable') {
            $where[] = "(COALESCE(al.action, '') IN ('undo', 'redo') OR ul.id IS NULL OR (
                NOT (ul.can_undo = 1 AND ul.is_undone = 0 AND ul.undo_status = 'pending')
                AND NOT (ul.is_undone = 1 OR ul.undo_status = 'completed')
            ))";
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function scopeWhere(): array
    {
        if ($this->academicYearId <= 0) {
            return ['1 = 0', []];
        }

        $conditions = [
            'al.target_type IN (' . implode(',', array_fill(0, count(self::OWNED_TARGET_TYPES), '?')) . ')',
            "(al.target_type IN ('setting', 'settings') AND al.target_name = 'student_completeness_fields_v2')",
        ];
        $params = self::OWNED_TARGET_TYPES;

        foreach (self::OWNED_ROUTES as $route) {
            $conditions[] = 'al.route LIKE ?';
            $params[] = '%/admin/' . $route . '%';
        }

        $params[] = $this->academicYearId;
        return ['(' . implode(' OR ', $conditions) . ') AND al.academic_year_id = ?', $params];
    }

    /** @return array<string,string> */
    private function actionOptions(): array
    {
        [$scopeSql, $params] = $this->scopeWhere();
        $stmt = $this->db->prepare(
            "SELECT DISTINCT al.action FROM activity_logs al WHERE {$scopeSql} AND al.action IS NOT NULL AND al.action <> '' ORDER BY al.action"
        );
        $stmt->execute($params);
        $options = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $action) {
            $options[(string) $action] = (string) $action;
        }
        return $options;
    }

    /** @return array<string,string> */
    private function typeOptions(): array
    {
        [$scopeSql, $params] = $this->scopeWhere();
        $stmt = $this->db->prepare(
            "SELECT DISTINCT al.target_type FROM activity_logs al WHERE {$scopeSql} AND al.target_type IS NOT NULL AND al.target_type <> '' ORDER BY al.target_type"
        );
        $stmt->execute($params);
        $options = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $targetType) {
            $targetType = (string) $targetType;
            $options[$targetType] = self::targetLabel($targetType);
        }
        return $options;
    }
}
