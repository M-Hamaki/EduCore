<?php

declare(strict_types=1);

require_once __DIR__ . '/RecoveryBackupService.php';
require_once __DIR__ . '/AcademicYear.php';
require_once __DIR__ . '/UndoManager.php';
require_once __DIR__ . '/ClassRolloverPlanService.php';
require_once __DIR__ . '/../src/Modules/AcademicStructure/ExperimentalAcademicScopePolicy.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\AcademicStructure\ExperimentalAcademicScopePolicy;

final class NewYearRolloverService
{
    private const MANAGED_TARGET_TABLES = [
        'academic_terms',
        'academic_months',
        'academic_weeks',
        'classes',
        'student_enrollments',
        'subject_grade_assignments',
        'assessment_schemes',
        'assessment_components',
        'assessment_component_week_rules',
    ];

    private const FORBIDDEN_TARGET_TABLES = [
        'attendance',
        'evaluations',
        'student_marks',
        'published_reports',
        'student_transfers',
        'student_external_transfers',
        'student_bus_assignments',
        'student_fees',
        'student_other_discounts',
        'fee_payments',
        'student_grades',
        'grade_audit_log',
        'assessment_student_locks',
        'assessment_windows',
        'report_windows',
    ];

    private const DEPENDENCY_ORDER = [
        'academic_terms' => 10,
        'academic_months' => 20,
        'academic_weeks' => 30,
        'classes' => 40,
        'student_enrollments' => 50,
        'subject_grade_assignments' => 60,
        'assessment_schemes' => 70,
        'assessment_components' => 80,
        'assessment_component_week_rules' => 90,
    ];

    private PDO $db;
    private RecoveryBackupService $recovery;
    private ClassRolloverPlanService $classPlans;
    private ?array $officialGradeIdSet = null;

    public function __construct(PDO $db, ?RecoveryBackupService $recovery = null)
    {
        $this->db = $db;
        $this->recovery = $recovery ?: new RecoveryBackupService($db);
        $this->classPlans = new ClassRolloverPlanService($db);
    }

    public function promotionRuleMatrix(int $sourceYearId, int $targetYearId): array
    {
        $this->assertSchemaReady();
        $this->assertYearPair($sourceYearId, $targetYearId);
        $grades = $this->officialGrades();
        $rules = $this->promotionRules($sourceYearId, $targetYearId);
        $matrix = [];
        foreach ($grades as $index => $grade) {
            $gradeId = (int) $grade['id'];
            $existing = $rules[$gradeId] ?? null;
            $suggested = $grades[$index + 1]['id'] ?? null;
            $matrix[] = [
                'source_grade_id' => $gradeId,
                'source_grade_name' => (string) $grade['grade_name'],
                'stage_name' => (string) ($grade['stage_name'] ?? ''),
                'rule_type' => (string) ($existing['rule_type'] ?? ($suggested ? 'promote' : 'graduate')),
                'target_grade_id' => isset($existing['target_grade_id'])
                    ? (int) $existing['target_grade_id']
                    : ($suggested ? (int) $suggested : null),
                'saved' => $existing !== null && (string) ($existing['status'] ?? '') === 'active',
            ];
        }
        return ['grades' => $grades, 'rules' => $matrix];
    }

