'use strict';

const {
    PERSONAS,
    StaffHrAcceptanceRunner,
    configFromEnvironment,
    encodeMultipart,
    extractCsrf,
    resolveSameOriginRedirect,
    sanitizeEvidence
} = require('./staff_hr_acceptance_runner');

class FakeTransport {
    constructor() {
        this.calls = [];
    }

    async request(path, options = {}) {
        this.calls.push({ path, options });
        if (path === 'login.php' && !options.form) {
            return { status: 200, url: 'http://example.test/login.php', body: '<input name="csrf_token" value="csrf-login"><input name="password">', headers: {} };
        }
        if (path === 'login.php') {
            return { status: 200, url: 'http://example.test/select_role.php', body: '<input name="csrf_token" value="csrf-role"><input name="role_key">', headers: {} };
        }
        return { status: 200, url: 'http://example.test/teacher/portal.php', body: 'بوابة العامل', headers: {} };
    }
}

(async () => {
    const transport = new FakeTransport();
    const runner = new StaffHrAcceptanceRunner({
        baseUrl: 'http://example.test/',
        password: 'not-a-real-demo-password'
    }, { transportFactory: () => transport });
    const result = await runner.scenario('Q16', 'worker_teacher', async (session) => {
        const response = await session.login('teacher');
        return { status: response.status, cookie: 'PHPSESSID=must-not-leak', password: 'must-not-leak' };
    });
    const summary = runner.summary();
    const blockedRunner = new StaffHrAcceptanceRunner({
        baseUrl: 'http://example.test/',
        password: 'not-a-real-demo-password'
    }, {
        transportFactory: () => new FakeTransport(),
        actionExecutor: async () => ({
            passed: false,
            blocked: true,
            evidence: { reason_code: 'EXPECTED_BLOCK', password: 'must-not-leak', route: 'admin/example.php' }
        })
    });
    const blockedSummary = await blockedRunner.runDefinitions([{
        id: 'Q01',
        title: 'blocked evidence',
        personas: ['super_admin'],
        mutates: true,
        actions: ['verify_blocked_evidence', 'verify_blocked_evidence_again']
    }]);
    const checks = {
        fixture_exposes_all_ten_personas: Object.keys(PERSONAS).length === 10,
        csrf_parser_reads_login_token: extractCsrf('<input value="abc" name="csrf_token">') === 'abc',
        login_posts_expected_username: transport.calls[1].options.form.username === 'demo.staffhr.teacher',
        login_posts_password_without_recording_it: transport.calls[1].options.form.password === 'not-a-real-demo-password'
            && JSON.stringify(summary).includes('not-a-real-demo-password') === false,
        role_selection_is_explicit: transport.calls[2].options.form.role_key === 'teacher',
        evidence_redacts_session_and_credentials: result.evidence.cookie === '[REDACTED]'
            && result.evidence.password === '[REDACTED]',
        valid_journey_is_recorded_as_passed: result.status === 'passed' && summary.counts.passed === 1,
        environment_refuses_missing_password: (() => {
            try { configFromEnvironment({ STAFF_HR_ACCEPTANCE_BASE_URL: 'http://example.test/' }); return false; }
            catch (error) { return error.message === 'ACCEPTANCE_PASSWORD_REQUIRED'; }
        })(),
        arbitrary_sensitive_keys_are_redacted: sanitizeEvidence({ authorization: 'Bearer x', nested: { token: 'x' } }).nested.token === '[REDACTED]',
        blocked_action_retains_only_sanitized_diagnostic_evidence: blockedSummary.results[0].evidence.action_evidence.reason_code === 'EXPECTED_BLOCK'
            && blockedSummary.results[0].evidence.action_evidence.password === '[REDACTED]'
            && blockedSummary.results[0].evidence.action_evidence.route === 'admin/example.php',
        relative_admin_redirect_stays_under_current_directory: resolveSameOriginRedirect(
            'hr_policy_calendar.php',
            new URL('http://example.test/admin/hr_policy_calendar.php'),
            new URL('http://example.test/')
        ) === 'http://example.test/admin/hr_policy_calendar.php',
        cross_origin_redirect_is_rejected: (() => {
            try {
                resolveSameOriginRedirect('https://outside.example/path', new URL('http://example.test/admin/page.php'), new URL('http://example.test/'));
                return false;
            } catch (error) {
                return error.message === 'ACCEPTANCE_CROSS_ORIGIN_BLOCKED';
            }
        })(),
        multipart_encoder_preserves_fields_and_fixture_bytes: (() => {
            const body = encodeMultipart(
                { csrf_token: 'safe', preview_biometric: '1' },
                [{ name: 'biometric_csv', filename: 'q03.csv', contentType: 'text/csv', data: Buffer.from('demo-row') }],
                'test-boundary'
            ).toString('utf8');
            return body.includes('name="csrf_token"')
                && body.includes('filename="q03.csv"')
                && body.includes('demo-row')
                && body.endsWith('--test-boundary--\r\n');
        })()
    };
    for (const [name, passed] of Object.entries(checks)) console.log(`${name}:${passed ? 'PASS' : 'FAIL'}`);
    process.exit(Object.values(checks).every(Boolean) ? 0 : 1);
})().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exit(1);
});
