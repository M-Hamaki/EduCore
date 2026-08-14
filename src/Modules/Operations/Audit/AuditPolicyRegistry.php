<?php

declare(strict_types=1);

namespace EduCore\Modules\Operations\Audit;

final class AuditPolicyRegistry
{
    private const SENSITIVE_FIELDS = [
        'password', 'password_hash', 'password_encrypted', 'remember_token',
        'access_token', 'refresh_token', 'api_key', 'secret', 'csrf_token',
        'session_id', 'private_key', 'client_secret',
        'ciphertext', 'encrypted_payload', 'encryption_iv', 'encryption_tag',
        'diagnosis', 'medical_notes', 'medical_reason', 'health_details',
        'health_data', 'certificate_details', 'psychological_notes',
    ];

    /**
     * Fields that are sensitive only in a specific HR resource family.
     * Prefix matching deliberately supports both table names and singular
     * entity types used by AuditService callers.
     */
    private const ENTITY_SENSITIVE_FIELDS = [
        'staff_ertaq_' => [
            'subject', 'body', 'body_cipher_or_text', 'message', 'description',
            'requester_identity', 'identity_details', 'withdrawal_reason',
            'resolution_summary', 'closure_reason', 'reopen_reason',
            'satisfaction_comment', 'assignment_reason', 'end_reason',
            'removal_reason', 'link_reason', 'decision_reason',
            'route_snapshot', 'conflict_exclusion_snapshot',
            'escalation_snapshot',
        ],
        'staff_discipline_' => [
            'subject', 'description', 'allegation', 'allegations', 'statement',
            'testimony', 'findings', 'investigation_notes', 'evidence_summary',
            'decision_reason', 'appeal_reason', 'medical_context',
            'storage_ref', 'original_name', 'mime_type', 'content_sha256',
            'policy_snapshot', 'access_effect', 'execution_payload', 'payload_json',
            'reopen_reason', 'cancellation_reason', 'conflict_declaration',
            'suspension_reason', 'outcome_reason', 'resolution_reason',
        ],
        'staff_leave_' => [
            'reason', 'reason_details', 'medical_context', 'doctor_notes',
            'certificate_reference', 'medical_document_ref',
            'supporting_document_ref', 'attachment_summary', 'return_notes',
            'original_name', 'storage_ref', 'detected_mime', 'sha256',
            'policy_snapshot', 'staffing_override_reason', 'decision_reason',
            'assessment_snapshot',
        ],
        'staff_permission_requests' => [
            'reason', 'custom_label', 'attachment_ref', 'policy_snapshot',
            'quota_exception_reason', 'reason_details', 'other_reason_text',
            'medical_context',
        ],
        'staff_resource_attachments' => [
            'original_name', 'storage_ref', 'storage_path', 'private_path', 'content',
        ],
        'staff_external_effects' => [
            'payload', 'request_payload', 'response_payload', 'error_details',
        ],
        'staff_notification_' => [
            'neutral_text', 'route', 'metadata', 'recipient_ids',
        ],
        'staff_schedule_command_receipts' => [
            'result_json', 'result', 'request_payload', 'response_payload',
        ],
        'staff_biometric_import_batches' => [
            'error_summary',
        ],
        'staff_biometric_identity_mappings' => [
            'biometric_identity', 'retired_reason',
        ],
        'staff_credential_' => [
            'credential_key', 'title', 'issuer', 'attachment_id', 'payload_hash',
            'idempotency_key',
        ],
        'staff_organization_correction_' => [
            'reason_text', 'reason_hash', 'impact_snapshot_json', 'impact_snapshot_hash',
            'payload_hash', 'idempotency_key', 'comment_hash', 'decision_hash',
            'source_snapshot_hash', 'impact_key',
        ],
        'staff_biometric_events' => [
            'biometric_identity', 'raw_payload_ref', 'link_reason',
            'reason_text', 'attachment_ref',
        ],
        'staff_attendance_reason_lines' => [
            'explanation', 'metadata',
        ],
        'staff_attendance_adjustments' => [
            'reason', 'proposed_values', 'resolution_comment',
        ],
        'staff_attendance_period_change_requests' => [
            'source_fingerprint', 'request_hash', 'change_fingerprint',
            'review_comment_hash', 'decision_hash', 'decision_idempotency_key',
        ],
        'staff_assignment_legacy_links' => [
            'legacy_source_key', 'source_payload_hash', 'decision_idempotency_key',
        ],
    ];