    public function savePromotionRules(
        int $sourceYearId,
        int $targetYearId,
        array $submittedRules,
        ?int $actorId
    ): array {
        $this->assertSchemaReady();
        $this->assertYearPair($sourceYearId, $targetYearId);
        $grades = $this->officialGrades();
        $gradeSet = [];
        foreach ($grades as $grade) {
            $gradeSet[(int) $grade['id']] = true;
        }
        $normalized = [];
        foreach ($grades as $grade) {
            $sourceGradeId = (int) $grade['id'];
            $value = trim((string) ($submittedRules[$sourceGradeId] ?? ''));
            if ($value === 'graduate') {
                $normalized[$sourceGradeId] = ['rule_type' => 'graduate', 'target_grade_id' => null];
                continue;
            }
            $targetGradeId = (int) $value;
            if ($targetGradeId <= 0 || !isset($gradeSet[$targetGradeId])) {
                throw new InvalidArgumentException('يجب تحديد انتقال صالح لكل صف رسمي.');
            }
            if ($targetGradeId === $sourceGradeId) {
                throw new InvalidArgumentException('لا يجوز أن ينتقل الصف إلى نفسه؛ استخدم قرار الرسوب للطالب بدلاً من ذلك.');
            }
            $normalized[$sourceGradeId] = ['rule_type' => 'promote', 'target_grade_id' => $targetGradeId];
        }
        $this->assertPromotionRulesAcyclic($normalized);

        $ownsTransaction = !$this->db->inTransaction();
        $batchId = UndoManager::newBatchId();
        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            $this->lockYears($sourceYearId, $targetYearId);
            $select = $this->db->prepare('SELECT * FROM grade_promotion_rules
                WHERE source_year_id = ? AND target_year_id = ? AND source_grade_id = ? FOR UPDATE');
            $insert = $this->db->prepare("INSERT INTO grade_promotion_rules
                (source_year_id, target_year_id, source_grade_id, rule_type, target_grade_id, status, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, 'active', ?, ?)");
            $update = $this->db->prepare("UPDATE grade_promotion_rules
                SET rule_type = ?, target_grade_id = ?, status = 'active', updated_by = ? WHERE id = ?");
            $audit = new AuditService($this->db);
            foreach ($normalized as $sourceGradeId => $rule) {
                $select->execute([$sourceYearId, $targetYearId, $sourceGradeId]);
                $before = $select->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($before) {
                    $update->execute([$rule['rule_type'], $rule['target_grade_id'], $actorId, (int) $before['id']]);
                    $after = $this->rowById('grade_promotion_rules', (int) $before['id']);
                    $audit->recordUpdate(
                        'grade_promotion_rule',
                        'grade_promotion_rules',
                        (int) $before['id'],
                        'قاعدة انتقال الصف #' . $sourceGradeId,
                        $before,
                        $after,
                        'تحديث قاعدة انتقال صف للعام الجديد',
                        $batchId
                    );
                } else {
                    $insert->execute([
                        $sourceYearId,
                        $targetYearId,
                        $sourceGradeId,
                        $rule['rule_type'],
                        $rule['target_grade_id'],
                        $actorId,
                        $actorId,
                    ]);
                    $id = (int) $this->db->lastInsertId();
                    $audit->recordInsert(
                        'grade_promotion_rule',
                        'grade_promotion_rules',
                        $id,
                        'قاعدة انتقال الصف #' . $sourceGradeId,
                        $this->rowById('grade_promotion_rules', $id),
                        'إضافة قاعدة انتقال صف للعام الجديد',
                        $batchId
                    );
                }
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $this->promotionRuleMatrix($sourceYearId, $targetYearId);
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function classPromotionMatrix(int $sourceYearId, int $targetYearId): array
    {
        $this->assertSchemaReady();
        $this->assertYearPair($sourceYearId, $targetYearId);
        return $this->classPlans->matrix($sourceYearId, $targetYearId,
            $this->promotionRules($sourceYearId, $targetYearId), $this->officialGrades());
    }

    public function saveClassMappings(int $sourceYearId, int $targetYearId,
        array $submittedMappings, ?int $actorId): array {
        $this->assertSchemaReady();
        $this->assertYearPair($sourceYearId, $targetYearId);
        $rules = $this->promotionRules($sourceYearId, $targetYearId);
        if ($this->validatePromotionRuleCoverage($rules) !== []) {
            throw new InvalidArgumentException('احفظ قواعد انتقال الصفوف كاملة قبل إعداد خريطة الفصول.');
        }
        return $this->classPlans->save($sourceYearId, $targetYearId, $rules,
            $this->officialGrades(), $submittedMappings, $actorId);
    }

    public function prepareDecisions(
        int $sourceYearId,
        int $targetYearId,
        array $decisionOverrides,
        array $retainedStudentIds,
        ?int $actorId
    ): array {
        $this->assertSchemaReady();
        $this->assertYearPair($sourceYearId, $targetYearId);
        $allowed = ['promoted', 'retained', 'pending', 'graduated', 'transferred_out', 'withdrawn'];
        $allowedEnrollmentStatuses = ['enrolled', 'transferred', 'discontinued'];
        $allowedAcademicStatuses = ['auto', 'promoted', 'retained', 'graduated', 'pending'];
        $overrides = [];
        foreach ($decisionOverrides as $studentId => $decisionInput) {
            $studentId = (int) $studentId;
            if ($studentId <= 0) {
                continue;
            }
            if (is_array($decisionInput)) {
                $enrollmentStatus = trim((string) ($decisionInput['enrollment_status'] ?? 'enrolled'));
                $academicStatus = trim((string) ($decisionInput['academic_status'] ?? 'auto'));
                if (in_array($enrollmentStatus, $allowedEnrollmentStatuses, true)
                    && in_array($academicStatus, $allowedAcademicStatuses, true)) {
                    $overrides[$studentId] = [
                        'enrollment_status' => $enrollmentStatus,
                        'academic_status' => $academicStatus,
                    ];
                }
                continue;
            }
            $decision = trim((string) $decisionInput);
            if (in_array($decision, $allowed, true)) {
                $overrides[$studentId] = match ($decision) {
                    'retained' => ['enrollment_status' => 'enrolled', 'academic_status' => 'retained'],
                    'graduated' => ['enrollment_status' => 'enrolled', 'academic_status' => 'graduated'],
                    'transferred_out' => ['enrollment_status' => 'transferred', 'academic_status' => 'auto'],
                    'withdrawn' => ['enrollment_status' => 'discontinued', 'academic_status' => 'auto'],
                    'pending' => ['enrollment_status' => 'enrolled', 'academic_status' => 'pending'],
                    default => ['enrollment_status' => 'enrolled', 'academic_status' => 'promoted'],
                };
            }
        }
        foreach ($this->studentIdSet($retainedStudentIds) as $studentId => $_) {
            if (!isset($overrides[$studentId])) {
                $overrides[$studentId] = [
                    'enrollment_status' => 'enrolled',
                    'academic_status' => 'retained',
                ];
            }
        }

        $ownsTransaction = !$this->db->inTransaction();
        $batchId = UndoManager::newBatchId();
        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            $this->lockYears($sourceYearId, $targetYearId);
            $students = $this->eligibleStudents($sourceYearId);
            $rules = $this->promotionRules($sourceYearId, $targetYearId);
            $existingStmt = $this->db->prepare("SELECT * FROM student_promotion_decisions
                WHERE source_year_id = ? AND target_year_id = ? FOR UPDATE");
            $existingStmt->execute([$sourceYearId, $targetYearId]);
            $existingByEnrollment = [];
            foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $existingRow) {
                $existingByEnrollment[(int) $existingRow['source_enrollment_id']] = $existingRow;
            }
            $insert = $this->db->prepare("INSERT INTO student_promotion_decisions
                (source_year_id, target_year_id, source_enrollment_id, student_id, decision,
                 enrollment_status, academic_status, status,
                  target_grade_id, reason_code, decision_source, source_snapshot_hash, decided_by, approved_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $update = $this->db->prepare("UPDATE student_promotion_decisions
                SET student_id = ?, decision = ?, enrollment_status = ?, academic_status = ?,
                    status = ?, target_grade_id = ?, reason_code = ?,
                     note = NULL, decision_source = ?, source_snapshot_hash = ?, applied_run_id = NULL,
                     target_enrollment_id = NULL, applied_at = NULL, decided_by = ?, approved_by = ?
                WHERE id = ?");
            $seen = [];
            $counts = array_fill_keys(
                ['promoted', 'retained', 'pending', 'graduated', 'transferred_out', 'withdrawn', 'excluded_test'],
                0
            );
            foreach ($students as $student) {
                $studentId = (int) $student['student_id'];
                $sourceEnrollmentId = (int) $student['source_enrollment_id'];
                $seen[$sourceEnrollmentId] = true;
                $resolved = $this->resolveDecision($student, $rules, $overrides[$studentId] ?? null);
                $snapshot = $this->studentSnapshotHash($student);
                $status = $resolved['decision'] === 'pending' ? 'draft' : 'approved';
                $approvedBy = $status === 'approved' ? $actorId : null;
                $existing = $existingByEnrollment[$sourceEnrollmentId] ?? null;
                if ($existing && (string) $existing['status'] === 'applied') {
                    throw new RuntimeException('لا يمكن تعديل قرار طُبق في تشغيل سابق.');
                }
                if ($existing) {
                    $update->execute([
                        $studentId,
                        $resolved['decision'],
                        $resolved['enrollment_status'],
                        $resolved['academic_status'],
                        $status,
                        $resolved['target_grade_id'],
                        $resolved['reason_code'],
                        $resolved['decision_source'],
                        $snapshot,
                        $actorId,
                        $approvedBy,
                        (int) $existing['id'],
                    ]);
                } else {
                    $insert->execute([
                        $sourceYearId,
                        $targetYearId,
                        $sourceEnrollmentId,
                        $studentId,
                        $resolved['decision'],
                        $resolved['enrollment_status'],
                        $resolved['academic_status'],
                        $status,
                        $resolved['target_grade_id'],
                        $resolved['reason_code'],
                        $resolved['decision_source'],
                        $snapshot,
                        $actorId,
                        $approvedBy,
                    ]);
                }
                $counts[$resolved['decision']]++;
            }

            $cancel = $this->db->prepare("UPDATE student_promotion_decisions SET status = 'cancelled', approved_by = NULL WHERE id = ?");
            foreach ($existingByEnrollment as $row) {
                if ((string) $row['status'] !== 'applied' && !isset($seen[(int) $row['source_enrollment_id']])) {
                    $cancel->execute([(int) $row['id']]);
                }
            }
            (new AuditService($this->db))->recordEvent(
                'update',
                'student_promotion_decisions',
                $targetYearId,
                'قرارات ترحيل العام #' . $targetYearId,
                [
                    'summary' => 'إعداد قرارات ترحيل الطلاب قبل النسخة الاحتياطية',
                    'source_year_id' => $sourceYearId,
                    'target_year_id' => $targetYearId,
                    'counts' => $counts,
                    'decision_count' => array_sum($counts),
                    'direct_undo_available' => false,
                ],
                ['batch_id' => $batchId]
            );
            $report = $this->preflight($sourceYearId, $targetYearId);
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $report;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function preflight(int $sourceYearId, int $targetYearId, array $retainedStudentIds = []): array
    {
        $this->assertSchemaReady();
        $source = $this->year($sourceYearId);
        $target = $this->year($targetYearId);
        $blockers = [];
        $warnings = [];

        if ($sourceYearId === $targetYearId) {
            $blockers[] = ['code' => 'same_year', 'message' => 'يجب أن يختلف العام المصدر عن الهدف.'];
        }
        if ((int) ($target['is_active'] ?? 0) === 1) {
            $blockers[] = ['code' => 'target_active', 'message' => 'لا يمكن التهيئة على عام نشط.'];
        }
        $sourceStart = $this->validDate((string) ($source['start_date'] ?? ''));
        $sourceEnd = $this->validDate((string) ($source['end_date'] ?? ''));
        $targetStart = $this->validDate((string) ($target['start_date'] ?? ''));
        $targetEnd = $this->validDate((string) ($target['end_date'] ?? ''));
        if (!$sourceStart || !$sourceEnd || !$targetStart || !$targetEnd) {
            $blockers[] = ['code' => 'year_dates_missing', 'message' => 'تواريخ بداية ونهاية العامين مطلوبة قبل التهيئة.'];
        } elseif ($targetStart <= $sourceStart || $sourceStart > $sourceEnd || $targetStart > $targetEnd) {
            $blockers[] = ['code' => 'year_dates_invalid', 'message' => 'ترتيب أو نطاق تواريخ العامين غير صالح.'];
        }

        $targetCounts = [];
        foreach (array_merge(self::MANAGED_TARGET_TABLES, self::FORBIDDEN_TARGET_TABLES) as $table) {
            $count = $this->countRowsForYear($table, $targetYearId);
            $targetCounts[$table] = $count;
            if ($count > 0) {
                $blockers[] = [
                    'code' => 'target_not_empty',
                    'table' => $table,
                    'count' => $count,
                    'message' => 'العام الهدف يحتوي بيانات مسبقة في ' . $table . '.',
                ];
            }
        }

        $students = $this->eligibleStudents($sourceYearId);
        $rules = $this->promotionRules($sourceYearId, $targetYearId);
        $studentCounts = [
            'promoted' => 0,
            'retained' => 0,
            'graduating' => 0,
            'pending' => 0,
            'transferred_out' => 0,
            'withdrawn' => 0,
            'excluded_test' => 0,
            'students_skipped' => 0,
        ];
        foreach ($this->validatePromotionRuleCoverage($rules) as $ruleBlocker) {
            $blockers[] = $ruleBlocker;
        }
        $decisionStmt = $this->db->prepare("SELECT * FROM student_promotion_decisions
            WHERE source_year_id = ? AND target_year_id = ? AND status <> 'cancelled'");
        $decisionStmt->execute([$sourceYearId, $targetYearId]);
        $decisionsByEnrollment = [];
        foreach ($decisionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $decisionRow) {
            $decisionsByEnrollment[(int) $decisionRow['source_enrollment_id']] = $decisionRow;
        }
        foreach ($students as $student) {
            $gradeId = (int) ($student['grade_id'] ?? 0);
            $stageId = (int) ($student['stage_id'] ?? 0);
            $isTest = ExperimentalAcademicScopePolicy::studentExperimentalReason($student) !== null;
            $decision = $decisionsByEnrollment[(int) $student['source_enrollment_id']] ?? null;
            if (!$decision) {
                $studentCounts['students_skipped']++;
                $blockers[] = $this->studentBlocker(
                    $student,
                    'decision_missing',
                    'لا يوجد قرار ترحيل محفوظ لهذا الطالب. احفظ القرارات وأعد المعاينة.'
                );
                continue;
            }
            if (!hash_equals((string) $decision['source_snapshot_hash'], $this->studentSnapshotHash($student))) {
                $studentCounts['students_skipped']++;
                $blockers[] = $this->studentBlocker(
                    $student,
                    'decision_stale',
                    'تغير قيد الطالب بعد حفظ القرار؛ أعد إعداد القرارات.'
                );
                continue;
            }
            $decisionType = (string) $decision['decision'];
            if ($isTest && $decisionType !== 'excluded_test') {
                $studentCounts['students_skipped']++;
                $blockers[] = $this->studentBlocker($student, 'test_decision_mismatch', 'يجب استبعاد بيانات الاختبار صراحة.');
                continue;
            }
            if (!$isTest && ($gradeId <= 0 || $stageId <= 0)) {
                $studentCounts['students_skipped']++;
                $blockers[] = $this->studentBlocker($student, 'student_placement_missing', 'طالب حقيقي نشط بلا مرحلة أو صف.');
                continue;
            }
            if ((string) $decision['status'] !== 'approved') {
                $studentCounts['students_skipped']++;
                $studentCounts['pending']++;
                $blockers[] = $this->studentBlocker($student, 'decision_pending', 'قرار الطالب معلق أو غير معتمد.');
                continue;
            }
            if ($decisionType !== 'excluded_test'
                && (!in_array((string) ($decision['enrollment_status'] ?? ''), ['enrolled', 'transferred', 'discontinued'], true)
                    || !in_array((string) ($decision['academic_status'] ?? ''), ['new', 'promoted', 'retained', 'graduated'], true))) {
                $studentCounts['students_skipped']++;
                $blockers[] = $this->studentBlocker(
                    $student,
                    'annual_status_missing',
                    'يجب تحديد حالة القيد والحالة الدراسية لهذا الطالب.'
                );
                continue;
            }
            if ($decisionType === 'promoted') {
                $targetGradeId = (int) ($decision['target_grade_id'] ?? 0);
                if ($targetGradeId <= 0 || !$this->isOfficialGrade($targetGradeId)) {
                    $studentCounts['students_skipped']++;
                    $blockers[] = $this->studentBlocker($student, 'decision_target_invalid', 'الصف الهدف في قرار الطالب غير صالح.');
                    continue;
                }
                $studentCounts['promoted']++;
            } elseif ($decisionType === 'retained') {
                $studentCounts['retained']++;
            } elseif ($decisionType === 'graduated') {
                $studentCounts['graduating']++;
            } elseif (isset($studentCounts[$decisionType])) {
                $studentCounts[$decisionType]++;
            } else {
                $studentCounts['students_skipped']++;
                $blockers[] = $this->studentBlocker($student, 'decision_invalid', 'قرار ترحيل الطالب غير معروف.');
            }
        }

        $classPlan = $this->classPlans->validate(
            $sourceYearId,
            $targetYearId,
            $rules,
            $this->officialGrades(),
            $students,
            $decisionsByEnrollment
        );
        array_push($blockers, ...$classPlan['blockers']);
        array_push($warnings, ...$classPlan['warnings']);

        if ($studentCounts['students_skipped'] > 0) {
            $blockers[] = [
                'code' => 'students_skipped',
                'count' => $studentCounts['students_skipped'],
                'message' => 'لا يمكن تخطي أي طالب حقيقي؛ عالج القرارات والموانع قبل التنفيذ.',
            ];
        }

        $calendarCounts = [
            'terms' => $this->countRowsForYear('academic_terms', $sourceYearId),
            'months' => $this->countRowsForYear('academic_months', $sourceYearId),
            'weeks' => $this->countRowsForYear('academic_weeks', $sourceYearId),
        ];
        if ($calendarCounts['terms'] === 0 || $calendarCounts['weeks'] === 0) {
            $blockers[] = ['code' => 'calendar_missing', 'message' => 'تقويم العام المصدر غير مكتمل.'];
        }
        if ($calendarCounts['months'] === 0) {
            $warnings[] = ['code' => 'months_empty', 'message' => 'لا توجد شهور أكاديمية؛ ستبقى روابط الشهر في الأسابيع فارغة.'];
        }

        $summary = [
            'source_year_id' => $sourceYearId,
            'target_year_id' => $targetYearId,
            'eligible_students' => count($students),
            'students' => $studentCounts,
            'calendar' => $calendarCounts,
            'classes' => (int) $classPlan['enabled_count'],
            'source_classes' => $this->countRowsForYear('classes', $sourceYearId),
            'class_mappings' => $classPlan,
            'subject_assignments' => $this->countRowsForYear('subject_grade_assignments', $sourceYearId),
            'assessment_schemes' => $this->countRowsForYear('assessment_schemes', $sourceYearId),
            'target_counts' => $targetCounts,
            'blockers' => $blockers,
            'blocker_groups' => $this->groupBlockers($blockers),
            'warnings' => $warnings,
        ];
        $summary['decision_fingerprint'] = $this->decisionFingerprint($sourceYearId, $targetYearId);
        $summary['source_fingerprint'] = hash('sha256', $this->json([
            'source' => $source,
            'counts' => [
                'students' => $summary['eligible_students'],
                'calendar' => $calendarCounts,
                'classes' => $summary['classes'],
                'source_classes' => $summary['source_classes'],
                'subject_assignments' => $summary['subject_assignments'],
                'assessment_schemes' => $summary['assessment_schemes'],
            ],
            'student_counts' => $studentCounts,
            'decision_fingerprint' => $summary['decision_fingerprint'],
            'promotion_rule_fingerprint' => $this->promotionRuleFingerprint($sourceYearId, $targetYearId),
                'class_mapping_fingerprint' => $this->classPlans->fingerprint($sourceYearId, $targetYearId),
        ]));
        $summary['ready'] = $blockers === [];
        return $summary;
    }

    public function execute(
        int $sourceYearId,
        int $targetYearId,
        string $backupKey,
        array $retainedStudentIds,
        ?int $actorId
    ): array {
        $receipt = $this->recovery->assertUsableVerifiedReceipt($backupKey);
        $preflight = $this->preflight($sourceYearId, $targetYearId, $retainedStudentIds);
        if (!$preflight['ready']) {
            throw new RuntimeException('فشل فحص الجاهزية؛ لا يمكن تنفيذ التهيئة.');
        }

        $runKey = bin2hex(random_bytes(16));
        $batchId = UndoManager::newBatchId();
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            $this->lockYears($sourceYearId, $targetYearId);
            $inside = $this->preflight($sourceYearId, $targetYearId, $retainedStudentIds);
            if (!$inside['ready']
                || !hash_equals((string) $preflight['source_fingerprint'], (string) $inside['source_fingerprint'])
                || !hash_equals((string) $preflight['decision_fingerprint'], (string) $inside['decision_fingerprint'])) {
                throw new RuntimeException('تغيرت بيانات المصدر أثناء الفحص؛ أعد المعاينة والنسخة الاحتياطية.');
            }
            $existing = $this->db->prepare("SELECT COUNT(*) FROM academic_year_rollover_runs
                WHERE target_year_id = ? AND status NOT IN ('rolled_back','failed')");
            $existing->execute([$targetYearId]);
            if ((int) $existing->fetchColumn() > 0) {
                throw new RuntimeException('يوجد تشغيل سابق يملك العام الهدف.');
            }
            $insertRun = $this->db->prepare("INSERT INTO academic_year_rollover_runs
                (run_key, source_year_id, target_year_id, recovery_backup_id, status,
                 source_fingerprint, decision_fingerprint, preflight_summary, audit_batch_id, created_by)
                VALUES (?, ?, ?, ?, 'executing', ?, ?, ?, ?, ?)");
            $insertRun->execute([
                $runKey,
                $sourceYearId,
                $targetYearId,
                (int) $receipt['id'],
                $inside['source_fingerprint'],
                $inside['decision_fingerprint'],
                $this->json($this->compactSummary($inside)),
                $batchId,
                $actorId ?: null,
            ]);
            $runId = (int) $this->db->lastInsertId();

            $maps = [];
            $auditItems = [];
            $dayShift = $this->dateShiftDays($sourceYearId, $targetYearId);
            $maps['terms'] = $this->copyTerms($runId, $sourceYearId, $targetYearId, $dayShift, $auditItems);
            $maps['months'] = $this->copyMonths($runId, $sourceYearId, $targetYearId, $dayShift, $maps['terms'], $auditItems);
            $maps['weeks'] = $this->copyWeeks(
                $runId, $sourceYearId, $targetYearId, $dayShift, $maps['terms'], $maps['months'], $auditItems
            );
            $classMaps = $this->classPlans->copyClasses(
                $runId,
                $sourceYearId,
                $targetYearId,
                $auditItems
            );
            $maps['classes'] = $classMaps['all'];
            $enrollmentReport = $this->copyEnrollments(
                $runId, $sourceYearId, $targetYearId, $classMaps['cohort'], $auditItems
            );
            if (($enrollmentReport['students_skipped'] ?? 0) > 0) {
                throw new RuntimeException('لا يمكن تخطي أي طالب أثناء التهيئة.');
            }
            $maps['assignments'] = $this->copySubjectAssignments(
                $runId, $sourceYearId, $targetYearId, $maps['terms'], $classMaps['curriculum'], $auditItems
            );
            $maps['schemes'] = $this->copySchemes(
                $runId, $sourceYearId, $targetYearId, $maps['terms'], $maps['assignments'], $auditItems
            );
            $maps['components'] = $this->copyComponents($runId, $maps['schemes'], $auditItems);
            $weekRuleCount = $this->copyWeekRules($runId, $maps['components'], $maps['weeks'], $auditItems);

            $report = [
                'run_key' => $runKey,
                'terms_copied' => count($maps['terms']),
                'months_copied' => count($maps['months']),
                'weeks_copied' => count($maps['weeks']),
                'classes_copied' => count($maps['classes']),
                'students_promoted' => $enrollmentReport['promoted'],
                'students_retained' => $enrollmentReport['retained'],
                'students_graduating' => $enrollmentReport['graduating'],
                'students_transferred_out' => $enrollmentReport['transferred_out'],
                'students_withdrawn' => $enrollmentReport['withdrawn'],
                'students_excluded_test' => $enrollmentReport['excluded_test'],
                'students_auto_placed' => $enrollmentReport['auto_placed'],
                'students_unassigned_promoted' => $enrollmentReport['unassigned_promoted'],
                'decisions_applied' => $enrollmentReport['decisions_applied'],
                'students_skipped' => 0,
                'subject_assignments_copied' => count($maps['assignments']),
                'assessment_schemes_copied' => count($maps['schemes']),
                'assessment_components_copied' => count($maps['components']),
                'week_rules_copied' => $weekRuleCount,
            ];
            $this->db->prepare("UPDATE academic_year_rollover_runs
                SET status = 'completed', execution_summary = ?, executed_at = NOW() WHERE id = ?")
                ->execute([$this->json($report), $runId]);
            if ($auditItems) {
                (new AuditService($this->db))->recordEvent(
                    'create',
                    'academic_year_rollover',
                    $runId,
                    'تهيئة العام #' . $targetYearId,
                    [
                        'summary' => 'تهيئة عام دراسي وفق السياسة الثابتة',
                        'report' => $report,
                        'manifest_item_count' => count($auditItems),
                        'direct_undo_available' => false,
                        'rollback_owner' => 'academic_year_rollover_manifest',
                    ],
                    ['batch_id' => $batchId]
                );
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $report;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function verifyRun(string $runKey): array
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            $run = $this->run($runKey, true);
            if (!in_array((string) $run['status'], ['completed', 'verified'], true)) {
                throw new RuntimeException('تشغيل التهيئة غير جاهز للتحقق.');
            }
            $targetYearId = (int) $run['target_year_id'];
            $summary = json_decode((string) ($run['execution_summary'] ?? ''), true) ?: [];
            $expected = [
                'academic_terms' => (int) ($summary['terms_copied'] ?? -1),
                'academic_months' => (int) ($summary['months_copied'] ?? -1),
                'academic_weeks' => (int) ($summary['weeks_copied'] ?? -1),
                'classes' => (int) ($summary['classes_copied'] ?? -1),
                'student_enrollments' => (int) (($summary['students_promoted'] ?? 0)
                    + ($summary['students_retained'] ?? 0)),
                'subject_grade_assignments' => (int) ($summary['subject_assignments_copied'] ?? -1),
                'assessment_schemes' => (int) ($summary['assessment_schemes_copied'] ?? -1),
                'assessment_components' => (int) ($summary['assessment_components_copied'] ?? -1),
                'assessment_component_week_rules' => (int) ($summary['week_rules_copied'] ?? -1),
            ];
            $actual = [];
            $checks = [];
            $checks['owned_items_exist'] = $this->ownedItemsExist((int) $run['id']);
            $checks['managed_counts_match'] = true;
            foreach ($expected as $table => $count) {
                $actual[$table] = $this->countRowsForYear($table, $targetYearId);
                if ($count < 0 || $actual[$table] !== $count) {
                    $checks['managed_counts_match'] = false;
                }
            }
            $manifestStmt = $this->db->prepare("SELECT entity_table, COUNT(*) AS item_count
                FROM academic_year_rollover_items WHERE run_id = ? GROUP BY entity_table");
            $manifestStmt->execute([(int) $run['id']]);
            $manifestCounts = [];
            foreach ($manifestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $manifestCounts[(string) $row['entity_table']] = (int) $row['item_count'];
            }
            $checks['manifest_counts_match'] = true;
            foreach ($expected as $table => $count) {
                if (($manifestCounts[$table] ?? 0) !== $count) {
                    $checks['manifest_counts_match'] = false;
                }
            }
            $checks['no_enrollment_orphans'] = $this->countEnrollmentOrphans($targetYearId) === 0;
            $decisionCountStmt = $this->db->prepare("SELECT COUNT(*) FROM student_promotion_decisions
                WHERE applied_run_id = ? AND status = 'applied'");
            $decisionCountStmt->execute([(int) $run['id']]);
            $checks['all_decisions_applied'] = (int) $decisionCountStmt->fetchColumn()
                === (int) ($summary['decisions_applied'] ?? -1);
            $decisionLinkStmt = $this->db->prepare("SELECT COUNT(*) FROM student_promotion_decisions d
                JOIN student_enrollments src ON src.id = d.source_enrollment_id
                LEFT JOIN student_enrollments se ON se.id = d.target_enrollment_id
                LEFT JOIN classes tc ON tc.id = se.class_id
                LEFT JOIN class_rollover_mappings cm
                  ON cm.source_year_id = d.source_year_id
                 AND cm.target_year_id = d.target_year_id
                 AND cm.source_class_id = src.class_id
                 AND cm.mapping_type = 'cohort'
                 AND cm.status = 'active'
                 AND cm.is_enabled = 1
                 AND cm.auto_place_students = 1
                LEFT JOIN academic_year_rollover_items ri
                  ON ri.run_id = d.applied_run_id
                 AND ri.entity_table = 'classes'
                 AND ri.source_record_id = CONCAT('class_mapping:', cm.id)
                WHERE d.applied_run_id = ? AND (
                    (d.decision IN ('promoted','retained') AND (
                        d.target_enrollment_id IS NULL OR se.id IS NULL
                        OR se.source_enrollment_id <> d.source_enrollment_id
                        OR se.promotion_decision_id <> d.id
                    ))
                    OR (d.decision = 'retained' AND se.class_id IS NOT NULL)
                    OR (d.decision = 'promoted' AND (
                        (cm.id IS NULL AND se.class_id IS NOT NULL)
                        OR (cm.id IS NOT NULL AND (
                            se.class_id IS NULL
                            OR ri.target_record_id IS NULL
                            OR CAST(ri.target_record_id AS UNSIGNED) <> se.class_id
                        ))
                        OR (se.class_id IS NOT NULL AND (
                            tc.academic_year_id <> d.target_year_id
                            OR tc.grade_id <> d.target_grade_id
                        ))
                    ))
                    OR (d.decision NOT IN ('promoted','retained') AND d.target_enrollment_id IS NOT NULL)
                )");
            $decisionLinkStmt->execute([(int) $run['id']]);
            $checks['decision_links_and_class_placement'] = (int) $decisionLinkStmt->fetchColumn() === 0;
            $checks['draft_policy_preserved'] = $this->countDraftPolicyViolations($targetYearId) === 0;
            $checks['historical_target_empty'] = true;
            $historicalCounts = [];
            foreach (self::FORBIDDEN_TARGET_TABLES as $table) {
                $historicalCounts[$table] = $this->countRowsForYear($table, $targetYearId);
                if ($historicalCounts[$table] > 0) {
                    $checks['historical_target_empty'] = false;
                }
            }
            $passed = !in_array(false, $checks, true);
            $verification = [
                'passed' => $passed,
                'checks' => $checks,
                'expected_counts' => $expected,
                'actual_counts' => $actual,
                'manifest_counts' => $manifestCounts,
                'historical_counts' => $historicalCounts,
                'verified_at' => gmdate('c'),
            ];
            $this->db->prepare("UPDATE academic_year_rollover_runs
                SET status = ?, verification_summary = ?, verified_at = ? WHERE id = ?")
                ->execute([
                    $passed ? 'verified' : 'completed',
                    $this->json($verification),
                    $passed ? date('Y-m-d H:i:s') : null,
                    (int) $run['id'],
                ]);
            (new AuditService($this->db))->recordEvent(
                $passed ? 'verify' : 'failure',
                'academic_year_rollover',
                (int) $run['id'],
                $runKey,
                ['summary' => $passed ? 'نجاح تحقق تهيئة العام' : 'فشل تحقق تهيئة العام', 'checks' => $checks]
            );
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $verification;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function rollback(string $runKey, ?int $actorId): array
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            $run = $this->run($runKey, true);
            if (!in_array((string) $run['status'], ['completed', 'verified'], true)) {
                throw new RuntimeException('لا يمكن الرجوع عن تشغيل في حالته الحالية.');
            }
            $target = $this->year((int) $run['target_year_id'], true);
            if ((int) $target['is_active'] === 1) {
                throw new RuntimeException('لا يمكن الرجوع بعد تفعيل العام الهدف.');
            }
            foreach (self::FORBIDDEN_TARGET_TABLES as $table) {
                if ($this->countRowsForYear($table, (int) $run['target_year_id']) > 0) {
                    throw new RuntimeException('ظهرت معاملات تشغيلية في العام الهدف؛ الرجوع التلقائي محظور.');
                }
            }

            $items = $this->db->prepare("SELECT * FROM academic_year_rollover_items
                WHERE run_id = ? AND action = 'insert' ORDER BY dependency_order DESC, id DESC FOR UPDATE");
            $items->execute([(int) $run['id']]);
            $deleted = 0;
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
                $table = (string) $item['entity_table'];
                if (!isset(self::DEPENDENCY_ORDER[$table])) {
                    throw new RuntimeException('يتضمن manifest جدولاً غير مسموح للرجوع.');
                }
                $id = (int) $item['target_record_id'];
                $stmt = $this->db->prepare('DELETE FROM ' . $this->quoteIdentifier($table) . ' WHERE id = ?');
                $stmt->execute([$id]);
                $deleted += $stmt->rowCount();
            }
            $this->db->prepare("UPDATE student_promotion_decisions
                SET status = 'approved', applied_run_id = NULL, target_enrollment_id = NULL,
                    applied_at = NULL
                WHERE applied_run_id = ?")
                ->execute([(int) $run['id']]);
            $this->db->prepare("UPDATE academic_year_rollover_runs
                SET status = 'rolled_back', rolled_back_at = NOW() WHERE id = ?")
                ->execute([(int) $run['id']]);
            (new AuditService($this->db))->recordEvent(
                'rollback',
                'academic_year_rollover',
                (int) $run['id'],
                $runKey,
                [
                    'summary' => 'رجوع موجّه عن تهيئة عام قبل التفعيل',
                    'deleted_manifest_rows' => $deleted,
                    'actor_id' => $actorId,
                    'direct_undo_available' => false,
                ]
            );
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return ['run_key' => $runKey, 'deleted_manifest_rows' => $deleted];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function activate(string $runKey, ?int $actorId): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            $run = $this->run($runKey, true);
            if ((string) $run['status'] !== 'verified') {
                throw new RuntimeException('لا يمكن تفعيل عام لم يجتز التحقق المستقل.');
            }
            $sourceYearId = (int) $run['source_year_id'];
            $targetYearId = (int) $run['target_year_id'];
            $this->lockYears($sourceYearId, $targetYearId);
            $this->applyTerminalAnnualStatuses($sourceYearId, $targetYearId, $actorId);
            $this->db->prepare("UPDATE classes SET status = 'active' WHERE academic_year_id = ?")->execute([$targetYearId]);
            $this->db->prepare("UPDATE academic_terms SET status = 'active' WHERE academic_year_id = ?")->execute([$targetYearId]);
            if ($this->tableExists('academic_months')) {
                $this->db->prepare("UPDATE academic_months SET status = 'active' WHERE academic_year_id = ?")->execute([$targetYearId]);
            }
            AcademicYear::setActive($this->db, $targetYearId);
            AcademicYear::lock($this->db, $sourceYearId);
            $this->db->prepare("UPDATE academic_year_rollover_runs
                SET status = 'activated', activated_at = NOW() WHERE id = ?")
                ->execute([(int) $run['id']]);
            (new AuditService($this->db))->recordEvent(
                'activate',
                'academic_year_rollover',
                (int) $run['id'],
                $runKey,
                [
                    'summary' => 'تفعيل العام الهدف وقفل العام المصدر',
                    'source_year_id' => $sourceYearId,
                    'target_year_id' => $targetYearId,
                    'actor_id' => $actorId,
                ]
            );
            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function applyTerminalAnnualStatuses(
        int $sourceYearId,
        int $targetYearId,
        ?int $actorId
    ): void {
        $sourceYear = $this->year($sourceYearId);
        $stmt = $this->db->prepare(
            "SELECT se.*, d.decision, d.enrollment_status AS decision_enrollment_status,
                    d.academic_status AS decision_academic_status
             FROM student_promotion_decisions d
             JOIN student_enrollments se ON se.id = d.source_enrollment_id
             WHERE d.source_year_id = ? AND d.target_year_id = ?
               AND d.status = 'applied' AND d.target_enrollment_id IS NULL
               AND d.decision IN ('graduated','transferred_out','withdrawn')
             ORDER BY se.id
             FOR UPDATE"
        );
        $stmt->execute([$sourceYearId, $targetYearId]);
        $audit = new AuditService($this->db);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $studentId = (int) $row['student_id'];
            $decision = (string) $row['decision'];
            $enrollmentStatus = (string) ($row['decision_enrollment_status'] ?? '');
            if (!in_array($enrollmentStatus, ['enrolled', 'transferred', 'discontinued'], true)) {
                $enrollmentStatus = match ($decision) {
                    'transferred_out' => 'transferred',
                    'withdrawn' => 'discontinued',
                    default => 'enrolled',
                };
            }
            $academicStatus = (string) ($row['decision_academic_status'] ?? '');
            if (!in_array($academicStatus, ['new', 'promoted', 'retained', 'graduated'], true)) {
                $academicStatus = $decision === 'graduated'
                    ? 'graduated'
                    : (string) ($row['academic_status'] ?? 'new');
            }
            if ($decision === 'graduated') {
                $academicStatus = 'graduated';
            }

            $beforeEnrollment = $row;
            unset(
                $beforeEnrollment['decision'],
                $beforeEnrollment['decision_enrollment_status'],
                $beforeEnrollment['decision_academic_status']
            );
            $this->db->prepare(
                'UPDATE student_enrollments
                 SET enrollment_status = ?, academic_status = ?,
                     graduation_year = CASE WHEN ? = \'graduated\' THEN ? ELSE graduation_year END
                 WHERE id = ?'
            )->execute([
                $enrollmentStatus,
                $academicStatus,
                $academicStatus,
                (string) ($sourceYear['name'] ?? ''),
                (int) $row['id'],
            ]);
            $afterEnrollment = $this->rowById('student_enrollments', (int) $row['id']);
            $audit->recordUpdate(
                'student_enrollment',
                'student_enrollments',
                (int) $row['id'],
                'قيد طالب #' . $studentId,
                $beforeEnrollment,
                $afterEnrollment,
                'اعتماد الحالة السنوية النهائية للطالب'
            );

            $profileBeforeStmt = $this->db->prepare('SELECT * FROM student_profiles WHERE user_id = ? FOR UPDATE');
            $profileBeforeStmt->execute([$studentId]);
            $profileBefore = $profileBeforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($profileBefore) {
                $profileStatus = $academicStatus === 'graduated' ? 'graduated' : $enrollmentStatus;
                $this->db->prepare('UPDATE student_profiles SET enrollment_status = ? WHERE user_id = ?')
                    ->execute([$profileStatus, $studentId]);
                $profileAfterStmt = $this->db->prepare('SELECT * FROM student_profiles WHERE user_id = ?');
                $profileAfterStmt->execute([$studentId]);
                $profileAfter = $profileAfterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $audit->recordUpdate(
                    'student_profile',
                    'student_profiles',
                    (int) $profileBefore['id'],
                    'ملف طالب #' . $studentId,
                    $profileBefore,
                    $profileAfter,
                    'مزامنة ملخص الحالة السنوية'
                );
            }

            $userBeforeStmt = $this->db->prepare(
                "SELECT * FROM users WHERE id = ? AND role = 'student' FOR UPDATE"
            );
            $userBeforeStmt->execute([$studentId]);
            $userBefore = $userBeforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($userBefore) {
                $accountStatus = $academicStatus === 'graduated' ? 'graduated' : 'inactive';
                $this->db->prepare('UPDATE users SET status = ? WHERE id = ?')
                    ->execute([$accountStatus, $studentId]);
                $userAfter = $this->rowById('users', $studentId);
                $audit->recordUpdate(
                    'student',
                    'users',
                    $studentId,
                    (string) ($userAfter['name'] ?? ('طالب #' . $studentId)),
                    $userBefore,
                    $userAfter,
                    'مزامنة حالة حساب الطالب مع قيده السنوي'
                );
            }
        }
    }

    private function copyTerms(int $runId, int $sourceYearId, int $targetYearId, int $shift, array &$audit): array
    {
        $rows = $this->rows('academic_terms', 'academic_year_id = ?', [$sourceYearId], 'term_order, id');
        $insert = $this->db->prepare("INSERT INTO academic_terms
            (academic_year_id, name, term_order, start_date, end_date, status)
            VALUES (?, ?, ?, ?, ?, 'inactive')");
        $map = [];
        foreach ($rows as $row) {
            $insert->execute([
                $targetYearId, $row['name'], $row['term_order'],
                $this->shiftDate($row['start_date'] ?? null, $shift),
                $this->shiftDate($row['end_date'] ?? null, $shift),
            ]);
            $map[(int) $row['id']] = (int) $this->db->lastInsertId();
            $this->recordOwnedInsert($runId, 'academic_terms', (int) $row['id'], $map[(int) $row['id']], $audit);
        }
        return $map;
    }

    private function copyMonths(
        int $runId, int $sourceYearId, int $targetYearId, int $shift, array $termMap, array &$audit
    ): array {
        if (!$this->tableExists('academic_months')) {
            return [];
        }
        $rows = $this->rows('academic_months', 'academic_year_id = ?', [$sourceYearId], 'term_id, month_order, id');
        $insert = $this->db->prepare("INSERT INTO academic_months
            (academic_year_id, term_id, name, month_order, start_date, end_date, month_type,
             status, notes, copied_from_month_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'inactive', ?, ?, NULL)");
        $map = [];
        foreach ($rows as $row) {
            $termId = $termMap[(int) $row['term_id']] ?? 0;
            if ($termId <= 0) {
                throw new RuntimeException('تعذر إعادة ربط شهر بالترم الجديد.');
            }
            $insert->execute([
                $targetYearId, $termId, $row['name'], $row['month_order'],
                $this->shiftDate($row['start_date'] ?? null, $shift),
                $this->shiftDate($row['end_date'] ?? null, $shift),
                $row['month_type'], $row['notes'], $row['id'],
            ]);
            $map[(int) $row['id']] = (int) $this->db->lastInsertId();
            $this->recordOwnedInsert($runId, 'academic_months', (int) $row['id'], $map[(int) $row['id']], $audit);
        }
        return $map;
    }

    private function copyWeeks(
        int $runId, int $sourceYearId, int $targetYearId, int $shift,
        array $termMap, array $monthMap, array &$audit
    ): array {
        $rows = $this->rows('academic_weeks', 'academic_year_id = ?', [$sourceYearId], 'term_id, week_order, id');
        $insert = $this->db->prepare("INSERT INTO academic_weeks
            (academic_year_id, term_id, month_id, month_label, name, week_order,
             start_date, end_date, week_type, counts_for_average, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $map = [];
        foreach ($rows as $row) {
            $termId = $termMap[(int) $row['term_id']] ?? 0;
            if ($termId <= 0) {
                throw new RuntimeException('تعذر إعادة ربط أسبوع بالترم الجديد.');
            }
            $monthId = !empty($row['month_id']) ? ($monthMap[(int) $row['month_id']] ?? null) : null;
            $insert->execute([
                $targetYearId, $termId, $monthId, $row['month_label'], $row['name'], $row['week_order'],
                $this->shiftDate($row['start_date'], $shift), $this->shiftDate($row['end_date'], $shift),
                $row['week_type'], $row['counts_for_average'], $row['notes'],
            ]);
            $map[(int) $row['id']] = (int) $this->db->lastInsertId();
            $this->recordOwnedInsert($runId, 'academic_weeks', (int) $row['id'], $map[(int) $row['id']], $audit);
        }
        return $map;
    }

    private function copyEnrollments(
        int $runId,
        int $sourceYearId,
        int $targetYearId,
        array $cohortClassMap,
        array &$audit
    ): array {
        $decisions = $this->db->prepare("SELECT d.*, se.stage_id AS source_stage_id,
                se.grade_id AS source_grade_id, se.class_id AS source_class_id,
                se.repeat_count AS source_repeat_count
            FROM student_promotion_decisions d
            JOIN student_enrollments se ON se.id = d.source_enrollment_id
            WHERE d.source_year_id = ? AND d.target_year_id = ? AND d.status = 'approved'
            ORDER BY d.student_id FOR UPDATE");
        $decisions->execute([$sourceYearId, $targetYearId]);
        $insert = $this->db->prepare("INSERT INTO student_enrollments
            (student_id, academic_year_id, source_enrollment_id, promotion_decision_id,
             stage_id, grade_id, class_id, is_repeater, repeat_count,
             enrollment_status, academic_status, graduation_year, enrollment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, CURDATE())");
        $applyWithEnrollment = $this->db->prepare("UPDATE student_promotion_decisions
            SET status = 'applied', applied_run_id = ?, target_enrollment_id = ?, applied_at = NOW() WHERE id = ?");
        $applyWithoutEnrollment = $this->db->prepare("UPDATE student_promotion_decisions
            SET status = 'applied', applied_run_id = ?, target_enrollment_id = NULL, applied_at = NOW() WHERE id = ?");
        $report = [
            'promoted' => 0,
            'retained' => 0,
            'graduating' => 0,
            'transferred_out' => 0,
            'withdrawn' => 0,
            'excluded_test' => 0,
            'auto_placed' => 0,
            'unassigned_promoted' => 0,
            'decisions_applied' => 0,
            'students_skipped' => 0,
        ];
        foreach ($decisions->fetchAll(PDO::FETCH_ASSOC) ?: [] as $decision) {
            $decisionType = (string) $decision['decision'];
            $decisionId = (int) $decision['id'];
            $enrollmentStatus = (string) ($decision['enrollment_status'] ?? '');
            $academicStatus = (string) ($decision['academic_status'] ?? '');
            if ($enrollmentStatus === '' && in_array($decisionType, ['promoted', 'retained'], true)) {
                $enrollmentStatus = 'enrolled';
            }
            if ($academicStatus === '' && in_array($decisionType, ['promoted', 'retained'], true)) {
                $academicStatus = $decisionType === 'retained' ? 'retained' : 'promoted';
            }
            if ($enrollmentStatus === 'enrolled'
                && in_array($academicStatus, ['promoted', 'retained'], true)) {
                $targetGradeId = $academicStatus === 'retained'
                    ? (int) $decision['source_grade_id']
                    : (int) $decision['target_grade_id'];
                $stageId = $academicStatus === 'retained'
                    ? (int) $decision['source_stage_id']
                    : (int) ($this->stageForGrade($targetGradeId) ?? 0);
                if ($targetGradeId <= 0 || $stageId <= 0) {
                    $report['students_skipped']++;
                    continue;
                }
                $sourceRepeatCount = (int) ($decision['source_repeat_count'] ?? 0);
                $isRepeater = $academicStatus === 'retained' ? 1 : 0;
                $repeatCount = $academicStatus === 'retained' ? $sourceRepeatCount + 1 : $sourceRepeatCount;
                $targetClassId = null;
                if ($academicStatus === 'promoted') {
                    $sourceClassId = (int) ($decision['source_class_id'] ?? 0);
                    $targetClassId = $sourceClassId > 0 && isset($cohortClassMap[$sourceClassId])
                        ? (int) $cohortClassMap[$sourceClassId]
                        : null;
                }
                $insert->execute([
                    (int) $decision['student_id'],
                    $targetYearId,
                    (int) $decision['source_enrollment_id'],
                    $decisionId,
                    $stageId,
                    $targetGradeId,
                    $targetClassId,
                    $isRepeater,
                    $repeatCount,
                    'enrolled',
                    $academicStatus,
                ]);
                $newId = (int) $this->db->lastInsertId();
                $this->recordOwnedInsert(
                    $runId,
                    'student_enrollments',
                    (int) $decision['source_enrollment_id'],
                    $newId,
                    $audit
                );
                $applyWithEnrollment->execute([$runId, $newId, $decisionId]);
                $report[$academicStatus === 'promoted' ? 'promoted' : 'retained']++;
                if ($academicStatus === 'promoted') {
                    $report[$targetClassId === null ? 'unassigned_promoted' : 'auto_placed']++;
                }
            } else {
                $applyWithoutEnrollment->execute([$runId, $decisionId]);
                if ($decisionType === 'graduated') {
                    $report['graduating']++;
                } elseif (isset($report[$decisionType])) {
                    $report[$decisionType]++;
                } else {
                    $report['students_skipped']++;
                    continue;
                }
            }
            $report['decisions_applied']++;
        }
        return $report;
    }

    private function copySubjectAssignments(
        int $runId, int $sourceYearId, int $targetYearId, array $termMap, array $classMap, array &$audit
    ): array {
        $rows = $this->rows('subject_grade_assignments', 'academic_year_id = ?', [$sourceYearId], 'id');
        $insert = $this->db->prepare("INSERT INTO subject_grade_assignments
            (academic_year_id, term_id, subject_id, stage_id, grade_id, class_id,
             is_active, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, NULL)");
        $map = [];
        foreach ($rows as $row) {
            $termId = !empty($row['term_id']) ? ($termMap[(int) $row['term_id']] ?? null) : null;
            $classId = !empty($row['class_id']) ? ($classMap[(int) $row['class_id']] ?? null) : null;
            if (!empty($row['term_id']) && !$termId) {
                throw new RuntimeException('تعذر إعادة ربط إسناد مادة بالترم.');
            }
            if (!empty($row['class_id']) && !$classId) {
                throw new RuntimeException('تعذر إعادة ربط إسناد مادة بالفصل.');
            }
            $insert->execute([
                $targetYearId, $termId, $row['subject_id'], $row['stage_id'], $row['grade_id'],
                $classId, $row['notes'],
            ]);
            $map[(int) $row['id']] = (int) $this->db->lastInsertId();
            $this->recordOwnedInsert($runId, 'subject_grade_assignments', (int) $row['id'], $map[(int) $row['id']], $audit);
        }
        return $map;
    }

    private function copySchemes(
        int $runId, int $sourceYearId, int $targetYearId, array $termMap, array $assignmentMap, array &$audit
    ): array {
        $rows = $this->rows('assessment_schemes', 'academic_year_id = ?', [$sourceYearId], 'id');
        $insert = $this->db->prepare("INSERT INTO assessment_schemes
            (academic_year_id, term_id, subject_assignment_id, subject_id, stage_id, grade_id,
             name, total_grade, pass_grade, counts_in_total, enable_excused_absence,
             normal_absence_policy, excused_absence_policy, rounding_enabled, rounding_mode,
             rounding_scope, annual_result_enabled, first_term_weight, second_term_weight,
             status, copied_from_scheme_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NULL)");
        $map = [];
        foreach ($rows as $row) {
            $termId = $termMap[(int) $row['term_id']] ?? 0;
            if ($termId <= 0) {
                throw new RuntimeException('تعذر إعادة ربط مخطط تقييم بالترم.');
            }
            $assignmentId = !empty($row['subject_assignment_id'])
                ? ($assignmentMap[(int) $row['subject_assignment_id']] ?? null)
                : null;
            $insert->execute([
                $targetYearId, $termId, $assignmentId, $row['subject_id'], $row['stage_id'], $row['grade_id'],
                $row['name'], $row['total_grade'], $row['pass_grade'], $row['counts_in_total'],
                $row['enable_excused_absence'], $row['normal_absence_policy'], $row['excused_absence_policy'],
                $row['rounding_enabled'], $row['rounding_mode'], $row['rounding_scope'],
                $row['annual_result_enabled'], $row['first_term_weight'], $row['second_term_weight'], $row['id'],
            ]);
            $map[(int) $row['id']] = (int) $this->db->lastInsertId();
            $this->recordOwnedInsert($runId, 'assessment_schemes', (int) $row['id'], $map[(int) $row['id']], $audit);
        }
        return $map;
    }

    private function copyComponents(int $runId, array $schemeMap, array &$audit): array
    {
        if (!$schemeMap) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($schemeMap), '?'));
        $rows = $this->db->prepare("SELECT * FROM assessment_components WHERE scheme_id IN ($placeholders) ORDER BY id");
        $rows->execute(array_keys($schemeMap));
        $pending = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $insert = $this->db->prepare("INSERT INTO assessment_components
            (scheme_id, parent_component_id, name, component_type, max_grade, is_weekly,
             repeat_per_week, counts_in_average, counts_in_total, visible_to_student,
             accepts_absence, accepts_excused_absence, sort_order, calculation_mode, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
        $map = [];
        while ($pending) {
            $progress = false;
            foreach ($pending as $index => $row) {
                $oldParent = !empty($row['parent_component_id']) ? (int) $row['parent_component_id'] : null;
                if ($oldParent !== null && !isset($map[$oldParent])) {
                    continue;
                }
                $insert->execute([
                    $schemeMap[(int) $row['scheme_id']], $oldParent === null ? null : $map[$oldParent],
                    $row['name'], $row['component_type'], $row['max_grade'], $row['is_weekly'],
                    $row['repeat_per_week'], $row['counts_in_average'], $row['counts_in_total'],
                    $row['visible_to_student'], $row['accepts_absence'], $row['accepts_excused_absence'],
                    $row['sort_order'], $row['calculation_mode'],
                ]);
                $map[(int) $row['id']] = (int) $this->db->lastInsertId();
                $this->recordOwnedInsert($runId, 'assessment_components', (int) $row['id'], $map[(int) $row['id']], $audit);
                unset($pending[$index]);
                $progress = true;
            }
            if (!$progress) {
                throw new RuntimeException('تعذر ترتيب مكونات التقييم التابعة.');
            }
        }
        return $map;
    }

    private function copyWeekRules(int $runId, array $componentMap, array $weekMap, array &$audit): int
    {
        if (!$componentMap) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($componentMap), '?'));
        $rows = $this->db->prepare("SELECT * FROM assessment_component_week_rules
            WHERE component_id IN ($placeholders) ORDER BY id");
        $rows->execute(array_keys($componentMap));
        $insert = $this->db->prepare("INSERT INTO assessment_component_week_rules
            (component_id, week_id, is_included, max_grade_override) VALUES (?, ?, ?, ?)");
        $count = 0;
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $componentId = $componentMap[(int) $row['component_id']] ?? 0;
            $weekId = $weekMap[(int) $row['week_id']] ?? 0;
            if ($componentId <= 0 || $weekId <= 0) {
                throw new RuntimeException('تعذر إعادة ربط قاعدة أسبوعية.');
            }
            $insert->execute([$componentId, $weekId, $row['is_included'], $row['max_grade_override']]);
            $newId = (int) $this->db->lastInsertId();
            $this->recordOwnedInsert($runId, 'assessment_component_week_rules', (int) $row['id'], $newId, $audit);
            $count++;
        }
        return $count;
    }

    private function recordOwnedInsert(
        int $runId, string $table, int|string|null $sourceId, int $targetId, array &$audit
    ): void {
        $this->db->prepare("INSERT INTO academic_year_rollover_items
            (run_id, entity_table, source_record_id, target_record_id, dependency_order, action)
            VALUES (?, ?, ?, ?, ?, 'insert')")
            ->execute([$runId, $table, $sourceId, $targetId, self::DEPENDENCY_ORDER[$table]]);
        $row = $this->rowById($table, $targetId);
        if (!$row) {
            throw new RuntimeException('تعذر إعادة تحميل سجل أنشأته التهيئة.');
        }
        $audit[] = ['table' => $table, 'record_id' => $targetId];
    }

    private function countRowsForYear(string $table, int $yearId): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        if ($table === 'assessment_components') {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM assessment_components c
                JOIN assessment_schemes s ON s.id = c.scheme_id WHERE s.academic_year_id = ?");
        } elseif ($table === 'assessment_component_week_rules') {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM assessment_component_week_rules r
                JOIN assessment_components c ON c.id = r.component_id
                JOIN assessment_schemes s ON s.id = c.scheme_id WHERE s.academic_year_id = ?");
        } elseif ($table === 'assessment_windows') {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM assessment_windows w
                JOIN assessment_schemes s ON s.id = w.scheme_id WHERE s.academic_year_id = ?");
        } elseif (in_array($table, ['student_fees', 'student_other_discounts'], true)
            && $this->columnExists($table, 'academic_year')) {
            $year = $this->year($yearId);
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table)
                . ' WHERE academic_year_id = ? OR (academic_year_id IS NULL AND academic_year = ?)');
            $stmt->execute([$yearId, (string) $year['name']]);
            return (int) $stmt->fetchColumn();
        } elseif ($table === 'fee_payments') {
            $year = $this->year($yearId);
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM fee_payments fp
                LEFT JOIN student_fees sf ON sf.id = fp.student_fee_id
                WHERE fp.academic_year_id = ?
                   OR (fp.academic_year_id IS NULL
                       AND (sf.academic_year_id = ? OR (sf.academic_year_id IS NULL AND sf.academic_year = ?)))");
            $stmt->execute([$yearId, $yearId, (string) $year['name']]);
            return (int) $stmt->fetchColumn();
        } elseif (!$this->columnExists($table, 'academic_year_id')) {
            return 0;
        } else {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE academic_year_id = ?');
        }
        $stmt->execute([$yearId]);
        return (int) $stmt->fetchColumn();
    }

    private function eligibleStudents(int $yearId): array
    {
        $stmt = $this->db->prepare("SELECT se.id AS source_enrollment_id, se.student_id,
                se.stage_id, se.grade_id, se.class_id, se.enrollment_status, se.academic_status,
                se.repeat_count, se.updated_at AS enrollment_updated_at,
                       sp.student_code, COALESCE(u.is_test_account, 0) AS is_test_account,
                COALESCE(s.is_experimental, 0) AS stage_is_experimental,
                COALESCE(g.is_experimental, 0) AS grade_is_experimental,
                COALESCE(c.is_experimental, 0) AS class_is_experimental,
                g.grade_name, s.stage_name
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN grades g ON g.id = se.grade_id
            LEFT JOIN stages s ON s.id = se.stage_id
            LEFT JOIN classes c ON c.id = se.class_id
            WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
              AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            ORDER BY se.student_id");
        $stmt->execute([$yearId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function studentBlocker(array $student, string $code, string $message): array
    {
        return [
            'code' => $code,
            'student_id' => (int) $student['student_id'],
            'student_code' => (string) ($student['student_code'] ?? ''),
            'grade_id' => isset($student['grade_id']) ? (int) $student['grade_id'] : null,
            'grade_name' => (string) ($student['grade_name'] ?? ''),
            'message' => $message,
        ];
    }

    private function assertYearPair(int $sourceYearId, int $targetYearId): void
    {
        if ($sourceYearId <= 0 || $targetYearId <= 0 || $sourceYearId === $targetYearId) {
            throw new InvalidArgumentException('يرجى اختيار عام مصدر وهدف صحيحين ومختلفين.');
        }
        $this->year($sourceYearId);
        $target = $this->year($targetYearId);
        if ((int) ($target['is_active'] ?? 0) === 1) {
            throw new InvalidArgumentException('لا يمكن إعداد قواعد ترحيل على عام هدف نشط.');
        }
    }

    private function officialGrades(): array
    {
        return $this->db->query("SELECT g.id, g.grade_name, g.stage_id, g.grade_order,
                s.stage_name, s.stage_order
            FROM grades g
            JOIN stages s ON s.id = g.stage_id
            WHERE g.status = 'active' AND s.status = 'active'
              AND g.is_experimental = 0 AND s.is_experimental = 0
            ORDER BY s.stage_order, g.grade_order, g.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function promotionRules(int $sourceYearId, int $targetYearId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM grade_promotion_rules
            WHERE source_year_id = ? AND target_year_id = ? AND status = 'active'
            ORDER BY source_grade_id");
        $stmt->execute([$sourceYearId, $targetYearId]);
        $rules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rules[(int) $row['source_grade_id']] = $row;
        }
        return $rules;
    }

    private function validatePromotionRuleCoverage(array $rules): array
    {
        $blockers = [];
        $normalized = [];
        foreach ($this->officialGrades() as $grade) {
            $gradeId = (int) $grade['id'];
            $rule = $rules[$gradeId] ?? null;
            if (!$rule) {
                $blockers[] = [
                    'code' => 'promotion_rule_missing',
                    'grade_id' => $gradeId,
                    'grade_name' => (string) $grade['grade_name'],
                    'message' => 'لا توجد قاعدة انتقال محفوظة للصف.',
                ];
                continue;
            }
            $type = (string) $rule['rule_type'];
            $targetGradeId = isset($rule['target_grade_id']) ? (int) $rule['target_grade_id'] : null;
            $normalized[$gradeId] = ['rule_type' => $type, 'target_grade_id' => $targetGradeId];
            if ($type === 'graduate' && $targetGradeId !== null) {
                $blockers[] = [
                    'code' => 'promotion_rule_invalid',
                    'grade_id' => $gradeId,
                    'grade_name' => (string) $grade['grade_name'],
                    'message' => 'قاعدة التخرج لا يجوز أن تحتوي صفًا هدفًا.',
                ];
            } elseif ($type === 'promote'
                && ($targetGradeId === null || $targetGradeId === $gradeId || !$this->isOfficialGrade($targetGradeId))) {
                $blockers[] = [
                    'code' => 'promotion_rule_invalid',
                    'grade_id' => $gradeId,
                    'grade_name' => (string) $grade['grade_name'],
                    'message' => 'قاعدة الانتقال تشير إلى صف هدف غير صالح أو تجريبي.',
                ];
            }
        }
        try {
            $this->assertPromotionRulesAcyclic($normalized);
        } catch (InvalidArgumentException $e) {
            $blockers[] = ['code' => 'promotion_rule_cycle', 'message' => $e->getMessage()];
        }
        return $blockers;
    }

    private function assertPromotionRulesAcyclic(array $rules): void
    {
        $visiting = [];
        $visited = [];
        $visit = function (int $gradeId) use (&$visit, &$visiting, &$visited, $rules): void {
            if (isset($visited[$gradeId])) {
                return;
            }
            if (isset($visiting[$gradeId])) {
                throw new InvalidArgumentException('قواعد انتقال الصفوف تحتوي دورة مغلقة غير صالحة.');
            }
            $visiting[$gradeId] = true;
            $rule = $rules[$gradeId] ?? null;
            if ($rule && (string) $rule['rule_type'] === 'promote') {
                $targetGradeId = (int) ($rule['target_grade_id'] ?? 0);
                if ($targetGradeId > 0 && isset($rules[$targetGradeId])) {
                    $visit($targetGradeId);
                }
            }
            unset($visiting[$gradeId]);
            $visited[$gradeId] = true;
        };
        foreach (array_keys($rules) as $gradeId) {
            $visit((int) $gradeId);
        }
    }

    private function isOfficialGrade(int $gradeId): bool
    {
        if ($gradeId <= 0) {
            return false;
        }
        if ($this->officialGradeIdSet === null) {
            $this->officialGradeIdSet = [];
            foreach ($this->officialGrades() as $grade) {
                $this->officialGradeIdSet[(int) $grade['id']] = true;
            }
        }
        return isset($this->officialGradeIdSet[$gradeId]);
    }

    private function resolveDecision(array $student, array $rules, ?array $override): array
    {
        $gradeId = (int) ($student['grade_id'] ?? 0);
        $stageId = (int) ($student['stage_id'] ?? 0);
        $currentAcademicStatus = in_array(
            (string) ($student['academic_status'] ?? ''),
            ['new', 'promoted', 'retained', 'graduated'],
            true
        ) ? (string) $student['academic_status'] : 'new';
        $reasonCode = ExperimentalAcademicScopePolicy::studentExperimentalReason($student);
        if ($reasonCode !== null) {
            return [
                'decision' => 'excluded_test',
                'enrollment_status' => null,
                'academic_status' => null,
                'target_grade_id' => null,
                'reason_code' => $reasonCode,
                'decision_source' => 'system',
            ];
        }
        if ($gradeId <= 0 || $stageId <= 0) {
            return [
                'decision' => 'pending',
                'enrollment_status' => 'enrolled',
                'academic_status' => null,
                'target_grade_id' => null,
                'reason_code' => 'placement_missing',
                'decision_source' => 'system',
            ];
        }
        if ($override !== null) {
            $enrollmentStatus = (string) ($override['enrollment_status'] ?? 'enrolled');
            $academicStatus = (string) ($override['academic_status'] ?? 'auto');
            if ($academicStatus === 'pending') {
                return [
                    'decision' => 'pending',
                    'enrollment_status' => $enrollmentStatus,
                    'academic_status' => null,
                    'target_grade_id' => null,
                    'reason_code' => 'manual_pending',
                    'decision_source' => 'manual',
                ];
            }
            if ($enrollmentStatus !== 'enrolled') {
                $resolvedAcademicStatus = $academicStatus === 'auto'
                    ? $currentAcademicStatus
                    : $academicStatus;
                return [
                    'decision' => $enrollmentStatus === 'transferred' ? 'transferred_out' : 'withdrawn',
                    'enrollment_status' => $enrollmentStatus,
                    'academic_status' => $resolvedAcademicStatus,
                    'target_grade_id' => null,
                    'reason_code' => 'manual_' . $enrollmentStatus,
                    'decision_source' => 'manual',
                ];
            }
            if (in_array($academicStatus, ['retained', 'graduated'], true)) {
                return [
                    'decision' => $academicStatus,
                    'enrollment_status' => 'enrolled',
                    'academic_status' => $academicStatus,
                    'target_grade_id' => $academicStatus === 'retained' ? $gradeId : null,
                    'reason_code' => 'manual_' . $academicStatus,
                    'decision_source' => 'manual',
                ];
            }
        }
        $manualPromotion = $override !== null
            && (string) ($override['academic_status'] ?? '') === 'promoted';
        $rule = $rules[$gradeId] ?? null;
        if (!$rule) {
            return [
                'decision' => 'pending',
                'enrollment_status' => 'enrolled',
                'academic_status' => null,
                'target_grade_id' => null,
                'reason_code' => 'promotion_rule_missing',
                'decision_source' => $manualPromotion ? 'manual' : 'system',
            ];
        }
        if ((string) $rule['rule_type'] === 'graduate') {
            return [
                'decision' => 'graduated',
                'enrollment_status' => 'enrolled',
                'academic_status' => 'graduated',
                'target_grade_id' => null,
                'reason_code' => 'graduation_rule',
                'decision_source' => $manualPromotion ? 'manual' : 'rule',
            ];
        }
        $targetGradeId = (int) ($rule['target_grade_id'] ?? 0);
        if (!$this->isOfficialGrade($targetGradeId)) {
            return [
                'decision' => 'pending',
                'enrollment_status' => 'enrolled',
                'academic_status' => null,
                'target_grade_id' => null,
                'reason_code' => 'promotion_target_invalid',
                'decision_source' => 'system',
            ];
        }
        return [
            'decision' => 'promoted',
            'enrollment_status' => 'enrolled',
            'academic_status' => 'promoted',
            'target_grade_id' => $targetGradeId,
            'reason_code' => 'promotion_rule',
            'decision_source' => $manualPromotion ? 'manual' : 'rule',
        ];
    }

    private function studentSnapshotHash(array $student): string
    {
        return hash('sha256', $this->json([
            'source_enrollment_id' => (int) ($student['source_enrollment_id'] ?? 0),
            'student_id' => (int) ($student['student_id'] ?? 0),
            'stage_id' => isset($student['stage_id']) ? (int) $student['stage_id'] : null,
            'grade_id' => isset($student['grade_id']) ? (int) $student['grade_id'] : null,
            'class_id' => isset($student['class_id']) ? (int) $student['class_id'] : null,
            'enrollment_status' => (string) ($student['enrollment_status'] ?? ''),
            'academic_status' => (string) ($student['academic_status'] ?? ''),
            'repeat_count' => (int) ($student['repeat_count'] ?? 0),
            'enrollment_updated_at' => (string) ($student['enrollment_updated_at'] ?? ''),
            'is_test_account' => (int) ($student['is_test_account'] ?? 0),
            'experimental_reason' => ExperimentalAcademicScopePolicy::studentExperimentalReason($student),
        ]));
    }

    private function decisionFingerprint(int $sourceYearId, int $targetYearId): string
    {
        $stmt = $this->db->prepare("SELECT source_enrollment_id, student_id, decision,
                enrollment_status, academic_status, status, target_grade_id,
                reason_code, decision_source, source_snapshot_hash
            FROM student_promotion_decisions
            WHERE source_year_id = ? AND target_year_id = ? AND status <> 'cancelled'
            ORDER BY source_enrollment_id");
        $stmt->execute([$sourceYearId, $targetYearId]);
        return hash('sha256', $this->json($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    private function promotionRuleFingerprint(int $sourceYearId, int $targetYearId): string
    {
        $stmt = $this->db->prepare("SELECT source_grade_id, rule_type, target_grade_id, status
            FROM grade_promotion_rules WHERE source_year_id = ? AND target_year_id = ? ORDER BY source_grade_id");
        $stmt->execute([$sourceYearId, $targetYearId]);
        return hash('sha256', $this->json($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    private function groupBlockers(array $blockers): array
    {
        $groups = [];
        foreach ($blockers as $blocker) {
            $key = (string) ($blocker['code'] ?? 'unknown') . '|'
                . (string) ($blocker['grade_id'] ?? '') . '|'
                . (string) ($blocker['table'] ?? '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'code' => (string) ($blocker['code'] ?? 'unknown'),
                    'message' => (string) ($blocker['message'] ?? 'مانع غير معروف'),
                    'grade_id' => $blocker['grade_id'] ?? null,
                    'grade_name' => (string) ($blocker['grade_name'] ?? ''),
                    'table' => (string) ($blocker['table'] ?? ''),
                    'count' => 0,
                    'samples' => [],
                ];
            }
            $groups[$key]['count'] += max(1, (int) ($blocker['count'] ?? 1));
            if (isset($blocker['student_id']) && count($groups[$key]['samples']) < 10) {
                $groups[$key]['samples'][] = [
                    'student_id' => (int) $blocker['student_id'],
                    'student_code' => (string) ($blocker['student_code'] ?? ''),
                ];
            }
        }
        return array_values($groups);
    }

    private function stageForGrade(int $gradeId): ?int
    {
        $stmt = $this->db->prepare('SELECT stage_id FROM grades WHERE id = ? LIMIT 1');
        $stmt->execute([$gradeId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    private function dateShiftDays(int $sourceYearId, int $targetYearId): int
    {
        $source = new DateTimeImmutable((string) $this->year($sourceYearId)['start_date']);
        $target = new DateTimeImmutable((string) $this->year($targetYearId)['start_date']);
        return (int) $source->diff($target)->format('%r%a');
    }

    private function shiftDate($date, int $days): ?string
    {
        if ($date === null || trim((string) $date) === '') {
            return null;
        }
        return (new DateTimeImmutable((string) $date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private function validDate(string $date): ?DateTimeImmutable
    {
        if ($date === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($date);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function ownedItemsExist(int $runId): bool
    {
        $stmt = $this->db->prepare('SELECT entity_table, target_record_id FROM academic_year_rollover_items WHERE run_id = ?');
        $stmt->execute([$runId]);
        $seen = false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
            $seen = true;
            $table = (string) $item['entity_table'];
            if (!isset(self::DEPENDENCY_ORDER[$table]) || !$this->rowById($table, (int) $item['target_record_id'])) {
                return false;
            }
        }
        return $seen;
    }

    private function countEnrollmentOrphans(int $yearId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM student_enrollments se
            LEFT JOIN users u ON u.id = se.student_id
            LEFT JOIN academic_years y ON y.id = se.academic_year_id
            LEFT JOIN classes c ON c.id = se.class_id
            WHERE se.academic_year_id = ?
              AND (u.id IS NULL OR y.id IS NULL OR (se.class_id IS NOT NULL AND c.id IS NULL))");
        $stmt->execute([$yearId]);
        return (int) $stmt->fetchColumn();
    }

    private function countDraftPolicyViolations(int $yearId): int
    {
        $checks = [
            "SELECT COUNT(*) FROM academic_terms WHERE academic_year_id = ? AND status <> 'inactive'",
            "SELECT COUNT(*) FROM academic_months WHERE academic_year_id = ? AND status <> 'inactive'",
            "SELECT COUNT(*) FROM classes WHERE academic_year_id = ? AND status <> 'inactive'",
            'SELECT COUNT(*) FROM subject_grade_assignments WHERE academic_year_id = ? AND is_active <> 0',
            "SELECT COUNT(*) FROM assessment_schemes WHERE academic_year_id = ? AND status <> 'draft'",
            'SELECT COUNT(*) FROM assessment_components c JOIN assessment_schemes s ON s.id = c.scheme_id'
                . ' WHERE s.academic_year_id = ? AND c.is_active <> 0',
        ];
        $violations = 0;
        foreach ($checks as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$yearId]);
            $violations += (int) $stmt->fetchColumn();
        }
        return $violations;
    }

    private function rows(string $table, string $where, array $params, string $order): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . $where . ' ORDER BY ' . $order);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function rowById(string $table, int $id): array
    {
        if (!isset(self::DEPENDENCY_ORDER[$table]) && $table !== 'grade_promotion_rules') {
            return [];
        }
        $stmt = $this->db->prepare('SELECT * FROM ' . $this->quoteIdentifier($table) . ' WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function year(int $id, bool $forUpdate = false): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('معرف العام الدراسي غير صالح.');
        }
        $stmt = $this->db->prepare('SELECT * FROM academic_years WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('العام الدراسي غير موجود.');
        }
        return $row;
    }

    private function run(string $runKey, bool $forUpdate): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $runKey)) {
            throw new InvalidArgumentException('معرف تشغيل التهيئة غير صالح.');
        }
        $stmt = $this->db->prepare('SELECT * FROM academic_year_rollover_runs WHERE run_key = ? LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$runKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('تشغيل التهيئة غير موجود.');
        }
        return $row;
    }

    private function lockYears(int $sourceYearId, int $targetYearId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM academic_years WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
        $stmt->execute([$sourceYearId, $targetYearId]);
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== 2) {
            throw new RuntimeException('تعذر قفل العامين المصدر والهدف.');
        }
    }

    private function studentIdSet(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $set[$id] = true;
            }
        }
        return $set;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }

    private function assertSchemaReady(): void
    {
        foreach ([
            'recovery_backups',
            'academic_year_rollover_runs',
            'academic_year_rollover_items',
            'grade_promotion_rules',
            'class_rollover_mappings',
            'student_promotion_decisions',
        ] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('مخطط التهيئة الآمنة غير جاهز. شغّل migration المطلوب أولاً.');
            }
        }
        foreach ([
            ['grades', 'is_experimental'],
            ['stages', 'is_experimental'],
            ['classes', 'is_experimental'],
            ['users', 'is_test_account'],
            ['classes', 'capacity'],
            ['student_enrollments', 'source_enrollment_id'],
            ['student_enrollments', 'promotion_decision_id'],
            ['student_enrollments', 'is_repeater'],
            ['student_enrollments', 'repeat_count'],
            ['student_enrollments', 'academic_status'],
            ['student_promotion_decisions', 'enrollment_status'],
            ['student_promotion_decisions', 'academic_status'],
            ['academic_year_rollover_runs', 'decision_fingerprint'],
        ] as $requiredColumn) {
            if (!$this->columnExists($requiredColumn[0], $requiredColumn[1])) {
                throw new RuntimeException('مخطط قرارات الترحيل غير مكتمل. شغّل migration المطلوب أولاً.');
            }
        }
    }

    private function compactSummary(array $summary): array
    {
        unset($summary['blockers']);
        return $summary;
    }

    private function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('تعذر ترميز تقرير التهيئة.');
        }
        return $json;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!isset(self::DEPENDENCY_ORDER[$identifier])
            && !in_array($identifier, self::FORBIDDEN_TARGET_TABLES, true)
            && $identifier !== 'grade_promotion_rules') {
            throw new InvalidArgumentException('اسم جدول غير مسموح.');
        }
        return chr(96) . $identifier . chr(96);
    }
}
