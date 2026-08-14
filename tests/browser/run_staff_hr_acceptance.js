'use strict';

const { StaffHrAcceptanceRunner, configFromEnvironment, sanitizeEvidence } = require('./staff_hr_acceptance_runner');
const { staffHrAcceptanceActionExecutor } = require('./staff_hr_acceptance_action_executor');
const { createStaffHrAcceptanceOperator } = require('./staff_hr_acceptance_operator');
const core = require('./staff_hr_acceptance_core.spec');
const edges = require('./staff_hr_acceptance_edges.spec');
const handoff = require('./staff_hr_acceptance_handoff.spec');

(async () => {
    const requested = String(process.env.STAFF_HR_ACCEPTANCE_SCENARIOS || '').trim();
    const allDefinitions = [...core, ...edges, ...handoff];
    const requestedIds = requested === '' ? null : new Set(requested.split(',').map((value) => value.trim()).filter(Boolean));
    const definitions = requestedIds === null
        ? allDefinitions
        : allDefinitions.filter((definition) => requestedIds.has(definition.id));
    if (requestedIds !== null && (definitions.length !== requestedIds.size || definitions.length === 0)) {
        throw new Error('ACCEPTANCE_SCENARIO_FILTER_INVALID');
    }
    const operator = createStaffHrAcceptanceOperator();
    const runner = new StaffHrAcceptanceRunner(configFromEnvironment(), {
        actionExecutor: async (context) => {
            const operatorOutcome = await operator(context);
            return operatorOutcome === null
                ? staffHrAcceptanceActionExecutor(context)
                : operatorOutcome;
        }
    });
    const summary = sanitizeEvidence(await runner.runDefinitions(definitions));
    process.stdout.write(JSON.stringify(summary, null, 2) + '\n');
    process.exit(summary.counts.failed === 0 && summary.counts.blocked === 0 ? 0 : 1);
})().catch((error) => {
    const evidence = sanitizeEvidence({
        dataset_id: 'staff_hr_acceptance_v1',
        status: 'blocked',
        error_code: String(error && error.message ? error.message : 'ACCEPTANCE_RUNNER_FAILED')
    });
    process.stderr.write(JSON.stringify(evidence) + '\n');
    process.exit(1);
});