    private const UNDOABLE_TABLES = [
        'users', 'student_profiles', 'staff_profiles', 'student_guardians',
        'student_siblings', 'student_kinships', 'classes', 'grades', 'stages',
        'subjects', 'buses', 'bus_staff', 'school_emails', 'attendance', 'evaluations',
        'evaluation_types', 'fee_structure', 'fee_payments', 'locations',
        'notifications', 'teacher_subjects', 'user_page_permissions',
        'staff_roles', 'staff_role_pages',
        'staff_disciplinary', 'staff_shift_overrides',
        'activities',
        'external_teachers',
        'student_enrollments',
        'student_external_transfers',
        'assessment_student_locks',
        'student_marks',
        'kinship_types',
        'staff_permissions',
        'staff_leaves',
        'settings',
        'staff_status_history',
        'staff_job_movements',
        'user_services',
        'lesson_ppt_templates',
        'academic_years',
        'student_bus_assignments',
        'student_fee_balances_history',
        'assessment_components',
        'assessment_component_week_rules',
        'assessment_windows',
        'published_reports',
        'published_report_details',
        'report_windows',
        'canva_templates',
        'user_class_access',
        'specialist_classes',
        'specialist_grade_assignments',
        'specialist_class_assignments',
        'staff_grade_assignments',
        'staff_class_assignments',
        'user_role_assignments',
        'student_transfers',
        'academic_terms',
        'academic_months',
        'academic_weeks',
        'subject_grade_assignments',
        'assessment_schemes',
        // Grouped assessment plans are created/undone atomically under one batch.
        'assessment_scheme_families',
        'assessment_scheme_scopes',
        'assessment_annual_policies',
        'assessment_annual_policy_terms',
        'grade_promotion_rules',
        // Finance configuration tables (undoable — drafts/settings):
        'finance_charge_types',
        'finance_periods',
        'finance_cashboxes',
        'finance_bank_accounts',
        'finance_receipt_number_sequences',
        'finance_import_batches',
        'finance_fee_plans',
        'finance_fee_plan_versions',
        'finance_fee_plan_installments',
        'finance_student_accounts',
        'finance_student_contracts',
        'finance_charge_installments',
        'finance_discount_rules',
        'finance_discount_awards',
        'payroll_components',
        'payroll_periods',
        'accounting_accounts',
        'accounting_cost_centers',
        'accounting_account_mapping_headers',
        'accounting_account_mapping_lines',
        'accounting_control_accounts',
        'finance_budgets',
        'finance_budget_versions',
        'finance_bus_fee_schedules',
    ];

    private const REGISTERED_NON_UNDOABLE_TABLES = [
        'finance_subledger_accounts',
        'recovery_backups',
        'academic_year_rollover_runs',
        'academic_year_rollover_items',
        'student_promotion_decisions',
        'class_rollover_mappings',
        'student_change_requests',
        'ai_lessons',
        // Migration review rows are operational follow-up records, not business
        // data restored by an administrator undo action.
        'assessment_scheme_migration_reviews',
        // Finance append-only / detail tables (tracked but NOT directly undoable):
        'finance_subledger_lines',
        'accounting_journal_lines',
        'finance_voucher_lines',
        'payroll_item_components',
        'staff_compensation_contract_components',
        'staff_advance_installments',
        'finance_discount_applications',
        'finance_budget_lines',
        'finance_cashbox_settlements',
        'finance_import_rows',
        'finance_approval_requests',
        'finance_legacy_compatibility_mappings',
        // Integrated staff-affairs resources are effective-dated, append-only,
        // workflow-owned, or corrected by explicit reversal/versioning. A
        // table-level undo engine cannot safely decide their row state.
        'staff_org_units',
        'staff_job_titles',
        'staff_assignments',
        'staff_assignment_legacy_links',
        'staff_manager_assignments',
        'staff_policy_groups',
        'staff_policy_group_memberships',
        'staff_delegations',
        'staff_policy_definitions',
        'staff_policy_versions',
        'staff_policy_scopes',
        'staff_schedule_policies',
        'staff_schedule_policy_versions',
        'staff_schedule_days',
        'staff_schedule_segments',
        'staff_schedule_scopes',
        'staff_calendar_exceptions',
        'staff_schedule_change_requests',
        'staff_schedule_command_receipts',
        'staff_schedule_participant_locks',
        'staff_attendance_entry_methods',
        'staff_biometric_import_batches',
        'staff_biometric_identity_mappings',
        'staff_biometric_events',
        'staff_attendance_runs',
        'staff_attendance_day_versions',
        'staff_attendance_segments',
        'staff_attendance_reason_lines',
        'staff_attendance_adjustments',
        'staff_attendance_periods',
        'staff_attendance_period_change_requests',
        'staff_attendance_report_projection_runs',
        'staff_attendance_report_aggregates',
        'staff_permission_types',
        'staff_permission_policy_versions',
        'staff_permission_policy_scopes',
        'staff_permission_requests',
        'staff_permission_request_periods',
        'staff_permission_quota_accounts',
        'staff_permission_quota_movements',
        'staff_approval_workflows',
        'staff_approval_workflow_versions',
        'staff_approval_stages',
        'staff_approval_instances',
        'staff_approval_steps',
        'staff_approval_assignees',
        'staff_approval_decisions',
        'staff_approval_escalation_events',
        'staff_credential_records',
        'staff_organization_corrections',
        'staff_organization_correction_decisions',
        'staff_organization_correction_impacts',
        'staff_leave_types',
        'staff_leave_policy_versions',
        'staff_leave_policy_scopes',
        'staff_leave_policy_blackouts',
        'staff_leave_requests',
        'staff_leave_staffing_overrides',
        'staff_leave_request_days',
        'staff_leave_request_attachments',
        'staff_leave_balance_accounts',
        'staff_leave_balance_movements',
        'staff_return_to_work_events',
        'staff_discipline_incidents',
        'staff_discipline_cases',
        'staff_discipline_case_parties',
        'staff_discipline_investigations',
        'staff_discipline_evidence',
        'staff_discipline_interim_measures',
        'staff_discipline_decisions',
        'staff_discipline_appeals',
        'staff_discipline_executions',
        'staff_discipline_finance_effects',
        'staff_discipline_reopen_events',
        'staff_ertaq_tickets',
        'staff_ertaq_messages',
        'staff_ertaq_assignments',
        'staff_ertaq_watchers',
        'staff_ertaq_sla_events',
        'staff_ertaq_parties',
        'staff_ertaq_ticket_links',
        'staff_ertaq_urgent_events',
        'staff_ertaq_withdrawal_events',
        'staff_resource_attachments',
        'user_notification_inbox',
        'notification_outbox',
        'staff_external_effects',
        'staff_hr_migration_batches',
        'staff_hr_migration_exceptions',
        'staff_hr_cutover_windows',
    ];

