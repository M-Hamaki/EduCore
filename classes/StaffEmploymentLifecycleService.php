<?php

require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';

final class StaffEmploymentLifecycleService
{
    private const JOB_TITLE_CANONICAL_MAP = [
        'مدير مرحلة' => 'معلم',
        'منسق إداري' => 'معلم',
        'رئيس قسم' => 'معلم',
        'مدرس أول' => 'معلم',
        'منسق قسم' => 'معلم',
        'مسؤول المكتبة' => 'أمين مكتبة',
        'أخصائي اجتماعي' => 'أخصائي',
        'أخصائي نفسي' => 'أخصائي',
        'مشرف حسابات' => 'محاسب',
        'مدير حسابات' => 'محاسب',
        'قسم الإعلام' => null,
    ];

    private const SUMMARY_FIELDS = [
        'job_title',
        'job_grade',
        'department',
        'contract_type',
        'contract_start',
        'contract_end',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function jobTitleOptions(): array
    {
        return [
            'مدير إداري', 'مدير مالي', 'منسق عام إدارة', 'مسؤول إداري', 'موظف إداري',
            'معلم', 'إداري', 'أخصائي', 'مسؤول العلاقات العامة',
            'أمين مكتبة', 'أمين معمل', 'محاسب', 'سائق', 'مشرف', 'عامل', 'أخرى',
        ];
    }

    public static function canonicalJobTitle($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return array_key_exists($value, self::JOB_TITLE_CANONICAL_MAP)
            ? self::JOB_TITLE_CANONICAL_MAP[$value]
            : $value;
    }

    public static function jobTitleFilterValues($value): array
    {
        $canonical = self::canonicalJobTitle($value);
        if ($canonical === null) {
            return [];
        }

        $values = [$canonical];
        foreach (self::JOB_TITLE_CANONICAL_MAP as $legacy => $mapped) {
            if ($mapped === $canonical) {
                $values[] = $legacy;
            }
        }

        return array_values(array_unique($values));
    }

    /** @param iterable<mixed> $values @return array<int,string> */
    public static function canonicalJobTitleOptionsFromValues(iterable $values): array
    {
        $options = [];
        foreach ($values as $value) {
            $canonical = self::canonicalJobTitle($value);
            if ($canonical !== null) {
                $options[$canonical] = true;
            }
        }

        $options = array_keys($options);
        sort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    public static function departmentOptions(): array
    {
        return ['رياض أطفال', 'ابتدائي', 'إعدادي', 'ثانوي', 'إداري', 'إشراف', 'خدمات معاونة'];
    }

    /**
     * Compatibility bridge for profiles created before normalized employment history.
     * Only missing values on the effective current event are filled; explicit history
     * wins except for reviewed job-title aliases that are canonicalized everywhere.
     */
    public function hydrateMissingCurrentSummary(array $events, array $profileData): array
    {
        if (!$events) {
            $statusAfter = ($profileData['current_work_status'] ?? 'on_duty') === 'off_duty'
                ? 'off_duty'
                : 'on_duty';
            $events[] = [
                'movement_type' => 'تعيين',
                'status_after' => $statusAfter,
                'status_label' => $statusAfter === 'off_duty' ? 'ليس على رأس العمل' : 'على رأس العمل',
                'status_reason' => $this->cleanValue($profileData['current_status_reason'] ?? null)
                    ?? 'تسجيل أولي من ملخص الملف الوظيفي',
                'effective_date' => $this->cleanDate($profileData['current_status_effective_date'] ?? null)
                    ?? $this->cleanDate($profileData['hire_date'] ?? null)
                    ?? $this->cleanDate($profileData['contract_start'] ?? null),
            ];
        }

        $currentIndex = $this->currentEventIndex($events);
        if ($currentIndex === null) {
            return $events;
        }

        foreach ($events as &$event) {
            $event['job_title'] = self::canonicalJobTitle($event['job_title'] ?? null);
        }
        unset($event);

        foreach (self::SUMMARY_FIELDS as $field) {
            if ($this->cleanValue($events[$currentIndex][$field] ?? null) !== null) {
                continue;
            }

            $summaryValue = match ($field) {
                'contract_type' => $this->canonicalContractType($profileData[$field] ?? null),
                'job_title' => self::canonicalJobTitle($profileData[$field] ?? null),
                default => $this->cleanValue($profileData[$field] ?? null),
            };
            if ($summaryValue !== null) {
                $events[$currentIndex][$field] = $summaryValue;
            }
        }

        return $events;
    }

    public function normalizeStatusHistory(array $post, array $profileData): array
    {
        $rows = $this->jsonArray($post['status_history'] ?? '[]');
        $events = [];
        foreach ($rows as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $movementType = $this->cleanValue($item['movement_type'] ?? null);
            if ($movementType === 'أخرى') {
                $movementType = $this->cleanValue($item['movement_type_custom'] ?? null) ?? 'أخرى';
            }
            $explicitStatus = $this->cleanValue($item['status_after'] ?? null);
            $statusLabel = $this->cleanValue($item['status_label'] ?? ($item['status'] ?? null));
            if ($statusLabel === 'أخرى' && $this->cleanValue($item['status_custom'] ?? null) !== null) {
                $statusLabel = $this->cleanValue($item['status_custom']);
            }
            if ($statusLabel === null) {
                $statusLabel = $explicitStatus === 'off_duty' ? 'ليس على رأس العمل' : 'على رأس العمل';
            }
            if ($movementType === null) {
                $movementType = $statusLabel === 'على رأس العمل' ? ($idx === 0 ? 'تعيين' : 'عودة للعمل') : $statusLabel;
            }
            $event = [
                'movement_type' => $movementType,
                'status_after' => in_array($explicitStatus, ['on_duty', 'off_duty'], true)
                    ? $explicitStatus : $this->statusAfter($statusLabel, $movementType),
                'status_label' => $statusLabel,
                'status_reason' => $this->cleanValue($item['status_reason'] ?? ($item['reason'] ?? null)),
                'effective_date' => $this->cleanDate($item['effective_date'] ?? null) ?? $this->cleanDate($item['decision_date'] ?? null),
                'decision_date' => $this->cleanDate($item['decision_date'] ?? null),
                'decision_no' => $this->cleanValue($item['decision_no'] ?? null),
                'issuer' => $this->cleanValue($item['issuer'] ?? null),
                'contract_type' => $this->contractTypeFromItem($item),
                'contract_start' => $this->cleanDate($item['contract_start'] ?? null),
                'contract_end' => $this->cleanDate($item['contract_end'] ?? null),
                'job_title' => $this->jobTitleFromItem($item),
                'job_grade' => $this->cleanValue($item['job_grade'] ?? null),
                'department' => ($item['department'] ?? null) === 'أخرى'
                    ? $this->cleanValue($item['department_custom'] ?? null) : $this->cleanValue($item['department'] ?? null),
                'last_working_day' => $this->cleanDate($item['last_working_day'] ?? null),
                'can_rehire' => $this->nullableBool($item['can_rehire'] ?? ($item['rehire'] ?? '')),
                'notes' => $this->cleanValue($item['notes'] ?? null),
            ];
            $meaningful = array_filter($event, static fn($value, $key) => $key !== 'status_after' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);
            if ($meaningful) {
                $events[] = $event;
            }
        }
        if (!$events) {
            $events[] = [
                'movement_type' => 'تعيين', 'status_after' => 'on_duty', 'status_label' => 'على رأس العمل',
                'status_reason' => 'تسجيل موظف جديد',
                'effective_date' => $this->cleanDate($profileData['hire_date'] ?? null)
                    ?? $this->cleanDate($profileData['contract_start'] ?? null) ?? date('Y-m-d'),
                'decision_date' => null, 'decision_no' => null, 'issuer' => null,
                'contract_type' => $this->canonicalContractType($profileData['contract_type'] ?? null),
                'contract_start' => $this->cleanDate($profileData['contract_start'] ?? null),
                'contract_end' => $this->cleanDate($profileData['contract_end'] ?? null),
                'job_title' => self::canonicalJobTitle($profileData['job_title'] ?? null),
                'job_grade' => $this->cleanValue($profileData['job_grade'] ?? null),
                'department' => $this->cleanValue($profileData['department'] ?? null),
                'last_working_day' => null, 'can_rehire' => null, 'notes' => null,
            ];
        }
        usort($events, static fn($a, $b) => strcmp($a['effective_date'] ?? '9999-12-31', $b['effective_date'] ?? '9999-12-31'));
        return $events;
    }

    public function applyStatusSummary(array &$profileData, array $events): void
    {
        $current = $this->currentEvent($events);
        if (!$current) {
            return;
        }
        $firstHireDate = null;
        foreach ($events as $event) {
            if (($event['status_after'] ?? '') === 'on_duty' && !empty($event['effective_date'])) {
                $firstHireDate ??= $event['effective_date'];
            }
        }
        foreach (self::SUMMARY_FIELDS as $field) {
            if (!empty($current[$field])) {
                $profileData[$field] = $current[$field];
            }
        }
        if ($firstHireDate) {
            $profileData['hire_date'] = $profileData['hire_date'] ?? $firstHireDate;
        }
    }

    public function syncStatusHistory(
        int $userId,
        array $events,
        ?int $actorId,
        ?string $batchId = null
    ): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $oldStmt = $this->db->prepare('SELECT * FROM staff_status_history WHERE user_id = ? FOR UPDATE');
            $oldStmt->execute([$userId]);
            $oldRows = $oldStmt->fetchAll(PDO::FETCH_ASSOC);
            $this->db->prepare('DELETE FROM staff_status_history WHERE user_id = ?')->execute([$userId]);
            $stmt = $this->db->prepare("INSERT INTO staff_status_history
                (user_id, movement_type, status_after, status_label, status_reason, effective_date, decision_date, decision_no, issuer, contract_type, contract_start, contract_end, job_title, job_grade, department, last_working_day, can_rehire, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $newRows = [];
            foreach ($events as $event) {
                $stmt->execute([$userId, $event['movement_type'], $event['status_after'], $event['status_label'],
                    $event['status_reason'], $event['effective_date'], $event['decision_date'], $event['decision_no'],
                    $event['issuer'], $event['contract_type'], $event['contract_start'], $event['contract_end'],
                    $event['job_title'], $event['job_grade'], $event['department'], $event['last_working_day'],
                    $event['can_rehire'], $event['notes'], $actorId]);
                $newRows[] = $this->fetchById('staff_status_history', (int)$this->db->lastInsertId());
            }
            $current = $this->currentEvent($events);
            if ($current) {
                $firstHire = null;
                $latestHire = null;
                foreach ($events as $event) {
                    if (($event['status_after'] ?? '') === 'on_duty') {
                        $firstHire ??= $event['effective_date'];
                        $latestHire = $event['effective_date'] ?? $latestHire;
                    }
                }
                $stmt = $this->db->prepare("UPDATE staff_profiles SET current_work_status = ?, current_status_reason = ?,
                    current_status_effective_date = ?, first_hire_date = ?, latest_hire_date = ?, last_working_day = ?,
                    can_rehire = ?, job_title = COALESCE(?, job_title), job_grade = COALESCE(?, job_grade),
                    department = COALESCE(?, department), contract_type = COALESCE(?, contract_type),
                    contract_start = COALESCE(?, contract_start), contract_end = COALESCE(?, contract_end) WHERE user_id = ?");
                $stmt->execute([$current['status_after'], $current['status_reason'] ?? $current['status_label'],
                    $current['effective_date'], $firstHire, $latestHire,
                    $current['status_after'] === 'off_duty' ? $current['last_working_day'] : null,
                    $current['can_rehire'], $current['job_title'], $current['job_grade'], $current['department'],
                    $current['contract_type'], $current['contract_start'], $current['contract_end'], $userId]);
            }
            $this->auditReplacement(
                'staff_status_history',
                'staff_status_history',
                $userId,
                $oldRows,
                $newRows,
                'استبدال سجل الحالة الوظيفية',
                $batchId
            );
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function normalizeJobMovements(array $post): array
    {
        $movements = [];
        foreach ($this->jsonArray($post['promotions'] ?? '[]') as $item) {
            if (!is_array($item)) continue;
            $type = $this->cleanValue($item['type'] ?? ($item['movement_type'] ?? null));
            if ($type === 'أخرى') $type = $this->cleanValue($item['type_custom'] ?? null) ?? 'أخرى';
            $movement = [
                'movement_type' => $type,
                'previous_job_title' => self::canonicalJobTitle($item['previous_job_title'] ?? null),
                'new_job_title' => self::canonicalJobTitle($item['new_job_title'] ?? ($item['new_title'] ?? null)),
                'previous_job_grade' => $this->cleanValue($item['previous_job_grade'] ?? null),
                'new_job_grade' => $this->cleanValue($item['new_job_grade'] ?? null),
                'previous_department' => $this->cleanValue($item['previous_department'] ?? null),
                'new_department' => $this->cleanValue($item['new_department'] ?? null),
                'previous_contract_type' => $this->canonicalContractType($item['previous_contract_type'] ?? null),
                'new_contract_type' => $this->canonicalContractType($item['new_contract_type'] ?? null),
                'decision_date' => $this->cleanDate($item['decision_date'] ?? null),
                'effective_date' => $this->cleanDate($item['effective_date'] ?? null),
                'decision_no' => $this->cleanValue($item['decision_no'] ?? null),
                'issuer' => $this->cleanValue($item['issuer'] ?? null),
                'reason' => $this->cleanValue($item['reason'] ?? null),
                'notes' => $this->cleanValue($item['notes'] ?? null),
            ];
            if (array_filter($movement, static fn($value) => $value !== null && $value !== '')) $movements[] = $movement;
        }
        usort($movements, static fn($a, $b) => strcmp($a['effective_date'] ?? $a['decision_date'] ?? '9999-12-31', $b['effective_date'] ?? $b['decision_date'] ?? '9999-12-31'));
        return $movements;
    }

    public function syncJobMovements(
        int $userId,
        array $movements,
        ?int $actorId,
        ?string $batchId = null
    ): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $oldStmt = $this->db->prepare('SELECT * FROM staff_job_movements WHERE user_id = ? FOR UPDATE');
            $oldStmt->execute([$userId]);
            $oldRows = $oldStmt->fetchAll(PDO::FETCH_ASSOC);
            $this->db->prepare('DELETE FROM staff_job_movements WHERE user_id = ?')->execute([$userId]);
            $newRows = [];
            $stmt = $this->db->prepare("INSERT INTO staff_job_movements
            (user_id, movement_type, previous_job_title, new_job_title, previous_job_grade, new_job_grade,
             previous_department, new_department, previous_contract_type, new_contract_type, decision_date,
             effective_date, decision_no, issuer, reason, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($movements as $m) {
                $stmt->execute([$userId, $m['movement_type'] ?? 'حركة وظيفية', $m['previous_job_title'], $m['new_job_title'],
                $m['previous_job_grade'], $m['new_job_grade'], $m['previous_department'], $m['new_department'],
                $m['previous_contract_type'], $m['new_contract_type'], $m['decision_date'], $m['effective_date'],
                $m['decision_no'], $m['issuer'], $m['reason'], $m['notes'], $actorId]);
                $newRows[] = $this->fetchById('staff_job_movements', (int)$this->db->lastInsertId());
            }
            $latest = null;
            foreach ($movements as $movement) {
                $date = $movement['effective_date'] ?? $movement['decision_date'] ?? null;
                if ($date === null || $date <= date('Y-m-d')) $latest = $movement;
            }
            if ($latest) {
                $updates = [];
                $params = [];
                foreach (['new_job_title' => 'job_title', 'new_job_grade' => 'job_grade', 'new_department' => 'department', 'new_contract_type' => 'contract_type'] as $source => $column) {
                    if (!empty($latest[$source])) { $updates[] = "`{$column}` = ?"; $params[] = $latest[$source]; }
                }
                $date = $latest['effective_date'] ?? $latest['decision_date'] ?? null;
                if ($date) { $updates[] = 'last_job_movement_date = ?'; $params[] = $date; }
                if ($updates) { $params[] = $userId; $this->db->prepare('UPDATE staff_profiles SET ' . implode(', ', $updates) . ' WHERE user_id = ?')->execute($params); }
            }
            $this->auditReplacement(
                'staff_job_movement',
                'staff_job_movements',
                $userId,
                $oldRows,
                $newRows,
                'استبدال سجل الحركات الوظيفية',
                $batchId
            );
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function fetchById(string $table, int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function auditReplacement(
        string $entityType,
        string $table,
        int $userId,
        array $oldRows,
        array $newRows,
        string $description,
        ?string $batchId = null
    ): void
    {
        $deleted = array_map(static fn(array $row): array => [
            'table' => $table, 'record_id' => $row['id'], 'snapshot' => $row, 'description' => $description,
        ], $oldRows);
        $inserted = array_map(static fn(array $row): array => [
            'table' => $table, 'record_id' => $row['id'], 'snapshot' => $row, 'description' => $description,
        ], array_values(array_filter($newRows)));
        (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordReplacement(
            $entityType,
            $userId,
            'موظف #' . $userId,
            $deleted,
            $inserted,
            ['summary' => $description],
            $batchId
        );
    }

    private function currentEvent(array $events): ?array
    {
        $index = $this->currentEventIndex($events);
        return $index === null ? null : $events[$index];
    }

    private function currentEventIndex(array $events): ?int
    {
        if (!$events) {
            return null;
        }

        $currentIndex = null;
        foreach ($events as $index => $event) {
            if (($event['effective_date'] ?? null) === null || $event['effective_date'] <= date('Y-m-d')) {
                $currentIndex = $index;
            }
        }

        return $currentIndex ?? array_key_last($events);
    }

    private function contractTypeFromItem(array $item): ?string
    {
        $value = $this->cleanValue($item['contract_type'] ?? null);
        if (in_array($value, ['أخرى', 'other'], true)) {
            return $this->cleanValue($item['contract_type_custom'] ?? null) ?? 'other';
        }

        return $this->canonicalContractType($value);
    }

    private function jobTitleFromItem(array $item): ?string
    {
        $value = $this->cleanValue($item['job_title'] ?? null);
        if ($value === 'أخرى') {
            $value = $this->cleanValue($item['job_title_custom'] ?? null);
        }

        return self::canonicalJobTitle($value);
    }

    private function canonicalContractType($value): ?string
    {
        $value = $this->cleanValue($value);
        return match ($value) {
            'دائم' => 'permanent',
            'مؤقت' => 'temporary',
            'جزئي' => 'parttime',
            'أخرى' => 'other',
            default => $value,
        };
    }

    private function jsonArray(?string $json): array { $decoded = json_decode((string) $json, true); return is_array($decoded) ? $decoded : []; }
    private function cleanValue($value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function cleanDate($value): ?string { $value = $this->cleanValue($value); return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null; }
    private function nullableBool($value): ?int { $value = trim((string) $value); return $value === '' ? null : (in_array($value, ['1', 'yes', 'true', 'نعم'], true) ? 1 : 0); }
    private function statusAfter(string $label, string $type): string { return in_array($label, ['على رأس العمل', 'تعيين', 'تعيين / بداية عمل', 'عودة للعمل', 'إعادة تعيين'], true) || in_array($type, ['على رأس العمل', 'تعيين', 'تعيين / بداية عمل', 'عودة للعمل', 'إعادة تعيين'], true) ? 'on_duty' : 'off_duty'; }
}
