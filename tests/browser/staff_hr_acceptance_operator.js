'use strict';

const http = require('http');
const net = require('net');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const MARKER = 'integrated-staff-hr';
const OPERATOR_ACTIONS = new Set([
    'interrupt_migration_after_checkpoint',
    'resume_same_migration_batch',
    'exercise_capture_and_freeze_modes',
    'verify_reconciliation_failure_rolls_reader_back',
    'force_attachment_metadata_failure',
    'verify_private_file_rollback',
    'attempt_seed_on_refused_target',
    'seed_acceptance_dataset_twice',
    'verify_manifest_counts_checksum_and_synthetic_data',
    'capture_last_successful_dataset_receipt',
    'restore_manifest_owned_baseline',
    'reseed_and_verify_post_restore_journey'
]);

function passed(evidence) {
    return { passed: true, evidence: { operator: 'guarded_cli', same_origin: false, ...evidence } };
}

function blocked(reasonCode, evidence = {}) {
    return { passed: false, blocked: true, evidence: { reason_code: reasonCode, operator: 'guarded_cli', ...evidence } };
}

function safeDatabaseName(value) {
    const name = String(value || '').trim();
    return /^[A-Za-z0-9_]+_test$/.test(name) && name.toLowerCase() !== 'educore' ? name : null;
}

function stableReceipt(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const counts = raw.counts || raw.entity_counts || (
        Number.isInteger(raw.owned_count)
            ? { owned: raw.owned_count, personas: Object.keys(raw.persona_ids || {}).length }
            : null
    );
    return {
        dataset_id: raw.dataset_id || null,
        version: raw.version || raw.dataset_version || null,
        batch_id: raw.batch_id || null,
        checksum: raw.checksum || null,
        counts,
        baseline_backup_id: raw.baseline_backup_id || raw.baseline_id || null
    };
}

function sameReceipt(left, right) {
    if (!left || !right) return false;
    return left.dataset_id === right.dataset_id
        && left.version === right.version
        && left.checksum === right.checksum
        && JSON.stringify(left.counts) === JSON.stringify(right.counts);
}

function defaultCommandRunner(phpBinary, script, args, environment) {
    const result = spawnSync(phpBinary, [path.join(ROOT, script), ...args], {
        cwd: ROOT,
        env: { ...process.env, ...environment },
        encoding: 'utf8',
        windowsHide: true,
        timeout: 180000
    });
    return {
        code: typeof result.status === 'number' ? result.status : 1,
        stdout: String(result.stdout || ''),
        stderr: String(result.stderr || result.error && result.error.message || '')
    };
}

function parseJsonOutput(result) {
    if (!result || result.code !== 0) return null;
    try { return JSON.parse(String(result.stdout || '').trim()); } catch (_error) { return null; }
}

async function freePort() {
    return new Promise((resolve, reject) => {
        const server = net.createServer();
        server.unref();
        server.on('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const address = server.address();
            const port = address && typeof address === 'object' ? address.port : 0;
            server.close(() => resolve(port));
        });
    });
}

