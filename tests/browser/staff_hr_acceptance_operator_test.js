'use strict';

const {
    createStaffHrAcceptanceOperator,
    safeDatabaseName,
    stableReceipt
} = require('./staff_hr_acceptance_operator');

function receipt(database) {
    return JSON.stringify({
        dataset_id: 'staff_hr_acceptance_v1',
        version: '2026.08.11-2',
        batch_id: `batch-${database}`,
        checksum: 'a'.repeat(64),
        owned_count: 13,
        persona_ids: { worker: 1, manager: 2 },
        baseline_backup_id: 7
    });
}

(async () => {
    const calls = [];
    const commandRunner = (_php, script, args, environment) => {
        calls.push({ script, args, environment });
        if (script.includes('migration_coordinator')) {
            return { code: 0, stdout: 'Staff-HR migration coordinator proof passed; temporary database removed.\n', stderr: '' };
        }
        if (script.includes('leave_attachment_integration')) {
            return { code: 0, stdout: 'staff_hr_leave_attachment_integration_test: PASS (22 assertions)\n', stderr: '' };
        }
        if (args.includes('--database=educore')) {
            return { code: 1, stdout: '', stderr: 'FAIL: STAFF_HR_ACCEPTANCE_TARGET_REFUSED\n' };
        }
        const databaseArg = args.find((item) => item.startsWith('--target-database='))
            || args.find((item) => item.startsWith('--database='))
            || '--database=unknown_test';
        return { code: 0, stdout: receipt(databaseArg.split('=')[1]), stderr: '' };
    };
    const operator = createStaffHrAcceptanceOperator({
        environment: {
            STAFF_HR_ACCEPTANCE_DB_NAME: 'educore_acceptance_operator_test',
            STAFF_HR_ACCEPTANCE_PASSWORD: 'synthetic-acceptance-password',
            STAFF_HR_ACCEPTANCE_ACTOR_ID: '91',
            STAFF_HR_ACCEPTANCE_RESTORE_DB: 'educore_acceptance_operator_restore_test'
        },
        commandRunner,
        replayRunner: async (_php, database, password) => database.endsWith('_test') && password.length >= 12
    });

    const run = async (action) => operator({ action });
    const migration = await Promise.all([
        run('interrupt_migration_after_checkpoint'),
        run('resume_same_migration_batch'),
        run('exercise_capture_and_freeze_modes'),
        run('verify_reconciliation_failure_rolls_reader_back')
    ]);
    const refused = await run('attempt_seed_on_refused_target');
    const attachmentFailure = await run('force_attachment_metadata_failure');
    const attachmentRollback = await run('verify_private_file_rollback');
    const seeded = await run('seed_acceptance_dataset_twice');
    const verified = await run('verify_manifest_counts_checksum_and_synthetic_data');
    const captured = await run('capture_last_successful_dataset_receipt');
    const restored = await run('restore_manifest_owned_baseline');
    const replayed = await run('reseed_and_verify_post_restore_journey');
    const ignored = await run('publish_scoped_schedules');

    const checks = {
        database_guard_accepts_only_explicit_test_names: safeDatabaseName('school_demo_test') === 'school_demo_test'
            && safeDatabaseName('educore') === null
            && safeDatabaseName('unsafe-name_test') === null,
        receipt_redacts_to_stable_manifest_fields: stableReceipt(JSON.parse(receipt('x'))).checksum === 'a'.repeat(64),
        migration_proof_is_reused_without_hidden_database_writes: migration.every((item) => item.passed === true),
        real_database_seed_is_refused_before_write: refused.passed === true && refused.evidence.refused_before_write === true,
        attachment_metadata_failure_uses_guarded_isolated_proof: attachmentFailure.passed === true
            && attachmentFailure.evidence.metadata_failure_forced === true,
        attachment_private_file_rollback_requires_prior_proof: attachmentRollback.passed === true
            && attachmentRollback.evidence.new_private_file_removed === true,
        repeat_seed_requires_equal_manifest_receipts: seeded.passed === true && seeded.evidence.idempotent === true,
        manifest_verification_uses_captured_counts_and_checksum: verified.passed === true
            && verified.evidence.receipt.counts.owned === 13
            && verified.evidence.receipt.counts.personas === 2,
        last_successful_receipt_is_captured_without_secret: captured.passed === true
            && JSON.stringify(captured).includes('synthetic-acceptance-password') === false,
        restore_is_scoped_to_configured_fresh_test_database: restored.passed === true
            && restored.evidence.target_database === 'educore_acceptance_operator_restore_test',
        post_restore_reseed_and_http_replay_are_required: replayed.passed === true && replayed.evidence.replayed === true,
        unrelated_ui_action_is_not_intercepted: ignored === null,
        every_cli_call_forces_test_marker: calls.every((call) => call.environment.STAFF_HR_TEST_MARKER === 'integrated-staff-hr')
    };
    for (const [name, value] of Object.entries(checks)) console.log(`${name}:${value ? 'PASS' : 'FAIL'}`);
    process.exit(Object.values(checks).every(Boolean) ? 0 : 1);
})().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exit(1);
});
