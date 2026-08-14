<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Infrastructure\Migration\StaffHrMigrationCoordinator;

final class StaffHrAcceptanceDatasetStore
{
    private const REQUIRED_TABLES = [
        'activity_logs',
        'users',
        'staff_profiles',
        'staff_roles',
        'user_role_assignments',
        'staff_org_units',
        'staff_job_titles',
        'staff_assignments',
        'staff_manager_assignments',
        'staff_policy_groups',
        'staff_policy_group_memberships',
        'staff_schedule_policies',
        'staff_schedule_policy_versions',
        'staff_schedule_days',
        'staff_schedule_scopes',
        'staff_permission_types',
        'staff_leave_types',
        'staff_approval_workflows',
        'staff_approval_workflow_versions',
        'staff_approval_stages',
        'staff_permission_policy_versions',
        'staff_permission_policy_scopes',
        'staff_permission_requests',
        'staff_permission_request_periods',
        'staff_permission_quota_accounts',
        'staff_permission_quota_movements',
        'staff_leave_policy_versions',
        'staff_leave_policy_scopes',
        'staff_leave_policy_blackouts',
        'staff_leave_requests',
        'staff_leave_request_days',
        'staff_leave_balance_accounts',
        'staff_leave_balance_movements',
        'staff_attendance_entry_methods',
        'staff_biometric_identity_mappings',
        'staff_biometric_events',
        'staff_attendance_runs',
        'staff_attendance_day_versions',
        'staff_attendance_segments',
        'staff_discipline_incidents',
        'staff_discipline_cases',
        'staff_discipline_decisions',
        'staff_discipline_appeals',
        'staff_discipline_evidence',
        'staff_discipline_interim_measures',
        'staff_discipline_reopen_events',
        'staff_ertaq_tickets',
        'staff_ertaq_urgent_events',
        'recovery_backups',
        'staff_hr_cutover_windows',
        'staff_hr_migration_batches',
        'staff_hr_migration_exceptions',
    ];

    private const DELETE_ORDER = [
        'staff_manager_assignments',
        'staff_policy_group_memberships',
        'staff_assignments',
        'user_role_assignments',
        'staff_roles',
        'staff_profiles',
        'staff_approval_workflows',
        'staff_leave_types',
        'staff_permission_types',
        'staff_schedule_policies',
        'staff_policy_groups',
        'staff_job_titles',
        'staff_org_units',
        'users',
    ];

    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function assertEnvironment(string $databaseName, string $environment, string $marker): void
    {
        $environment = strtolower(trim($environment));
        if (!in_array($environment, ['test', 'testing'], true)
            || $marker !== StaffHrAcceptanceDataset::REQUIRED_MARKER
            || !preg_match('/^[A-Za-z0-9_]+_test$/D', $databaseName)
            || strtolower($databaseName) === 'educore') {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_TARGET_REFUSED');
        }
    }

    /** @param array<string,mixed> $dataset @return array<string,mixed> */
    public function seed(array $dataset, string $password, callable $baselineFactory): array
    {
        StaffHrAcceptanceDataset::assertSafe($dataset);
        if (!StaffHrAcceptanceDataset::verifyChecksum($dataset)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_CHECKSUM_INVALID');
        }
        if (strlen($password) < 12 || strlen($password) > 200) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_PASSWORD_INVALID');
        }
        $this->assertSchema();
        $checksum = (string) $dataset['meta']['checksum'];
        $batchKey = $this->batchKey($checksum);
        $existing = $this->batchByKey($batchKey);
        if ($existing !== null) {
            return $this->replayedSeed($existing, $dataset);
        }