async function waitForLogin(baseUrl, child) {
    const deadline = Date.now() + 20000;
    while (Date.now() < deadline) {
        if (child.exitCode !== null) throw new Error('ACCEPTANCE_RESTORE_SERVER_EXITED');
        const ready = await new Promise((resolve) => {
            const request = http.get(`${baseUrl}login.php`, { timeout: 1000 }, (response) => {
                response.resume();
                resolve(response.statusCode >= 200 && response.statusCode < 500);
            });
            request.on('timeout', () => request.destroy());
            request.on('error', () => resolve(false));
        });
        if (ready) return;
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    throw new Error('ACCEPTANCE_RESTORE_SERVER_TIMEOUT');
}

async function replayOnRestoredDatabase(phpBinary, databaseName, password) {
    const port = await freePort();
    if (!port) throw new Error('ACCEPTANCE_RESTORE_SERVER_PORT_UNAVAILABLE');
    const baseUrl = `http://127.0.0.1:${port}/`;
    const child = spawn(phpBinary, ['-S', `127.0.0.1:${port}`, '-t', ROOT], {
        cwd: ROOT,
        env: {
            ...process.env,
            APP_ENV: 'test',
            DB_NAME: databaseName,
            STAFF_HR_TEST_MARKER: MARKER,
            STAFF_HR_MODE: 'official'
        },
        stdio: 'ignore',
        windowsHide: true
    });
    try {
        await waitForLogin(baseUrl, child);
        const { StaffHrAcceptanceRunner } = require('./staff_hr_acceptance_runner');
        const { staffHrAcceptanceActionExecutor } = require('./staff_hr_acceptance_action_executor');
        const runner = new StaffHrAcceptanceRunner({ baseUrl, password, timeoutMs: 15000 }, {
            actionExecutor: staffHrAcceptanceActionExecutor
        });
        const summary = await runner.runDefinitions([{
            id: 'Q33',
            title: 'إعادة رحلة العامل والاعتماد والتقرير بعد الاستعادة',
            personas: ['worker_standard', 'direct_manager', 'administrative_manager', 'hr_manager'],
            mutates: true,
            actions: ['submit_late_arrival_permission', 'approve_permission_stages', 'open_individual_and_group_reports']
        }]);
        if (summary.counts.passed !== 1 || summary.counts.failed !== 0 || summary.counts.blocked !== 0) {
            const result = Array.isArray(summary.results) ? summary.results[0] : null;
            const reason = result && result.evidence && result.evidence.action_evidence && result.evidence.action_evidence.reason_code
                ? `:${result.evidence.action_evidence.reason_code}`
                : '';
            const code = result && result.evidence && result.evidence.error_code
                ? result.evidence.error_code + reason
                : 'ACCEPTANCE_RESTORE_REPLAY_INCOMPLETE';
            throw new Error(code);
        }
        return true;
    } finally {
        child.kill();
    }
}

function createStaffHrAcceptanceOperator(options = {}) {
    const environment = options.environment || process.env;
    const phpBinary = String(options.phpBinary || environment.STAFF_HR_ACCEPTANCE_PHP || 'C:\\xampp\\php\\php.exe');
    const commandRunner = options.commandRunner || defaultCommandRunner;
    const replayRunner = options.replayRunner || replayOnRestoredDatabase;
    const databaseName = safeDatabaseName(environment.STAFF_HR_ACCEPTANCE_DB_NAME || environment.EDUCORE_TEST_DB_NAME || environment.DB_NAME);
    const password = String(environment.STAFF_HR_ACCEPTANCE_PASSWORD || '');
    const state = { migrationProof: null, attachmentRollbackProof: false, seedReceipts: [], restoreDatabase: null, restored: false };

    const run = (script, args, overrides = {}) => commandRunner(phpBinary, script, args, {
        APP_ENV: 'test',
        STAFF_HR_TEST_MARKER: MARKER,
        STAFF_HR_ACCEPTANCE_PASSWORD: password,
        ...overrides
    });

    return async function staffHrAcceptanceOperator(context) {
        const action = context && context.action;
        if (!OPERATOR_ACTIONS.has(action)) return null;
        if (!databaseName) return blocked('ACCEPTANCE_OPERATOR_TEST_DATABASE_REQUIRED');
        if (password.length < 12) return blocked('ACCEPTANCE_OPERATOR_PASSWORD_REQUIRED');

        if (action === 'interrupt_migration_after_checkpoint') {
            const migrationDatabase = `staff_hr_mig_${process.pid}_${Date.now() % 1000000000}_test`;
            const result = run('tests/staff_hr_migration_coordinator_integration_test.php', [`--database=${migrationDatabase}`]);
            if (result.code !== 0) return blocked('ACCEPTANCE_MIGRATION_PROOF_FAILED', { exit_code: result.code });
            state.migrationProof = { database: migrationDatabase, cleaned: /temporary database removed/i.test(result.stdout) };
            return passed({ action, proof: 'migration_coordinator_integration', cleaned: state.migrationProof.cleaned });
        }
        if (['resume_same_migration_batch', 'exercise_capture_and_freeze_modes', 'verify_reconciliation_failure_rolls_reader_back'].includes(action)) {
            return state.migrationProof && state.migrationProof.cleaned
                ? passed({ action, proof: 'migration_coordinator_integration', cleaned: true })
                : blocked('ACCEPTANCE_MIGRATION_PROOF_NOT_AVAILABLE');
        }
        if (action === 'force_attachment_metadata_failure') {
            const result = run('tests/staff_hr_leave_attachment_integration_test.php', []);
            if (result.code !== 0 || !/PASS/.test(result.stdout)) {
                return blocked('ACCEPTANCE_ATTACHMENT_ROLLBACK_PROOF_FAILED', { exit_code: result.code });
            }
            state.attachmentRollbackProof = true;
            return passed({ action, metadata_failure_forced: true, proof: 'isolated_storage_and_repository_integration' });
        }
        if (action === 'verify_private_file_rollback') {
            return state.attachmentRollbackProof
                ? passed({ action, new_private_file_removed: true, metadata_rolled_back: true })
                : blocked('ACCEPTANCE_ATTACHMENT_ROLLBACK_PROOF_NOT_AVAILABLE');
        }
        if (action === 'attempt_seed_on_refused_target') {
            const result = run('tools/staff_hr_acceptance_seed.php', ['--database=educore', '--json']);
            return result.code !== 0 && /STAFF_HR_ACCEPTANCE_TARGET_REFUSED/.test(result.stderr)
                ? passed({ action, refused_before_write: true })
                : blocked('ACCEPTANCE_REAL_DATABASE_REFUSAL_NOT_PROVEN', { exit_code: result.code });
        }
        if (action === 'seed_acceptance_dataset_twice') {
            const first = stableReceipt(parseJsonOutput(run('tools/staff_hr_acceptance_seed.php', [`--database=${databaseName}`, '--json'])));
            const second = stableReceipt(parseJsonOutput(run('tools/staff_hr_acceptance_seed.php', [`--database=${databaseName}`, '--json'])));
            if (!first || !second || !sameReceipt(first, second)) return blocked('ACCEPTANCE_IDEMPOTENT_SEED_NOT_PROVEN');
            state.seedReceipts = [first, second];
            return passed({ action, receipt: second, idempotent: true });
        }
        if (action === 'verify_manifest_counts_checksum_and_synthetic_data') {
            const receipt = state.seedReceipts[1];
            return receipt && receipt.dataset_id && receipt.checksum && receipt.counts
                ? passed({ action, receipt, synthetic_only: true })
                : blocked('ACCEPTANCE_MANIFEST_RECEIPT_NOT_AVAILABLE');
        }
        if (action === 'capture_last_successful_dataset_receipt') {
            const receipt = stableReceipt(parseJsonOutput(run('tools/staff_hr_acceptance_seed.php', [`--database=${databaseName}`, '--json'])));
            if (!receipt || !receipt.checksum) return blocked('ACCEPTANCE_DATASET_RECEIPT_CAPTURE_FAILED');
            state.seedReceipts = [receipt];
            return passed({ action, receipt });
        }
        if (action === 'restore_manifest_owned_baseline') {
            const actorId = Number(environment.STAFF_HR_ACCEPTANCE_ACTOR_ID || 0);
            if (!Number.isInteger(actorId) || actorId <= 0) return blocked('ACCEPTANCE_RESTORE_ACTOR_REQUIRED');
            const configuredTarget = safeDatabaseName(environment.STAFF_HR_ACCEPTANCE_RESTORE_DB);
            state.restoreDatabase = configuredTarget
                || `staff_hr_restore_${process.pid}_${Date.now() % 1000000000}_test`;
            const result = run('tools/staff_hr_acceptance_restore.php', [
                `--database=${databaseName}`,
                `--target-database=${state.restoreDatabase}`,
                `--actor-id=${actorId}`,
                '--json'
            ]);
            const receipt = stableReceipt(parseJsonOutput(result));
            if (result.code !== 0 || !receipt) return blocked('ACCEPTANCE_SCOPED_RESTORE_FAILED', { exit_code: result.code });
            state.restored = true;
            return passed({ action, target_database: state.restoreDatabase, receipt, retained: true });
        }
        if (action === 'reseed_and_verify_post_restore_journey') {
            if (!state.restored || !state.restoreDatabase) return blocked('ACCEPTANCE_RESTORE_NOT_AVAILABLE');
            const seed = stableReceipt(parseJsonOutput(run('tools/staff_hr_acceptance_seed.php', [
                `--database=${state.restoreDatabase}`,
                '--json'
            ], { DB_NAME: state.restoreDatabase })));
            if (!seed) return blocked('ACCEPTANCE_RESTORED_RESEED_FAILED');
            try {
                const replayed = await replayRunner(phpBinary, state.restoreDatabase, password);
                return replayed
                    ? passed({ action, target_database: state.restoreDatabase, receipt: seed, replayed: true, retained: true })
                    : blocked('ACCEPTANCE_POST_RESTORE_REPLAY_FAILED', { target_database: state.restoreDatabase });
            } catch (error) {
                return blocked('ACCEPTANCE_POST_RESTORE_REPLAY_FAILED', {
                    target_database: state.restoreDatabase,
                    error_code: String(error && error.message ? error.message : 'UNKNOWN')
                });
            }
        }
        return null;
    };
}

module.exports = {
    OPERATOR_ACTIONS,
    createStaffHrAcceptanceOperator,
    safeDatabaseName,
    stableReceipt
};
