'use strict';

const { StaffHrAcceptanceRunner, configFromEnvironment, sanitizeEvidence } = require('./staff_hr_acceptance_runner');
const { staffHrAcceptanceActionExecutor } = require('./staff_hr_acceptance_action_executor');
const { createStaffHrAcceptanceOperator } = require('./staff_hr_acceptance_operator');
const core = require('./staff_hr_acceptance_core.spec');
const edges = require('./staff_hr_acceptance_edges.spec');
const handoff = require('./staff_hr_acceptance_handoff.spec');

(async () => {
    const requested = [...new Set(process.argv.slice(2).map((value) => String(value).toUpperCase()))];
    if (requested.length === 0 || requested.some((value) => !/^Q(?:0[1-9]|[12][0-9]|3[0-3])$/.test(value))) {
        throw new Error('ACCEPTANCE_SCENARIO_ID_REQUIRED');
    }
    const definitions = [...core, ...edges, ...handoff].filter((definition) => requested.includes(definition.id));
    if (definitions.length !== requested.length) throw new Error('ACCEPTANCE_SCENARIO_NOT_FOUND');

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
    process.stderr.write(JSON.stringify(sanitizeEvidence({
        dataset_id: 'staff_hr_acceptance_v1',
        status: 'blocked',
        error_code: String(error && error.message ? error.message : 'ACCEPTANCE_RUNNER_FAILED')
    })) + '\n');
    process.exit(1);
});
