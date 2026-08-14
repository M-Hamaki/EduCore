'use strict';

const { AcceptanceBlockedError, StaffHrAcceptanceRunner, validateJourneyDefinitions } = require('./staff_hr_acceptance_runner');
const core = require('./staff_hr_acceptance_core.spec');
const edges = require('./staff_hr_acceptance_edges.spec');
const handoff = require('./staff_hr_acceptance_handoff.spec');

(async () => {
    const all = [...core, ...edges, ...handoff];
    const expected = Array.from({ length: 33 }, (_, index) => `Q${String(index + 1).padStart(2, '0')}`);
    const loginTransport = {
        async request(path, options = {}) {
            if (path === 'login.php' && !options.form) return { status: 200, url: 'http://example.test/login.php', body: '<input name="csrf_token" value="a"><input name="password">', headers: {} };
            if (path === 'login.php') return { status: 200, url: 'http://example.test/select_role.php', body: '<input name="csrf_token" value="b"><input name="role_key">', headers: {} };
            return { status: 200, url: 'http://example.test/portal.php', body: 'ok', headers: {} };
        }
    };
    const blockedRunner = new StaffHrAcceptanceRunner({ baseUrl: 'http://example.test/', password: 'synthetic-only-password' }, {
        transportFactory: () => loginTransport
    });
    const blockedSummary = await blockedRunner.runDefinitions([core[0]]);
    const checks = {
        definitions_are_valid: validateJourneyDefinitions(all) === true,
        all_q01_q33_are_covered_once: all.map((item) => item.id).join(',') === expected.join(','),
        core_range_is_q01_q17: core.length === 17 && core[0].id === 'Q01' && core[16].id === 'Q17',
        edge_range_is_q18_q30: edges.length === 13 && edges[0].id === 'Q18' && edges[12].id === 'Q30',
        handoff_range_is_q31_q33: handoff.length === 3 && handoff[0].id === 'Q31' && handoff[2].id === 'Q33',
        mutating_journeys_have_verification: all.filter((item) => item.mutates).every((item) => item.actions.some((action) => /verify|assert|reconcile|report|audit/.test(action))),
        missing_ui_executor_fails_closed: blockedSummary.counts.blocked === 1 && blockedSummary.counts.passed === 0,
        blocked_error_has_stable_type: new AcceptanceBlockedError('x') instanceof Error
    };
    for (const [name, passed] of Object.entries(checks)) console.log(`${name}:${passed ? 'PASS' : 'FAIL'}`);
    process.exit(Object.values(checks).every(Boolean) ? 0 : 1);
})().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exit(1);
});
