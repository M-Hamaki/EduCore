'use strict';

const core = require('./staff_hr_acceptance_core.spec');
const edges = require('./staff_hr_acceptance_edges.spec');
const handoff = require('./staff_hr_acceptance_handoff.spec');
const {
    staffHrAcceptanceActionExecutor,
    csvCells,
    formContaining,
    hiddenFields,
    selectOption
} = require('./staff_hr_acceptance_action_executor');
const fs = require('fs');
const path = require('path');

(async () => {
    const definitions = [...core, ...edges, ...handoff];
    const expected = [...new Set(definitions.flatMap((definition) => definition.actions))].sort();
    const sample = '<form><input type="hidden" name="csrf_token" value="safe-csrf">'
        + '<button name="permission_request_intent" value="submit">إرسال</button>'
        + '<select name="permission_type_id"><option value="">اختر</option><option value="17">تأخير حضور تجريبي</option></select></form>';
    const form = formContaining(sample, 'permission_request_intent', 'submit');
    const unknown = await staffHrAcceptanceActionExecutor({ action: 'not_declared', definition: {}, primarySession: null, sessionFor: () => null });
    const knownBlocked = await staffHrAcceptanceActionExecutor({ action: 'publish_split_shift', definition: edges[3], primarySession: null, sessionFor: () => null });
    const urgentBlocked = await staffHrAcceptanceActionExecutor({ action: 'submit_immediate_risk_complaint', definition: edges[0], primarySession: null, sessionFor: () => null });
    const checks = {
        every_q01_q33_action_has_a_concrete_executor_entry: staffHrAcceptanceActionExecutor.supportedActionNames().join(',') === expected.join(','),
        parser_finds_the_intended_same_origin_form: typeof form === 'string',
        parser_preserves_rendered_csrf_without_logging_it: hiddenFields(form).csrf_token === 'safe-csrf',
        parser_resolves_acceptance_type_by_visible_label: selectOption(form, 'permission_type_id', /تأخير/) === '17',
        csv_parser_preserves_escaped_formula_prefix_as_text: csvCells('\uFEFFname,value\r\nworker,"\'=SUM(1,2)"')[3] === "'=SUM(1,2)",
        unavailable_declared_action_fails_closed_as_blocked: knownBlocked.blocked === true && knownBlocked.evidence.reason_code === 'UI_ROUTE_OR_REQUIRED_FIELD_NOT_AVAILABLE',
        unrouted_immediate_risk_complaint_fails_closed: urgentBlocked.blocked === true && urgentBlocked.evidence.reason_code === 'ERTAQ_PROTECTION_TEAM_ROUTE_NOT_CONFIGURED',
        undeclared_action_is_never_executed: unknown.blocked === true && unknown.evidence.reason_code === 'ACTION_NOT_DECLARED_BY_Q01_Q33',
        executable_runner_composes_ui_and_guarded_cli_executors: (() => {
            const source = fs.readFileSync(path.join(__dirname, 'run_staff_hr_acceptance.js'), 'utf8');
            return source.includes('createStaffHrAcceptanceOperator')
                && source.includes('staffHrAcceptanceActionExecutor(context)');
        })(),
        targeted_runner_filters_only_explicit_q01_q33_ids: (() => {
            const source = fs.readFileSync(path.join(__dirname, 'run_staff_hr_acceptance_scenario.js'), 'utf8');
            return source.includes('process.argv.slice(2)')
                && source.includes('ACCEPTANCE_SCENARIO_ID_REQUIRED')
                && source.includes('runner.runDefinitions(definitions)');
        })()
    };
    for (const [name, value] of Object.entries(checks)) console.log(`${name}:${value ? 'PASS' : 'FAIL'}`);
    process.exit(Object.values(checks).every(Boolean) ? 0 : 1);
})().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exit(1);
});
