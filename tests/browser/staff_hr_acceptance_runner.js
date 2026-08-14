'use strict';

const http = require('http');
const https = require('https');
const crypto = require('crypto');
const { URL, URLSearchParams } = require('url');

const DATASET_ID = 'staff_hr_acceptance_v1';
const DATASET_VERSION = '2026.08.11-2';

const PERSONAS = Object.freeze({
    super_admin: { username: 'demo.staffhr.superadmin', roles: ['admin', 'super_admin'] },
    hr_manager: { username: 'demo.staffhr.hr', roles: ['admin'] },
    administrative_manager: { username: 'demo.staffhr.admin.manager', roles: ['admin'] },
    direct_manager: { username: 'demo.staffhr.direct.manager', roles: ['teacher'] },
    delegate_manager: { username: 'demo.staffhr.delegate', roles: ['teacher'] },
    worker_teacher: { username: 'demo.staffhr.teacher', roles: ['teacher'] },
    worker_specialist: { username: 'demo.staffhr.specialist', roles: ['specialist'] },
    worker_standard: { username: 'demo.staffhr.worker', roles: ['employee'] },
    protection_officer: { username: 'demo.staffhr.protection', roles: ['admin'] },
    finance_operator: { username: 'demo.staffhr.finance', roles: ['admin'] }
});

class AcceptanceBlockedError extends Error {}

class CookieJar {
    constructor() {
        this.cookies = new Map();
    }

    absorb(headers) {
        const values = typeof headers.getSetCookie === 'function'
            ? headers.getSetCookie()
            : (headers['set-cookie'] || []);
        for (const value of Array.isArray(values) ? values : [values]) {
            if (!value) continue;
            const pair = String(value).split(';', 1)[0];
            const separator = pair.indexOf('=');
            if (separator > 0) this.cookies.set(pair.slice(0, separator), pair.slice(separator + 1));
        }
    }

    header() {
        return [...this.cookies.entries()].map(([key, value]) => `${key}=${value}`).join('; ');
    }
}

function resolveSameOriginRedirect(location, currentTarget, baseUrl) {
    const applicationBase = baseUrl instanceof URL ? baseUrl : new URL(baseUrl);
    const redirectTarget = new URL(location, currentTarget);
    if (redirectTarget.origin !== applicationBase.origin) throw new Error('ACCEPTANCE_CROSS_ORIGIN_BLOCKED');
    return redirectTarget.toString();
}

