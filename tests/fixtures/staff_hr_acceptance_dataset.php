<?php

declare(strict_types=1);

/**
 * Deterministic, synthetic Staff-HR acceptance manifest.
 *
 * This file performs no writes. Seed/restore tools must validate its marker,
 * target suffix, ownership keys, and checksum before opening a transaction.
 */
final class StaffHrAcceptanceDataset
{
    public const DATASET_ID = 'staff_hr_acceptance_v1';
    public const VERSION = '2026.08.11-2';
    public const REQUIRED_MARKER = 'integrated-staff-hr';
    public const DATABASE_SUFFIX = '_test';

    /** @return array<string,mixed> */
    public static function build(): array
    {
        $personas = [
            self::persona('super_admin', 'مسؤول النظام التجريبي', 'demo.staffhr.superadmin@example.test', ['admin', 'super_admin']),
            self::persona('hr_manager', 'مدير الموارد البشرية التجريبي', 'demo.staffhr.hr@example.test', ['admin', 'staff_hr_override_manager']),
            self::persona('administrative_manager', 'المدير الإداري التجريبي', 'demo.staffhr.admin.manager@example.test', ['admin']),
            self::persona('direct_manager', 'المدير المباشر التجريبي', 'demo.staffhr.direct.manager@example.test', ['teacher']),
            self::persona('delegate_manager', 'النائب التجريبي', 'demo.staffhr.delegate@example.test', ['teacher']),
            self::persona('worker_teacher', 'المعلم العامل التجريبي', 'demo.staffhr.teacher@example.test', ['teacher']),
            self::persona('worker_specialist', 'الأخصائي العامل التجريبي', 'demo.staffhr.specialist@example.test', ['specialist']),
            self::persona('worker_standard', 'العامل الإداري التجريبي', 'demo.staffhr.worker@example.test', ['employee']),
            self::persona('protection_officer', 'مسؤول الحماية التجريبي', 'demo.staffhr.protection@example.test', ['admin']),
            self::persona('finance_operator', 'مسؤول المالية التجريبي', 'demo.staffhr.finance@example.test', ['admin']),
        ];

        $resources = [
            'organization_units' => [
                ['key' => 'demo_school', 'code' => 'DEMO-SCHOOL', 'name' => 'المدرسة التجريبية للقبول', 'parent_key' => null],
                ['key' => 'demo_primary', 'code' => 'DEMO-PRIMARY', 'name' => 'القوة الابتدائية التجريبية', 'parent_key' => 'demo_school'],
                ['key' => 'demo_admin', 'code' => 'DEMO-ADMIN', 'name' => 'القوة الإدارية التجريبية', 'parent_key' => 'demo_school'],
            ],
            'job_titles' => [
                ['key' => 'demo_teacher', 'code' => 'DEMO-TEACHER', 'name' => 'معلم تجريبي'],
                ['key' => 'demo_specialist', 'code' => 'DEMO-SPECIALIST', 'name' => 'أخصائي تجريبي'],
                ['key' => 'demo_hr_manager', 'code' => 'DEMO-HR', 'name' => 'مدير HR تجريبي'],
                ['key' => 'demo_worker', 'code' => 'DEMO-WORKER', 'name' => 'عامل إداري تجريبي'],
            ],
            'policy_groups' => [
                ['key' => 'demo_teaching_staff', 'code' => 'DEMO-TEACHING', 'name' => 'هيئة التدريس التجريبية'],
                ['key' => 'demo_administration', 'code' => 'DEMO-ADMIN-STAFF', 'name' => 'الإداريون التجريبيون'],
            ],
            'schedule_policies' => [
                [
                    'key' => 'demo_standard_0730_1430',
                    'name' => 'دوام تجريبي 07:30–14:30',
                    'timezone' => 'Africa/Cairo',
                    'workdays' => [1, 2, 3, 4, 7],
                    'start' => '07:30',
                    'end' => '14:30',
                    'late_grace_minutes' => 0,
                    'early_grace_minutes' => 0,
                ],
                [
                    'key' => 'demo_split_shift',
                    'name' => 'دوام تجريبي مقسم',
                    'timezone' => 'Africa/Cairo',
                    'workdays' => [1],
                    'segments' => [['07:30', '11:30'], ['12:30', '15:30']],
                ],
            ],
            'permission_types' => [
                ['key' => 'demo_late_arrival', 'code' => 'DEMO-LATE', 'name' => 'تأخير حضور تجريبي', 'behavior' => 'late_arrival'],
                ['key' => 'demo_early_leave', 'code' => 'DEMO-EARLY', 'name' => 'انصراف مبكر تجريبي', 'behavior' => 'early_leave'],
                ['key' => 'demo_mission', 'code' => 'DEMO-MISSION', 'name' => 'مأمورية تجريبية', 'behavior' => 'mission'],
                ['key' => 'demo_custom', 'code' => 'DEMO-CUSTOM', 'name' => 'إذن آخر تجريبي', 'behavior' => 'other'],
            ],
            'leave_types' => [
                ['key' => 'demo_annual', 'code' => 'DEMO-ANNUAL', 'name' => 'إجازة اعتيادية تجريبية', 'attachment' => 'optional'],
                ['key' => 'demo_medical', 'code' => 'DEMO-MEDICAL', 'name' => 'إجازة مرضية تجريبية', 'attachment' => 'required'],
                ['key' => 'demo_unpaid', 'code' => 'DEMO-UNPAID', 'name' => 'إجازة دون راتب تجريبية', 'attachment' => 'optional'],
            ],
            'approval_workflows' => [
                [
                    'key' => 'demo_permission_three_stage',
                    'resource_type' => 'permission_request',
                    'stages' => ['direct_manager', 'admin_manager', 'hr_manager'],
                ],
                [
                    'key' => 'demo_leave_two_stage',
                    'resource_type' => 'leave_request',
                    'stages' => ['direct_manager', 'hr_manager'],
                ],
            ],
            'biometric_events' => [
                ['key' => 'demo_worker_in', 'persona_key' => 'worker_standard', 'external_event_key' => 'DEMO-EVENT-0001', 'event_at' => '2026-08-11T07:30:00+03:00', 'event_type' => 'in'],
                ['key' => 'demo_worker_out', 'persona_key' => 'worker_standard', 'external_event_key' => 'DEMO-EVENT-0002', 'event_at' => '2026-08-11T14:30:00+03:00', 'event_type' => 'out'],
                ['key' => 'demo_unknown_punch', 'persona_key' => null, 'external_event_key' => 'DEMO-EVENT-UNKNOWN-0001', 'event_at' => '2026-08-11T08:05:00+03:00', 'event_type' => 'unknown'],
            ],
            'permission_requests' => [
                ['key' => 'demo_late_approved', 'persona_key' => 'worker_standard', 'type_key' => 'demo_late_arrival', 'from_at' => '2026-08-12T07:30:00+03:00', 'to_at' => '2026-08-12T09:30:00+03:00', 'status' => 'approved'],
                ['key' => 'demo_mission_pending', 'persona_key' => 'worker_teacher', 'type_key' => 'demo_mission', 'from_at' => '2026-08-13T10:00:00+03:00', 'to_at' => '2026-08-13T12:00:00+03:00', 'status' => 'pending_approval'],
            ],
            'permission_ledgers' => [
                ['key' => 'demo_worker_late_2026_08', 'persona_key' => 'worker_standard', 'type_key' => 'demo_late_arrival', 'period_key' => '2026-08', 'reserved_count' => 0, 'consumed_count' => 1, 'reserved_minutes' => 0, 'consumed_minutes' => 120],
            ],
            'leave_requests' => [
                ['key' => 'demo_annual_approved', 'persona_key' => 'worker_standard', 'type_key' => 'demo_annual', 'from_at' => '2026-09-01T00:00:00+03:00', 'to_at' => '2026-09-03T00:00:00+03:00', 'requested_units' => '2.000', 'status' => 'approved'],
                ['key' => 'demo_medical_draft', 'persona_key' => 'worker_specialist', 'type_key' => 'demo_medical', 'from_at' => '2026-09-07T00:00:00+03:00', 'to_at' => '2026-09-08T00:00:00+03:00', 'requested_units' => '1.000', 'status' => 'draft'],
                ['key' => 'demo_q27_teacher_unpaid_balance_anchor', 'persona_key' => 'worker_teacher', 'type_key' => 'demo_unpaid', 'from_at' => '2027-01-05T00:00:00+02:00', 'to_at' => '2027-01-06T00:00:00+02:00', 'requested_units' => '1.000', 'status' => 'draft'],
                ['key' => 'demo_q27_specialist_unpaid_balance_anchor', 'persona_key' => 'worker_specialist', 'type_key' => 'demo_unpaid', 'from_at' => '2027-01-05T00:00:00+02:00', 'to_at' => '2027-01-06T00:00:00+02:00', 'requested_units' => '1.000', 'status' => 'draft'],
            ],
            'leave_ledgers' => [
                ['key' => 'demo_worker_annual_cy2026', 'persona_key' => 'worker_standard', 'type_key' => 'demo_annual', 'period_key' => 'CY-2026', 'entitled_units' => '21.000', 'reserved_units' => '0.000', 'consumed_units' => '2.000'],
                ['key' => 'demo_q27_teacher_unpaid_cy2027', 'persona_key' => 'worker_teacher', 'type_key' => 'demo_unpaid', 'period_key' => 'CY-2027', 'entitled_units' => '21.000', 'reserved_units' => '0.000', 'consumed_units' => '0.000'],
                ['key' => 'demo_q27_specialist_unpaid_cy2027', 'persona_key' => 'worker_specialist', 'type_key' => 'demo_unpaid', 'period_key' => 'CY-2027', 'entitled_units' => '21.000', 'reserved_units' => '0.000', 'consumed_units' => '0.000'],
            ],
            'attendance_versions' => [
                ['key' => 'demo_worker_2026_08_11_v1', 'persona_key' => 'worker_standard', 'work_date' => '2026-08-11', 'version_no' => 1, 'status' => 'present', 'late_minutes' => 0, 'early_leave_minutes' => 0, 'is_official' => true],
            ],
            'discipline_cases' => [
                ['key' => 'demo_discipline_case_open', 'persona_key' => 'worker_standard', 'case_no' => 'DEMO-CASE-0001', 'confidentiality_level' => 'normal', 'status' => 'appeal_pending'],
                ['key' => 'demo_discipline_case_closed', 'persona_key' => 'worker_standard', 'case_no' => 'DEMO-CASE-0002', 'confidentiality_level' => 'normal', 'status' => 'closed'],
            ],
            'discipline_appeals' => [
                ['key' => 'demo_discipline_appeal_pending', 'case_key' => 'demo_discipline_case_open', 'appellant_key' => 'worker_standard', 'status' => 'submitted'],
            ],
            'ertaq_tickets' => [
                ['key' => 'demo_ertaq_normal', 'requester_key' => 'worker_standard', 'ticket_no' => 'DEMO-ERTAQ-0001', 'type' => 'suggestion', 'confidentiality_level' => 'normal', 'priority' => 'normal', 'risk_level' => 'none', 'status' => 'new'],
                ['key' => 'demo_ertaq_confidential', 'requester_key' => 'worker_teacher', 'ticket_no' => 'DEMO-ERTAQ-0002', 'type' => 'complaint', 'confidentiality_level' => 'restricted', 'priority' => 'normal', 'risk_level' => 'none', 'status' => 'triaged'],
                ['key' => 'demo_ertaq_urgent', 'requester_key' => 'worker_specialist', 'ticket_no' => 'DEMO-ERTAQ-0003', 'type' => 'complaint', 'confidentiality_level' => 'highly_restricted', 'priority' => 'urgent', 'risk_level' => 'immediate', 'status' => 'urgent_protected'],
            ],
        ];

        $scenarios = [];
        for ($number = 1; $number <= 33; ++$number) {
            $key = sprintf('Q%02d', $number);
            $scenarios[$key] = self::scenario($key);
        }

        $manifest = [
            'meta' => [
                'dataset_id' => self::DATASET_ID,
                'version' => self::VERSION,
                'required_marker' => self::REQUIRED_MARKER,
                'database_suffix' => self::DATABASE_SUFFIX,
                'synthetic' => true,
                'locale' => 'ar-EG',
                'timezone' => 'Africa/Cairo',
                'clock_anchor' => '2026-08-11T07:00:00+03:00',
            ],
            'personas' => $personas,
            'resources' => $resources,
            'scenarios' => $scenarios,
            'ownership' => self::ownership($personas, $resources, $scenarios),
        ];
        $manifest['meta']['checksum'] = self::checksum($manifest);
        self::assertSafe($manifest);
        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    public static function assertSafe(array $manifest): void
    {
        $meta = (array) ($manifest['meta'] ?? []);
        if (($meta['dataset_id'] ?? null) !== self::DATASET_ID
            || ($meta['required_marker'] ?? null) !== self::REQUIRED_MARKER
            || ($meta['database_suffix'] ?? null) !== self::DATABASE_SUFFIX
            || ($meta['synthetic'] ?? null) !== true) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_MANIFEST_GUARD_INVALID');
        }
        $encoded = self::json($manifest);
        foreach (['localhost', 'file://', 'C:\\', '/home/', 'BEGIN PRIVATE KEY', 'sk-', 'password', 'secret', 'token'] as $forbidden) {
            if (stripos($encoded, $forbidden) !== false) {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_MANIFEST_SENSITIVE_VALUE');
            }
        }
        foreach ((array) ($manifest['personas'] ?? []) as $persona) {
            $email = (string) ($persona['email'] ?? '');
            $name = (string) ($persona['name'] ?? '');
            $employeeCode = (string) ($persona['employee_code'] ?? '');
            if (!str_ends_with($email, '@example.test')
                || !str_contains($name, 'التجريبي')
                || preg_match('/^E2099\d{4}$/D', $employeeCode) !== 1) {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_PERSONA_NOT_SYNTHETIC');
            }
        }
        $scenarioKeys = array_keys((array) ($manifest['scenarios'] ?? []));
        $expected = array_map(static fn (int $number): string => sprintf('Q%02d', $number), range(1, 33));
        if ($scenarioKeys !== $expected) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_SCENARIO_COVERAGE_INVALID');
        }
    }

    /** @param array<string,mixed> $manifest */
    public static function verifyChecksum(array $manifest): bool
    {
        $stored = (string) ($manifest['meta']['checksum'] ?? '');
        $copy = $manifest;
        unset($copy['meta']['checksum']);
        return preg_match('/^[a-f0-9]{64}$/D', $stored) === 1
            && hash_equals($stored, self::checksum($copy));
    }

    /** @param array<string,mixed> $value */
    public static function checksum(array $value): string
    {
        return hash('sha256', self::json(self::canonicalize($value)));
    }

    /** @return array<string,mixed> */
    private static function persona(string $key, string $name, string $email, array $roles): array
    {
        $orderedKeys = [
            'super_admin', 'hr_manager', 'administrative_manager', 'direct_manager', 'delegate_manager',
            'worker_teacher', 'worker_specialist', 'worker_standard', 'protection_officer', 'finance_operator',
        ];
        $position = array_search($key, $orderedKeys, true);
        if ($position === false) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_PERSONA_KEY_UNKNOWN');
        }
        $sequence = $position + 1;
        return [
            'key' => $key,
            'name' => $name,
            'email' => $email,
            'employee_code' => sprintf('E2099%04d', $sequence),
            'biometric_id' => sprintf('DEMO-BIO-%04d', $sequence),
            'roles' => array_values($roles),
            'account_status' => 'active',
            'profile_status' => 'active',
        ];
    }

    /** @return array<string,mixed> */
    private static function scenario(string $key): array
    {
        $defaults = [
            'personas' => ['worker_standard', 'hr_manager'],
            'resource_keys' => ['demo_school', 'demo_standard_0730_1430'],
            'expected' => 'acceptance_contract_enforced',
        ];
        $overrides = [
            'Q04' => ['resource_keys' => ['demo_late_arrival', 'demo_standard_0730_1430']],
            'Q05' => ['resource_keys' => ['demo_early_leave', 'demo_standard_0730_1430']],
            'Q06' => ['resource_keys' => ['demo_mission', 'demo_standard_0730_1430']],
            'Q08' => ['personas' => ['worker_standard', 'direct_manager', 'administrative_manager', 'hr_manager'], 'resource_keys' => ['demo_permission_three_stage']],
            'Q09' => ['personas' => ['worker_standard', 'direct_manager', 'delegate_manager', 'protection_officer']],
            'Q11' => ['resource_keys' => ['demo_annual', 'demo_medical']],
            'Q12' => ['resource_keys' => ['demo_medical']],
            'Q16' => ['personas' => ['worker_teacher', 'worker_specialist', 'hr_manager']],
            'Q21' => ['resource_keys' => ['demo_split_shift']],
            'Q27' => ['personas' => ['worker_teacher', 'worker_specialist', 'hr_manager'], 'resource_keys' => ['demo_unpaid', 'demo_teaching_staff']],
            'Q28' => ['personas' => ['worker_standard', 'direct_manager', 'protection_officer']],
            'Q30' => ['personas' => ['super_admin'], 'resource_keys' => ['demo_school']],
            'Q31' => ['personas' => ['super_admin'], 'resource_keys' => []],
            'Q32' => ['personas' => ['super_admin', 'worker_standard'], 'resource_keys' => []],
            'Q33' => ['personas' => ['super_admin', 'worker_teacher', 'hr_manager'], 'resource_keys' => []],
        ];
        return ['key' => $key] + array_replace($defaults, $overrides[$key] ?? []);
    }

    /** @return array<string,mixed> */
    private static function ownership(array $personas, array $resources, array $scenarios): array
    {
        $keys = [];
        foreach ($personas as $persona) {
            $keys[] = 'persona:' . $persona['key'];
        }
        foreach ($resources as $type => $rows) {
            foreach ($rows as $row) {
                $keys[] = 'resource:' . $type . ':' . $row['key'];
            }
        }
        foreach ($scenarios as $key => $_scenario) {
            $keys[] = 'scenario:' . $key;
        }
        sort($keys, SORT_STRING);
        return ['dataset_id' => self::DATASET_ID, 'resource_keys' => $keys];
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

return StaffHrAcceptanceDataset::build();