        $baseline = $baselineFactory();
        if (!is_array($baseline)
            || (int) ($baseline['id'] ?? 0) <= 0
            || preg_match('/^[a-f0-9]{32}$/D', (string) ($baseline['backup_key'] ?? '')) !== 1) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_BASELINE_BACKUP_INVALID');
        }

        $this->db->beginTransaction();
        try {
            $manifest = [$this->owned('recovery_backups', (int) $baseline['id'])];
            $overrideRoleId = $this->insertAcceptanceOverrideRole();
            $manifest[] = $this->owned('staff_roles', $overrideRoleId);
            $personas = [];
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_PASSWORD_HASH_FAILED');
            }
            foreach ((array) $dataset['personas'] as $persona) {
                $userId = $this->insertUser($persona, $passwordHash);
                $personas[(string) $persona['key']] = $userId;
                $manifest[] = $this->owned('users', $userId);
            }
            $actorId = (int) ($personas['super_admin'] ?? 0);
            if ($actorId <= 0) {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_ACTOR_MISSING');
            }
            $this->setAuditActor($actorId, 'مسؤول النظام التجريبي', 'super_admin');

            foreach ((array) $dataset['personas'] as $persona) {
                $userId = $personas[(string) $persona['key']];
                $profileId = $this->insertProfile($userId, $persona);
                $manifest[] = $this->owned('staff_profiles', $profileId);
                foreach ((array) ($persona['roles'] ?? []) as $index => $roleKey) {
                    $roleId = $this->insertRoleAssignment(
                        $userId,
                        (string) $roleKey,
                        $index === 0,
                        $actorId
                    );
                    $manifest[] = $this->owned('user_role_assignments', $roleId);
                }
            }

            $resources = (array) $dataset['resources'];
            $units = $this->seedUnits((array) $resources['organization_units'], $actorId, $manifest);
            $titles = $this->seedTitles((array) $resources['job_titles'], $actorId, $manifest);
            $groups = $this->seedGroups((array) $resources['policy_groups'], $actorId, $manifest);
            $assignments = $this->seedAssignments($personas, $units, $titles, $actorId, $manifest);
            $this->seedManagers($personas, $units, $actorId, $manifest);
            $this->seedMemberships($personas, $groups, $actorId, $manifest);
            $this->seedSchedules((array) $resources['schedule_policies'], $actorId, $manifest);
            $permissionTypes = $this->seedPermissionTypes((array) $resources['permission_types'], $actorId, $manifest);
            $leaveTypes = $this->seedLeaveTypes((array) $resources['leave_types'], $actorId, $manifest);
            $workflowVersions = $this->seedWorkflows((array) $resources['approval_workflows'], $personas, $actorId, $manifest);
            $permissionPolicies = $this->seedPermissionPolicies($permissionTypes, $actorId, $manifest);
            $leavePolicies = $this->seedLeavePolicies($leaveTypes, $actorId, $manifest);
            $permissionEvidence = $this->seedPermissionEvidence(
                (array) $resources['permission_requests'],
                $personas,
                $permissionTypes,
                $permissionPolicies,
                $workflowVersions,
                $assignments,
                $actorId,
                $manifest
            );
            $this->seedPermissionLedgers(
                (array) $resources['permission_ledgers'],
                $personas,
                $permissionTypes,
                $permissionEvidence,
                $actorId,
                $manifest
            );
            $leaveEvidence = $this->seedLeaveEvidence(
                (array) $resources['leave_requests'],
                $personas,
                $leaveTypes,
                $leavePolicies,
                $workflowVersions,
                $assignments,
                $actorId,
                $manifest
            );
            $this->seedLeaveLedgers(
                (array) $resources['leave_ledgers'],
                $personas,
                $leaveTypes,
                $leaveEvidence,
                $actorId,
                $manifest
            );
            $biometricEvents = $this->seedBiometricEvidence(
                (array) $resources['biometric_events'],
                $personas,
                $actorId,
                $manifest
            );
            $this->seedAttendanceVersions(
                (array) $resources['attendance_versions'],
                $personas,
                $assignments,
                $biometricEvents,
                $actorId,
                $manifest
            );
            $this->seedDisciplineEvidence(
                (array) $resources['discipline_cases'],
                (array) $resources['discipline_appeals'],
                $personas,
                $actorId,
                $manifest
            );
            $this->seedErtaqEvidence(
                (array) $resources['ertaq_tickets'],
                $personas,
                $units,
                $actorId,
                $manifest
            );

            $audit = new AuditService($this->db);
            $coordinator = new StaffHrMigrationCoordinator($this->db, $audit);
            $window = $coordinator->openWindow(
                'freeze',
                'acceptance:' . $checksum,
                $actorId,
                $this->windowKey($checksum),
                new DateTimeImmutable('+10 years', new DateTimeZone('UTC'))
            );
            $batch = $coordinator->beginBatch(
                (int) $window['window_id'],
                StaffHrAcceptanceDataset::DATASET_ID,
                'acceptance:' . $checksum,
                $actorId,
                $batchKey,
                $manifest
            );
            $count = count($manifest);
            $coordinator->checkpoint(
                (int) $window['window_id'],
                (int) $batch['batch_id'],
                'acceptance:complete:' . $checksum,
                ['read' => $count, 'write' => $count, 'skip' => 0, 'error' => 0],
                $checksum,
                $actorId
            );
            $coordinator->completeBatch((int) $batch['batch_id'], 'acceptance:' . $checksum, $actorId);
            $coordinator->closeWindow((int) $window['window_id'], [
                'read' => $count,
                'write' => $count,
                'skip' => 0,
                'error' => 0,
                'checksum' => $checksum,
            ], $actorId);
            $this->db->commit();

            return [
                'dataset_id' => StaffHrAcceptanceDataset::DATASET_ID,
                'checksum' => $checksum,
                'window_id' => (int) $window['window_id'],
                'batch_id' => (int) $batch['batch_id'],
                'owned_count' => $count,
                'persona_ids' => $personas,
                'baseline_backup_id' => (int) $baseline['id'],
                'baseline_backup_key' => (string) $baseline['backup_key'],
                'replayed' => false,
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $dataset @return array<string,mixed> */
    public function restore(array $dataset, int $actorId, ?callable $baselineRestorer = null): array
    {
        StaffHrAcceptanceDataset::assertSafe($dataset);
        if (!StaffHrAcceptanceDataset::verifyChecksum($dataset) || $actorId <= 0) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_RESTORE_INPUT_INVALID');
        }
        $this->assertSchema();
        $checksum = (string) $dataset['meta']['checksum'];
        $batch = $this->batchByKey($this->batchKey($checksum));
        if ($batch === null) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_BATCH_NOT_FOUND');
        }
        $manifest = $this->decodeManifest((string) ($batch['manifest_json'] ?? ''));
        if ($this->manifestContains($manifest, 'users', $actorId)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_RESTORE_ACTOR_IS_DATASET_OWNED');
        }
        $actor = $this->activeActor($actorId);
        $this->setAuditActor($actorId, (string) $actor['name'], (string) $actor['role']);
        $baselineRows = array_values(array_filter(
            $manifest,
            static fn (array $row): bool => (string) ($row['resource_type'] ?? '') === 'recovery_backups'
        ));
        if ($baselineRows !== []) {
            if (count($baselineRows) !== 1 || $baselineRestorer === null) {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_FRESH_DATABASE_REQUIRED');
            }
            $baselineId = (int) ($baselineRows[0]['resource_id'] ?? 0);
            $this->assertOwnedRow('recovery_backups', $baselineId);
            $baseline = $this->rowById('recovery_backups', $baselineId);
            $restored = $baselineRestorer((string) ($baseline['backup_key'] ?? ''));
            if (!is_array($restored)
                || (string) ($restored['restored_database_name'] ?? '') === '') {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_BASELINE_RESTORE_FAILED');
            }
            (new AuditService($this->db))->recordEvent(
                'staff_hr_acceptance_baseline_restored',
                'recovery_backup',
                $baselineId,
                null,
                [
                    'dataset_id' => StaffHrAcceptanceDataset::DATASET_ID,
                    'target_database_hash' => hash('sha256', (string) $restored['restored_database_name']),
                    'replayed' => (bool) ($restored['replayed'] ?? false),
                    'direct_undo_available' => false,
                ],
                ['user_id' => $actorId]
            );

            return [
                'dataset_id' => StaffHrAcceptanceDataset::DATASET_ID,
                'checksum' => $checksum,
                'window_id' => (int) $batch['cutover_window_id'],
                'batch_id' => (int) $batch['id'],
                'deleted_count' => 0,
                'restored_database_name' => (string) $restored['restored_database_name'],
                'baseline_backup_id' => $baselineId,
                'replayed' => (bool) ($restored['replayed'] ?? false),
            ];
        }
        $audit = new AuditService($this->db);
        $coordinator = new StaffHrMigrationCoordinator($this->db, $audit);
        $receipt = $coordinator->rollbackWindow(
            (int) $batch['cutover_window_id'],
            'استعادة baseline لحزمة قبول Staff-HR التجريبية',
            $actorId,
            function (array $owned): array {
                return $this->deleteOwned($owned);
            }
        );
        return [
            'dataset_id' => StaffHrAcceptanceDataset::DATASET_ID,
            'checksum' => $checksum,
            'window_id' => (int) $batch['cutover_window_id'],
            'batch_id' => (int) $batch['id'],
            'deleted_count' => (int) ($receipt['reversed_count'] ?? 0),
            'rollback_checksum' => (string) ($receipt['rollback_checksum'] ?? ''),
            'replayed' => (bool) ($receipt['replayed'] ?? false),
        ];
    }

    private function assertSchema(): void
    {
        $missing = [];
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        foreach (self::REQUIRED_TABLES as $table) {
            $statement->execute([$table]);
            if ((int) $statement->fetchColumn() !== 1) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_SCHEMA_MISSING:' . implode(',', $missing));
        }
    }

    /** @param array<string,mixed> $persona */
    private function insertUser(array $persona, string $passwordHash): int
    {
        $email = (string) $persona['email'];
        if ($this->findId('users', 'email', $email) !== null) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_EMAIL_ALREADY_EXISTS');
        }
        $baseRole = (string) (($persona['roles'][0] ?? null) ?: 'employee');
        $statement = $this->db->prepare(
            'INSERT INTO users
             (name, employee_code, username, email, password, password_hash, password_key_version,
              role, status, is_test_account)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            (string) $persona['name'],
            (string) $persona['employee_code'],
            strstr($email, '@', true),
            $email,
            $passwordHash,
            $passwordHash,
            2,
            $baseRole,
            'active',
            1,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $persona */
    private function insertProfile(int $userId, array $persona): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_profiles
             (user_id, employee_code, biometric_id, full_name_ar, email_personal, hire_date,
              job_title, department, current_work_status, current_status_effective_date,
              first_hire_date, latest_hire_date, can_rehire, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            (string) $persona['employee_code'],
            (string) $persona['biometric_id'],
            (string) $persona['name'],
            (string) $persona['email'],
            '2026-08-01',
            'مسمى تجريبي',
            'قوة تجريبية',
            'on_duty',
            '2026-08-01',
            '2026-08-01',
            '2026-08-01',
            1,
            'STAFF_HR_ACCEPTANCE_DATASET',
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function insertRoleAssignment(int $userId, string $roleKey, bool $primary, int $actorId): int
    {
        $exists = $this->db->prepare("SELECT COUNT(*) FROM staff_roles WHERE role_key = ? AND status = 'active'");
        $exists->execute([$roleKey]);
        if ((int) $exists->fetchColumn() !== 1) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_ROLE_MISSING:' . $roleKey);
        }
        $statement = $this->db->prepare(
            'INSERT INTO user_role_assignments (user_id, role_key, is_primary, status, assigned_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$userId, $roleKey, $primary ? 1 : 0, 'active', $actorId]);
        return (int) $this->db->lastInsertId();
    }

    private function insertAcceptanceOverrideRole(): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_roles (role_key, role_name, base_role_key, portal_type, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            'staff_hr_override_manager',
            'مدير استثناءات التشغيل التجريبي',
            'admin',
            'admin_like',
            'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,int> */
    private function seedUnits(array $rows, int $actorId, array &$manifest): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $parentId = isset($row['parent_key']) ? ($ids[(string) $row['parent_key']] ?? null) : null;
            $statement = $this->db->prepare(
                'INSERT INTO staff_org_units
                 (code, name, unit_type, parent_id, valid_from, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([(string) $row['code'], (string) $row['name'], 'acceptance_demo', $parentId, '2026-08-01', 'active', $actorId]);
            $id = (int) $this->db->lastInsertId();
            $ids[(string) $row['key']] = $id;
            $manifest[] = $this->owned('staff_org_units', $id);
        }
        return $ids;
    }

    /** @return array<string,int> */
    private function seedTitles(array $rows, int $actorId, array &$manifest): array
    {
        $ids = [];
        $statement = $this->db->prepare(
            'INSERT INTO staff_job_titles (code, name, active_from, status, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $statement->execute([(string) $row['code'], (string) $row['name'], '2026-08-01', 'active', $actorId]);
            $id = (int) $this->db->lastInsertId();
            $ids[(string) $row['key']] = $id;
            $manifest[] = $this->owned('staff_job_titles', $id);
        }
        return $ids;
    }

    /** @return array<string,int> */
    private function seedGroups(array $rows, int $actorId, array &$manifest): array
    {
        $ids = [];
        $statement = $this->db->prepare(
            'INSERT INTO staff_policy_groups (code, name, purpose, valid_from, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $statement->execute([(string) $row['code'], (string) $row['name'], 'بيانات قبول تجريبية', '2026-08-01', 'active', $actorId]);
            $id = (int) $this->db->lastInsertId();
            $ids[(string) $row['key']] = $id;
            $manifest[] = $this->owned('staff_policy_groups', $id);
        }
        return $ids;
    }

    private function seedAssignments(array $personas, array $units, array $titles, int $actorId, array &$manifest): array
    {
        $titleFor = [
            'hr_manager' => 'demo_hr_manager',
            'worker_teacher' => 'demo_teacher',
            'direct_manager' => 'demo_teacher',
            'delegate_manager' => 'demo_teacher',
            'worker_specialist' => 'demo_specialist',
        ];
        $statement = $this->db->prepare(
            'INSERT INTO staff_assignments
             (staff_user_id, org_unit_id, job_title_id, assignment_kind, employment_status,
              work_fraction, valid_from, source, source_ref, version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $assignments = [];
        foreach ($personas as $key => $userId) {
            if ($key === 'super_admin') {
                continue;
            }
            $unitKey = in_array($key, ['worker_teacher', 'worker_specialist', 'direct_manager', 'delegate_manager'], true)
                ? 'demo_primary' : 'demo_admin';
            $titleKey = $titleFor[$key] ?? 'demo_worker';
            $statement->execute([
                $userId, $units[$unitKey], $titles[$titleKey], 'primary', 'active', 1,
                '2026-08-01', 'manual', 'acceptance:' . StaffHrAcceptanceDataset::DATASET_ID . ':' . $key,
                1, $actorId,
            ]);
            $assignmentId = (int) $this->db->lastInsertId();
            $assignments[(string) $key] = $assignmentId;
            $manifest[] = $this->owned('staff_assignments', $assignmentId);
        }
        return $assignments;
    }

    private function seedManagers(array $personas, array $units, int $actorId, array &$manifest): void
    {
        $rows = [
            ['org_unit', $units['demo_primary'], $personas['direct_manager'], 'direct', 10],
            ['org_unit', $units['demo_admin'], $personas['direct_manager'], 'direct', 10],
            ['org_unit', $units['demo_school'], $personas['administrative_manager'], 'administrative', 10],
            ['org_unit', $units['demo_primary'], $personas['administrative_manager'], 'administrative', 10],
            ['org_unit', $units['demo_admin'], $personas['administrative_manager'], 'administrative', 10],
            ['org_unit', $units['demo_school'], $personas['hr_manager'], 'hr', 10],
        ];
        $statement = $this->db->prepare(
            'INSERT INTO staff_manager_assignments
             (subject_type, subject_id, manager_user_id, manager_kind, priority, valid_from, status, source, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $statement->execute([$row[0], $row[1], $row[2], $row[3], $row[4], '2026-08-01', 'active', 'acceptance_dataset', $actorId]);
            $manifest[] = $this->owned('staff_manager_assignments', (int) $this->db->lastInsertId());
        }
    }

    private function seedMemberships(array $personas, array $groups, int $actorId, array &$manifest): void
    {
        $map = [
            'worker_teacher' => 'demo_teaching_staff',
            'worker_specialist' => 'demo_teaching_staff',
            'worker_standard' => 'demo_administration',
        ];
        $statement = $this->db->prepare(
            'INSERT INTO staff_policy_group_memberships
             (group_id, staff_user_id, valid_from, status, source, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($map as $personaKey => $groupKey) {
            $statement->execute([$groups[$groupKey], $personas[$personaKey], '2026-08-01', 'active', 'acceptance_dataset', $actorId]);
            $manifest[] = $this->owned('staff_policy_group_memberships', (int) $this->db->lastInsertId());
        }
    }

    private function seedSchedules(array $rows, int $actorId, array &$manifest): void
    {
        $policyInsert = $this->db->prepare(
            'INSERT INTO staff_schedule_policies (code, name, description, status, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $versionInsert = $this->db->prepare(
            'INSERT INTO staff_schedule_policy_versions
             (policy_id, version_no, state, valid_from, timezone, lock_version,
              create_idempotency_key, create_payload_hash, created_by)
             VALUES (?, 1, ?, ?, ?, 1, ?, ?, ?)'
        );
        $dayInsert = $this->db->prepare(
            'INSERT INTO staff_schedule_days
             (policy_version_id, weekday, is_working_day, start_time, end_time,
              end_day_offset, required_minutes, late_grace_minutes, early_grace_minutes,
              entry_window_before_minutes, entry_window_after_minutes,
              exit_window_before_minutes, exit_window_after_minutes)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 180, 240, 240, 180)'
        );
        $scopeInsert = $this->db->prepare(
            'INSERT INTO staff_schedule_scopes
             (policy_version_id, scope_type, scope_id, priority, valid_from, status, created_by)
             VALUES (?, ?, 0, 0, ?, ?, ?)'
        );
        $publish = $this->db->prepare(
            "UPDATE staff_schedule_policy_versions
             SET state = 'published', publication_key = ?, publication_payload_hash = ?,
                 published_by = ?, published_at = ?, lock_version = lock_version + 1
             WHERE id = ? AND state = 'draft'"
        );
        foreach ($rows as $row) {
            $policyInsert->execute([
                'DEMO-SCHEDULE-' . strtoupper(str_replace('demo_', '', (string) $row['key'])),
                (string) $row['name'],
                'سياسة قبول تجريبية؛ تفاصيلها تنشرها رحلات القبول.',
                'active', $actorId,
            ]);
            $policyId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_schedule_policies', $policyId);
            if ((string) $row['key'] !== 'demo_standard_0730_1430') {
                continue;
            }
            $versionKey = 'staff-hr-acceptance:schedule-version:' . (string) $row['key'];
            $versionInsert->execute([
                $policyId, 'draft', '2026-01-01 00:00:00.000000', (string) $row['timezone'],
                $versionKey, $this->hashKey('schedule-version:' . (string) $row['key']), $actorId,
            ]);
            $versionId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_schedule_policy_versions', $versionId);
            $workdays = array_map('intval', (array) $row['workdays']);
            for ($weekday = 1; $weekday <= 7; ++$weekday) {
                $working = in_array($weekday, $workdays, true);
                $dayInsert->execute([
                    $versionId, $weekday, $working ? 1 : 0,
                    $working ? (string) $row['start'] . ':00' : null,
                    $working ? (string) $row['end'] . ':00' : null,
                    $working ? 420 : 0,
                    (int) $row['late_grace_minutes'],
                    (int) $row['early_grace_minutes'],
                ]);
                $manifest[] = $this->owned('staff_schedule_days', (int) $this->db->lastInsertId());
            }
            $scopeInsert->execute([$versionId, 'global', '2026-01-01 00:00:00.000000', 'active', $actorId]);
            $manifest[] = $this->owned('staff_schedule_scopes', (int) $this->db->lastInsertId());
            $publicationKey = 'staff-hr-acceptance:schedule-publish:' . (string) $row['key'];
            $publish->execute([
                $publicationKey,
                $this->hashKey('schedule-publication:' . (string) $row['key']),
                $actorId,
                '2026-08-01 00:00:00.000000',
                $versionId,
            ]);
            if ($publish->rowCount() !== 1) {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_SCHEDULE_PUBLISH_FAILED');
            }
        }

    }

    private function seedPermissionTypes(array $rows, int $actorId, array &$manifest): array
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_permission_types
             (code, name, coverage_behavior, requires_reason, requires_custom_label,
              requires_attachment, allow_retroactive, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ids = [];
        foreach ($rows as $row) {
            $behavior = (string) $row['behavior'];
            $statement->execute([
                (string) $row['code'], (string) $row['name'], $behavior === 'other' ? 'none' : $behavior,
                1, $behavior === 'other' ? 1 : 0, 0, 1, 'active', $actorId,
            ]);
            $id = (int) $this->db->lastInsertId();
            $ids[(string) $row['key']] = $id;
            $manifest[] = $this->owned('staff_permission_types', $id);
        }
        return $ids;
    }

    private function seedLeaveTypes(array $rows, int $actorId, array &$manifest): array
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_leave_types
             (code, name, unit, requires_reason, requires_attachment, requires_medical_document,
              allow_partial_unit, payroll_effect_code, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ids = [];
        foreach ($rows as $row) {
            $medical = (string) $row['key'] === 'demo_medical';
            $unpaid = (string) $row['key'] === 'demo_unpaid';
            $statement->execute([
                (string) $row['code'], (string) $row['name'], 'day', 1,
                $medical ? 1 : 0, $medical ? 1 : 0, 1,
                $unpaid ? 'UNPAID_LEAVE' : 'PAID_LEAVE', 'active', $actorId,
            ]);
            $id = (int) $this->db->lastInsertId();
            $ids[(string) $row['key']] = $id;
            $manifest[] = $this->owned('staff_leave_types', $id);
        }
        return $ids;
    }

    private function seedWorkflows(array $rows, array $personas, int $actorId, array &$manifest): array
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_approval_workflows (code, name, resource_type, status, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $versions = [];
        foreach ($rows as $row) {
            $statement->execute([
                'DEMO-WORKFLOW-' . strtoupper(str_replace('demo_', '', (string) $row['key'])),
                'مسار قبول تجريبي ' . (string) $row['key'],
                (string) $row['resource_type'], 'active', $actorId,
            ]);
            $workflowId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_approval_workflows', $workflowId);

            $version = $this->db->prepare(
                'INSERT INTO staff_approval_workflow_versions
                 (workflow_id, version_no, state, valid_from, cancellation_rule,
                  escalation_rule, created_by)
                 VALUES (?, 1, ?, ?, ?, ?, ?)'
            );
            $version->execute([
                $workflowId,
                'draft',
                '2026-01-01 00:00:00.000000',
                'request_cancellation',
                $this->json(['mode' => 'fail_closed']),
                $actorId,
            ]);
            $versionId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_approval_workflow_versions', $versionId);
            $stageInsert = $this->db->prepare(
                'INSERT INTO staff_approval_stages
                 (workflow_version_id, sequence_no, name, resolver_type, resolver_config,
                  decision_mode, sla_minutes, on_timeout, self_approval_rule,
                  same_actor_rule, tie_rule, rejection_rule)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ((array) ($row['stages'] ?? []) as $index => $stageKey) {
                $resolver = match ((string) $stageKey) {
                    'direct_manager' => 'direct_manager',
                    'admin_manager' => 'admin_manager',
                    'hr_manager' => 'named_users',
                    default => 'role_scope',
                };
                $resolverConfig = match ($resolver) {
                    'role_scope' => ['role_keys' => [(string) $stageKey]],
                    'named_users' => ['user_ids' => [$personas['hr_manager']]],
                    default => ['relationship' => (string) $stageKey],
                };
                $stageInsert->execute([
                    $versionId,
                    $index + 1,
                    'مرحلة قبول ' . (string) $stageKey,
                    $resolver,
                    $this->json($resolverConfig),
                    'sequential',
                    1440,
                    'fail_closed',
                    'require_alternate',
                    'require_alternate',
                    'reject',
                    'stop_workflow',
                ]);
                $manifest[] = $this->owned('staff_approval_stages', (int) $this->db->lastInsertId());
            }
            $publish = $this->db->prepare(
                "UPDATE staff_approval_workflow_versions
                 SET state = 'published', published_by = ?, published_at = ?
                 WHERE id = ? AND state = 'draft'"
            );
            $publish->execute([$actorId, '2026-08-01 00:00:00.000000', $versionId]);
            if ($publish->rowCount() !== 1) {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_WORKFLOW_PUBLISH_FAILED');
            }
            $versions[(string) $row['resource_type']] = $versionId;
        }
        return $versions;
    }

    /** @return array<string,int> */
    private function seedPermissionPolicies(array $permissionTypes, int $actorId, array &$manifest): array
    {
        $versions = [];
        $insertVersion = $this->db->prepare(
            'INSERT INTO staff_permission_policy_versions
             (permission_type_id, version_no, state, valid_from, timezone,
              max_requests_per_month, max_minutes_per_request, max_minutes_per_month,
              min_notice_minutes, retroactive_limit_days, reserve_on_submit,
              allow_overlap, allow_quota_override, created_by)
             VALUES (?, 1, ?, ?, ?, 8, 240, 960, 0, 30, 1, 0, 0, ?)'
        );
        $insertScope = $this->db->prepare(
            'INSERT INTO staff_permission_policy_scopes
             (policy_version_id, scope_type, scope_id, priority, valid_from, status, created_by)
             VALUES (?, ?, 0, 0, ?, ?, ?)'
        );
        $publish = $this->db->prepare(
            "UPDATE staff_permission_policy_versions
             SET state = 'published', published_by = ?, published_at = ?
             WHERE id = ? AND state = 'draft'"
        );
        foreach ($permissionTypes as $key => $typeId) {
            $insertVersion->execute([$typeId, 'draft', '2026-01-01 00:00:00.000000', 'Africa/Cairo', $actorId]);
            $versionId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_permission_policy_versions', $versionId);
            $insertScope->execute([$versionId, 'global', '2026-01-01 00:00:00.000000', 'active', $actorId]);
            $manifest[] = $this->owned('staff_permission_policy_scopes', (int) $this->db->lastInsertId());
            $publish->execute([$actorId, '2026-08-01 00:00:00.000000', $versionId]);
            if ($publish->rowCount() !== 1) {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_PERMISSION_POLICY_PUBLISH_FAILED');
            }
            $versions[(string) $key] = $versionId;
        }
        return $versions;
    }

    /** @return array<string,int> */
    private function seedLeavePolicies(array $leaveTypes, int $actorId, array &$manifest): array
    {
        $versions = [];
        $insertVersion = $this->db->prepare(
            'INSERT INTO staff_leave_policy_versions
             (leave_type_id, version_no, state, valid_from, timezone,
              entitlement_period_type, entitlement_units, accrual_mode, accrual_units,
              min_notice_minutes, minimum_increment_minutes, allow_partial_unit,
              allow_overlap, allow_negative_balance, negative_balance_limit_units,
              requires_attachment, requires_medical_document, payroll_effect_code, created_by)
             SELECT id, 1, ?, ?, ?, ?, 21.000, ?, 0.000, 0, 60,
                    allow_partial_unit, 0, 0, 0.000,
                    requires_attachment, requires_medical_document, payroll_effect_code, ?
             FROM staff_leave_types WHERE id = ?'
        );
        $insertScope = $this->db->prepare(
            'INSERT INTO staff_leave_policy_scopes
             (policy_version_id, scope_type, scope_id, priority, valid_from,
              minimum_available_staff, max_absence_percentage,
              requires_staffing_override, override_role_key, status, created_by)
             VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertBlackout = $this->db->prepare(
            'INSERT INTO staff_leave_policy_blackouts
             (policy_version_id, scope_type, scope_id, from_at, to_at, label,
              requires_override, override_role_key, status, created_by)
             VALUES (?, \'global\', 0, ?, ?, ?, 1, \'staff_hr_override_manager\', \'active\', ?)'
        );
        $publish = $this->db->prepare(
            "UPDATE staff_leave_policy_versions
             SET state = 'published', published_by = ?, published_at = ?
             WHERE id = ? AND state = 'draft'"
        );
        foreach ($leaveTypes as $key => $typeId) {
            $insertVersion->execute([
                'draft', '2026-01-01 00:00:00.000000', 'Africa/Cairo',
                'calendar_year', 'grant', $actorId, $typeId,
            ]);
            $versionId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_leave_policy_versions', $versionId);
            // Keep the ordinary annual-leave journey (Q11) independent from
            // the deliberately restrictive staffing/blackout journey (Q27).
            $staffingDemo = (string) $key === 'demo_unpaid';
            $insertScope->execute([
                $versionId, 'global', '2026-01-01 00:00:00.000000',
                $staffingDemo ? 10 : null,
                $staffingDemo ? '10.00' : null,
                $staffingDemo ? 1 : 0,
                $staffingDemo ? 'staff_hr_override_manager' : null,
                'active', $actorId,
            ]);
            $manifest[] = $this->owned('staff_leave_policy_scopes', (int) $this->db->lastInsertId());
            if ($staffingDemo) {
                $insertBlackout->execute([
                    $versionId, '2027-02-10 00:00:00.000000', '2027-02-13 00:00:00.000000',
                    'فترة حظر تشغيلية تجريبية Q27', $actorId,
                ]);
                $manifest[] = $this->owned('staff_leave_policy_blackouts', (int) $this->db->lastInsertId());
            }
            $publish->execute([$actorId, '2026-08-01 00:00:00.000000', $versionId]);
            if ($publish->rowCount() !== 1) {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_LEAVE_POLICY_PUBLISH_FAILED');
            }
            $versions[(string) $key] = $versionId;
        }
        return $versions;
    }

    /** @return array<string,array{id:int,period_id:int,persona_key:string,type_key:string}> */
    private function seedPermissionEvidence(
        array $rows,
        array $personas,
        array $permissionTypes,
        array $permissionPolicies,
        array $workflowVersions,
        array $assignments,
        int $actorId,
        array &$manifest
    ): array {
        $evidence = [];
        $insert = $this->db->prepare(
            'INSERT INTO staff_permission_requests
             (staff_user_id, permission_type_id, from_at, to_at, timezone,
              requested_minutes, reason, status, create_idempotency_key, request_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $periodInsert = $this->db->prepare(
            'INSERT INTO staff_permission_request_periods
             (request_id, period_key, period_from_at, period_to_at, requested_count, requested_minutes)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $submit = $this->db->prepare(
            'UPDATE staff_permission_requests
             SET status = ?, policy_version_id = ?, policy_snapshot = ?, workflow_version_id = ?,
                 assignment_id = ?, submitted_by = ?, submitted_at = ?, decided_at = ?,
                 submission_idempotency_key = ?, lock_version = lock_version + 1
             WHERE id = ? AND status = ?'
        );
        $decide = $this->db->prepare(
            "UPDATE staff_permission_requests
             SET status = 'approved', decided_at = ?, lock_version = lock_version + 1
             WHERE id = ? AND status = 'pending_approval'"
        );
        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $personaKey = (string) $row['persona_key'];
            $typeKey = (string) $row['type_key'];
            $from = $this->localInstant((string) $row['from_at']);
            $to = $this->localInstant((string) $row['to_at']);
            $minutes = $this->minutesBetween((string) $row['from_at'], (string) $row['to_at']);
            $createKey = 'staff-hr-acceptance:permission-create:' . $key;
            $insert->execute([
                $personas[$personaKey], $permissionTypes[$typeKey], $from, $to,
                'Africa/Cairo', $minutes, 'طلب إذن تجريبي معزول', 'draft',
                $createKey, $this->hashKey('permission-request:' . $key),
            ]);
            $requestId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_permission_requests', $requestId);
            $periodKey = substr($from, 0, 7);
            $periodInsert->execute([$requestId, $periodKey, $from, $to, $minutes]);
            $periodId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_permission_request_periods', $periodId);
            $status = (string) $row['status'];
            if ($status !== 'draft') {
                $submit->execute([
                    'pending_approval',
                    $permissionPolicies[$typeKey],
                    $this->json(['dataset' => StaffHrAcceptanceDataset::DATASET_ID, 'policy_key' => $typeKey]),
                    $workflowVersions['permission_request'],
                    $assignments[$personaKey],
                    $personas[$personaKey],
                    '2026-08-11 09:00:00.000000',
                    null,
                    'staff-hr-acceptance:permission-submit:' . $key,
                    $requestId,
                    'draft',
                ]);
                if ($submit->rowCount() !== 1) {
                    throw new RuntimeException('STAFF_HR_ACCEPTANCE_PERMISSION_SUBMIT_FAILED');
                }
                if ($status === 'approved') {
                    $decide->execute(['2026-08-11 10:00:00.000000', $requestId]);
                    if ($decide->rowCount() !== 1) {
                        throw new RuntimeException('STAFF_HR_ACCEPTANCE_PERMISSION_DECISION_FAILED');
                    }
                }
            }
            $evidence[$key] = [
                'id' => $requestId,
                'period_id' => $periodId,
                'persona_key' => $personaKey,
                'type_key' => $typeKey,
            ];
        }
        return $evidence;
    }

    private function seedPermissionLedgers(
        array $rows,
        array $personas,
        array $permissionTypes,
        array $evidence,
        int $actorId,
        array &$manifest
    ): void {
        $accountInsert = $this->db->prepare(
            'INSERT INTO staff_permission_quota_accounts
             (staff_user_id, permission_type_id, period_key, status,
              reserved_count, consumed_count, reserved_minutes, consumed_minutes, lock_version)
             VALUES (?, ?, ?, ?, 0, 0, 0, 0, 1)'
        );
        $movementInsert = $this->db->prepare(
            'INSERT INTO staff_permission_quota_movements
             (account_id, request_id, request_period_id, movement_type, count_delta,
              minutes_delta, quota_exception, idempotency_key, movement_hash, reason_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)'
        );
        $accountUpdate = $this->db->prepare(
            'UPDATE staff_permission_quota_accounts
             SET reserved_count = ?, consumed_count = ?, reserved_minutes = ?,
                 consumed_minutes = ?, lock_version = lock_version + 1
             WHERE id = ?'
        );
        foreach ($rows as $row) {
            $personaKey = (string) $row['persona_key'];
            $typeKey = (string) $row['type_key'];
            $request = $this->permissionEvidenceFor($evidence, $personaKey, $typeKey);
            $accountInsert->execute([
                $personas[$personaKey], $permissionTypes[$typeKey],
                (string) $row['period_key'], 'open',
            ]);
            $accountId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_permission_quota_accounts', $accountId);
            $count = (int) $row['consumed_count'];
            $minutes = (int) $row['consumed_minutes'];
            if ($count > 0 || $minutes > 0) {
                $movementKey = 'staff-hr-acceptance:permission-consume:' . (string) $row['key'];
                $movementInsert->execute([
                    $accountId, $request['id'], $request['period_id'], 'consume',
                    $count, $minutes, $movementKey,
                    $this->hashKey('permission-movement:' . (string) $row['key']),
                    'ACCEPTANCE_APPROVED', $actorId,
                ]);
                $manifest[] = $this->owned('staff_permission_quota_movements', (int) $this->db->lastInsertId());
            }
            $accountUpdate->execute([
                (int) $row['reserved_count'], $count,
                (int) $row['reserved_minutes'], $minutes, $accountId,
            ]);
        }
    }

    /** @return array<string,array{id:int,day_ids:list<int>,persona_key:string,type_key:string}> */
    private function seedLeaveEvidence(
        array $rows,
        array $personas,
        array $leaveTypes,
        array $leavePolicies,
        array $workflowVersions,
        array $assignments,
        int $actorId,
        array &$manifest
    ): array {
        $evidence = [];
        $insert = $this->db->prepare(
            'INSERT INTO staff_leave_requests
             (staff_user_id, leave_type_id, request_kind, from_at, to_at, timezone,
              requested_units, requested_minutes, reason, status,
              create_idempotency_key, request_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)'
        );
        $dayInsert = $this->db->prepare(
            'INSERT INTO staff_leave_request_days
             (request_id, work_date, day_kind, from_at, to_at, requested_units,
              requested_minutes, consumed_units, consumed_minutes,
              entitlement_period_key, allocation_key)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?)'
        );
        $submit = $this->db->prepare(
            'UPDATE staff_leave_requests
             SET status = ?, policy_version_id = ?, policy_snapshot = ?, workflow_version_id = ?,
                 assignment_id = ?, submitted_by = ?, submitted_at = ?, approved_at = ?,
                 decided_at = ?, submission_idempotency_key = ?, lock_version = lock_version + 1
             WHERE id = ? AND status = ?'
        );
        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $personaKey = (string) $row['persona_key'];
            $typeKey = (string) $row['type_key'];
            $from = $this->localInstant((string) $row['from_at']);
            $to = $this->localInstant((string) $row['to_at']);
            $units = (string) $row['requested_units'];
            $insert->execute([
                $personas[$personaKey], $leaveTypes[$typeKey], 'leave', $from, $to,
                'Africa/Cairo', $units, 'طلب إجازة تجريبي معزول', 'draft',
                'staff-hr-acceptance:leave-create:' . $key,
                $this->hashKey('leave-request:' . $key),
            ]);
            $requestId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_leave_requests', $requestId);
            $dayIds = [];
            $dayCount = max(1, (int) ceil((float) $units));
            $cursor = new DateTimeImmutable(substr($from, 0, 10), new DateTimeZone('Africa/Cairo'));
            for ($index = 0; $index < $dayCount; ++$index) {
                $workDate = $cursor->modify('+' . $index . ' days')->format('Y-m-d');
                $dayUnits = min(1.0, max(0.0, (float) $units - $index));
                $consumed = (string) $row['status'] === 'approved' ? $dayUnits : 0.0;
                $dayInsert->execute([
                    $requestId, $workDate, 'workday', $workDate . ' 00:00:00.000000',
                    $workDate . ' 23:59:59.999999', number_format($dayUnits, 3, '.', ''),
                    number_format($consumed, 3, '.', ''), 'CY-2026',
                    $this->hashKey('leave-day:' . $key . ':' . $workDate),
                ]);
                $dayId = (int) $this->db->lastInsertId();
                $dayIds[] = $dayId;
                $manifest[] = $this->owned('staff_leave_request_days', $dayId);
            }
            $status = (string) $row['status'];
            if ($status !== 'draft') {
                $approvedAt = $status === 'approved' ? '2026-08-11 10:05:00.000000' : null;
                $submit->execute([
                    $status,
                    $leavePolicies[$typeKey],
                    $this->json(['dataset' => StaffHrAcceptanceDataset::DATASET_ID, 'policy_key' => $typeKey]),
                    $workflowVersions['leave_request'],
                    $assignments[$personaKey],
                    $personas[$personaKey],
                    '2026-08-11 09:05:00.000000',
                    $approvedAt,
                    $approvedAt,
                    'staff-hr-acceptance:leave-submit:' . $key,
                    $requestId,
                    'draft',
                ]);
                if ($submit->rowCount() !== 1) {
                    throw new RuntimeException('STAFF_HR_ACCEPTANCE_LEAVE_SUBMIT_FAILED');
                }
            }
            $evidence[$key] = [
                'id' => $requestId,
                'day_ids' => $dayIds,
                'persona_key' => $personaKey,
                'type_key' => $typeKey,
            ];
        }
        return $evidence;
    }

    private function seedLeaveLedgers(
        array $rows,
        array $personas,
        array $leaveTypes,
        array $evidence,
        int $actorId,
        array &$manifest
    ): void {
        $accountInsert = $this->db->prepare(
            'INSERT INTO staff_leave_balance_accounts
             (staff_user_id, leave_type_id, entitlement_period_key, period_from, period_to,
              status, available_units, reserved_units, consumed_units, granted_units,
              expired_units, negative_balance_limit_units, lock_version)
             VALUES (?, ?, ?, ?, ?, ?, 0.000, 0.000, 0.000, 0.000, 0.000, 0.000, 1)'
        );
        $movementInsert = $this->db->prepare(
            'INSERT INTO staff_leave_balance_movements
             (account_id, leave_request_id, request_day_id, movement_type, units_delta,
              available_delta, reserved_delta, consumed_delta, source_type, source_id,
              logical_key, idempotency_key, movement_hash, reason_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $accountUpdate = $this->db->prepare(
            'UPDATE staff_leave_balance_accounts
             SET available_units = ?, reserved_units = ?, consumed_units = ?, granted_units = ?,
                 lock_version = lock_version + 1 WHERE id = ?'
        );
        foreach ($rows as $row) {
            $personaKey = (string) $row['persona_key'];
            $typeKey = (string) $row['type_key'];
            $request = $this->leaveEvidenceFor($evidence, $personaKey, $typeKey);
            $periodYear = preg_match('/^CY-(\d{4})$/D', (string) $row['period_key'], $periodMatch) === 1
                ? (int) $periodMatch[1]
                : 2026;
            $accountInsert->execute([
                $personas[$personaKey], $leaveTypes[$typeKey], (string) $row['period_key'],
                sprintf('%04d-01-01', $periodYear), sprintf('%04d-12-31', $periodYear), 'open',
            ]);
            $accountId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_leave_balance_accounts', $accountId);
            $granted = (float) $row['entitled_units'];
            $consumed = (float) $row['consumed_units'];
            $grantKey = $this->hashKey('leave-grant:' . (string) $row['key']);
            $movementInsert->execute([
                $accountId, null, null, 'grant', number_format($granted, 3, '.', ''),
                number_format($granted, 3, '.', ''), '0.000', '0.000',
                'acceptance_dataset', null, $grantKey,
                'staff-hr-acceptance:leave-grant:' . (string) $row['key'],
                $this->hashKey('leave-grant-movement:' . (string) $row['key']),
                'ACCEPTANCE_ENTITLEMENT', $actorId,
            ]);
            $manifest[] = $this->owned('staff_leave_balance_movements', (int) $this->db->lastInsertId());
            if ($consumed > 0) {
                $consumeKey = $this->hashKey('leave-consume:' . (string) $row['key']);
                $movementInsert->execute([
                    $accountId, $request['id'], null, 'consume', number_format(-$consumed, 3, '.', ''),
                    number_format(-$consumed, 3, '.', ''), '0.000', number_format($consumed, 3, '.', ''),
                    'staff_leave_request', $request['id'], $consumeKey,
                    'staff-hr-acceptance:leave-consume:' . (string) $row['key'],
                    $this->hashKey('leave-consume-movement:' . (string) $row['key']),
                    'ACCEPTANCE_APPROVED', $actorId,
                ]);
                $manifest[] = $this->owned('staff_leave_balance_movements', (int) $this->db->lastInsertId());
            }
            $available = $granted - $consumed - (float) $row['reserved_units'];
            $accountUpdate->execute([
                number_format($available, 3, '.', ''),
                (string) $row['reserved_units'],
                (string) $row['consumed_units'],
                (string) $row['entitled_units'],
                $accountId,
            ]);
        }
    }

    /** @return array<string,int> */
    private function seedBiometricEvidence(
        array $rows,
        array $personas,
        int $actorId,
        array &$manifest
    ): array {
        $method = $this->db->prepare(
            'INSERT INTO staff_attendance_entry_methods
             (code, name, method_type, requires_reason, requires_attachment,
              requires_review, allowed_scope, status, created_by)
             VALUES (?, ?, ?, 0, 0, 0, ?, ?, ?)'
        );
        $method->execute(['DEMO-BIOMETRIC', 'جهاز بصمة القبول التجريبي', 'biometric', 'acceptance_test', 'active', $actorId]);
        $methodId = (int) $this->db->lastInsertId();
        $manifest[] = $this->owned('staff_attendance_entry_methods', $methodId);

        $profile = $this->db->prepare('SELECT biometric_id FROM staff_profiles WHERE user_id = ? LIMIT 1');
        $mappingInsert = $this->db->prepare(
            'INSERT INTO staff_biometric_identity_mappings
             (device_id, biometric_identity, staff_user_id, valid_from, source, confirmed_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $mappings = [];
        foreach ($rows as $row) {
            $personaKey = $row['persona_key'] ?? null;
            if (!is_string($personaKey) || isset($mappings[$personaKey])) {
                continue;
            }
            $profile->execute([$personas[$personaKey]]);
            $identity = (string) $profile->fetchColumn();
            if ($identity === '') {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_BIOMETRIC_IDENTITY_MISSING');
            }
            $mappingInsert->execute([2099, $identity, $personas[$personaKey], '2026-01-01 00:00:00.000000', 'acceptance_dataset', $actorId]);
            $mappingId = (int) $this->db->lastInsertId();
            $mappings[$personaKey] = ['id' => $mappingId, 'identity' => $identity];
            $manifest[] = $this->owned('staff_biometric_identity_mappings', $mappingId);
        }

        $eventInsert = $this->db->prepare(
            'INSERT INTO staff_biometric_events
             (entry_method_id, device_id, external_event_key, idempotency_key,
              biometric_identity, identity_mapping_id, staff_user_id,
              device_event_at, received_at, device_timezone, normalized_event_at_utc,
              event_at_local, clock_offset_seconds, clock_status, event_type,
              raw_hash, link_status, link_reason, processing_order,
              recorded_by, review_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $events = [];
        foreach ($rows as $index => $row) {
            $key = (string) $row['key'];
            $personaKey = $row['persona_key'] ?? null;
            $matched = is_string($personaKey);
            $mapping = $matched ? $mappings[$personaKey] : null;
            $identity = $matched ? (string) $mapping['identity'] : 'DEMO-UNKNOWN-IDENTITY';
            $eventInsert->execute([
                $methodId, 2099, (string) $row['external_event_key'],
                'staff-hr-acceptance:biometric:' . $key,
                $identity,
                $matched ? (int) $mapping['id'] : null,
                $matched ? $personas[$personaKey] : null,
                $this->localInstant((string) $row['event_at']),
                $this->utcInstant((string) $row['event_at']),
                'Africa/Cairo',
                $this->utcInstant((string) $row['event_at']),
                $this->localInstant((string) $row['event_at']),
                0, 'trusted', (string) $row['event_type'],
                $this->hashKey('biometric-event:' . $key),
                $matched ? 'matched' : 'unmatched',
                $matched ? 'ACCEPTANCE_MAPPING' : 'ACCEPTANCE_UNKNOWN_IDENTITY',
                $index + 1, $actorId, 'not_required',
            ]);
            $eventId = (int) $this->db->lastInsertId();
            $events[$key] = $eventId;
            $manifest[] = $this->owned('staff_biometric_events', $eventId);
        }
        return $events;
    }

    private function seedAttendanceVersions(
        array $rows,
        array $personas,
        array $assignments,
        array $events,
        int $actorId,
        array &$manifest
    ): void {
        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $workDate = (string) $row['work_date'];
            $engine = 'acceptance-v1';
            $fingerprint = $this->hashKey('attendance-source:' . $key);
            $runInsert = $this->db->prepare(
                'INSERT INTO staff_attendance_runs
                 (engine_version, mode, range_from, range_to, cutoff_at, initiated_by,
                  status, source_fingerprint, idempotency_key, summary, started_at, finished_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $runInsert->execute([
                $engine, 'official', $workDate, $workDate, $workDate . ' 23:59:59.999999',
                $actorId, 'completed', $fingerprint,
                'staff-hr-acceptance:attendance-run:' . $key,
                $this->json(['dataset' => StaffHrAcceptanceDataset::DATASET_ID]),
                $workDate . ' 15:00:00.000000', $workDate . ' 15:01:00.000000',
            ]);
            $runId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_attendance_runs', $runId);
            $personaKey = (string) $row['persona_key'];
            $dayInsert = $this->db->prepare(
                'INSERT INTO staff_attendance_day_versions
                 (staff_user_id, work_date, version_no, run_id, assignment_id,
                  expected_start, expected_end, required_minutes, first_in, last_out,
                  worked_minutes, covered_late_minutes, covered_early_minutes,
                  mission_minutes, leave_minutes, late_minutes, early_leave_minutes,
                  missing_minutes, status, calculation_mode, engine_version,
                  source_fingerprint, is_official, calculated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 420, ?, ?, 420, 0, 0, 0, 0, ?, ?, 0, ?, ?, ?, ?, 0, ?)'
            );
            $dayInsert->execute([
                $personas[$personaKey], $workDate, (int) $row['version_no'], $runId,
                $assignments[$personaKey], $workDate . ' 07:30:00.000000',
                $workDate . ' 14:30:00.000000', $workDate . ' 07:30:00.000000',
                $workDate . ' 14:30:00.000000', (int) $row['late_minutes'],
                (int) $row['early_leave_minutes'], (string) $row['status'],
                'official', $engine, $fingerprint, $workDate . ' 15:01:00.000000',
            ]);
            $dayId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_attendance_day_versions', $dayId);
            $segment = $this->db->prepare(
                'INSERT INTO staff_attendance_segments
                 (day_version_id, sequence_no, segment_type, expected_start, expected_end,
                  actual_start, actual_end, required_minutes, worked_minutes,
                  covered_minutes, missing_minutes, entry_event_id, exit_event_id, status)
                 VALUES (?, 1, ?, ?, ?, ?, ?, 420, 420, 0, 0, ?, ?, ?)'
            );
            $segment->execute([
                $dayId, 'work', $workDate . ' 07:30:00.000000', $workDate . ' 14:30:00.000000',
                $workDate . ' 07:30:00.000000', $workDate . ' 14:30:00.000000',
                $events['demo_worker_in'] ?? null, $events['demo_worker_out'] ?? null, 'matched',
            ]);
            $manifest[] = $this->owned('staff_attendance_segments', (int) $this->db->lastInsertId());
            if ((bool) $row['is_official']) {
                $publish = $this->db->prepare(
                    'UPDATE staff_attendance_day_versions
                     SET is_official = 1, officialized_by = ?, officialized_at = ?
                     WHERE id = ? AND is_official = 0'
                );
                $publish->execute([$actorId, $workDate . ' 15:02:00.000000', $dayId]);
                if ($publish->rowCount() !== 1) {
                    throw new RuntimeException('STAFF_HR_ACCEPTANCE_ATTENDANCE_PUBLISH_FAILED');
                }
            }
        }
    }

    private function seedDisciplineEvidence(
        array $cases,
        array $appeals,
        array $personas,
        int $actorId,
        array &$manifest
    ): void {
        $caseIds = [];
        $decisionIds = [];
        foreach ($cases as $row) {
            $key = (string) $row['key'];
            $subjectId = $personas[(string) $row['persona_key']];
            $incidentInsert = $this->db->prepare(
                'INSERT INTO staff_discipline_incidents
                 (incident_no, subject_staff_user_id, reported_by_user_id, occurred_at,
                  reported_at, source_resource_type, source_reference_snapshot,
                  classification, confidentiality_level, description, status,
                  create_idempotency_key, incident_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $incidentInsert->execute([
                'DEMO-INCIDENT-' . substr($this->hashKey($key), 0, 10), $subjectId,
                $personas['hr_manager'], '2026-08-05 09:00:00.000000',
                '2026-08-05 10:00:00.000000', 'staff_hr_acceptance_dataset',
                $this->json(['dataset' => StaffHrAcceptanceDataset::DATASET_ID]),
                'acceptance_demo', (string) $row['confidentiality_level'],
                'واقعة تأديبية تجريبية معزولة', 'reported',
                $this->hashKey('discipline-incident-key:' . $key),
                $this->hashKey('discipline-incident:' . $key),
            ]);
            $incidentId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_discipline_incidents', $incidentId);
            $caseInsert = $this->db->prepare(
                'INSERT INTO staff_discipline_cases
                 (case_no, incident_id, subject_staff_user_id, classification,
                  confidentiality_level, status, opened_by_user_id, opened_at,
                  closed_by_user_id, closed_at,
                  create_idempotency_key, case_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $caseInsert->execute([
                (string) $row['case_no'], $incidentId, $subjectId, 'acceptance_demo',
                (string) $row['confidentiality_level'], (string) $row['status'],
                $personas['hr_manager'], '2026-08-05 10:05:00.000000',
                (string)$row['status'] === 'closed' ? $personas['administrative_manager'] : null,
                (string)$row['status'] === 'closed' ? '2026-08-09 10:00:00.000000' : null,
                $this->hashKey('discipline-case-key:' . $key),
                $this->hashKey('discipline-case:' . $key),
            ]);
            $caseId = (int) $this->db->lastInsertId();
            $caseIds[$key] = $caseId;
            $manifest[] = $this->owned('staff_discipline_cases', $caseId);
            $decisionInsert = $this->db->prepare(
                'INSERT INTO staff_discipline_decisions
                 (case_id, decision_no, decision_sequence, sanction_code, status,
                  prepared_by_user_id, decided_by_user_id, decided_at, issued_at,
                  effective_from, decision_reason, policy_snapshot, notification_status,
                  financial_effect_requested, decision_hash, idempotency_key)
                 VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
            );
            $decisionInsert->execute([
                $caseId, 'DEMO-DECISION-' . substr($this->hashKey($key), 0, 10),
                'WRITTEN_WARNING', 'issued', $personas['hr_manager'],
                $personas['administrative_manager'], '2026-08-07 10:00:00.000000',
                '2026-08-07 10:05:00.000000', '2026-08-08 00:00:00.000000',
                'قرار تأديبي تجريبي غير مالي',
                $this->json(['dataset' => StaffHrAcceptanceDataset::DATASET_ID]),
                'not_required', $this->hashKey('discipline-decision:' . $key),
                $this->hashKey('discipline-decision-key:' . $key),
            ]);
            $decisionId = (int) $this->db->lastInsertId();
            $decisionIds[$key] = $decisionId;
            $manifest[] = $this->owned('staff_discipline_decisions', $decisionId);
            $evidenceInsert = $this->db->prepare(
                'INSERT INTO staff_discipline_evidence
                 (case_id, evidence_kind, source_resource_type, source_resource_id,
                  chain_hash, evidence_summary, collected_by_user_id, collected_at,
                  status, idempotency_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $evidenceInsert->execute([
                $caseId, 'attendance_reference', 'staff_attendance_day_version', 1,
                $this->hashKey('discipline-evidence-chain:' . $key),
                'دليل تجريبي جديد محفوظ كسجل مرجعي فقط', $personas['hr_manager'],
                '2026-08-08 08:00:00.000000', 'verified',
                $this->hashKey('discipline-evidence-key:' . $key),
            ]);
            $manifest[] = $this->owned('staff_discipline_evidence', (int) $this->db->lastInsertId());
        }
        foreach ($appeals as $row) {
            $caseKey = (string) $row['case_key'];
            $appealInsert = $this->db->prepare(
                'INSERT INTO staff_discipline_appeals
                 (case_id, decision_id, appellant_user_id, reviewer_user_id, status,
                  submitted_at, due_at, appeal_reason, suspends_execution,
                  idempotency_key, appeal_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
            );
            $appealInsert->execute([
                $caseIds[$caseKey], $decisionIds[$caseKey],
                $personas[(string) $row['appellant_key']], $personas['direct_manager'],
                (string) $row['status'], '2026-08-08 09:00:00.000000',
                '2026-08-22 09:00:00.000000', 'تظلم تجريبي معزول',
                $this->hashKey('discipline-appeal-key:' . (string) $row['key']),
                $this->hashKey('discipline-appeal:' . (string) $row['key']),
            ]);
            $manifest[] = $this->owned('staff_discipline_appeals', (int) $this->db->lastInsertId());
        }
    }

    private function seedErtaqEvidence(
        array $rows,
        array $personas,
        array $units,
        int $actorId,
        array &$manifest
    ): void {
        $ticketInsert = $this->db->prepare(
            'INSERT INTO staff_ertaq_tickets
             (ticket_no, requester_user_id, type, classification, confidentiality_level,
              priority, risk_level, subject, status, urgent_route_id,
              create_idempotency_key, ticket_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $urgentInsert = $this->db->prepare(
            'INSERT INTO staff_ertaq_urgent_events
             (ticket_id, risk_type, routed_team_id, routed_by_user_id,
              route_snapshot, conflict_exclusion_snapshot, status,
              idempotency_key, urgent_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $urgent = (string) $row['risk_level'] === 'immediate';
            $routeId = $urgent ? $units['demo_admin'] : null;
            $ticketInsert->execute([
                (string) $row['ticket_no'], $personas[(string) $row['requester_key']],
                (string) $row['type'], 'acceptance_demo',
                (string) $row['confidentiality_level'], (string) $row['priority'],
                (string) $row['risk_level'], 'تذكرة ارتق تجريبية: ' . $key,
                (string) $row['status'], $routeId,
                $this->hashKey('ertaq-ticket-key:' . $key),
                $this->hashKey('ertaq-ticket:' . $key),
            ]);
            $ticketId = (int) $this->db->lastInsertId();
            $manifest[] = $this->owned('staff_ertaq_tickets', $ticketId);
            if ($urgent) {
                $urgentInsert->execute([
                    $ticketId, 'immediate_protection', $routeId, $actorId,
                    $this->json(['dataset' => StaffHrAcceptanceDataset::DATASET_ID, 'route' => 'demo_admin']),
                    $this->json(['excluded_requester' => true]), 'routed',
                    $this->hashKey('ertaq-urgent-key:' . $key),
                    $this->hashKey('ertaq-urgent:' . $key),
                ]);
                $manifest[] = $this->owned('staff_ertaq_urgent_events', (int) $this->db->lastInsertId());
            }
        }
    }

    /** @return array{id:int,period_id:int,persona_key:string,type_key:string} */
    private function permissionEvidenceFor(array $evidence, string $personaKey, string $typeKey): array
    {
        foreach ($evidence as $row) {
            if ((string) $row['persona_key'] === $personaKey && (string) $row['type_key'] === $typeKey) {
                return $row;
            }
        }
        throw new RuntimeException('STAFF_HR_ACCEPTANCE_PERMISSION_LEDGER_REQUEST_MISSING');
    }

    /** @return array{id:int,day_ids:list<int>,persona_key:string,type_key:string} */
    private function leaveEvidenceFor(array $evidence, string $personaKey, string $typeKey): array
    {
        foreach ($evidence as $row) {
            if ((string) $row['persona_key'] === $personaKey && (string) $row['type_key'] === $typeKey) {
                return $row;
            }
        }
        throw new RuntimeException('STAFF_HR_ACCEPTANCE_LEAVE_LEDGER_REQUEST_MISSING');
    }

    /** @param list<array<string,mixed>> $owned @return array{reversed:int,checksum:string} */
    private function deleteOwned(array $owned): array
    {
        $grouped = [];
        foreach ($owned as $row) {
            $type = (string) ($row['resource_type'] ?? '');
            $id = (int) ($row['resource_id'] ?? 0);
            if (!in_array($type, self::DELETE_ORDER, true) || $id <= 0) {
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_MANIFEST_RESOURCE_INVALID');
            }
            $grouped[$type][] = $id;
        }
        $deleted = [];
        foreach (self::DELETE_ORDER as $type) {
            $ids = array_values(array_unique(array_map('intval', $grouped[$type] ?? [])));
            if ($type === 'staff_org_units') {
                rsort($ids, SORT_NUMERIC);
            }
            foreach ($ids as $id) {
                $this->assertOwnedRow($type, $id);
                $statement = $this->db->prepare("DELETE FROM `{$type}` WHERE id = ?");
                $statement->execute([$id]);
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('STAFF_HR_ACCEPTANCE_RESTORE_ROW_STALE');
                }
                $deleted[] = $type . ':' . $id;
            }
        }
        sort($deleted, SORT_STRING);
        return ['reversed' => count($deleted), 'checksum' => hash('sha256', json_encode($deleted, JSON_THROW_ON_ERROR))];
    }

    private function assertOwnedRow(string $table, int $id): void
    {
        $rules = [
            'users' => ["is_test_account = 1 AND email LIKE 'demo.staffhr.%@example.test'", []],
            'staff_roles' => ["role_key = 'staff_hr_override_manager'", []],
            'staff_profiles' => ["notes = 'STAFF_HR_ACCEPTANCE_DATASET'", []],
            'user_role_assignments' => ["user_id IN (SELECT id FROM users WHERE is_test_account = 1 AND email LIKE 'demo.staffhr.%@example.test')", []],
            'staff_org_units' => ["code LIKE 'DEMO-%'", []],
            'staff_job_titles' => ["code LIKE 'DEMO-%'", []],
            'staff_policy_groups' => ["code LIKE 'DEMO-%'", []],
            'staff_assignments' => ["source_ref LIKE 'acceptance:staff_hr_acceptance_v1:%'", []],
            'staff_manager_assignments' => ["source = 'acceptance_dataset'", []],
            'staff_policy_group_memberships' => ["source = 'acceptance_dataset'", []],
            'staff_schedule_policies' => ["code LIKE 'DEMO-SCHEDULE-%'", []],
            'staff_schedule_policy_versions' => [
                "policy_id IN (SELECT id FROM staff_schedule_policies WHERE code LIKE 'DEMO-SCHEDULE-%')",
                [],
            ],
            'staff_schedule_days' => [
                "policy_version_id IN (
                    SELECT version_row.id FROM staff_schedule_policy_versions version_row
                    JOIN staff_schedule_policies policy_row ON policy_row.id = version_row.policy_id
                    WHERE policy_row.code LIKE 'DEMO-SCHEDULE-%'
                )",
                [],
            ],
            'staff_schedule_scopes' => [
                "policy_version_id IN (
                    SELECT version_row.id FROM staff_schedule_policy_versions version_row
                    JOIN staff_schedule_policies policy_row ON policy_row.id = version_row.policy_id
                    WHERE policy_row.code LIKE 'DEMO-SCHEDULE-%'
                )",
                [],
            ],
            'staff_permission_types' => ["code LIKE 'DEMO-%'", []],
            'staff_leave_types' => ["code LIKE 'DEMO-%'", []],
            'staff_approval_workflows' => ["code LIKE 'DEMO-WORKFLOW-%'", []],
            'staff_approval_workflow_versions' => [
                "workflow_id IN (SELECT id FROM staff_approval_workflows WHERE code LIKE 'DEMO-WORKFLOW-%')",
                [],
            ],
            'staff_approval_stages' => [
                "workflow_version_id IN (
                    SELECT version_row.id FROM staff_approval_workflow_versions version_row
                    JOIN staff_approval_workflows workflow_row ON workflow_row.id = version_row.workflow_id
                    WHERE workflow_row.code LIKE 'DEMO-WORKFLOW-%'
                )",
                [],
            ],
            'staff_permission_policy_versions' => [
                "permission_type_id IN (SELECT id FROM staff_permission_types WHERE code LIKE 'DEMO-%')",
                [],
            ],
            'staff_permission_policy_scopes' => [
                "policy_version_id IN (
                    SELECT policy_row.id FROM staff_permission_policy_versions policy_row
                    JOIN staff_permission_types type_row ON type_row.id = policy_row.permission_type_id
                    WHERE type_row.code LIKE 'DEMO-%'
                )",
                [],
            ],
            'staff_permission_requests' => ["create_idempotency_key LIKE 'staff-hr-acceptance:permission-create:%'", []],
            'staff_permission_request_periods' => [
                "request_id IN (SELECT id FROM staff_permission_requests WHERE create_idempotency_key LIKE 'staff-hr-acceptance:permission-create:%')",
                [],
            ],
            'staff_permission_quota_accounts' => [
                "staff_user_id IN (SELECT id FROM users WHERE is_test_account = 1 AND email LIKE 'demo.staffhr.%@example.test')
                 AND permission_type_id IN (SELECT id FROM staff_permission_types WHERE code LIKE 'DEMO-%')",
                [],
            ],
            'staff_permission_quota_movements' => ["idempotency_key LIKE 'staff-hr-acceptance:permission-%'", []],
            'staff_leave_policy_versions' => [
                "leave_type_id IN (SELECT id FROM staff_leave_types WHERE code LIKE 'DEMO-%')",
                [],
            ],
            'staff_leave_policy_scopes' => [
                "policy_version_id IN (
                    SELECT policy_row.id FROM staff_leave_policy_versions policy_row
                    JOIN staff_leave_types type_row ON type_row.id = policy_row.leave_type_id
                    WHERE type_row.code LIKE 'DEMO-%'
                )",
                [],
            ],
            'staff_leave_policy_blackouts' => [
                "policy_version_id IN (
                    SELECT policy_row.id FROM staff_leave_policy_versions policy_row
                    JOIN staff_leave_types type_row ON type_row.id = policy_row.leave_type_id
                    WHERE type_row.code LIKE 'DEMO-%'
                )",
                [],
            ],
            'staff_leave_requests' => ["create_idempotency_key LIKE 'staff-hr-acceptance:leave-create:%'", []],
            'staff_leave_request_days' => [
                "request_id IN (SELECT id FROM staff_leave_requests WHERE create_idempotency_key LIKE 'staff-hr-acceptance:leave-create:%')",
                [],
            ],
            'staff_leave_balance_accounts' => [
                "staff_user_id IN (SELECT id FROM users WHERE is_test_account = 1 AND email LIKE 'demo.staffhr.%@example.test')
                 AND leave_type_id IN (SELECT id FROM staff_leave_types WHERE code LIKE 'DEMO-%')",
                [],
            ],
            'staff_leave_balance_movements' => ["idempotency_key LIKE 'staff-hr-acceptance:leave-%'", []],
            'staff_attendance_entry_methods' => ["code = 'DEMO-BIOMETRIC'", []],
            'staff_biometric_identity_mappings' => ["source = 'acceptance_dataset'", []],
            'staff_biometric_events' => ["idempotency_key LIKE 'staff-hr-acceptance:biometric:%'", []],
            'staff_attendance_runs' => ["idempotency_key LIKE 'staff-hr-acceptance:attendance-run:%'", []],
            'staff_attendance_day_versions' => [
                "run_id IN (SELECT id FROM staff_attendance_runs WHERE idempotency_key LIKE 'staff-hr-acceptance:attendance-run:%')",
                [],
            ],
            'staff_attendance_segments' => [
                "day_version_id IN (
                    SELECT day_row.id FROM staff_attendance_day_versions day_row
                    JOIN staff_attendance_runs run_row ON run_row.id = day_row.run_id
                    WHERE run_row.idempotency_key LIKE 'staff-hr-acceptance:attendance-run:%'
                )",
                [],
            ],
            'staff_discipline_incidents' => ["source_resource_type = 'staff_hr_acceptance_dataset'", []],
            'staff_discipline_cases' => [
                "incident_id IN (SELECT id FROM staff_discipline_incidents WHERE source_resource_type = 'staff_hr_acceptance_dataset')",
                [],
            ],
            'staff_discipline_decisions' => ["decision_no LIKE 'DEMO-DECISION-%'", []],
            'staff_discipline_appeals' => [
                "decision_id IN (SELECT id FROM staff_discipline_decisions WHERE decision_no LIKE 'DEMO-DECISION-%')",
                [],
            ],
            'staff_discipline_evidence' => [
                "case_id IN (SELECT id FROM staff_discipline_cases WHERE case_no LIKE 'DEMO-CASE-%')",
                [],
            ],
            'staff_discipline_interim_measures' => [
                "case_id IN (SELECT id FROM staff_discipline_cases WHERE case_no LIKE 'DEMO-CASE-%')",
                [],
            ],
            'staff_discipline_reopen_events' => [
                "case_id IN (SELECT id FROM staff_discipline_cases WHERE case_no LIKE 'DEMO-CASE-%')",
                [],
            ],
            'staff_ertaq_tickets' => ["ticket_no LIKE 'DEMO-ERTAQ-%'", []],
            'staff_ertaq_urgent_events' => [
                "ticket_id IN (SELECT id FROM staff_ertaq_tickets WHERE ticket_no LIKE 'DEMO-ERTAQ-%')",
                [],
            ],
            'recovery_backups' => [
                "database_name = DATABASE()
                 AND backup_key REGEXP '^[a-f0-9]{32}$'
                 AND package_path LIKE 'storage/test-runtime/staff-hr-acceptance-backups/%'",
                [],
            ],
        ];
        if (!isset($rules[$table])) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_RESTORE_TABLE_FORBIDDEN');
        }
        $statement = $this->db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE id = ? AND {$rules[$table][0]}");
        $statement->execute([$id]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_RESTORE_OWNERSHIP_MISMATCH');
        }
    }

    /** @return array<string,mixed> */
    private function replayedSeed(array $batch, array $dataset): array
    {
        if (!in_array((string) $batch['status'], ['completed', 'completed_with_exceptions'], true)
            || !hash_equals((string) $batch['checksum'], (string) $dataset['meta']['checksum'])) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_EXISTING_BATCH_CONFLICT');
        }
        $manifest = $this->decodeManifest((string) $batch['manifest_json']);
        foreach ($manifest as $row) {
            $this->assertOwnedRow((string) $row['resource_type'], (int) $row['resource_id']);
        }
        $personas = [];
        $baselineBackupId = 0;
        $baselineBackupKey = '';
        foreach ($manifest as $row) {
            if ((string) ($row['resource_type'] ?? '') !== 'recovery_backups') {
                continue;
            }
            $baselineBackupId = (int) ($row['resource_id'] ?? 0);
            $baseline = $this->rowById('recovery_backups', $baselineBackupId);
            $baselineBackupKey = (string) ($baseline['backup_key'] ?? '');
            break;
        }
        $statement = $this->db->prepare('SELECT id FROM users WHERE email = ? AND is_test_account = 1');
        foreach ((array) $dataset['personas'] as $persona) {
            $statement->execute([(string) $persona['email']]);
            $personas[(string) $persona['key']] = (int) $statement->fetchColumn();
        }
        return [
            'dataset_id' => StaffHrAcceptanceDataset::DATASET_ID,
            'checksum' => (string) $dataset['meta']['checksum'],
            'window_id' => (int) $batch['cutover_window_id'],
            'batch_id' => (int) $batch['id'],
            'owned_count' => count($manifest),
            'persona_ids' => $personas,
            'baseline_backup_id' => $baselineBackupId,
            'baseline_backup_key' => $baselineBackupKey,
            'replayed' => true,
        ];
    }

    /** @return array<string,mixed>|null */
    private function batchByKey(string $key): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_hr_migration_batches WHERE idempotency_key = ? LIMIT 1');
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function activeActor(int $actorId): array
    {
        $statement = $this->db->prepare("SELECT id, name, role FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $statement->execute([$actorId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_RESTORE_ACTOR_INVALID');
        }
        return $row;
    }

    private function setAuditActor(int $id, string $name, string $role): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['user_id'] = $id;
        $_SESSION['name'] = $name;
        $_SESSION['role'] = $role;
    }

    private function findId(string $table, string $column, string $value): ?int
    {
        $statement = $this->db->prepare("SELECT id FROM `{$table}` WHERE `{$column}` = ? LIMIT 1");
        $statement->execute([$value]);
        $id = (int) $statement->fetchColumn();
        return $id > 0 ? $id : null;
    }

    /** @return array{resource_type:string,resource_id:int} */
    private function owned(string $type, int $id): array
    {
        return ['resource_type' => $type, 'resource_id' => $id];
    }

    /** @return list<array<string,mixed>> */
    private function decodeManifest(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_MANIFEST_CORRUPT');
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    private function manifestContains(array $manifest, string $type, int $id): bool
    {
        foreach ($manifest as $row) {
            if ((string) ($row['resource_type'] ?? '') === $type && (int) ($row['resource_id'] ?? 0) === $id) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function rowById(string $table, int $id): array
    {
        if (!in_array($table, self::REQUIRED_TABLES, true) || $id <= 0) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_ROW_LOOKUP_FORBIDDEN');
        }
        $statement = $this->db->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_OWNED_ROW_MISSING');
        }
        return $row;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function hashKey(string $value): string
    {
        return hash('sha256', StaffHrAcceptanceDataset::DATASET_ID . ':' . $value);
    }

    private function localInstant(string $value): string
    {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Africa/Cairo'))
            ->format('Y-m-d H:i:s.u');
    }

    private function utcInstant(string $value): string
    {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private function minutesBetween(string $from, string $to): int
    {
        $seconds = (new DateTimeImmutable($to))->getTimestamp() - (new DateTimeImmutable($from))->getTimestamp();
        if ($seconds <= 0 || $seconds % 60 !== 0) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_TIME_WINDOW_INVALID');
        }
        return (int) ($seconds / 60);
    }

    private function windowKey(string $checksum): string
    {
        return 'staff-hr-acceptance-window:' . $checksum;
    }

    private function batchKey(string $checksum): string
    {
        return 'staff-hr-acceptance-seed:' . $checksum;
    }
}