function encodeMultipart(fields, files, boundary) {
    const chunks = [];
    const append = (value) => chunks.push(Buffer.isBuffer(value) ? value : Buffer.from(String(value), 'utf8'));
    for (const [name, value] of Object.entries(fields || {})) {
        if (!/^[A-Za-z0-9_\[\]-]+$/.test(name)) throw new Error('ACCEPTANCE_MULTIPART_FIELD_INVALID');
        append(`--${boundary}\r\nContent-Disposition: form-data; name="${name}"\r\n\r\n${value}\r\n`);
    }
    for (const file of files || []) {
        if (!file || !/^[A-Za-z0-9_\[\]-]+$/.test(String(file.name || ''))) throw new Error('ACCEPTANCE_MULTIPART_FILE_FIELD_INVALID');
        const filename = String(file.filename || 'fixture.bin').replace(/["\r\n\\/]/g, '_');
        const contentType = String(file.contentType || 'application/octet-stream').replace(/[\r\n]/g, '');
        append(`--${boundary}\r\nContent-Disposition: form-data; name="${file.name}"; filename="${filename}"\r\nContent-Type: ${contentType}\r\n\r\n`);
        append(file.data);
        append('\r\n');
    }
    append(`--${boundary}--\r\n`);
    return Buffer.concat(chunks);
}

class HttpTransport {
    constructor(baseUrl, options = {}) {
        this.baseUrl = new URL(baseUrl);
        this.timeoutMs = options.timeoutMs || 15000;
        this.jar = options.jar || new CookieJar();
    }

    async request(path, options = {}, redirects = 0) {
        if (redirects > 10) throw new Error('ACCEPTANCE_REDIRECT_LIMIT');
        const target = new URL(path, this.baseUrl);
        if (target.origin !== this.baseUrl.origin) throw new Error('ACCEPTANCE_CROSS_ORIGIN_BLOCKED');
        let body = null;
        const headers = { Accept: 'text/html,application/json', ...(options.headers || {}) };
        if (options.form) {
            const parameters = new URLSearchParams();
            for (const [name, value] of Object.entries(options.form)) {
                if (Array.isArray(value)) {
                    value.forEach((item) => parameters.append(name, String(item)));
                } else {
                    parameters.append(name, String(value));
                }
            }
            body = Buffer.from(parameters.toString(), 'utf8');
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } else if (options.multipart) {
            const boundary = `----EduCoreAcceptance${crypto.randomBytes(12).toString('hex')}`;
            body = encodeMultipart(options.multipart.fields || {}, options.multipart.files || [], boundary);
            headers['Content-Type'] = `multipart/form-data; boundary=${boundary}`;
        }
        if (body !== null) {
            headers['Content-Length'] = body.length;
        }
        const cookie = this.jar.header();
        if (cookie) headers.Cookie = cookie;

        const response = await new Promise((resolve, reject) => {
            const driver = target.protocol === 'https:' ? https : http;
            const request = driver.request(target, {
                method: options.method || (body === null ? 'GET' : 'POST'),
                headers,
                timeout: this.timeoutMs
            }, (incoming) => {
                const chunks = [];
                incoming.on('data', (chunk) => chunks.push(chunk));
                incoming.on('end', () => resolve({
                    status: incoming.statusCode || 0,
                    headers: incoming.headers,
                    body: Buffer.concat(chunks).toString('utf8'),
                    url: target.toString()
                }));
            });
            request.on('timeout', () => request.destroy(new Error('ACCEPTANCE_REQUEST_TIMEOUT')));
            request.on('error', reject);
            if (body !== null) request.write(body);
            request.end();
        });
        this.jar.absorb(response.headers);
        const location = response.headers.location;
        if (location && [301, 302, 303, 307, 308].includes(response.status)) {
            const preserve = [307, 308].includes(response.status);
            const redirectTarget = resolveSameOriginRedirect(location, target, this.baseUrl);
            return this.request(redirectTarget, preserve ? options : { method: 'GET' }, redirects + 1);
        }
        return response;
    }
}

function extractCsrf(html) {
    const match = String(html).match(/name=["']csrf_token["'][^>]*value=["']([^"']+)["']/i)
        || String(html).match(/value=["']([^"']+)["'][^>]*name=["']csrf_token["']/i);
    if (!match) throw new Error('ACCEPTANCE_CSRF_NOT_FOUND');
    return match[1];
}

function sanitizeEvidence(value, key = '') {
    if (/password|secret|token|cookie|session|authorization/i.test(key)) return '[REDACTED]';
    if (Array.isArray(value)) return value.map((item) => sanitizeEvidence(item));
    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value).map(([itemKey, item]) => [itemKey, sanitizeEvidence(item, itemKey)]));
    }
    const text = typeof value === 'string' ? value : null;
    if (text && (/PHPSESSID=/i.test(text) || /STAFF_HR_ACCEPTANCE_PASSWORD/i.test(text))) return '[REDACTED]';
    return value;
}

class PersonaSession {
    constructor(config, personaKey, transport = null) {
        if (!PERSONAS[personaKey]) throw new Error(`ACCEPTANCE_PERSONA_UNKNOWN:${personaKey}`);
        this.config = config;
        this.personaKey = personaKey;
        this.persona = PERSONAS[personaKey];
        this.transport = transport || new HttpTransport(config.baseUrl, { timeoutMs: config.timeoutMs });
        this.lastResponse = null;
    }

    async login(roleKey = null) {
        const loginPage = await this.transport.request('login.php');
        const csrf = extractCsrf(loginPage.body);
        const response = await this.transport.request('login.php', {
            form: {
                csrf_token: csrf,
                username: this.persona.username,
                password: this.config.password,
                skip_intro: '1'
            }
        });
        if (/name=["']password["']/i.test(response.body)) throw new Error('ACCEPTANCE_LOGIN_FAILED');
        if (/select_role\.php/i.test(response.url) || /name=["']role_key["']/i.test(response.body)) {
            const selectedRole = roleKey || this.persona.roles[0];
            if (!this.persona.roles.includes(selectedRole)) throw new Error('ACCEPTANCE_ROLE_NOT_ASSIGNED');
            const roleCsrf = extractCsrf(response.body);
            this.lastResponse = await this.transport.request('select_role.php', {
                form: { csrf_token: roleCsrf, role_key: selectedRole }
            });
        } else {
            this.lastResponse = response;
        }
        return this.lastResponse;
    }

    async get(path) {
        this.lastResponse = await this.transport.request(path);
        return this.lastResponse;
    }

    async postForm(path, fields, options = {}) {
        let csrf = options.csrf || null;
        if (!csrf) csrf = extractCsrf((await this.get(path)).body);
        this.lastResponse = await this.transport.request(path, { form: { ...fields, csrf_token: csrf } });
        return this.lastResponse;
    }


    async postMultipart(path, fields, files, options = {}) {
        let csrf = options.csrf || null;
        if (!csrf) csrf = extractCsrf((await this.get(path)).body);
        this.lastResponse = await this.transport.request(path, {
            multipart: { fields: { ...fields, csrf_token: csrf }, files }
        });
        return this.lastResponse;
    }
}

class StaffHrAcceptanceRunner {
    constructor(config, options = {}) {
        this.config = config;
        this.transportFactory = options.transportFactory || null;
        this.actionExecutor = options.actionExecutor || null;
        this.results = [];
    }

    session(personaKey) {
        const transport = this.transportFactory ? this.transportFactory(personaKey) : null;
        return new PersonaSession(this.config, personaKey, transport);
    }

    async scenario(scenarioId, personaKey, journey) {
        if (!/^Q(?:0[1-9]|[12][0-9]|3[0-3])$/.test(scenarioId)) throw new Error('ACCEPTANCE_SCENARIO_ID_INVALID');
        const startedAt = new Date().toISOString();
        const session = this.session(personaKey);
        try {
            const evidence = await journey(session, this);
            return this.record(scenarioId, personaKey, 'passed', startedAt, evidence);
        } catch (error) {
            const status = error instanceof AcceptanceBlockedError ? 'blocked' : 'failed';
            return this.record(scenarioId, personaKey, status, startedAt, {
                error_code: String(error && error.message ? error.message : 'ACCEPTANCE_UNKNOWN_FAILURE'),
                action_evidence: sanitizeEvidence(error && error.actionEvidence ? error.actionEvidence : {})
            });
        }
    }

    async performAction(definition, action, sessions) {
        if (typeof this.actionExecutor !== 'function') {
            throw new AcceptanceBlockedError(`ACCEPTANCE_ACTION_EXECUTOR_REQUIRED:${definition.id}:${action}`);
        }
        const outcome = await this.actionExecutor({
            action,
            definition,
            primarySession: sessions[definition.personas[0]],
            sessionFor: (personaKey) => {
                if (!sessions[personaKey]) throw new Error(`ACCEPTANCE_ACTION_PERSONA_NOT_LOGGED_IN:${personaKey}`);
                return sessions[personaKey];
            }
        });
        if (!outcome || outcome.passed !== true) {
            const code = outcome && outcome.blocked
                ? `ACCEPTANCE_ACTION_BLOCKED:${definition.id}:${action}`
                : `ACCEPTANCE_ACTION_FAILED:${definition.id}:${action}`;
            const error = outcome && outcome.blocked ? new AcceptanceBlockedError(code) : new Error(code);
            error.actionEvidence = sanitizeEvidence(outcome && outcome.evidence ? outcome.evidence : {});
            throw error;
        }
        return sanitizeEvidence(outcome.evidence || { action });
    }

    async runDefinitions(definitions) {
        validateJourneyDefinitions(definitions);
        for (const definition of definitions) {
            await this.scenario(definition.id, definition.personas[0], async () => {
                const sessions = {};
                for (const personaKey of definition.personas) {
                    const session = this.session(personaKey);
                    await session.login((definition.roles || {})[personaKey] || null);
                    sessions[personaKey] = session;
                }
                const steps = [];
                for (const action of definition.actions) {
                    steps.push({ action, evidence: await this.performAction(definition, action, sessions) });
                }
                return { title: definition.title, mutates: definition.mutates === true, steps };
            });
        }
        return this.summary();
    }

    record(scenarioId, personaKey, status, startedAt, evidence = {}) {
        const result = sanitizeEvidence({
            dataset_id: DATASET_ID,
            dataset_version: DATASET_VERSION,
            scenario_id: scenarioId,
            persona: personaKey,
            status,
            started_at: startedAt,
            finished_at: new Date().toISOString(),
            evidence
        });
        this.results.push(result);
        return result;
    }

    summary() {
        const counts = { passed: 0, failed: 0, blocked: 0 };
        for (const result of this.results) counts[result.status] += 1;
        return sanitizeEvidence({ dataset_id: DATASET_ID, dataset_version: DATASET_VERSION, counts, results: this.results });
    }
}

function validateJourneyDefinitions(definitions) {
    if (!Array.isArray(definitions) || definitions.length === 0) throw new Error('ACCEPTANCE_JOURNEYS_REQUIRED');
    const ids = new Set();
    for (const definition of definitions) {
        if (!definition || !/^Q(?:0[1-9]|[12][0-9]|3[0-3])$/.test(definition.id || '')) {
            throw new Error('ACCEPTANCE_JOURNEY_ID_INVALID');
        }
        if (ids.has(definition.id)) throw new Error(`ACCEPTANCE_JOURNEY_DUPLICATE:${definition.id}`);
        ids.add(definition.id);
        if (typeof definition.title !== 'string' || definition.title.trim() === '') {
            throw new Error(`ACCEPTANCE_JOURNEY_TITLE_REQUIRED:${definition.id}`);
        }
        if (!Array.isArray(definition.personas) || definition.personas.length === 0
            || definition.personas.some((persona) => !PERSONAS[persona])) {
            throw new Error(`ACCEPTANCE_JOURNEY_PERSONA_INVALID:${definition.id}`);
        }
        if (!Array.isArray(definition.actions) || definition.actions.length < 2
            || definition.actions.some((action) => !/^[a-z][a-z0-9_]+$/.test(action))) {
            throw new Error(`ACCEPTANCE_JOURNEY_ACTIONS_INVALID:${definition.id}`);
        }
        if (definition.mutates === true && !definition.actions.some((action) => /verify|assert|reconcile|report|audit/.test(action))) {
            throw new Error(`ACCEPTANCE_JOURNEY_VERIFICATION_REQUIRED:${definition.id}`);
        }
    }
    return true;
}

function configFromEnvironment(environment = process.env) {
    const baseUrl = String(environment.STAFF_HR_ACCEPTANCE_BASE_URL || '');
    const password = String(environment.STAFF_HR_ACCEPTANCE_PASSWORD || '');
    if (!/^https?:\/\//i.test(baseUrl)) throw new AcceptanceBlockedError('ACCEPTANCE_BASE_URL_REQUIRED');
    if (password.length < 12) throw new AcceptanceBlockedError('ACCEPTANCE_PASSWORD_REQUIRED');
    return { baseUrl: baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`, password, timeoutMs: 15000 };
}

module.exports = {
    AcceptanceBlockedError,
    CookieJar,
    HttpTransport,
    PERSONAS,
    PersonaSession,
    StaffHrAcceptanceRunner,
    configFromEnvironment,
    encodeMultipart,
    extractCsrf,
    sanitizeEvidence,
    resolveSameOriginRedirect,
    validateJourneyDefinitions
};