    private const REVERSAL_ONLY_TABLES = [
        'fee_payments',
        // Finance posted movements (reversal-only — never hard-delete):
        'finance_subledger_transactions',
        'finance_receipts',
        'finance_payment_allocations',
        'finance_unapplied_credits',
        'finance_unapplied_credit_applications',
        'finance_adjustments',
        'finance_refunds',
        'finance_student_charges',
        'staff_compensation_contracts',
        'payroll_runs',
        'payroll_run_items',
        'payroll_payments',
        'staff_advances',
        'staff_advance_movements',
        'accounting_journal_entries',
        'finance_vouchers',
    ];

    private const NON_RESTORABLE_DELETE_TABLES = [
        'users', 'school_emails', 'external_teachers', 'lesson_ppt_templates', 'canva_templates',
    ];

    public static function isRegisteredTable(string $table): bool
    {
        return in_array($table, self::UNDOABLE_TABLES, true)
            || in_array($table, self::REGISTERED_NON_UNDOABLE_TABLES, true)
            || in_array($table, self::REVERSAL_ONLY_TABLES, true);
    }

    public static function allowsDirectUndo(string $table, ?string $action = null): bool
    {
        if (!self::isRegisteredTable($table)
            || in_array($table, self::REVERSAL_ONLY_TABLES, true)
            || in_array($table, self::REGISTERED_NON_UNDOABLE_TABLES, true)) {
            return false;
        }

        return $action !== 'delete' || !in_array($table, self::NON_RESTORABLE_DELETE_TABLES, true);
    }

    public static function directUndoBlockReason(string $table, ?string $action = null): ?string
    {
        if (!self::isRegisteredTable($table)) {
            return 'unregistered_entity';
        }
        if (in_array($table, self::REVERSAL_ONLY_TABLES, true)) {
            return 'reversal_required';
        }
        if (in_array($table, self::REGISTERED_NON_UNDOABLE_TABLES, true)) {
            return 'workflow_owned_rollback';
        }
        if ($action === 'delete' && in_array($table, self::NON_RESTORABLE_DELETE_TABLES, true)) {
            return 'credential_snapshot_excluded';
        }

        return null;
    }

    public static function redact(array $data, ?string $entityType = null): array
    {
        $redacted = [];
        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);
            if (self::isSensitiveField($normalized)
                || self::isEntitySensitiveField($entityType, $normalized)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = self::redact($value, $entityType);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    public static function undoSnapshot(array $data, ?string $entityType = null): array
    {
        $snapshot = [];
        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);
            if (self::isSensitiveField($normalized)
                || self::isEntitySensitiveField($entityType, $normalized)) {
                continue;
            }

            $snapshot[$key] = is_array($value)
                ? self::undoSnapshot($value, $entityType)
                : $value;
        }

        return $snapshot;
    }

    private static function isSensitiveField(string $field): bool
    {
        if (in_array($field, self::SENSITIVE_FIELDS, true)) {
            return true;
        }

        foreach (['password', 'token', 'secret', 'api_key', 'private_key', 'session_id'] as $marker) {
            if (strpos($field, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function isEntitySensitiveField(?string $entityType, string $field): bool
    {
        $entityType = strtolower(trim((string) $entityType));
        if ($entityType === '') {
            return false;
        }

        foreach (self::ENTITY_SENSITIVE_FIELDS as $entityPrefix => $fields) {
            if (str_starts_with($entityType, $entityPrefix)
                && in_array($field, $fields, true)) {
                return true;
            }
        }

        return false;
    }
}
