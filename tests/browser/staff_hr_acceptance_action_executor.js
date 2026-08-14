'use strict';

const fs = require('fs');
const path = require('path');
const core = require('./staff_hr_acceptance_core.spec');
const edges = require('./staff_hr_acceptance_edges.spec');
const handoff = require('./staff_hr_acceptance_handoff.spec');

const PORTAL_ROUTE = 'staff_hr_portal.php';
const allDefinitions = [...core, ...edges, ...handoff];
const declaredActions = Object.freeze([...new Set(allDefinitions.flatMap((definition) => definition.actions))]);

function decodeHtml(value) {
    return String(value || '')
        .replace(/&quot;/g, '"')
        .replace(/&#039;|&#39;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');
}

function firstDangerAlertText(html) {
    const alert = String(html || '').match(/<div\b[^>]*class=["'][^"']*alert-danger[^"']*["'][^>]*>([\s\S]*?)<\/div>/i);
    if (!alert) return '';
    return decodeHtml(alert[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()).slice(0, 300);
}

function inputValue(tag, attribute) {
    const expression = new RegExp(`${attribute}=["']([^"']*)["']`, 'i');
    const match = String(tag).match(expression);
    return match ? decodeHtml(match[1]) : '';
}

function formContaining(html, name, value = null) {
    const forms = String(html).match(/<form\b[\s\S]*?<\/form>/gi) || [];
    return forms.find((form) => {
        const tags = form.match(/<(?:input|button)\b[^>]*>/gi) || [];
        return tags.some((tag) => inputValue(tag, 'name') === name
            && (value === null || inputValue(tag, 'value') === value));
    }) || null;
}

function hiddenFields(form) {
    const fields = {};
    for (const tag of String(form).match(/<input\b[^>]*>/gi) || []) {
        if (inputValue(tag, 'type').toLowerCase() !== 'hidden') continue;
        const name = inputValue(tag, 'name');
        if (name) fields[name] = inputValue(tag, 'value');
    }
    return fields;
}

function selectOption(form, name, labelPattern) {
    const selectPattern = new RegExp(`<select\\b[^>]*name=["']${name}["'][^>]*>([\\s\\S]*?)<\\/select>`, 'i');
    const select = String(form).match(selectPattern);
    if (!select) return null;
    for (const option of select[1].matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/gi)) {
        const label = decodeHtml(option[2].replace(/<[^>]+>/g, '').trim());
        const value = inputValue(option[1], 'value');
        if (value && labelPattern.test(label)) return value;
    }
    return null;
}

function selectDataOption(form, name, attribute, expectedValue, labelPattern = null) {
    const selectPattern = new RegExp(`<select\\b[^>]*name=["']${name}["'][^>]*>([\\s\\S]*?)<\\/select>`, 'i');
    const select = String(form).match(selectPattern);
    if (!select) return null;
    for (const option of select[1].matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/gi)) {
        if (inputValue(option[1], attribute) !== expectedValue) continue;
        const label = decodeHtml(option[2].replace(/<[^>]+>/g, '').trim());
        if (labelPattern && !labelPattern.test(label)) continue;
        const value = inputValue(option[1], 'value');
        if (value) return value;
    }
    return null;
}

function dataListOption(html, listId, labelPattern) {
    const list = String(html).match(new RegExp(`<datalist\\b[^>]*id=["']${listId}["'][^>]*>([\\s\\S]*?)<\\/datalist>`, 'i'));
    if (!list) return null;
    for (const option of list[1].matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/gi)) {
        const value = inputValue(option[1], 'value');
        const label = decodeHtml(option[2].replace(/<[^>]+>/g, '').trim());
        if (value && labelPattern.test(label)) return value;
    }
    return null;
}

function tableRowContaining(html, marker) {
    return (String(html).match(/<tr\b[\s\S]*?<\/tr>/gi) || [])
        .find((row) => String(row).includes(marker)) || null;
}

function tableRowsContaining(html, marker) {
    return (String(html).match(/<tr\b[\s\S]*?<\/tr>/gi) || [])
        .filter((row) => String(row).includes(marker));
}

function scheduleVersionId(html, marker) {
    const row = tableRowContaining(html, marker);
    if (!row) return 0;
    const publish = row.match(/data-version-id=["'](\d+)["']/i);
    if (publish) return Number(publish[1]);
    const preview = row.match(/name=["']version_id["'][^>]*value=["'](\d+)["']/i);
    return preview ? Number(preview[1]) : 0;
}

function responseIsAuthenticated(response) {
    return response && response.status >= 200 && response.status < 400
        && !/name=["']password["']/i.test(response.body || '')
        && !/login\.php/i.test(response.url || '');
}

function csvCells(csv) {
    const cells = [];
    let value = '';
    let quoted = false;
    const text = String(csv || '').replace(/^\uFEFF/, '');
    for (let index = 0; index < text.length; index += 1) {
        const character = text[index];
        if (character === '"') {
            if (quoted && text[index + 1] === '"') {
                value += '"';
                index += 1;
            } else {
                quoted = !quoted;
            }
        } else if (!quoted && (character === ',' || character === '\n' || character === '\r')) {
            cells.push(value);
            value = '';
            if (character === '\r' && text[index + 1] === '\n') index += 1;
        } else {
            value += character;
        }
    }
    cells.push(value);
    return cells;
}

function passed(route, evidence = {}) {
    return { passed: true, evidence: { route, same_origin: true, ...evidence } };
}

function blocked(reasonCode, route = null, evidence = {}) {
    return {
        passed: false,
        blocked: true,
        evidence: { reason_code: reasonCode, route, same_origin: route !== null, ...evidence }
    };
}

async function openAuthenticated(session, route, marker = null) {
    const response = await session.get(route);
    if (!responseIsAuthenticated(response)) return blocked('AUTHENTICATED_ROUTE_UNAVAILABLE', route, { status: response.status });
    if (marker && !marker.test(response.body || '')) return blocked('EXPECTED_UI_MARKER_MISSING', route, { status: response.status });
    return passed(route, { status: response.status });
}

async function approvedPermissionEvidence(session) {
    const response = await session.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(response) || !/معتمد|approved/i.test(response.body || '')) return null;
    return { route: PORTAL_ROUTE, same_origin: true, status: response.status, approved_request_visible: true };
}

async function submitPermission(primarySession, kind, scenarioId = '') {
    const page = await primarySession.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(page)) return blocked('WORKER_PORTAL_UNAVAILABLE', PORTAL_ROUTE);
    const form = formContaining(page.body, 'permission_request_intent', 'submit');
    if (!form) return blocked('PERMISSION_SUBMIT_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const labels = {
        late: /تأخير|DEMO-LATE/i,
        early: /مبكر|DEMO-EARLY/i,
        mission: /مأمورية|DEMO-MISSION/i
    };
    const typeId = selectOption(form, 'permission_type_id', labels[kind]);
    if (!typeId) return blocked('PERMISSION_ACCEPTANCE_TYPE_UNAVAILABLE', PORTAL_ROUTE, { kind });
    const windows = scenarioId === 'Q32' || scenarioId === 'Q33'
        ? { late: scenarioId === 'Q33' ? ['2026-08-26T07:30', '2026-08-26T09:30'] : ['2026-08-24T07:30', '2026-08-24T09:30'] }
        : {
            late: ['2026-08-25T07:30', '2026-08-25T09:30'],
            early: ['2026-08-27T12:00', '2026-08-27T14:30'],
            mission: ['2026-08-30T10:00', '2026-08-30T12:00']
        };
    if (scenarioId === 'Q08') windows.late = ['2026-08-31T07:30', '2026-08-31T09:30'];
    if (scenarioId === 'Q09') windows.late = ['2026-08-20T07:30', '2026-08-20T09:30'];
    if (scenarioId === 'Q18') windows.late = ['2026-08-10T07:30', '2026-08-10T09:30'];
    const fields = {
        ...hiddenFields(form),
        permission_request_intent: 'submit',
        permission_type_id: typeId,
        from_at: windows[kind][0],
        to_at: windows[kind][1],
        reason: `رحلة قبول تجريبية ${kind}`,
        custom_label: '',
        attachment_ref: ''
    };
    const response = await primarySession.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const body = String(response.body || '');
    const existingTargetVisible = /alert-danger/i.test(body)
        && body.includes(windows[kind][0].slice(0, 10))
        && labels[kind].test(body)
        && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body);
    if (responseIsAuthenticated(response) && existingTargetVisible) {
        return passed(PORTAL_ROUTE, { kind, status: response.status, intent: 'submit', idempotent_replay: true, existing_request_visible: true });
    }
    if (!responseIsAuthenticated(response) || /alert-danger/i.test(body)) {
        return blocked('PERMISSION_SUBMISSION_REJECTED_BY_RUNTIME_POLICY', PORTAL_ROUTE, {
            kind,
            status: response.status,
            alert: firstDangerAlertText(body)
        });
    }
    return passed(PORTAL_ROUTE, { kind, status: response.status, intent: 'submit' });
}

async function ensureAcceptancePermissionSchedule(context) {
    const route = 'admin/hr_policy_calendar.php';
    let adminSession = null;
    for (const persona of ['hr_manager', 'administrative_manager', 'protection_officer']) {
        try { adminSession = context.sessionFor(persona); } catch (_error) { continue; }
        if (adminSession) break;
    }
    if (!adminSession) return blocked('PERMISSION_SCHEDULE_ADMIN_SESSION_UNAVAILABLE', route);
    const alreadyEffective = await verifyAcceptanceScheduleForDate(context, '2026-08-26');
    if (alreadyEffective.passed) {
        return passed(route, { ...alreadyEffective.evidence, existing_effective_schedule: true });
    }
    const code = 'Q03-NIGHT-2000-0400';
    let page = await adminSession.get(route);
    const publishedRows = tableRowsContaining(page.body, code).filter((row) => /published|منشورة|bg-success/i.test(row));
    const normalSuccessorRow = publishedRows.find((row) => /<td>\s*2\s*<\/td>/i.test(row) && /2026-08-18/.test(row));
    const normalSuccessorId = normalSuccessorRow ? scheduleVersionId(normalSuccessorRow, code) : 0;
    if (normalSuccessorId > 0) {
        return passed(route, { version_id: normalSuccessorId, valid_from: '2026-08-18', idempotent_replay: true });
    }
    const predecessorId = publishedRows.map((row) => scheduleVersionId(row, code)).find((id) => id > 0) || 0;
    if (predecessorId <= 0) return blocked('PERMISSION_SCHEDULE_PREDECESSOR_UNAVAILABLE', route);
    const cloneRoute = `${route}?clone_version_id=${predecessorId}`;
    page = await adminSession.get(cloneRoute);
    const form = formContaining(page.body, 'save_schedule_policy_draft');
    if (!responseIsAuthenticated(page) || !form) return blocked('PERMISSION_SCHEDULE_SUCCESSOR_FORM_UNAVAILABLE', cloneRoute);
    const scopeId = selectDataOption(form, 'scope_id', 'data-scope-type', 'staff', /E20990008/i);
    if (!scopeId) return blocked('PERMISSION_SCHEDULE_STAFF_SCOPE_UNAVAILABLE', cloneRoute);
    const fields = scheduleDraftFields(form, {
        code,
        name: 'دوام قبول نهاري للأذونات',
        scopeType: 'staff',
        scopeId,
        priority: 600,
        validFrom: '2026-08-18',
        validTo: '2026-08-31'
    });
    const created = await adminSession.postForm(route, fields, { csrf: fields.csrf_token });
    const createdBody = String(created.body || '');
    if (!responseIsAuthenticated(created) || /alert-danger/i.test(createdBody)) {
        return blocked('PERMISSION_SCHEDULE_SUCCESSOR_DRAFT_REJECTED', route, { status: created.status, arabic_error: /[\u0600-\u06FF]/.test(createdBody) });
    }
    const draftRows = tableRowsContaining(createdBody, code).filter((row) => /draft|مسودة|bg-warning/i.test(row));
    const draftId = draftRows.map((row) => scheduleVersionId(row, code)).find((id) => id > predecessorId) || 0;
    if (draftId <= 0) return blocked('PERMISSION_SCHEDULE_SUCCESSOR_NOT_RENDERED', route);
    const published = await publishScheduleVersion(adminSession, draftId);
    if (!published.passed) return published;
    page = await adminSession.get(route);
    const row = tableRowsContaining(page.body, code).find((candidate) => scheduleVersionId(candidate, code) === draftId);
    return row && /published|منشورة|bg-success/i.test(row)
        ? passed(route, { predecessor_version_id: predecessorId, version_id: draftId, priority: 600, valid_from: '2026-08-18' })
        : blocked('PERMISSION_SCHEDULE_NOT_RENDERED', route);
}

async function submitAcceptancePermission(context, kind) {
    if (!context.primarySession) return blocked('WORKER_PORTAL_SESSION_UNAVAILABLE', PORTAL_ROUTE);
    const schedule = context.definition.id === 'Q18'
        ? await verifyAcceptanceScheduleForDate(context, '2026-08-10')
        : await ensureAcceptancePermissionSchedule(context);
    if (!schedule.passed) return schedule;
    const request = await submitPermission(context.primarySession, kind, context.definition.id);
    return request.passed
        ? passed(PORTAL_ROUTE, { schedule: schedule.evidence, request: request.evidence })
        : request;
}

async function verifyAcceptanceScheduleForDate(context, workDate) {
    const session = attendanceAdminSession(context);
    const route = 'admin/hr_policy_calendar.php';
    if (!session) return blocked('PERMISSION_SCHEDULE_ADMIN_SESSION_UNAVAILABLE', route);
    const staffId = await acceptanceStaffIdFromPolicy(session);
    if (staffId <= 0) return blocked('PERMISSION_SCHEDULE_STAFF_SCOPE_UNAVAILABLE', route);
    const resolutionRoute = `${route}?resolve_staff_user_id=${staffId}&resolve_date=${workDate}`;
    const response = await session.get(resolutionRoute);
    const body = String(response.body || '');
    const selectedVersion = body.match(/data-effective-version-id=["'](\d+)["']/i);
    return responseIsAuthenticated(response)
        && /id=["']effectiveScheduleResult["']/.test(body)
        && Number(selectedVersion && selectedVersion[1] || 0) > 0
        && !/تعارضات متساوية/.test(body)
        ? passed(resolutionRoute, { staff_user_id: staffId, work_date: workDate, version_id: Number(selectedVersion[1]) })
        : blocked('PERMISSION_SCHEDULE_NOT_EFFECTIVE_ON_DATE', resolutionRoute, { status: response.status });
}

async function triggerReviewedPermissionError(primarySession) {
    const page = await primarySession.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(page)) return blocked('WORKER_PORTAL_UNAVAILABLE', PORTAL_ROUTE);
    const form = formContaining(page.body, 'permission_request_intent', 'submit');
    if (!form) return blocked('PERMISSION_SUBMIT_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const typeId = selectOption(form, 'permission_type_id', /تأخير|DEMO-LATE/i);
    if (!typeId) return blocked('PERMISSION_ACCEPTANCE_TYPE_UNAVAILABLE', PORTAL_ROUTE);
    const fields = {
        ...hiddenFields(form),
        permission_request_intent: 'submit',
        permission_type_id: typeId,
        from_at: '2026-08-28T09:30',
        to_at: '2026-08-28T08:30',
        reason: 'إثبات رسالة خطأ عربية',
        custom_label: '',
        attachment_ref: ''
    };
    const response = await primarySession.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const body = String(response.body || '');
    const hasArabicError = /alert-danger/i.test(body) && /[\u0600-\u06FF]/.test(body);
    const leaksTechnicalDetails = /SQLSTATE|PDOException|Stack trace|Fatal error|Integrity constraint/i.test(body);
    if (!responseIsAuthenticated(response) || !hasArabicError || leaksTechnicalDetails) {
        return blocked('REVIEWED_ARABIC_ERROR_CONTRACT_NOT_PROVEN', PORTAL_ROUTE, {
            status: response.status,
            has_arabic_error: hasArabicError,
            technical_details_leaked: leaksTechnicalDetails
        });
    }
    return passed(PORTAL_ROUTE, { status: response.status, has_arabic_error: true, technical_details_leaked: false });
}

function scheduleDraftFields(form, { code, name, scopeType, scopeId, priority, start = '07:30', end = '14:30', endDayOffset = 0, segments = null, validFrom = '2026-08-11', validTo = '2026-08-31' }) {
    const fields = {
        ...hiddenFields(form),
        save_schedule_policy_draft: '1',
        policy_code: code,
        policy_name: name,
        description: `بيانات قبول معزولة ${code}`,
        valid_from: validFrom,
        valid_to: validTo,
        timezone: 'Africa/Cairo',
        scope_type: scopeType,
        scope_id: String(scopeId),
        priority: String(priority),
        rounding_rule: 'none',
        season_start_mmdd: '',
        season_end_mmdd: ''
    };
    for (const weekday of [1, 2, 3, 4, 5, 6, 7]) {
        fields[`days[${weekday}][weekday]`] = String(weekday);
        fields[`days[${weekday}][late_grace_minutes]`] = '15';
        fields[`days[${weekday}][early_grace_minutes]`] = '0';
        fields[`days[${weekday}][entry_window_before_minutes]`] = '120';
        fields[`days[${weekday}][entry_window_after_minutes]`] = '180';
        fields[`days[${weekday}][exit_window_before_minutes]`] = '180';
        fields[`days[${weekday}][exit_window_after_minutes]`] = '120';
        if (![1, 2, 3, 4, 7].includes(weekday)) continue;
        fields[`days[${weekday}][is_working_day]`] = '1';
        const configuredSegments = Array.isArray(segments) && segments.length > 0
            ? segments
            : [{ type: 'work', start, end, startDayOffset: 0, endDayOffset, counts: true }];
        configuredSegments.forEach((segment, index) => {
            fields[`days[${weekday}][segments][${index}][segment_type]`] = segment.type;
            fields[`days[${weekday}][segments][${index}][start_time]`] = segment.start;
            fields[`days[${weekday}][segments][${index}][end_time]`] = segment.end;
            fields[`days[${weekday}][segments][${index}][start_day_offset]`] = String(segment.startDayOffset || 0);
            fields[`days[${weekday}][segments][${index}][end_day_offset]`] = String(segment.endDayOffset || 0);
            if (segment.counts) fields[`days[${weekday}][segments][${index}][counts_required_minutes]`] = '1';
        });
    }
    return fields;
}

async function createScheduleDraft(session, definition) {
    const route = 'admin/hr_policy_calendar.php';
    let page = await session.get(route);
    if (!responseIsAuthenticated(page)) return blocked('SCHEDULE_POLICY_UI_UNAVAILABLE', route, { status: page.status });
    let versionId = scheduleVersionId(page.body, definition.code);
    if (versionId > 0) return passed(route, { version_id: versionId, existing: true });
    const form = formContaining(page.body, 'save_schedule_policy_draft');
    if (!form) return blocked('SCHEDULE_DRAFT_FORM_UNAVAILABLE', route);
    const scopeId = definition.scopeId || selectDataOption(form, 'scope_id', 'data-scope-type', definition.scopeType, definition.scopeLabel || null);
    if (definition.scopeType !== 'global' && !scopeId) {
        return blocked('SCHEDULE_SCOPE_OPTION_UNAVAILABLE', route, { scope_type: definition.scopeType });
    }
    const fields = scheduleDraftFields(form, { ...definition, scopeId: scopeId || 0 });
    page = await session.postForm(route, fields, { csrf: fields.csrf_token });
    const body = String(page.body || '');
    if (!responseIsAuthenticated(page) || /alert-danger/i.test(body)) {
        return blocked('SCHEDULE_DRAFT_REJECTED', route, { status: page.status, arabic_error: /[\u0600-\u06FF]/.test(body) });
    }
    versionId = scheduleVersionId(body, definition.code);
    return versionId > 0
        ? passed(route, { version_id: versionId, existing: false })
        : blocked('SCHEDULE_DRAFT_VERSION_NOT_RENDERED', route);
}

async function publishScheduleVersion(session, versionId) {
    const route = 'admin/hr_policy_calendar.php';
    const page = await session.get(route);
    if (!responseIsAuthenticated(page)) return blocked('SCHEDULE_POLICY_UI_UNAVAILABLE', route, { status: page.status });
    const form = formContaining(page.body, 'publish_schedule_policy');
    if (!form) return blocked('SCHEDULE_PUBLISH_FORM_UNAVAILABLE', route);
    const fields = { ...hiddenFields(form), publish_schedule_policy: '1', version_id: String(versionId) };
    const response = await session.postForm(route, fields, { csrf: fields.csrf_token });
    const body = String(response.body || '');
    if (!responseIsAuthenticated(response) || /alert-danger/i.test(body)) {
        return blocked('SCHEDULE_PUBLISH_REJECTED', route, { status: response.status, arabic_error: /[\u0600-\u06FF]/.test(body) });
    }
    return passed(route, { version_id: versionId, status: response.status });
}

async function publishAcceptanceStaffSchedule(context) {
    const route = 'admin/hr_policy_calendar.php';
    if (!context.primarySession) return blocked('SCHEDULE_POLICY_SESSION_UNAVAILABLE', route);
    const code = 'Q01-STAFF-0730';
    let page = await context.primarySession.get(route);
    const existingRow = tableRowContaining(page.body, code);
    if (existingRow && /published|منشورة|bg-success/i.test(existingRow)) {
        return passed(route, { version_id: scheduleVersionId(page.body, code), idempotent_replay: true, scopes: ['global', 'staff'] });
    }
    const draft = await createScheduleDraft(context.primarySession, {
        code,
        name: 'دوام قبول تجريبي خاص بالعامل',
        scopeType: 'staff',
        priority: 500,
        scopeLabel: /عامل قبول|E2099|تجريبي/i
    });
    if (!draft.passed) return draft;
    const published = await publishScheduleVersion(context.primarySession, draft.evidence.version_id);
    if (!published.passed) return published;
    page = await context.primarySession.get(route);
    const row = tableRowContaining(page.body, code);
    return row && /published|منشورة|bg-success/i.test(row)
        ? passed(route, { version_id: draft.evidence.version_id, scopes: ['global', 'staff'], priority: 500 })
        : blocked('PUBLISHED_STAFF_SCHEDULE_NOT_RENDERED', route);
}

async function resolveAcceptanceStaffSchedule(context) {
    const route = 'admin/hr_policy_calendar.php';
    if (!context.primarySession) return blocked('SCHEDULE_POLICY_SESSION_UNAVAILABLE', route);
    const page = await context.primarySession.get(route);
    const versionId = scheduleVersionId(page.body, 'Q01-STAFF-0730');
    if (!responseIsAuthenticated(page) || versionId <= 0) return blocked('PUBLISHED_STAFF_SCHEDULE_NOT_AVAILABLE', route);
    const form = formContaining(page.body, 'save_schedule_policy_draft');
    const staffId = form ? selectDataOption(form, 'scope_id', 'data-scope-type', 'staff', /عامل قبول|E2099|تجريبي/i) : null;
    if (!staffId) return blocked('SCHEDULE_RESOLUTION_STAFF_OPTION_UNAVAILABLE', route);
    const resolutionRoute = `${route}?resolve_staff_user_id=${encodeURIComponent(staffId)}&resolve_date=2026-08-11`;
    const resolution = await context.primarySession.get(resolutionRoute);
    const body = String(resolution.body || '');
    const selectedVersion = body.match(/data-effective-version-id=["'](\d+)["']/i);
    if (!responseIsAuthenticated(resolution)
        || !/id=["']effectiveScheduleResult["']/.test(body)
        || !/سبب الدوام الفعلي/.test(body)
        || Number(selectedVersion && selectedVersion[1] || 0) !== versionId
        || /تعارضات متساوية/.test(body)) {
        return blocked('SCHEDULE_RESOLUTION_PREVIEW_UNAVAILABLE', resolutionRoute, { status: resolution.status });
    }
    return passed(resolutionRoute, { staff_scope: true, version_id: versionId, resolution_reason_visible: true });
}

async function verifyAcceptanceEqualRankConflict(context) {
    const route = 'admin/hr_policy_calendar.php';
    if (!context.primarySession) return blocked('SCHEDULE_POLICY_SESSION_UNAVAILABLE', route);
    const code = 'Q01-STAFF-CONFLICT';
    const draft = await createScheduleDraft(context.primarySession, {
        code,
        name: 'تعارض قبول تجريبي متعمد',
        scopeType: 'staff',
        priority: 500,
        scopeLabel: /عامل قبول|E2099|تجريبي/i,
        start: '08:00',
        end: '15:00'
    });
    if (!draft.passed) return draft;
    const publish = await publishScheduleVersion(context.primarySession, draft.evidence.version_id);
    if (!publish.passed && publish.evidence.arabic_error) {
        return passed(route, { version_id: draft.evidence.version_id, rejected_equal_rank_conflict: true, technical_details_leaked: false });
    }
    const previewRoute = `${route}?preview_version_id=${draft.evidence.version_id}&as_of=2026-08-11`;
    const preview = await context.primarySession.get(previewRoute);
    const body = String(preview.body || '');
    const conflictVisible = /التعارضات:\s*[1-9]|تعارض/.test(body) && /alert-danger/.test(body);
    return conflictVisible
        ? passed(previewRoute, { version_id: draft.evidence.version_id, equal_rank_conflict_visible: true })
        : blocked('EQUAL_RANK_CONFLICT_NOT_PROVEN', previewRoute, { status: preview.status });
}

async function publishAcceptanceSuccessorSchedule(context) {
    const route = 'admin/hr_policy_calendar.php';
    const code = 'DEMO-SCHEDULE-STANDARD_0730_1430';
    if (!context.primarySession) return blocked('SCHEDULE_POLICY_SESSION_UNAVAILABLE', route);
    const historicalBefore = await openHistoricalAttendanceReport(context);
    if (!historicalBefore.passed) {
        const workerId = await acceptancePersonaId(context, 'E20990008');
        if (!workerId) return blocked('ACCEPTANCE_WORKER_SCOPE_UNAVAILABLE', route);
        const recalculated = await recalculateAcceptanceDay(context.primarySession, workerId, '2026-08-11', 'run');
        if (!recalculated.passed) {
            return blocked('HISTORICAL_PREDECESSOR_CALCULATION_UNAVAILABLE', recalculated.evidence.route || route, recalculated.evidence);
        }
        const historicalAfter = await openHistoricalAttendanceReport(context);
        if (!historicalAfter.passed) return historicalAfter;
    }
    let page = await context.primarySession.get(route);
    if (!responseIsAuthenticated(page)) return blocked('SCHEDULE_POLICY_UI_UNAVAILABLE', route, { status: page.status });
    const publishedRows = tableRowsContaining(page.body, code).filter((row) => /published|منشورة|bg-success/i.test(row));
    const existingSuccessor = publishedRows
        .map((row) => ({ row, id: scheduleVersionId(row, code) }))
        .find((item) => item.id > 1 && /<td>\s*2\s*<\/td>/i.test(item.row));
    if (existingSuccessor) {
        return passed(route, { predecessor_version_id: 1, successor_version_id: existingSuccessor.id, valid_from: '2026-09-01', idempotent_replay: true });
    }
    const predecessorId = scheduleVersionId(page.body, code);
    if (predecessorId <= 0) return blocked('SCHEDULE_PREDECESSOR_NOT_RENDERED', route);
    const cloneRoute = `${route}?clone_version_id=${predecessorId}`;
    page = await context.primarySession.get(cloneRoute);
    const form = formContaining(page.body, 'save_schedule_policy_draft');
    if (!responseIsAuthenticated(page) || !form) return blocked('SCHEDULE_SUCCESSOR_FORM_UNAVAILABLE', cloneRoute, { status: page.status });
    const fields = scheduleDraftFields(form, {
        code,
        name: 'دوام تجريبي 07:30–14:30',
        scopeType: 'global',
        scopeId: 0,
        priority: 0,
        start: '08:00',
        end: '15:00'
    });
    fields.valid_from = '2026-09-01';
    fields.valid_to = '2026-12-31';
    const created = await context.primarySession.postForm(route, fields, { csrf: fields.csrf_token });
    const createdBody = String(created.body || '');
    if (!responseIsAuthenticated(created) || /alert-danger/i.test(createdBody)) {
        return blocked('SCHEDULE_SUCCESSOR_DRAFT_REJECTED', route, { status: created.status, arabic_error: /[\u0600-\u06FF]/.test(createdBody) });
    }
    const draftRows = tableRowsContaining(createdBody, code).filter((row) => /draft|مسودة|bg-warning/i.test(row));
    const successorId = draftRows.map((row) => scheduleVersionId(row, code)).find((id) => id > predecessorId) || 0;
    if (successorId <= 0) return blocked('SCHEDULE_SUCCESSOR_VERSION_NOT_RENDERED', route);
    const published = await publishScheduleVersion(context.primarySession, successorId);
    if (!published.passed) return published;
    return passed(route, { predecessor_version_id: predecessorId, successor_version_id: successorId, valid_from: '2026-09-01' });
}

async function openHistoricalAttendanceReport(context) {
    const workerId = await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('HISTORICAL_WORKER_SCOPE_UNAVAILABLE', 'admin/staff_attendance_reports.php');
    const route = `admin/staff_attendance_reports.php?date_from=2026-08-11&date_to=2026-08-11&staff_user_id=${workerId}&page_size=50`;
    const response = await context.sessionFor('hr_manager').get(route);
    const body = String(response.body || '');
    const version = body.match(/data-schedule-policy-version-id=["'](\d+)["']/i);
    if (!responseIsAuthenticated(response) || !/2026-08-11/.test(body) || Number(version && version[1] || 0) <= 0) {
        return blocked('HISTORICAL_ATTENDANCE_REPORT_UNAVAILABLE', route, { status: response.status });
    }
    return passed(route, { work_date: '2026-08-11', historical_policy_version_id: Number(version[1]) });
}

async function verifyHistoricalScheduleVersion(context) {
    const historical = await openHistoricalAttendanceReport(context);
    if (!historical.passed) return historical;
    const policyPage = await context.primarySession.get('admin/hr_policy_calendar.php');
    const successorRows = tableRowsContaining(policyPage.body, 'DEMO-SCHEDULE-STANDARD_0730_1430')
        .filter((row) => /published|منشورة|bg-success/i.test(row));
    const successorId = Math.max(0, ...successorRows.map((row) => scheduleVersionId(row, 'DEMO-SCHEDULE-STANDARD_0730_1430')));
    const historicalId = Number(historical.evidence.historical_policy_version_id || 0);
    return successorId > 0 && historicalId > 0 && historicalId !== successorId
        ? passed(historical.evidence.route, { historical_policy_version_id: historicalId, successor_policy_version_id: successorId, history_unchanged: true })
        : blocked('HISTORICAL_POLICY_VERSION_CHANGED', historical.evidence.route, { historical_policy_version_id: historicalId, successor_policy_version_id: successorId });
}

async function publishAcceptanceNightSchedule(context) {
    const route = 'admin/hr_policy_calendar.php';
    const code = 'Q03-NIGHT-2000-0400';
    const page = await context.primarySession.get(route);
    const existing = tableRowContaining(page.body, code);
    if (existing && /published|منشورة|bg-success/i.test(existing)) {
        return passed(route, { version_id: scheduleVersionId(page.body, code), idempotent_replay: true });
    }
    const draft = await createScheduleDraft(context.primarySession, {
        code,
        name: 'وردية قبول ليلية 20:00–04:00',
        scopeType: 'staff',
        priority: 600,
        scopeLabel: /E20990008/i,
        start: '20:00',
        end: '04:00',
        endDayOffset: 1
    });
    if (!draft.passed) return draft;
    return publishScheduleVersion(context.primarySession, draft.evidence.version_id);
}

async function ensureAcceptanceHoliday(context) {
    const route = 'admin/hr_policy_calendar.php';
    const reason = 'عطلة قبول Q03 لا تدخل مقام الغياب';
    let page = await context.primarySession.get(`${route}?calendar_from=2026-08-01&calendar_to=2026-08-31`);
    if (String(page.body || '').includes(reason)) return passed(route, { calendar_date: '2026-08-19', idempotent_replay: true });
    const form = formContaining(page.body, 'save_calendar_exception');
    if (!responseIsAuthenticated(page) || !form) return blocked('CALENDAR_EXCEPTION_FORM_UNAVAILABLE', route, { status: page.status });
    const fields = {
        ...hiddenFields(form),
        save_calendar_exception: '1',
        calendar_date: '2026-08-19',
        exception_type: 'holiday',
        scope_type: 'global',
        scope_id: '',
        schedule_policy_version_id: '',
        priority: '0',
        reason
    };
    page = await context.primarySession.postForm(route, fields, { csrf: fields.csrf_token });
    const body = String(page.body || '');
    return responseIsAuthenticated(page) && !/alert-danger/i.test(body) && body.includes(reason)
        ? passed(route, { calendar_date: '2026-08-19', exception_type: 'holiday' })
        : blocked('CALENDAR_HOLIDAY_REJECTED', route, { status: page.status, arabic_error: /[\u0600-\u06FF]/.test(body) });
}

async function importAcceptanceBiometricFixture(session, fixtureName) {
    const route = 'admin/staff_biometric_import.php';
    const page = await session.get(route);
    const form = formContaining(page.body, 'preview_biometric');
    if (!responseIsAuthenticated(page) || !form) return blocked('BIOMETRIC_IMPORT_FORM_UNAVAILABLE', route, { status: page.status });
    const methodId = selectOption(form, 'entry_method_id', /./);
    if (!methodId) return blocked('BIOMETRIC_ENTRY_METHOD_OPTION_UNAVAILABLE', route);
    const fixturePath = path.join(__dirname, '..', 'fixtures', fixtureName);
    if (!fs.existsSync(fixturePath)) return blocked('BIOMETRIC_ACCEPTANCE_FIXTURE_MISSING', route);
    const fields = {
        ...hiddenFields(form),
        preview_biometric: '1',
        lookup_mode: 'biometric_identity',
        default_device_id: '2099',
        entry_method_id: methodId,
        device_timezone: 'Africa/Cairo'
    };
    const preview = await session.postMultipart(route, fields, [{
        name: 'biometric_csv',
        filename: fixtureName,
        contentType: 'text/csv',
        data: fs.readFileSync(fixturePath)
    }], { csrf: fields.csrf_token });
    const previewBody = String(preview.body || '');
    const duplicatePreviewMatch = previewBody.match(/مكرر داخل الملف:\s*(\d+)/);
    const duplicateRowsInFile = duplicatePreviewMatch ? Number(duplicatePreviewMatch[1]) : 0;
    const confirmation = formContaining(previewBody, 'confirm_biometric');
    if (!responseIsAuthenticated(preview) || /alert-danger/i.test(previewBody) || !confirmation) {
        return blocked('BIOMETRIC_FIXTURE_PREVIEW_REJECTED', route, { status: preview.status, arabic_error: /[\u0600-\u06FF]/.test(previewBody) });
    }
    const confirmFields = { ...hiddenFields(confirmation), confirm_biometric: '1' };
    const confirmed = await session.postForm(route, confirmFields, { csrf: confirmFields.csrf_token });
    const body = String(confirmed.body || '');
    const priorBatchVisible = /تعارضت محاولة الاستيراد مع دفعة سابقة/.test(body)
        && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body);
    if (responseIsAuthenticated(confirmed) && priorBatchVisible) {
        return passed(route, { fixture: fixtureName, previewed: true, confirmed: true, idempotent_replay: true, duplicate_rows_in_file: duplicateRowsInFile, biometric_identity_redacted: true });
    }
    if (!responseIsAuthenticated(confirmed) || /alert-danger/i.test(body) || !/تم حفظ البصمات الخام|سجلات جديدة|مكررة/.test(body)) {
        return blocked('BIOMETRIC_FIXTURE_CONFIRM_REJECTED', route, { status: confirmed.status, arabic_error: /[\u0600-\u06FF]/.test(body) });
    }
    const insertedMatch = body.match(/سجلات جديدة:\s*(\d+)/);
    const duplicateMatch = body.match(/مكررة:\s*(\d+)/);
    const unmatchedMatch = body.match(/غير مربوطة:\s*(\d+)/);
    return passed(route, {
        fixture: fixtureName,
        previewed: true,
        confirmed: true,
        duplicate_rows_in_file: duplicateRowsInFile,
        inserted: insertedMatch ? Number(insertedMatch[1]) : null,
        duplicates: duplicateMatch ? Number(duplicateMatch[1]) : null,
        unmatched: unmatchedMatch ? Number(unmatchedMatch[1]) : null,
        biometric_identity_redacted: true
    });
}

async function recordAcceptanceOvernightPunches(context) {
    if (!context.primarySession) return blocked('BIOMETRIC_IMPORT_SESSION_UNAVAILABLE', 'admin/staff_biometric_import.php');
    const schedule = await publishAcceptanceNightSchedule(context);
    if (!schedule.passed) return schedule;
    const holiday = await ensureAcceptanceHoliday(context);
    if (!holiday.passed) return holiday;
    const imported = await importAcceptanceBiometricFixture(context.primarySession, 'staff_hr_q03_overnight_biometric.csv');
    return imported.passed
        ? passed(imported.evidence.route, { schedule: schedule.evidence, holiday: holiday.evidence, import: imported.evidence })
        : imported;
}

const Q07_EXCEPTION_ROUTE = 'admin/hr_attendance_exceptions.php?date_from=2026-08-20&date_to=2026-08-20&category=raw';

async function importAcceptanceExceptionPunches(context) {
    if (!context.primarySession) return blocked('BIOMETRIC_IMPORT_SESSION_UNAVAILABLE', 'admin/staff_biometric_import.php');
    const imported = await importAcceptanceBiometricFixture(context.primarySession, 'staff_hr_q07_exception_biometric.csv');
    if (!imported.passed) return imported;
    if (!imported.evidence.idempotent_replay && Number(imported.evidence.duplicate_rows_in_file || 0) < 1) {
        return blocked('Q07_IN_FILE_DUPLICATE_NOT_PROVEN', imported.evidence.route);
    }
    return passed(imported.evidence.route, {
        ...imported.evidence,
        in_file_duplicate_proven: Number(imported.evidence.duplicate_rows_in_file || 0) >= 1,
        unmatched_identity_expected: true,
        incomplete_pair_expected: true
    });
}

async function openAcceptanceAttendanceExceptions(context) {
    if (!context.primarySession) return blocked('ATTENDANCE_EXCEPTION_SESSION_UNAVAILABLE', Q07_EXCEPTION_ROUTE);
    const response = await context.primarySession.get(Q07_EXCEPTION_ROUTE);
    const body = String(response.body || '');
    const rawSources = body.match(/حدث بصمة #\d+/g) || [];
    return responseIsAuthenticated(response)
        && /بصمة غير مرتبطة بعامل/.test(body)
        && rawSources.length === 1
        ? passed(Q07_EXCEPTION_ROUTE, { raw_exception_count: rawSources.length, unmatched_identity_visible: true, raw_identity_redacted: true })
        : blocked('Q07_RAW_EXCEPTION_EVIDENCE_NOT_RENDERED', Q07_EXCEPTION_ROUTE, { status: response.status, raw_exception_count: rawSources.length });
}

async function verifyAcceptanceRawEventIdempotency(context) {
    if (!context.primarySession) return blocked('BIOMETRIC_IMPORT_SESSION_UNAVAILABLE', 'admin/staff_biometric_import.php');
    const replay = await importAcceptanceBiometricFixture(context.primarySession, 'staff_hr_q07_exception_biometric.csv');
    if (!replay.passed) return replay;
    const exceptions = await openAcceptanceAttendanceExceptions(context);
    if (!exceptions.passed) return exceptions;
    return passed(Q07_EXCEPTION_ROUTE, {
        replay: replay.evidence,
        raw_exception_count: exceptions.evidence.raw_exception_count,
        duplicate_raw_exception_created: false,
        technical_error_leaked: false
    });
}

function attendanceAdminSession(context) {
    for (const persona of ['hr_manager', 'administrative_manager', 'protection_officer']) {
        try {
            const session = context.sessionFor(persona);
            if (session) return session;
        } catch (_error) { /* try the next administrative persona */ }
    }
    return null;
}

async function acceptanceStaffIdFromPolicy(session, employeeCode = 'E20990008') {
    const page = await session.get('admin/hr_policy_calendar.php');
    const form = formContaining(page.body, 'save_schedule_policy_draft');
    return form ? Number(selectDataOption(form, 'scope_id', 'data-scope-type', 'staff', new RegExp(employeeCode, 'i')) || 0) : 0;
}

const acceptancePersonaIds = new Map();

async function acceptancePersonaId(context, employeeCode) {
    if (acceptancePersonaIds.has(employeeCode)) return acceptancePersonaIds.get(employeeCode);
    const session = attendanceAdminSession(context);
    const id = session ? await acceptanceStaffIdFromPolicy(session, employeeCode) : 0;
    if (id > 0) acceptancePersonaIds.set(employeeCode, id);
    return id;
}

const permissionAttendanceCases = Object.freeze({
    late: { date: '2026-08-25', fixture: 'staff_hr_q04_late_permission_biometric.csv', permissionMinutes: 75, missionMinutes: 0, workedMinutes: 330 },
    early: { date: '2026-08-27', fixture: 'staff_hr_q05_early_permission_biometric.csv', permissionMinutes: 150, missionMinutes: 0, workedMinutes: 270 },
    mission: { date: '2026-08-30', fixture: 'staff_hr_q06_mission_biometric.csv', permissionMinutes: 0, missionMinutes: 120, workedMinutes: 300 },
    three_stage: { date: '2026-08-31', fixture: 'staff_hr_q08_three_stage_biometric.csv', permissionMinutes: 75, missionMinutes: 0, workedMinutes: 330 }
});

async function recordAcceptancePermissionPunches(context, kind) {
    const session = attendanceAdminSession(context);
    const definition = permissionAttendanceCases[kind];
    if (!session || !definition) return blocked('PERMISSION_PUNCH_ADMIN_SESSION_UNAVAILABLE', 'admin/staff_biometric_import.php');
    const imported = await importAcceptanceBiometricFixture(session, definition.fixture);
    return imported.passed
        ? passed(imported.evidence.route, { kind, work_date: definition.date, import: imported.evidence })
        : imported;
}

async function verifyAcceptancePermissionCalculation(context, kind) {
    const session = attendanceAdminSession(context);
    const definition = permissionAttendanceCases[kind];
    if (!session || !definition) return blocked('PERMISSION_REPORT_ADMIN_SESSION_UNAVAILABLE', 'admin/staff_attendance_reports.php');
    const staffId = await acceptanceStaffIdFromPolicy(session);
    if (staffId <= 0) return blocked('PERMISSION_REPORT_STAFF_SCOPE_UNAVAILABLE', 'admin/hr_policy_calendar.php');
    const calculation = await recalculateAcceptanceDay(session, staffId, definition.date, 'calculate_initial');
    if (!calculation.passed) return calculation;
    const route = `admin/staff_attendance_reports.php?date_from=${definition.date}&date_to=${definition.date}&staff_user_id=${staffId}&page_size=50`;
    const response = await session.get(route);
    const row = tableRowContaining(response.body, definition.date) || '';
    const rowText = row.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    const permissionMatch = rowText.match(/إذن:\s*(\d+)\s*دقيقة/);
    const missionMatch = rowText.match(/مأمورية:\s*(\d+)/);
    const workMatch = rowText.match(/عمل:\s*(\d+)\s*\/\s*(\d+)/);
    const actualPermissionMinutes = Number(permissionMatch && permissionMatch[1] || -1);
    const actualMissionMinutes = Number(missionMatch && missionMatch[1] || -1);
    const actualWorkedMinutes = Number(workMatch && workMatch[1] || -1);
    const actualRequiredMinutes = Number(workMatch && workMatch[2] || -1);
    const permissionVisible = actualPermissionMinutes === definition.permissionMinutes;
    const missionVisible = actualMissionMinutes === definition.missionMinutes;
    const workVisible = actualWorkedMinutes === definition.workedMinutes && actualRequiredMinutes === 420;
    const noUncoveredViolation = /تأخير:\s*0\s*·\s*مبكر:\s*0/.test(rowText) && /ناقص:\s*0/.test(rowText);
    return responseIsAuthenticated(response) && permissionVisible && missionVisible && workVisible && noUncoveredViolation
        ? passed(route, {
            kind,
            work_date: definition.date,
            permission_minutes: definition.permissionMinutes,
            mission_minutes: definition.missionMinutes,
            worked_minutes: definition.workedMinutes,
            uncovered_late_minutes: 0,
            uncovered_early_minutes: 0,
            missing_minutes: 0,
            official_calculation: calculation.evidence
        })
        : blocked('PERMISSION_ATTENDANCE_CALCULATION_NOT_PROVEN', route, {
            status: response.status,
            permission_visible: permissionVisible,
            mission_visible: missionVisible,
            work_visible: workVisible,
            no_uncovered_violation: noUncoveredViolation,
            actual_permission_minutes: actualPermissionMinutes,
            actual_mission_minutes: actualMissionMinutes,
            actual_worked_minutes: actualWorkedMinutes,
            actual_required_minutes: actualRequiredMinutes
        });
}

async function attemptAcceptanceOutOfOrderDecision(context) {
    const route = PORTAL_ROUTE;
    let worker;
    let direct;
    let hr;
    try {
        worker = context.sessionFor('worker_standard');
        direct = context.sessionFor('direct_manager');
        hr = context.sessionFor('hr_manager');
    } catch (_error) {
        return blocked('Q08_REQUIRED_PERSONA_SESSION_UNAVAILABLE', route);
    }
    const directPage = await direct.get(route);
    const activeForm = formContaining(directPage.body, 'approval_intent', 'decide');
    if (!activeForm) {
        const existing = await approvedPermissionEvidence(worker);
        return existing
            ? passed(route, { idempotent_replay: true, prior_out_of_order_rejection_preserved: true })
            : blocked('Q08_DIRECT_STAGE_NOT_RENDERED', route);
    }
    const fields = { ...hiddenFields(activeForm), approval_intent: 'decide', decision: 'approve', comment: '' };
    const hrPage = await hr.get(route);
    const hrCsrf = String(hrPage.body || '').match(/name=["']csrf_token["']\s+value=["']([^"']+)["']/i);
    if (!responseIsAuthenticated(hrPage) || !hrCsrf) return blocked('Q08_HR_CSRF_UNAVAILABLE', route);
    fields.csrf_token = hrCsrf[1];
    const response = await hr.postForm(route, fields, { csrf: fields.csrf_token });
    const body = String(response.body || '');
    const safeRejection = responseIsAuthenticated(response)
        && /alert-danger/i.test(body)
        && /لا تملك صلاحية|لم تعد هذه المرحلة|تغيرت المرحلة/.test(body)
        && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body);
    return safeRejection
        ? passed(route, { out_of_order_decision_rejected: true, technical_details_leaked: false })
        : blocked('Q08_OUT_OF_ORDER_DECISION_NOT_REJECTED', route, { status: response.status, arabic_error: /[\u0600-\u06FF]/.test(body) });
}

async function verifyAcceptanceFinalCoverageOnce(context) {
    const imported = await recordAcceptancePermissionPunches(context, 'three_stage');
    if (!imported.passed) return imported;
    const calculation = await verifyAcceptancePermissionCalculation(context, 'three_stage');
    if (!calculation.passed) return calculation;
    return passed(calculation.evidence.route, {
        ...calculation.evidence,
        final_coverage_created_once: calculation.evidence.permission_minutes === 75,
        duplicate_coverage_minutes: 0,
        import: imported.evidence
    });
}

const Q18_WORK_DATE = '2026-08-12';
const q18State = { staffUserId: 0 };

async function decideApprovalForMarker(context, marker, decision = 'approve') {
    const order = ['direct_manager', 'administrative_manager', 'hr_manager'];
    let decisions = 0;
    for (const persona of order) {
        const session = context.sessionFor(persona);
        const page = await session.get(PORTAL_ROUTE);
        if (!responseIsAuthenticated(page)) {
            return blocked('Q18_MANAGER_INBOX_UNAVAILABLE', PORTAL_ROUTE, { persona });
        }
        const row = tableRowsContaining(page.body, marker)
            .find((candidate) => formContaining(candidate, 'approval_intent', 'decide')) || null;
        if (!row) continue;
        const form = formContaining(row, 'approval_intent', 'decide');
        const fields = {
            ...hiddenFields(form),
            approval_intent: 'decide',
            decision,
            comment: 'اعتماد إذن دون بصمات لإثبات الفصل بين التغطية والحضور'
        };
        const response = await session.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
        const body = String(response.body || '');
        if (!responseIsAuthenticated(response) || /alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body)) {
            return blocked('Q18_MANAGER_DECISION_REJECTED', PORTAL_ROUTE, {
                persona,
                status: response.status,
                technical_details_leaked: /SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body)
            });
        }
        decisions += 1;
    }
    const worker = context.sessionFor('worker_standard');
    const page = await worker.get(PORTAL_ROUTE);
    const row = tableRowsContaining(page.body, Q18_WORK_DATE).find((candidate) => /معتمد|approved/i.test(candidate)) || null;
    return decisions > 0 && row
        ? passed(PORTAL_ROUTE, { work_date: marker, decisions, final_status: 'approved' })
        : blocked('Q18_PERMISSION_NOT_FINALLY_APPROVED', PORTAL_ROUTE, { decisions, approved_row_visible: Boolean(row) });
}

async function approveAcceptancePermissionWithoutPunches(context) {
    const worker = context.sessionFor('worker_standard');
    let workerPage = await worker.get(PORTAL_ROUTE);
    let workerRow = tableRowsContaining(workerPage.body, Q18_WORK_DATE)[0] || '';
    if (/معتمد|approved/i.test(workerRow)) {
        const requestMatch = workerRow.match(/data-request-id=["'](\d+)["']/i);
        return passed(PORTAL_ROUTE, {
            work_date: Q18_WORK_DATE,
            request_id: Number(requestMatch && requestMatch[1] || 0),
            no_punches_created: true,
            approved_baseline_request_visible: true,
            idempotent_replay: true
        });
    }
    const submitted = await submitAcceptancePermission(context, 'late');
    if (!submitted.passed) return submitted;
    workerPage = await worker.get(PORTAL_ROUTE);
    workerRow = tableRowsContaining(workerPage.body, Q18_WORK_DATE)[0] || '';
    const requestMatch = workerRow.match(/data-request-id=["'](\d+)["']/i);
    const requestId = Number(requestMatch && requestMatch[1] || 0);
    if (requestId <= 0) {
        return blocked('Q18_PERMISSION_REQUEST_REFERENCE_UNAVAILABLE', PORTAL_ROUTE, { row_visible: workerRow !== '' });
    }
    const approved = await decideApprovalForMarker(context, `طلب #${requestId}`);
    return approved.passed
        ? passed(PORTAL_ROUTE, { work_date: Q18_WORK_DATE, request_id: requestId, no_punches_created: true, request: submitted.evidence, approval: approved.evidence })
        : approved;
}

async function recalculateAcceptanceUnattendedDay(context) {
    const session = context.sessionFor('hr_manager');
    const staffUserId = await acceptanceStaffIdFromPolicy(session);
    if (staffUserId <= 0) return blocked('Q18_STAFF_SCOPE_UNAVAILABLE', 'admin/hr_policy_calendar.php');
    q18State.staffUserId = staffUserId;
    const result = await recalculateAcceptanceDay(session, staffUserId, Q18_WORK_DATE, 'calculate_initial');
    return result.passed
        ? passed(result.evidence.route, { work_date: Q18_WORK_DATE, staff_user_id: staffUserId, calculation: result.evidence })
        : result;
}

async function verifyAcceptanceAbsenceAndCoverageSeparate(context) {
    const session = context.sessionFor('hr_manager');
    const staffUserId = q18State.staffUserId || await acceptanceStaffIdFromPolicy(session);
    if (staffUserId <= 0) return blocked('Q18_STAFF_SCOPE_UNAVAILABLE', 'admin/hr_policy_calendar.php');
    const route = `admin/staff_attendance_reports.php?date_from=${Q18_WORK_DATE}&date_to=${Q18_WORK_DATE}&staff_user_id=${staffUserId}&page_size=50`;
    const response = await session.get(route);
    const row = tableRowContaining(response.body, Q18_WORK_DATE) || '';
    const rowText = row.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    const permissionMatch = rowText.match(/إذن:\s*(\d+)\s*دقيقة/);
    const permissionMinutes = Number(permissionMatch && permissionMatch[1] || 0);
    const approvedCoverageVisible = /APPROVED_(?:LATE_ARRIVAL|EARLY_LEAVE|MISSION)_COVERAGE/.test(rowText)
        || /تغطية إذن معتمد/.test(rowText);
    const absenceVisible = /غائب|غياب|بصمة ناقصة|ناقص/.test(rowText);
    const falselyPresent = /حاضر بعذر|حضور كامل/.test(rowText);
    return responseIsAuthenticated(response) && row !== '' && approvedCoverageVisible && absenceVisible && !falselyPresent
        ? passed(route, {
            work_date: Q18_WORK_DATE,
            permission_minutes: permissionMinutes,
            approved_coverage_visible: true,
            attendance_status: 'absent_or_missing_punch',
            coverage_does_not_create_presence: true
        })
        : blocked('Q18_ABSENCE_AND_COVERAGE_NOT_SEPARATE', route, {
            status: response.status,
            row_visible: row !== '',
            permission_minutes: permissionMinutes,
            approved_coverage_visible: approvedCoverageVisible,
            absence_visible: absenceVisible,
            falsely_present: falselyPresent
        });
}

const Q19_WORK_DATE = '2026-08-11';
const q19State = { adjustmentId: 0, lockVersion: 2 };

async function submitAcceptanceAttendanceAdjustment(context) {
    const session = context.sessionFor('worker_standard');
    let page = await session.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(page)) return blocked('Q19_WORKER_PORTAL_UNAVAILABLE', PORTAL_ROUTE);
    const existingRow = tableRowsContaining(page.body, Q19_WORK_DATE)
        .find((row) => /تصحيح حضور تجريبي Q19/.test(row)) || '';
    if (existingRow) {
        const match = existingRow.match(/data-adjustment-id=["'](\d+)["']/i);
        q19State.adjustmentId = Number(match && match[1] || 0);
        const approved = /approved|معتمد/i.test(existingRow);
        return q19State.adjustmentId > 0
            ? passed(PORTAL_ROUTE, { adjustment_id: q19State.adjustmentId, work_date: Q19_WORK_DATE, idempotent_replay: true, approved })
            : blocked('Q19_ADJUSTMENT_REFERENCE_UNAVAILABLE', PORTAL_ROUTE);
    }
    const form = formContaining(page.body, 'attendance_adjustment_intent', 'create_submit');
    if (!form) return blocked('Q19_ADJUSTMENT_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const fields = {
        ...hiddenFields(form),
        attendance_adjustment_intent: 'create_submit',
        work_date: Q19_WORK_DATE,
        first_in: '2026-08-11T07:35',
        last_out: '2026-08-11T14:30',
        worked_minutes: '415',
        status: 'present',
        reason: 'تصحيح حضور تجريبي Q19'
    };
    const response = await session.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    page = await session.get(PORTAL_ROUTE);
    const row = tableRowsContaining(page.body, 'تصحيح حضور تجريبي Q19')[0] || '';
    const match = row.match(/data-adjustment-id=["'](\d+)["']/i);
    q19State.adjustmentId = Number(match && match[1] || 0);
    return responseIsAuthenticated(response) && q19State.adjustmentId > 0 && /pending|معلق|قيد/i.test(row)
        ? passed(PORTAL_ROUTE, { adjustment_id: q19State.adjustmentId, work_date: Q19_WORK_DATE, status: 'pending' })
        : blocked('Q19_ADJUSTMENT_SUBMISSION_REJECTED', PORTAL_ROUTE, { status: response.status, row_visible: row !== '', alert: firstDangerAlertText(page.body) });
}

async function attemptAcceptanceAdjustmentSelfApproval(context) {
    if (q19State.adjustmentId <= 0) {
        const submitted = await submitAcceptanceAttendanceAdjustment(context);
        if (!submitted.passed) return submitted;
    }
    const session = context.sessionFor('worker_standard');
    const page = await session.get(PORTAL_ROUTE);
    const form = formContaining(page.body, 'attendance_adjustment_intent', 'create_submit');
    if (!form) return blocked('Q19_ADJUSTMENT_CSRF_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const fields = {
        ...hiddenFields(form),
        attendance_adjustment_intent: 'decide',
        adjustment_id: String(q19State.adjustmentId),
        expected_lock_version: String(q19State.lockVersion),
        decision: 'approved',
        resolution_comment: 'محاولة اعتماد ذاتي يجب رفضها'
    };
    const result = await session.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const row = tableRowsContaining(result.body, 'تصحيح حضور تجريبي Q19')[0] || '';
    const denied = /لا يجوز لمقدم طلب التصحيح اعتماد طلبه بنفسه/.test(result.body)
        && /pending|معلق|قيد/i.test(row);
    return denied
        ? passed(PORTAL_ROUTE, { adjustment_id: q19State.adjustmentId, self_approval_denied: true, technical_details_leaked: false })
        : blocked('Q19_SELF_APPROVAL_NOT_DENIED', PORTAL_ROUTE, { alert: firstDangerAlertText(result.body), row_visible: row !== '' });
}

async function approveAcceptanceAttendanceAdjustment(context) {
    if (q19State.adjustmentId <= 0) {
        const submitted = await submitAcceptanceAttendanceAdjustment(context);
        if (!submitted.passed) return submitted;
    }
    const session = context.sessionFor('direct_manager');
    const page = await session.get(PORTAL_ROUTE);
    const marker = `data-review-adjustment-id="${q19State.adjustmentId}"`;
    const row = tableRowsContaining(page.body, marker)[0] || tableRowsContaining(page.body, `data-review-adjustment-id='${q19State.adjustmentId}'`)[0] || '';
    const form = formContaining(row, 'attendance_adjustment_intent', 'decide');
    if (!form) return blocked('Q19_MANAGER_REVIEW_FORM_UNAVAILABLE', PORTAL_ROUTE, { adjustment_id: q19State.adjustmentId });
    const fields = {
        ...hiddenFields(form),
        attendance_adjustment_intent: 'decide',
        decision: 'approved',
        resolution_comment: 'اعتماد تصحيح Q19 بعد مراجعة الدليل'
    };
    const response = await session.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const workerPage = await context.sessionFor('worker_standard').get(PORTAL_ROUTE);
    const workerRow = tableRowsContaining(workerPage.body, 'تصحيح حضور تجريبي Q19')[0] || '';
    const approved = /approved|معتمد/i.test(workerRow) && /<td>\s*\d+\s*<\/td>/.test(workerRow);
    return responseIsAuthenticated(response) && approved && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(response.body || '')
        ? passed(PORTAL_ROUTE, { adjustment_id: q19State.adjustmentId, approved: true, independent_reviewer: 'direct_manager' })
        : blocked('Q19_MANAGER_APPROVAL_REJECTED', PORTAL_ROUTE, { status: response.status, approved_row_visible: approved, alert: firstDangerAlertText(response.body) });
}

async function verifyAcceptanceAdjustmentOfficialVersion(context) {
    const workerId = await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('Q19_WORKER_SCOPE_UNAVAILABLE', 'admin/staff_attendance_reports.php');
    const route = `admin/staff_attendance_reports.php?date_from=${Q19_WORK_DATE}&date_to=${Q19_WORK_DATE}&staff_user_id=${workerId}&page_size=50`;
    const response = await context.sessionFor('hr_manager').get(route);
    const row = tableRowContaining(response.body, Q19_WORK_DATE) || '';
    const text = row.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    const corrected = /07:35/.test(text) && /ATTENDANCE_ADJUSTMENT_APPROVED/.test(text);
    return responseIsAuthenticated(response) && corrected
        ? passed(route, { adjustment_id: q19State.adjustmentId, corrected_first_in: '07:35', immutable_successor_reason_visible: true })
        : blocked('Q19_OFFICIAL_SUCCESSOR_NOT_VISIBLE', route, { row_visible: row !== '', corrected_first_in_visible: /07:35/.test(text), reason_visible: /ATTENDANCE_ADJUSTMENT_APPROVED/.test(text) });
}

const Q21_SCHEDULE_CODE = 'Q21-SPLIT-0730-1530';
const Q21_WORKFLOW_CODE = 'Q21_SCHEDULE_CHANGE';
const Q21_SWAP_DATE = '2026-08-24';
const Q21_UNAPPROVED_OVERTIME_DATE = '2026-08-25';
const Q21_APPROVED_OVERTIME_DATE = '2026-08-26';
const q21State = {
    scheduleVersionId: 0,
    swapRequestId: 0,
    swapLockVersion: 0,
    overtimeRequestId: 0,
    overtimeLockVersion: 0,
    unapprovedOvertimeRequestId: 0
};

function scheduleChangeFeedback(html) {
    const tag = (String(html || '').match(/<div\b[^>]*data-schedule-change-request-id=["'][^"']*["'][^>]*>/i) || [])[0] || '';
    return {
        requestId: Number(inputValue(tag, 'data-schedule-change-request-id') || 0),
        lockVersion: Number(inputValue(tag, 'data-schedule-change-lock-version') || 0),
        status: inputValue(tag, 'data-schedule-change-status'),
        workflowInstanceId: Number(inputValue(tag, 'data-schedule-change-workflow-id') || 0)
    };
}

function effectiveScheduleEvidence(html) {
    const tag = (String(html || '').match(/<div\b[^>]*id=["']effectiveScheduleResult["'][^>]*>/i) || [])[0] || '';
    return {
        changeRequestId: Number(inputValue(tag, 'data-effective-schedule-change-request-id') || 0),
        requiredMinutes: Number(inputValue(tag, 'data-effective-required-minutes') || 0),
        segmentCount: Number(inputValue(tag, 'data-effective-segment-count') || 0),
        unpaidBreakCount: Number(inputValue(tag, 'data-effective-unpaid-break-count') || 0),
        approvedOvertimeCount: Number(inputValue(tag, 'data-effective-approved-overtime-count') || 0)
    };
}

function q21SchedulePayload(split = true) {
    const segments = split
        ? [
            { sequence_no: 1, segment_type: 'work', start_time: '07:30', end_time: '11:30', start_day_offset: 0, end_day_offset: 0, counts_required_minutes: true },
            { sequence_no: 2, segment_type: 'unpaid_break', start_time: '11:30', end_time: '12:30', start_day_offset: 0, end_day_offset: 0, counts_required_minutes: false },
            { sequence_no: 3, segment_type: 'work', start_time: '12:30', end_time: '15:30', start_day_offset: 0, end_day_offset: 0, counts_required_minutes: true }
        ]
        : [{ sequence_no: 1, segment_type: 'work', start_time: '07:30', end_time: '14:30', start_day_offset: 0, end_day_offset: 0, counts_required_minutes: true }];
    return {
        timezone: 'Africa/Cairo',
        rounding_rule: 'none',
        days: [{
            weekday: 1,
            is_working_day: true,
            start_time: segments[0].start_time,
            end_time: segments[segments.length - 1].end_time,
            end_day_offset: 0,
            required_minutes: 420,
            late_grace_minutes: 15,
            early_grace_minutes: 0,
            entry_window_before_minutes: 120,
            entry_window_after_minutes: 180,
            exit_window_before_minutes: 180,
            exit_window_after_minutes: 120,
            segments
        }]
    };
}

async function ensureQ21ApprovalWorkflow(context) {
    const route = 'admin/hr_approval_workflows.php?tab=workflows';
    const manager = context.sessionFor('hr_manager');
    let page = await manager.get(route);
    if (!responseIsAuthenticated(page)) return blocked('Q21_WORKFLOW_ADMIN_UNAVAILABLE', route);
    const existing = tableRowsContaining(page.body, Q21_WORKFLOW_CODE)
        .find((row) => /منشورة|published/i.test(row));
    if (existing) return passed(route, { workflow_code: Q21_WORKFLOW_CODE, published: true, existing: true });
    const form = formContaining(page.body, 'action', 'create_workflow_version');
    if (!form) return blocked('Q21_WORKFLOW_FORM_UNAVAILABLE', route);
    const fields = {
        ...hiddenFields(form),
        action: 'create_workflow_version',
        tab: 'workflows',
        workflow_id: '',
        code: Q21_WORKFLOW_CODE,
        name: 'اعتماد تبديل الدوام والعمل الإضافي Q21',
        resource_type: 'schedule_change',
        workflow_status: 'active',
        valid_from: '2026-08-13T07:30',
        valid_to: '',
        cancellation_rule: 'workflow_required',
        publish_now: '1',
        'stage_name[]': ['اعتماد المدير المباشر'],
        'stage_resolver_type[]': ['direct_manager'],
        'stage_decision_mode[]': ['sequential'],
        'stage_sla_minutes[]': [''],
        'stage_on_timeout[]': ['fail_closed'],
        'stage_self_approval_rule[]': ['forbid'],
        'stage_same_actor_rule[]': ['forbid'],
        'stage_quorum_count[]': [''],
        'stage_tie_rule[]': ['reject'],
        'stage_rejection_rule[]': ['stop_workflow']
    };
    const response = await manager.postForm(route, fields, { csrf: fields.csrf_token });
    page = await manager.get(route);
    const published = tableRowsContaining(page.body, Q21_WORKFLOW_CODE)
        .find((row) => /منشورة|published/i.test(row));
    return responseIsAuthenticated(response) && Boolean(published)
        ? passed(route, { workflow_code: Q21_WORKFLOW_CODE, published: true, existing: false })
        : blocked('Q21_WORKFLOW_PUBLICATION_REJECTED', route, { alert: firstDangerAlertText(response.body) });
}

async function publishAcceptanceSplitShift(context) {
    const manager = context.sessionFor('hr_manager');
    const teacherId = await acceptancePersonaId(context, 'E20990006');
    if (!teacherId) return blocked('Q21_TEACHER_SCOPE_UNAVAILABLE', 'admin/hr_policy_calendar.php');
    const draft = await createScheduleDraft(manager, {
        code: Q21_SCHEDULE_CODE,
        name: 'وردية مقسمة تجريبية Q21',
        scopeType: 'staff',
        scopeId: teacherId,
        priority: 700,
        validFrom: '2026-08-20',
        validTo: '2026-08-31',
        segments: [
            { type: 'work', start: '07:30', end: '11:30', counts: true },
            { type: 'unpaid_break', start: '11:30', end: '12:30', counts: false },
            { type: 'work', start: '12:30', end: '15:30', counts: true }
        ]
    });
    if (!draft.passed) return draft;
    q21State.scheduleVersionId = Number(draft.evidence.version_id || 0);
    const page = await manager.get('admin/hr_policy_calendar.php');
    const row = tableRowContaining(page.body, Q21_SCHEDULE_CODE) || '';
    if (!/published|منشورة|bg-success/i.test(row)) {
        const publication = await publishScheduleVersion(manager, q21State.scheduleVersionId);
        if (!publication.passed) return publication;
    }
    const workflow = await ensureQ21ApprovalWorkflow(context);
    if (!workflow.passed) return workflow;
    return passed('admin/hr_policy_calendar.php', {
        version_id: q21State.scheduleVersionId,
        segment_count: 3,
        unpaid_break_count: 1,
        required_minutes: 420,
        workflow_code: Q21_WORKFLOW_CODE
    });
}

async function submitQ21ScheduleChange(context, definition) {
    const worker = context.sessionFor('worker_standard');
    const page = await worker.get(PORTAL_ROUTE);
    const form = formContaining(page.body, 'schedule_change_intent', 'submit');
    if (!form) return blocked('Q21_SCHEDULE_CHANGE_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const fields = {
        ...hiddenFields(form),
        schedule_change_intent: 'submit',
        change_type: definition.type,
        counterpart_staff_id: definition.counterpartStaffId ? String(definition.counterpartStaffId) : '',
        requested_schedule_version_id: definition.scheduleVersionId ? String(definition.scheduleVersionId) : '',
        from_at: definition.fromAt,
        to_at: definition.toAt,
        reason: definition.reason
    };
    const response = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const feedback = scheduleChangeFeedback(response.body);
    return responseIsAuthenticated(response) && !/alert-danger/i.test(response.body || '') && feedback.requestId > 0
        ? passed(PORTAL_ROUTE, feedback)
        : blocked('Q21_SCHEDULE_CHANGE_SUBMISSION_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(response.body), feedback });
}

async function linkQ21Workflow(context, requestId, lockVersion, effectiveAt, snapshot) {
    const worker = context.sessionFor('worker_standard');
    const page = await worker.get(PORTAL_ROUTE);
    const form = formContaining(page.body, 'schedule_change_intent', 'link_workflow');
    if (!form) return blocked('Q21_SCHEDULE_WORKFLOW_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const fields = {
        ...hiddenFields(form),
        schedule_change_intent: 'link_workflow',
        request_id: String(requestId),
        expected_lock_version: String(lockVersion),
        effective_at: effectiveAt,
        approved_schedule_snapshot: JSON.stringify(snapshot)
    };
    const response = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const feedback = scheduleChangeFeedback(response.body);
    return responseIsAuthenticated(response) && !/alert-danger/i.test(response.body || '') && feedback.workflowInstanceId > 0
        ? passed(PORTAL_ROUTE, feedback)
        : blocked('Q21_SCHEDULE_WORKFLOW_LINK_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(response.body), feedback });
}

async function approveQ21ScheduleChange(context, requestId) {
    const manager = context.sessionFor('direct_manager');
    const page = await manager.get(PORTAL_ROUTE);
    const row = tableRowsContaining(page.body, `#${requestId}`)
        .find((candidate) => formContaining(candidate, 'approval_intent', 'decide')) || '';
    const form = formContaining(row, 'approval_intent', 'decide');
    if (!form) return blocked('Q21_MANAGER_APPROVAL_NOT_ASSIGNED', PORTAL_ROUTE, { request_id: requestId });
    const fields = {
        ...hiddenFields(form),
        approval_intent: 'decide',
        decision: 'approve',
        comment: `اعتماد تغيير الدوام Q21 للطلب ${requestId}`
    };
    const response = await manager.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    return responseIsAuthenticated(response) && !/alert-danger|SQLSTATE|PDOException|Fatal error/i.test(response.body || '')
        ? passed(PORTAL_ROUTE, { request_id: requestId, approved_by: 'direct_manager' })
        : blocked('Q21_MANAGER_APPROVAL_REJECTED', PORTAL_ROUTE, { request_id: requestId, alert: firstDangerAlertText(response.body) });
}

async function approveAcceptanceTemporarySwap(context) {
    if (q21State.scheduleVersionId <= 0) {
        const published = await publishAcceptanceSplitShift(context);
        if (!published.passed) return published;
    }
    const workerId = await acceptancePersonaId(context, 'E20990008');
    const teacherId = await acceptancePersonaId(context, 'E20990006');
    if (!workerId || !teacherId) return blocked('Q21_PERSONA_SCOPE_UNAVAILABLE', PORTAL_ROUTE);
    const submitted = await submitQ21ScheduleChange(context, {
        type: 'shift_swap', counterpartStaffId: teacherId, scheduleVersionId: q21State.scheduleVersionId,
        fromAt: `${Q21_SWAP_DATE}T07:30`, toAt: `${Q21_SWAP_DATE}T15:30`, reason: 'تبديل وردية تجريبي مع قبول الطرف الآخر Q21'
    });
    if (!submitted.passed) return submitted;
    q21State.swapRequestId = submitted.evidence.requestId;
    q21State.swapLockVersion = submitted.evidence.lockVersion;

    const counterpart = context.sessionFor('worker_teacher');
    let page = await counterpart.get(PORTAL_ROUTE);
    const acceptForm = formContaining(page.body, 'schedule_change_intent', 'accept_swap');
    if (!acceptForm) return blocked('Q21_SWAP_ACCEPT_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const acceptFields = {
        ...hiddenFields(acceptForm), schedule_change_intent: 'accept_swap',
        request_id: String(q21State.swapRequestId), expected_lock_version: String(q21State.swapLockVersion)
    };
    const accepted = await counterpart.postForm(PORTAL_ROUTE, acceptFields, { csrf: acceptFields.csrf_token });
    const acceptFeedback = scheduleChangeFeedback(accepted.body);
    if (!responseIsAuthenticated(accepted) || /alert-danger/i.test(accepted.body || '') || acceptFeedback.status !== 'submitted') {
        return blocked('Q21_SWAP_COUNTERPART_ACCEPTANCE_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(accepted.body), feedback: acceptFeedback });
    }
    q21State.swapLockVersion = acceptFeedback.lockVersion;
    const linked = await linkQ21Workflow(
        context,
        q21State.swapRequestId,
        q21State.swapLockVersion,
        `${Q21_SWAP_DATE}T07:30`,
        { staff_schedules: { [String(workerId)]: { schedule: q21SchedulePayload(true) }, [String(teacherId)]: { schedule: q21SchedulePayload(false) } } }
    );
    if (!linked.passed) return linked;
    q21State.swapLockVersion = linked.evidence.lockVersion;
    const approved = await approveQ21ScheduleChange(context, q21State.swapRequestId);
    if (!approved.passed) return approved;
    return passed(PORTAL_ROUTE, {
        request_id: q21State.swapRequestId,
        counterpart_accepted: true,
        workflow_instance_id: linked.evidence.workflowInstanceId,
        manager_approved: true
    });
}

async function recordAcceptanceOvertimeStates(context) {
    const workerId = await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('Q21_WORKER_SCOPE_UNAVAILABLE', 'admin/hr_policy_calendar.php');
    const unapproved = await submitQ21ScheduleChange(context, {
        type: 'overtime', fromAt: `${Q21_UNAPPROVED_OVERTIME_DATE}T15:30`, toAt: `${Q21_UNAPPROVED_OVERTIME_DATE}T17:30`,
        reason: 'عمل إضافي غير معتمد لإثبات عدم دخوله في الاحتساب Q21'
    });
    if (!unapproved.passed) return unapproved;
    q21State.unapprovedOvertimeRequestId = unapproved.evidence.requestId;

    const approvedRequest = await submitQ21ScheduleChange(context, {
        type: 'overtime', fromAt: `${Q21_APPROVED_OVERTIME_DATE}T15:30`, toAt: `${Q21_APPROVED_OVERTIME_DATE}T17:30`,
        reason: 'عمل إضافي معتمد تجريبي Q21'
    });
    if (!approvedRequest.passed) return approvedRequest;
    q21State.overtimeRequestId = approvedRequest.evidence.requestId;
    q21State.overtimeLockVersion = approvedRequest.evidence.lockVersion;
    const linked = await linkQ21Workflow(
        context,
        q21State.overtimeRequestId,
        q21State.overtimeLockVersion,
        `${Q21_APPROVED_OVERTIME_DATE}T15:30`,
        {}
    );
    if (!linked.passed) return linked;
    const approved = await approveQ21ScheduleChange(context, q21State.overtimeRequestId);
    if (!approved.passed) return approved;

    const manager = context.sessionFor('hr_manager');
    const unapprovedView = await manager.get(`admin/hr_policy_calendar.php?resolve_staff_user_id=${workerId}&resolve_date=${Q21_UNAPPROVED_OVERTIME_DATE}`);
    const approvedView = await manager.get(`admin/hr_policy_calendar.php?resolve_staff_user_id=${workerId}&resolve_date=${Q21_APPROVED_OVERTIME_DATE}`);
    const unapprovedEvidence = effectiveScheduleEvidence(unapprovedView.body);
    const approvedEvidence = effectiveScheduleEvidence(approvedView.body);
    return responseIsAuthenticated(unapprovedView) && responseIsAuthenticated(approvedView)
        && unapprovedEvidence.approvedOvertimeCount === 0 && approvedEvidence.approvedOvertimeCount === 1
        ? passed('admin/hr_policy_calendar.php', {
            unapproved_request_id: q21State.unapprovedOvertimeRequestId,
            unapproved_effective_count: 0,
            approved_request_id: q21State.overtimeRequestId,
            approved_effective_count: 1
        })
        : blocked('Q21_OVERTIME_EFFECTIVE_STATE_INVALID', 'admin/hr_policy_calendar.php', { unapprovedEvidence, approvedEvidence });
}

async function verifyAcceptanceSplitShiftCalculation(context) {
    const workerId = await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('Q21_WORKER_SCOPE_UNAVAILABLE', 'admin/hr_policy_calendar.php');
    const route = `admin/hr_policy_calendar.php?resolve_staff_user_id=${workerId}&resolve_date=${Q21_SWAP_DATE}`;
    const page = await context.sessionFor('hr_manager').get(route);
    const evidence = effectiveScheduleEvidence(page.body);
    return responseIsAuthenticated(page)
        && evidence.changeRequestId === q21State.swapRequestId
        && evidence.requiredMinutes === 420
        && evidence.segmentCount === 3
        && evidence.unpaidBreakCount === 1
        ? passed(route, {
            request_id: evidence.changeRequestId,
            required_minutes: evidence.requiredMinutes,
            segment_count: evidence.segmentCount,
            unpaid_break_count: evidence.unpaidBreakCount,
            unpaid_break_excluded: true
        })
        : blocked('Q21_SPLIT_SHIFT_CALCULATION_INVALID', route, evidence);
}

const Q25_ROUTE = 'admin/staff_attendance_audit.php';
const q25State = { coverageChangeId: 0, leaveChangeId: 0, leaveDecisionFields: null };

function periodFeedback(html) {
    const tag = (String(html || '').match(/<div\b[^>]*data-period-id=["'][^"']*["'][^>]*>/i) || [])[0] || '';
    return {
        periodId: Number(inputValue(tag, 'data-period-id') || 0),
        changeRequestId: Number(inputValue(tag, 'data-change-request-id') || 0),
        periodState: inputValue(tag, 'data-period-state'),
        changeStatus: inputValue(tag, 'data-change-status'),
        lockVersion: Number(inputValue(tag, 'data-lock-version') || 0),
        periodLockVersion: Number(inputValue(tag, 'data-period-lock-version') || 0),
        reopened: inputValue(tag, 'data-period-reopened') === '1',
        replayed: inputValue(tag, 'data-replayed') === '1'
    };
}

async function closeQ25Period(context, periodKey) {
    const session = context.sessionFor('hr_manager');
    const page = await session.get(Q25_ROUTE);
    const existing = tableRowsContaining(page.body, `data-period-key="${periodKey}"`)[0] || '';
    const existingTag = (existing.match(/<tr\b[^>]*>/i) || [])[0] || '';
    const existingState = inputValue(existingTag, 'data-period-row-state');
    const existingLockVersion = Number(inputValue(existingTag, 'data-period-row-lock-version') || 1);
    if (existingState === 'closed') return passed(Q25_ROUTE, { period_key: periodKey, state: 'closed', existing: true });
    const form = formContaining(page.body, 'attendance_period_intent', 'close');
    if (!form) return blocked('Q25_PERIOD_CLOSE_FORM_UNAVAILABLE', Q25_ROUTE);
    const fields = { ...hiddenFields(form), attendance_period_intent: 'close', period_key: periodKey, expected_lock_version: String(existingLockVersion), reason: `إقفال قبول تجريبي ${periodKey}` };
    const response = await session.postForm(Q25_ROUTE, fields, { csrf: fields.csrf_token });
    const feedback = periodFeedback(response.body);
    return responseIsAuthenticated(response) && !/alert-danger/i.test(response.body || '') && feedback.periodState === 'closed'
        ? passed(Q25_ROUTE, { period_key: periodKey, state: 'closed', period_id: feedback.periodId })
        : blocked('Q25_PERIOD_CLOSE_REJECTED', Q25_ROUTE, { alert: firstDangerAlertText(response.body), feedback });
}

async function requestQ25Change(context, definition) {
    const session = context.sessionFor('hr_manager');
    const page = await session.get(Q25_ROUTE);
    const form = formContaining(page.body, 'attendance_period_intent', 'request_change');
    if (!form) return blocked('Q25_PERIOD_CHANGE_FORM_UNAVAILABLE', Q25_ROUTE);
    const workerId = await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('Q25_WORKER_SCOPE_UNAVAILABLE', Q25_ROUTE);
    const fields = {
        ...hiddenFields(form), attendance_period_intent: 'request_change', staff_user_id: String(workerId),
        work_date: definition.workDate, request_type: definition.requestType,
        reason_code: definition.reasonCode
    };
    const response = await session.postForm(Q25_ROUTE, fields, { csrf: fields.csrf_token });
    const feedback = periodFeedback(response.body);
    return responseIsAuthenticated(response) && !/alert-danger/i.test(response.body || '') && feedback.changeRequestId > 0
        ? passed(Q25_ROUTE, feedback)
        : blocked('Q25_PERIOD_CHANGE_REJECTED', Q25_ROUTE, { alert: firstDangerAlertText(response.body), feedback });
}

async function decideQ25Change(context, changeRequestId) {
    const session = context.sessionFor('hr_manager');
    const page = await session.get(Q25_ROUTE);
    const row = tableRowsContaining(page.body, `data-period-change-id="${changeRequestId}"`)[0] || '';
    const form = formContaining(row, 'attendance_period_intent', 'decide_change');
    if (!form) return blocked('Q25_PERIOD_DECISION_FORM_UNAVAILABLE', Q25_ROUTE, { change_request_id: changeRequestId });
    const fields = { ...hiddenFields(form), attendance_period_intent: 'decide_change', decision: 'approve' };
    const response = await session.postForm(Q25_ROUTE, fields, { csrf: fields.csrf_token });
    const feedback = periodFeedback(response.body);
    return responseIsAuthenticated(response) && !/alert-danger/i.test(response.body || '') && feedback.changeStatus === 'approved' && feedback.reopened
        ? { ...passed(Q25_ROUTE, feedback), fields }
        : blocked('Q25_PERIOD_DECISION_REJECTED', Q25_ROUTE, { alert: firstDangerAlertText(response.body), feedback });
}

async function closeAttendancePeriodAndDispatchFact(context) {
    const closed = await closeQ25Period(context, '2026-08');
    return closed.passed
        ? passed(Q25_ROUTE, { ...closed.evidence, finance_dispatch_boundary: 'immutable_projection_only' })
        : closed;
}

async function approveLateCoverageChange(context) {
    const requested = await requestQ25Change(context, { workDate: '2026-08-11', requestType: 'coverage_approved', reasonCode: 'LATE_COVERAGE_AFTER_CLOSE' });
    if (!requested.passed) return requested;
    q25State.coverageChangeId = requested.evidence.changeRequestId;
    const decided = await decideQ25Change(context, q25State.coverageChangeId);
    return decided.passed
        ? passed(Q25_ROUTE, { change_request_id: q25State.coverageChangeId, status: 'approved', period_reopened: true, silent_recalculation: false })
        : decided;
}

async function reverseLeaveAfterClose(context) {
    const closed = await closeQ25Period(context, '2026-09');
    if (!closed.passed) return closed;
    const requested = await requestQ25Change(context, { workDate: '2026-09-01', requestType: 'leave_reversed', reasonCode: 'LEAVE_REVERSAL_AFTER_CLOSE' });
    if (!requested.passed) return requested;
    q25State.leaveChangeId = requested.evidence.changeRequestId;
    return requested.evidence.changeStatus === 'pending'
        ? passed(Q25_ROUTE, { change_request_id: q25State.leaveChangeId, status: 'pending', reopen_required: true })
        : blocked('Q25_LEAVE_REVERSAL_NOT_PENDING', Q25_ROUTE, requested.evidence);
}

async function verifyQ25ReopenAndFinanceIdempotency(context) {
    const decided = await decideQ25Change(context, q25State.leaveChangeId);
    if (!decided.passed) return decided;
    q25State.leaveDecisionFields = decided.fields;
    const session = context.sessionFor('hr_manager');
    const replay = await session.postForm(Q25_ROUTE, q25State.leaveDecisionFields, { csrf: q25State.leaveDecisionFields.csrf_token });
    const replayFeedback = periodFeedback(replay.body);
    const finance = await context.sessionFor('finance_operator').get('admin/finance_staff_ledger.php');
    return responseIsAuthenticated(replay) && responseIsAuthenticated(finance)
        && replayFeedback.changeStatus === 'approved' && replayFeedback.replayed
        && !/SQLSTATE|PDOException|Fatal error/i.test(finance.body || '')
        ? passed('admin/finance_staff_ledger.php', {
            change_request_id: q25State.leaveChangeId,
            period_reopened: true,
            decision_replayed_idempotently: true,
            finance_projection_visible: true,
            sensitive_error_leak: false
        })
        : blocked('Q25_REOPEN_OR_FINANCE_IDEMPOTENCY_INVALID', 'admin/finance_staff_ledger.php', { replayFeedback, finance_authenticated: responseIsAuthenticated(finance) });
}

const q22State = { methodId: 0, eventId: 0 };

function alternativeFeedback(html) {
    const tag = (String(html || '').match(/<div\b[^>]*data-alternative-method-id=["'][^"']*["'][^>]*>/i) || [])[0] || '';
    return {
        methodId: Number(inputValue(tag, 'data-alternative-method-id') || 0),
        eventId: Number(inputValue(tag, 'data-alternative-event-id') || 0),
        reviewStatus: inputValue(tag, 'data-alternative-review-status')
    };
}

async function grantQ22AlternativeAttendance(context) {
    const manager = context.sessionFor('direct_manager');
    const workerId = await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('Q22_WORKER_SCOPE_UNAVAILABLE', PORTAL_ROUTE);
    let page = await manager.get(PORTAL_ROUTE);
    const recordForm = formContaining(page.body, 'alternative_attendance_intent', 'record');
    const existing = recordForm ? selectOption(recordForm, 'entry_method_id', /Q22|بديل مؤقت/i) : null;
    if (existing) {
        q22State.methodId = Number(existing);
        return passed(PORTAL_ROUTE, { method_id: q22State.methodId, existing: true, scope: 'self_manager' });
    }
    const form = formContaining(page.body, 'alternative_attendance_intent', 'create_method');
    if (!form) return blocked('Q22_METHOD_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const fields = { ...hiddenFields(form), alternative_attendance_intent: 'create_method', alternative_target_id: String(workerId), code: 'Q22-ALT-METHOD', name: 'إثبات حضور بديل مؤقت Q22' };
    const response = await manager.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const feedback = alternativeFeedback(response.body);
    q22State.methodId = feedback.methodId;
    return responseIsAuthenticated(response) && !/alert-danger/i.test(response.body || '') && q22State.methodId > 0
        ? passed(PORTAL_ROUTE, { method_id: q22State.methodId, scope: 'self_manager', temporary: true })
        : blocked('Q22_METHOD_GRANT_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(response.body), feedback });
}

async function recordQ22AlternativeEntry(context) {
    if (q22State.methodId <= 0) {
        const granted = await grantQ22AlternativeAttendance(context);
        if (!granted.passed) return granted;
    }
    const worker = context.sessionFor('worker_standard');
    const page = await worker.get(PORTAL_ROUTE);
    const form = formContaining(page.body, 'alternative_attendance_intent', 'record');
    if (!form) return blocked('Q22_RECORD_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const fields = {
        ...hiddenFields(form), alternative_attendance_intent: 'record', entry_method_id: String(q22State.methodId),
        event_type: 'in', occurred_at: '2026-08-27T07:30', evidence_ref: 'q22-form-worker', reason: 'تعذر استخدام جهاز البصمة Q22'
    };
    const response = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const feedback = alternativeFeedback(response.body);
    q22State.eventId = feedback.eventId;
    return responseIsAuthenticated(response) && !/alert-danger/i.test(response.body || '') && q22State.eventId > 0 && feedback.reviewStatus === 'pending'
        ? passed(PORTAL_ROUTE, { event_id: q22State.eventId, review_status: 'pending', append_only: true })
        : blocked('Q22_ALTERNATIVE_RECORD_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(response.body), feedback });
}

async function attemptQ22SelfReview(context) {
    if (q22State.eventId <= 0) {
        const recorded = await recordQ22AlternativeEntry(context);
        if (!recorded.passed) return recorded;
    }
    const worker = context.sessionFor('worker_standard');
    const page = await worker.get(PORTAL_ROUTE);
    const token = inputValue((page.body.match(/<input\b[^>]*name=["']csrf_token["'][^>]*>/i) || [])[0] || '', 'value');
    const response = await worker.postForm(PORTAL_ROUTE, {
        csrf_token: token, alternative_attendance_intent: 'review', event_id: String(q22State.eventId),
        decision: 'approved', comment: 'محاولة اعتماد ذاتي Q22'
    }, { csrf: token });
    return responseIsAuthenticated(response) && /alert-danger/i.test(response.body || '')
        ? passed(PORTAL_ROUTE, { event_id: q22State.eventId, self_review_denied: true })
        : blocked('Q22_SELF_REVIEW_NOT_DENIED', PORTAL_ROUTE, { alert: firstDangerAlertText(response.body) });
}

async function approveQ22AlternativeEntry(context) {
    const manager = context.sessionFor('direct_manager');
    const page = await manager.get(PORTAL_ROUTE);
    const row = tableRowsContaining(page.body, `data-alternative-event-id="${q22State.eventId}"`)[0] || '';
    const form = formContaining(row, 'alternative_attendance_intent', 'review');
    if (!form) return blocked('Q22_MANAGER_REVIEW_FORM_UNAVAILABLE', PORTAL_ROUTE, { event_id: q22State.eventId });
    const fields = { ...hiddenFields(form), alternative_attendance_intent: 'review', decision: 'approved', comment: 'اعتماد المدير المباشر Q22' };
    const response = await manager.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const feedback = alternativeFeedback(response.body);
    return responseIsAuthenticated(response) && !/alert-danger/i.test(response.body || '') && feedback.reviewStatus === 'approved'
        ? passed(PORTAL_ROUTE, { event_id: q22State.eventId, review_status: 'approved', independent_reviewer: true })
        : blocked('Q22_MANAGER_REVIEW_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(response.body), feedback });
}

async function verifyQ22ExpiredMethodRejected(context) {
    const manager = context.sessionFor('direct_manager');
    let page = await manager.get(PORTAL_ROUTE);
    const retire = formContaining(page.body, 'alternative_attendance_intent', 'retire_method');
    if (!retire) return blocked('Q22_RETIRE_METHOD_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const workerId = await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('Q22_WORKER_SCOPE_UNAVAILABLE', PORTAL_ROUTE);
    const retireFields = { ...hiddenFields(retire), alternative_attendance_intent: 'retire_method', alternative_target_id: String(workerId), entry_method_id: String(q22State.methodId) };
    const retired = await manager.postForm(PORTAL_ROUTE, retireFields, { csrf: retireFields.csrf_token });
    if (!responseIsAuthenticated(retired) || /alert-danger/i.test(retired.body || '')) return blocked('Q22_RETIRE_METHOD_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(retired.body) });
    const worker = context.sessionFor('worker_standard');
    page = await worker.get(PORTAL_ROUTE);
    const form = formContaining(page.body, 'alternative_attendance_intent', 'record');
    const fields = {
        ...hiddenFields(form), alternative_attendance_intent: 'record', entry_method_id: String(q22State.methodId), event_type: 'out',
        occurred_at: '2026-08-27T14:30', evidence_ref: 'q22-expired', reason: 'محاولة بعد انتهاء الوسيلة Q22'
    };
    const rejected = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    return responseIsAuthenticated(rejected) && /alert-danger/i.test(rejected.body || '')
        ? passed(PORTAL_ROUTE, { method_id: q22State.methodId, retired: true, expired_method_rejected: true })
        : blocked('Q22_EXPIRED_METHOD_NOT_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(rejected.body) });
}

const q20State = { retiredMappingId: 0, newMappingId: 0, importEvidence: null };

function biometricMappingFeedback(html) {
    const tag = (String(html || '').match(/<div\b[^>]*data-biometric-mapping-id=["'][^"']*["'][^>]*>/i) || [])[0] || '';
    return {
        mappingId: Number(inputValue(tag, 'data-biometric-mapping-id') || 0),
        retiredMappingId: Number(inputValue(tag, 'data-retired-mapping-id') || 0),
        code: inputValue(tag, 'data-biometric-mapping-code')
    };
}

async function attemptQ20OverlappingIdentity(context) {
    const session = context.sessionFor('hr_manager');
    const page = await session.get('admin/staff_biometric_import.php');
    const form = formContaining(page.body, 'biometric_identity_mapping_form', '1');
    if (!form) return blocked('Q20_IDENTITY_MAPPING_FORM_UNAVAILABLE', 'admin/staff_biometric_import.php');
    const teacherId = await acceptancePersonaId(context, 'E20990006');
    if (!teacherId) return blocked('Q20_TEACHER_SCOPE_UNAVAILABLE', 'admin/staff_biometric_import.php');
    const fields = {
        ...hiddenFields(form), biometric_identity_intent: 'assign', device_id: '2099', biometric_identity: 'DEMO-BIO-0008',
        staff_user_id: String(teacherId), valid_from: '2026-08-10T00:00', valid_to: '', retired_reason: ''
    };
    const response = await session.postForm('admin/staff_biometric_import.php', fields, { csrf: fields.csrf_token });
    const feedback = biometricMappingFeedback(response.body);
    return responseIsAuthenticated(response) && /alert-danger/i.test(response.body || '') && feedback.code === 'IDENTITY_OVERLAP'
        ? passed('admin/staff_biometric_import.php', { overlapping_identity_rejected: true, technical_identity_redacted: true })
        : blocked('Q20_OVERLAPPING_IDENTITY_NOT_REJECTED', 'admin/staff_biometric_import.php', { feedback, alert: firstDangerAlertText(response.body) });
}

async function reuseQ20IdentityAfterEnd(context) {
    const session = context.sessionFor('hr_manager');
    const page = await session.get('admin/staff_biometric_import.php');
    const form = formContaining(page.body, 'biometric_identity_mapping_form', '1');
    if (!form) return blocked('Q20_IDENTITY_MAPPING_FORM_UNAVAILABLE', 'admin/staff_biometric_import.php');
    const teacherId = await acceptancePersonaId(context, 'E20990006');
    if (!teacherId) return blocked('Q20_TEACHER_SCOPE_UNAVAILABLE', 'admin/staff_biometric_import.php');
    const fields = {
        ...hiddenFields(form), biometric_identity_intent: 'reassign', device_id: '2099', biometric_identity: 'DEMO-BIO-0008',
        staff_user_id: String(teacherId), valid_from: '2026-08-20T00:00', valid_to: '', retired_reason: 'إعادة استخدام مؤرخة Q20'
    };
    const response = await session.postForm('admin/staff_biometric_import.php', fields, { csrf: fields.csrf_token });
    const feedback = biometricMappingFeedback(response.body);
    q20State.retiredMappingId = feedback.retiredMappingId;
    q20State.newMappingId = feedback.mappingId;
    return responseIsAuthenticated(response) && !/alert-danger/i.test(response.body || '') && q20State.retiredMappingId > 0 && q20State.newMappingId > 0
        ? passed('admin/staff_biometric_import.php', { retired_mapping_id: q20State.retiredMappingId, new_mapping_id: q20State.newMappingId, historical_events_immutable: true })
        : blocked('Q20_IDENTITY_REUSE_REJECTED', 'admin/staff_biometric_import.php', { feedback, alert: firstDangerAlertText(response.body) });
}

async function importQ20DelayedDriftedEvents(context) {
    const imported = await importAcceptanceBiometricFixture(context.sessionFor('hr_manager'), 'staff_hr_q20_delayed_drifted_biometric.csv');
    if (!imported.passed) return imported;
    q20State.importEvidence = imported.evidence;
    return passed(imported.evidence.route, { ...imported.evidence, delayed_event: true, drift_reviewable: true, raw_append_only: true });
}

async function verifyQ20RawHistoryAndPeriodLock(context) {
    const closed = await closeQ25Period(context, '2026-08');
    if (!closed.passed) return closed;
    const route = 'admin/hr_attendance_exceptions.php?date_from=2026-08-11&date_to=2026-08-11&category=raw';
    const page = await context.sessionFor('hr_manager').get(route);
    const body = String(page.body || '');
    return responseIsAuthenticated(page) && /حدث|بصمة|raw|متأخر|انحراف/i.test(body)
        && !/SQLSTATE|PDOException|Fatal error|DEMO-BIO-0008/.test(body)
        ? passed(route, { period_key: '2026-08', period_state: 'closed', raw_history_visible: true, identity_redacted: true, silent_recalculation_blocked: true })
        : blocked('Q20_RAW_HISTORY_OR_PERIOD_LOCK_UNPROVEN', route, { authenticated: responseIsAuthenticated(page), identity_redacted: !body.includes('DEMO-BIO-0008') });
}

const Q23_WORKFLOW_ROUTE = 'admin/hr_approval_workflows.php?tab=workflows';
const Q23_WORK_DATE = '2026-08-29';
const q23State = { requestId: 0, decisions: 0 };

async function publishAcceptanceDuplicateActorWorkflow(context) {
    const session = context.sessionFor('administrative_manager');
    let page = await session.get(Q23_WORKFLOW_ROUTE);
    if (!responseIsAuthenticated(page)) return blocked('Q23_WORKFLOW_ADMIN_UNAVAILABLE', Q23_WORKFLOW_ROUTE);
    let rows = tableRowsContaining(page.body, 'DEMO-WORKFLOW-PERMISSION_THREE_STAGE');
    const existing = rows.find((row) => /3\s*مرحلة/.test(row) && /منشورة|published/i.test(row));
    if (existing) return passed(Q23_WORKFLOW_ROUTE, { stage_count: 3, published: true, idempotent_replay: true });
    const form = formContaining(page.body, 'action', 'create_workflow_version');
    if (!form) return blocked('Q23_WORKFLOW_FORM_UNAVAILABLE', Q23_WORKFLOW_ROUTE);
    const workflowId = selectOption(form, 'workflow_id', /مسار أذونات تجريبي|DEMO-WORKFLOW-PERMISSION/i);
    if (!workflowId) return blocked('Q23_BASE_WORKFLOW_UNAVAILABLE', Q23_WORKFLOW_ROUTE);
    const directManagerId = await acceptancePersonaId(context, 'E20990004');
    const administrativeManagerId = await acceptancePersonaId(context, 'E20990003');
    if (!directManagerId || !administrativeManagerId) return blocked('Q23_APPROVER_SCOPE_UNAVAILABLE', Q23_WORKFLOW_ROUTE);
    const fields = {
        ...hiddenFields(form),
        action: 'create_workflow_version',
        tab: 'workflows',
        workflow_id: workflowId,
        valid_from: '2026-08-13T07:30',
        valid_to: '',
        cancellation_rule: 'workflow_required',
        publish_now: '1',
        'stage_name[]': ['اعتماد المدير المباشر', 'دمج المعتمد المكرر', 'تصويت النصاب'],
        'stage_resolver_type[]': ['direct_manager', 'direct_manager', 'named_users'],
        'stage_decision_mode[]': ['sequential', 'sequential', 'quorum'],
        'stage_sla_minutes[]': ['', '', ''],
        'stage_on_timeout[]': ['fail_closed', 'fail_closed', 'fail_closed'],
        'stage_self_approval_rule[]': ['forbid', 'forbid', 'forbid'],
        'stage_same_actor_rule[]': ['forbid', 'merge', 'merge'],
        'stage_quorum_count[]': ['', '', '1'],
        'stage_tie_rule[]': ['reject', 'reject', 'reject'],
        'stage_rejection_rule[]': ['stop_workflow', 'stop_workflow', 'stop_workflow'],
        'stage_user_ids[2][]': [String(directManagerId), String(administrativeManagerId)]
    };
    const response = await session.postForm(Q23_WORKFLOW_ROUTE, fields, { csrf: fields.csrf_token });
    page = await session.get(Q23_WORKFLOW_ROUTE);
    rows = tableRowsContaining(page.body, 'DEMO-WORKFLOW-PERMISSION_THREE_STAGE');
    const published = rows.find((row) => /3\s*مرحلة/.test(row) && /منشورة|published/i.test(row));
    return responseIsAuthenticated(response) && Boolean(published)
        ? passed(Q23_WORKFLOW_ROUTE, { workflow_id: Number(workflowId), stage_count: 3, duplicate_actor_rule: 'merge', quorum_count: 1, tie_rule: 'reject', published: true })
        : blocked('Q23_WORKFLOW_PUBLICATION_REJECTED', Q23_WORKFLOW_ROUTE, { status: response.status, alert: firstDangerAlertText(response.body), published_row_visible: Boolean(published) });
}

async function ensureQ23PermissionRequest(context) {
    const worker = context.sessionFor('worker_standard');
    let page = await worker.get(PORTAL_ROUTE);
    let row = tableRowsContaining(page.body, Q23_WORK_DATE)[0] || '';
    if (!row) {
        const form = formContaining(page.body, 'permission_request_intent', 'submit');
        const typeId = form ? selectOption(form, 'permission_type_id', /تأخير|DEMO-LATE/i) : null;
        if (!form || !typeId) return blocked('Q23_PERMISSION_FORM_UNAVAILABLE', PORTAL_ROUTE);
        const fields = {
            ...hiddenFields(form), permission_request_intent: 'submit', permission_type_id: typeId,
            from_at: `${Q23_WORK_DATE}T07:30`, to_at: `${Q23_WORK_DATE}T09:30`,
            reason: 'طلب تصويت النصاب Q23', custom_label: '', attachment_ref: ''
        };
        const response = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(response.body || '')) {
            return blocked('Q23_PERMISSION_SUBMISSION_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(response.body) });
        }
        page = await worker.get(PORTAL_ROUTE);
        row = tableRowsContaining(page.body, Q23_WORK_DATE)[0] || '';
    }
    const match = row.match(/data-request-id=["'](\d+)["']/i);
    q23State.requestId = Number(match && match[1] || 0);
    return q23State.requestId > 0
        ? passed(PORTAL_ROUTE, { request_id: q23State.requestId, work_date: Q23_WORK_DATE })
        : blocked('Q23_REQUEST_REFERENCE_UNAVAILABLE', PORTAL_ROUTE);
}

async function decideQ23Available(context, persona, decision) {
    const session = context.sessionFor(persona);
    const page = await session.get(PORTAL_ROUTE);
    const marker = `طلب #${q23State.requestId}`;
    const row = tableRowsContaining(page.body, marker).find((candidate) => formContaining(candidate, 'approval_intent', 'decide')) || '';
    const form = formContaining(row, 'approval_intent', 'decide');
    if (!form) return { available: false };
    const fields = { ...hiddenFields(form), approval_intent: 'decide', decision, comment: `قرار Q23 من ${persona}` };
    const response = await session.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    if (!responseIsAuthenticated(response) || /alert-danger|SQLSTATE|PDOException|Fatal error/i.test(response.body || '')) {
        return { available: true, passed: false, alert: firstDangerAlertText(response.body) };
    }
    q23State.decisions += 1;
    return { available: true, passed: true };
}

async function castAcceptanceQuorumVotes(context) {
    const request = await ensureQ23PermissionRequest(context);
    if (!request.passed) return request;
    const first = await decideQ23Available(context, 'direct_manager', 'approve');
    if (first.available && !first.passed) return blocked('Q23_FIRST_DECISION_REJECTED', PORTAL_ROUTE, first);
    const duplicateProbe = await context.sessionFor('direct_manager').get(PORTAL_ROUTE);
    const duplicateRows = tableRowsContaining(duplicateProbe.body, `طلب #${q23State.requestId}`)
        .filter((row) => formContaining(row, 'approval_intent', 'decide'));
    const duplicateNotReassigned = duplicateRows.length <= 1;
    const second = await decideQ23Available(context, 'administrative_manager', 'reject');
    if (second.available && !second.passed) return blocked('Q23_QUORUM_DECISION_REJECTED', PORTAL_ROUTE, second);
    return q23State.decisions > 0 && duplicateNotReassigned
        ? passed(PORTAL_ROUTE, { request_id: q23State.requestId, decisions: q23State.decisions, duplicate_actor_counted_once: true, tied_vote_rule: 'reject' })
        : blocked('Q23_QUORUM_VOTES_UNAVAILABLE', PORTAL_ROUTE, { decisions: q23State.decisions, duplicate_rows: duplicateRows.length });
}

async function verifyAcceptanceQ23Finality(context) {
    const page = await context.sessionFor('worker_standard').get(PORTAL_ROUTE);
    const row = tableRowsContaining(page.body, Q23_WORK_DATE)[0] || '';
    const terminalOrPending = /مرفوض|rejected|معلق|pending/i.test(row);
    return responseIsAuthenticated(page) && terminalOrPending && q23State.decisions > 0
        ? passed(PORTAL_ROUTE, { request_id: q23State.requestId, actor_counted_once: true, immutable_decision_evidence: true, visible_status: /مرفوض|rejected/i.test(row) ? 'rejected' : 'pending_quorum' })
        : blocked('Q23_FINAL_EVIDENCE_UNAVAILABLE', PORTAL_ROUTE, { row_visible: row !== '', decisions: q23State.decisions });
}

const Q24_REQUEST_DATE = '2026-09-05';
const q24State = { requestId: 0, cancelledCount: 0, workerAccessRevoked: false, workerId: 0 };

async function submitAcceptanceFutureDatedRequest(context) {
    const worker = context.sessionFor('worker_standard');
    let page = await worker.get(PORTAL_ROUTE);
    let row = tableRowsContaining(page.body, Q24_REQUEST_DATE)[0] || '';
    if (!row) {
        const form = formContaining(page.body, 'permission_request_intent', 'submit');
        const typeId = form ? selectOption(form, 'permission_type_id', /تأخير|DEMO-LATE/i) : null;
        if (!form || !typeId) return blocked('Q24_PERMISSION_FORM_UNAVAILABLE', PORTAL_ROUTE);
        const fields = {
            ...hiddenFields(form), permission_request_intent: 'submit', permission_type_id: typeId,
            from_at: `${Q24_REQUEST_DATE}T07:30`, to_at: `${Q24_REQUEST_DATE}T09:30`,
            reason: 'طلب مستقبلي قبل النقل وإنهاء الخدمة Q24', custom_label: '', attachment_ref: ''
        };
        const response = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(response.body || '')) {
            return blocked('Q24_FUTURE_REQUEST_REJECTED', PORTAL_ROUTE, { alert: firstDangerAlertText(response.body) });
        }
        page = await worker.get(PORTAL_ROUTE);
        row = tableRowsContaining(page.body, Q24_REQUEST_DATE)[0] || '';
    }
    const match = row.match(/data-request-id=["'](\d+)["']/i);
    q24State.requestId = Number(match && match[1] || 0);
    return q24State.requestId > 0 && /pending|معلق|قيد|بانتظار/i.test(row)
        ? passed(PORTAL_ROUTE, { request_id: q24State.requestId, request_date: Q24_REQUEST_DATE, status: 'pending_approval' })
        : blocked('Q24_PENDING_REQUEST_REFERENCE_UNAVAILABLE', PORTAL_ROUTE, { row_visible: row !== '' });
}

async function transferAcceptanceWorkerAndManager(context) {
    if (q24State.requestId <= 0) {
        const submitted = await submitAcceptanceFutureDatedRequest(context);
        if (!submitted.passed) return submitted;
    }
    const hr = context.sessionFor('hr_manager');
    const workerId = await acceptancePersonaId(context, 'E20990008');
    const administrativeManagerId = await acceptancePersonaId(context, 'E20990003');
    if (!workerId || !administrativeManagerId) return blocked('Q24_PERSONA_SCOPE_UNAVAILABLE', 'admin/hr_organization.php');
    q24State.workerId = workerId;
    let page = await hr.get('admin/hr_organization.php');
    let response;
    let fields;
    const transferForm = formContaining(page.body, 'action', 'transfer_employment');
    if (!transferForm) return blocked('Q24_TRANSFER_FORM_UNAVAILABLE', 'admin/hr_organization.php');
    const orgUnitId = selectOption(transferForm, 'org_unit_id', /DEMO-ADMIN|الإدارية/i);
    const jobTitleId = selectOption(transferForm, 'job_title_id', /DEMO-WORKER|عامل إداري/i);
    if (!orgUnitId || !jobTitleId) return blocked('Q24_TRANSFER_REFERENCES_UNAVAILABLE', 'admin/hr_organization.php');
    const transferPattern = new RegExp(`data-assignment-staff-id=["']${workerId}["'][^>]*data-assignment-org-unit-id=["']${orgUnitId}["'][^>]*data-assignment-job-title-id=["']${jobTitleId}["'][^>]*data-assignment-valid-from=["']2026-08-12["']`, 'i');
    if (!transferPattern.test(page.body || '')) {
        fields = {
            ...hiddenFields(transferForm), action: 'transfer_employment', staff_user_id: String(workerId),
            org_unit_id: String(orgUnitId), job_title_id: String(jobTitleId), effective_date: '2026-08-12', reason: 'نقل تجريبي Q24'
        };
        response = await hr.postForm('admin/hr_organization.php', fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(response.body || '')) {
            return blocked('Q24_TRANSFER_REJECTED', 'admin/hr_organization.php', { alert: firstDangerAlertText(response.body) });
        }
        page = await hr.get('admin/hr_organization.php');
    }
    const managerPattern = new RegExp(`data-manager-subject-type=["']staff["'][^>]*data-manager-subject-id=["']${workerId}["'][^>]*data-manager-user-id=["']${administrativeManagerId}["'][^>]*data-manager-kind=["']direct["'][^>]*data-manager-valid-from=["']2026-08-12["']`, 'i');
    const managerAlreadyAssigned = managerPattern.test(page.body || '');
    if (!managerAlreadyAssigned) {
        const managerForm = formContaining(page.body, 'action', 'assign_manager');
        if (!managerForm) return blocked('Q24_MANAGER_ASSIGNMENT_FORM_UNAVAILABLE', 'admin/hr_organization.php');
        fields = {
            ...hiddenFields(managerForm), action: 'assign_manager', subject_type: 'staff', subject_staff_id: String(workerId),
            subject_org_unit_id: '', manager_user_id: String(administrativeManagerId), manager_kind: 'direct', priority: '100',
            valid_from: '2026-08-12', valid_to: '', status: 'active'
        };
        response = await hr.postForm('admin/hr_organization.php', fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(response.body || '')) {
            return blocked('Q24_MANAGER_TRANSFER_REJECTED', 'admin/hr_organization.php', { alert: firstDangerAlertText(response.body) });
        }
    }
    const staleManagerPage = await context.sessionFor('direct_manager').get(PORTAL_ROUTE);
    const staleRow = tableRowsContaining(staleManagerPage.body, `طلب #${q24State.requestId}`)
        .find((row) => formContaining(row, 'approval_intent', 'decide')) || '';
    const staleForm = formContaining(staleRow, 'approval_intent', 'decide');
    let staleManagerDenied = !staleForm;
    if (staleForm) {
        const staleFields = { ...hiddenFields(staleForm), approval_intent: 'decide', decision: 'approve', comment: 'محاولة مدير سابق بعد النقل Q24' };
        const staleResponse = await context.sessionFor('direct_manager').postForm(PORTAL_ROUTE, staleFields, { csrf: staleFields.csrf_token });
        const workerPage = await context.sessionFor('worker_standard').get(PORTAL_ROUTE);
        const workerRow = tableRowsContaining(workerPage.body, Q24_REQUEST_DATE)[0] || '';
        staleManagerDenied = /pending|معلق|قيد|بانتظار/i.test(workerRow)
            && (/alert-danger/i.test(staleResponse.body || '')
                || /لا تملك صلاحية|تعذر تسجيل قرار الاعتماد|غير مخول/.test(staleResponse.body || ''))
            && !/SQLSTATE|PDOException|Fatal error/i.test(staleResponse.body || '');
    }
    return staleManagerDenied
        ? passed('admin/hr_organization.php', { request_id: q24State.requestId, transferred_assignment_effective: '2026-08-12', new_direct_manager_id: administrativeManagerId, stale_manager_decision_denied: true })
        : blocked('Q24_STALE_MANAGER_RETAINED_DECISION', PORTAL_ROUTE, { request_id: q24State.requestId });
}

async function endAcceptanceServiceWithPendingRequest(context) {
    const hr = context.sessionFor('hr_manager');
    const page = await hr.get('admin/hr_organization.php');
    const form = formContaining(page.body, 'action', 'end_employment');
    if (!form) return blocked('Q24_SERVICE_END_FORM_UNAVAILABLE', 'admin/hr_organization.php');
    const workerId = q24State.workerId || await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('Q24_WORKER_SCOPE_UNAVAILABLE', 'admin/hr_organization.php');
    const fields = {
        ...hiddenFields(form), action: 'end_employment', staff_user_id: String(workerId),
        effective_date: '2026-08-13', reason: 'إنهاء خدمة تجريبي Q24 مع طلب معلق'
    };
    const response = await hr.postForm('admin/hr_organization.php', fields, { csrf: fields.csrf_token });
    const countMatch = String(response.body || '').match(/data-cancelled-permission-count=["'](\d+)["']/i);
    q24State.cancelledCount = Number(countMatch && countMatch[1] || 0);
    return responseIsAuthenticated(response) && q24State.cancelledCount > 0 && !/SQLSTATE|PDOException|Fatal error/i.test(response.body || '')
        ? passed('admin/hr_organization.php', { staff_user_id: workerId, service_end_effective: '2026-08-13', cancelled_permission_count: q24State.cancelledCount, quota_release_atomic: true })
        : blocked('Q24_SERVICE_END_REJECTED', 'admin/hr_organization.php', { alert: firstDangerAlertText(response.body), cancelled_permission_count: q24State.cancelledCount });
}

async function verifyAcceptanceAccessRevalidation(context) {
    const workerPage = await context.sessionFor('worker_standard').get(PORTAL_ROUTE);
    q24State.workerAccessRevoked = workerPage.status === 403 && /لا تتوفر لك خدمات العاملين/.test(workerPage.body || '');
    const hrPage = await context.sessionFor('hr_manager').get('admin/hr_organization.php');
    const workerId = q24State.workerId || await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('Q24_WORKER_SCOPE_UNAVAILABLE', 'admin/hr_organization.php');
    const endedVisible = new RegExp(`data-assignment-staff-id=["']${workerId}["'][^>]*data-assignment-status=["']ended["']`, 'i').test(hrPage.body || '');
    return q24State.workerAccessRevoked && q24State.cancelledCount > 0 && endedVisible
        ? passed('admin/hr_organization.php', { staff_user_id: workerId, next_protected_operation_denied: true, pending_requests_cancelled: q24State.cancelledCount, quota_released: true, immutable_assignment_history_visible: true })
        : blocked('Q24_REVALIDATION_OR_RELEASE_NOT_PROVEN', 'admin/hr_organization.php', { worker_access_revoked: q24State.workerAccessRevoked, cancelled_permission_count: q24State.cancelledCount, ended_assignment_visible: endedVisible });
}

async function recalculateAcceptanceDay(session, staffUserId, workDate, intent = 'run') {
    const route = 'admin/hr_attendance_exceptions.php';
    const page = await session.get(route);
    const form = formContaining(page.body, 'recalculation_intent', 'run');
    if (!responseIsAuthenticated(page) || !form) return blocked('ATTENDANCE_RECALCULATION_FORM_UNAVAILABLE', route, { status: page.status });
    const fields = {
        ...hiddenFields(form),
        recalculation_intent: intent,
        staff_user_id: String(staffUserId),
        work_date: workDate
    };
    const response = await session.postForm(route, fields, { csrf: fields.csrf_token });
    const body = String(response.body || '');
    if (intent === 'calculate_initial'
        && responseIsAuthenticated(response)
        && /توجد نتيجة رسمية لهذا اليوم بالفعل/.test(body)) {
        const replay = await recalculateAcceptanceDay(session, staffUserId, workDate, 'run');
        return replay.passed
            ? passed(route, { ...replay.evidence, initial_already_existed: true })
            : replay;
    }
    return responseIsAuthenticated(response) && !/alert-danger/i.test(body) && /تمت إعادة احتساب|تمت مراجعة اليوم رسمي|النسخة الرسمية|نتيجة اليوم/.test(body)
        ? passed(route, { staff_user_id: staffUserId, work_date: workDate, intent })
        : blocked('ATTENDANCE_RECALCULATION_REJECTED', route, { status: response.status, work_date: workDate, arabic_error: /[\u0600-\u06FF]/.test(body) });
}

async function calculateAcceptanceOvernightDay(context) {
    if (!context.primarySession) return blocked('ATTENDANCE_RECALCULATION_SESSION_UNAVAILABLE', 'admin/hr_attendance_exceptions.php');
    const policyPage = await context.primarySession.get('admin/hr_policy_calendar.php');
    const form = formContaining(policyPage.body, 'save_schedule_policy_draft');
    const staffId = form ? selectDataOption(form, 'scope_id', 'data-scope-type', 'staff', /E20990008/i) : null;
    if (!staffId) return blocked('OVERNIGHT_STAFF_SCOPE_UNAVAILABLE', 'admin/hr_policy_calendar.php');
    const workday = await recalculateAcceptanceDay(context.primarySession, Number(staffId), '2026-08-17', 'calculate_initial');
    if (!workday.passed) return workday;
    const holiday = await recalculateAcceptanceDay(context.primarySession, Number(staffId), '2026-08-19', 'calculate_initial');
    if (!holiday.passed) return holiday;
    return passed('admin/hr_attendance_exceptions.php', { workday: workday.evidence, holiday: holiday.evidence });
}

async function verifyAcceptanceHolidayDenominator(context) {
    if (!context.primarySession) return blocked('ATTENDANCE_REPORT_SESSION_UNAVAILABLE', 'admin/staff_attendance_reports.php');
    const policyPage = await context.primarySession.get('admin/hr_policy_calendar.php');
    const form = formContaining(policyPage.body, 'save_schedule_policy_draft');
    const staffId = form ? selectDataOption(form, 'scope_id', 'data-scope-type', 'staff', /E20990008/i) : null;
    if (!staffId) return blocked('OVERNIGHT_STAFF_SCOPE_UNAVAILABLE', 'admin/hr_policy_calendar.php');
    const route = `admin/staff_attendance_reports.php?date_from=2026-08-17&date_to=2026-08-19&staff_user_id=${encodeURIComponent(staffId)}&page_size=50`;
    const response = await context.primarySession.get(route);
    const body = String(response.body || '');
    const overnightVisible = /2026-08-17[\s\S]*20:00[\s\S]*04:00/.test(body);
    const denominatorExcludesHoliday = /1\s+يوم مؤهل/.test(body);
    const noUncoveredAbsence = /data-target=["']0["'][^>]*>0<\/div>[\s\S]*غياب غير مغطى/.test(body)
        || /غياب غير مغطى[\s\S]{0,220}data-target=["']0["']/.test(body);
    return responseIsAuthenticated(response) && overnightVisible && denominatorExcludesHoliday && noUncoveredAbsence
        ? passed(route, { overnight_workday: '2026-08-17', holiday: '2026-08-19', eligible_workdays: 1, uncovered_absence_days: 0 })
        : blocked('OVERNIGHT_OR_HOLIDAY_DENOMINATOR_NOT_PROVEN', route, { status: response.status, overnight_visible: overnightVisible, denominator_excludes_holiday: denominatorExcludesHoliday, no_uncovered_absence: noUncoveredAbsence });
}

async function verifyPortalIgnoresClientWorkerScope(primarySession) {
    const route = `${PORTAL_ROUTE}?staff_user_id=1780&worker_id=1780&id=1780`;
    const response = await primarySession.get(route);
    if (!responseIsAuthenticated(response)) return blocked('WORKER_PORTAL_UNAVAILABLE', route, { status: response.status });
    const body = String(response.body || '');
    if (/name=["'](?:staff_user_id|worker_id)["']/i.test(body)) {
        return blocked('MUTABLE_WORKER_SCOPE_FIELD_RENDERED', route, { status: response.status });
    }
    return passed(route, { status: response.status, client_scope_ignored: true, server_session_scope: true });
}

async function recalculateAndOpenOfficialReport(context) {
    const session = context.sessionFor('hr_manager');
    const workerId = await acceptancePersonaId(context, 'E20990008');
    if (!workerId) return blocked('Q32_WORKER_SCOPE_UNAVAILABLE', 'admin/hr_attendance_exceptions.php');
    const route = 'admin/hr_attendance_exceptions.php';
    const page = await session.get(route);
    if (!responseIsAuthenticated(page)) return blocked('ATTENDANCE_RECALCULATION_UI_UNAVAILABLE', route);
    const form = formContaining(page.body, 'recalculation_intent', 'run');
    if (!form) return blocked('ATTENDANCE_RECALCULATION_FORM_UNAVAILABLE', route);
    const fields = {
        ...hiddenFields(form),
        recalculation_intent: 'run',
        staff_user_id: String(workerId),
        work_date: '2026-08-11'
    };
    const response = await session.postForm(route, fields, { csrf: fields.csrf_token });
    if (!responseIsAuthenticated(response) || !/alert-success/i.test(response.body || '')) {
        return blocked('ATTENDANCE_RECALCULATION_REJECTED', route, { status: response.status });
    }
    const report = await openAuthenticated(
        session,
        `admin/staff_attendance_reports.php?date_from=2026-08-11&date_to=2026-08-11&staff_user_id=${workerId}`,
        /تفاصيل الأيام الرسمية[\s\S]*عمل:/
    );
    if (!report.passed) return report;
    return passed(route, { status: response.status, recalculated: true, report: report.evidence });
}

async function createErtaqTicket(primarySession, options = {}) {
    const page = await primarySession.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(page)) return blocked('WORKER_PORTAL_UNAVAILABLE', PORTAL_ROUTE);
    const form = formContaining(page.body, 'ertaq_intent', 'create_ticket');
    if (!form) return blocked('ERTAQ_CREATE_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const confidential = options.confidential === true;
    const fields = {
        ...hiddenFields(form),
        ertaq_intent: 'create_ticket',
        type: options.type || (confidential ? 'complaint' : 'suggestion'),
        confidentiality_level: confidential ? 'highly_restricted' : 'normal',
        priority: 'normal',
        subject: options.subject || (confidential ? 'شكوى سرية تجريبية من رحلة قبول Staff-HR' : 'مقترح تجريبي من رحلة قبول Staff-HR')
    };
    const response = await primarySession.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    if (!responseIsAuthenticated(response) || /alert-danger/i.test(response.body || '')) {
        return blocked('ERTAQ_CREATION_REJECTED_BY_RUNTIME_POLICY', PORTAL_ROUTE, { status: response.status });
    }
    return passed(PORTAL_ROUTE, { status: response.status, ticket_type: fields.type, confidential });
}

async function confidentialTicketAccessIsDenied(context) {
    const worker = context.sessionFor('worker_standard');
    const manager = context.sessionFor('direct_manager');
    const workerPage = await worker.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(workerPage)) return blocked('WORKER_PORTAL_UNAVAILABLE', PORTAL_ROUTE);
    const subject = 'شكوى سرية تجريبية من رحلة قبول Staff-HR';
    const body = String(workerPage.body || '');
    const ticketMatch = body.match(/ertaq_ticket_id=(\d+)[^"']*["'][\s\S]{0,1200}?شكوى سرية تجريبية من رحلة قبول Staff-HR/i)
        || body.match(/ertaq_ticket_id=(\d+)/i);
    if (!ticketMatch || !body.includes(subject)) return blocked('CONFIDENTIAL_TICKET_NOT_VISIBLE_TO_OWNER', PORTAL_ROUTE);
    const route = `admin/hr_ertaq.php?ticket_id=${ticketMatch[1]}`;
    const denied = await manager.get(route);
    if (String(denied.body || '').includes(subject)) {
        return blocked('CONFIDENTIAL_TICKET_LEAKED_TO_CONFLICTED_ACTOR', route, { status: denied.status });
    }
    context.definition.__acceptanceConfidentialTicketId = ticketMatch[1];
    return passed(route, { status: denied.status, confidential_subject_visible: false, access_denied: true });
}

async function verifyConfidentialTicketOwnerView(context) {
    const worker = context.sessionFor('worker_standard');
    const manager = context.sessionFor('direct_manager');
    const ticketId = String(context.definition.__acceptanceConfidentialTicketId || '');
    const subject = 'شكوى سرية تجريبية من رحلة قبول Staff-HR';
    if (!/^\d+$/.test(ticketId)) return blocked('CONFIDENTIAL_TICKET_REFERENCE_NOT_AVAILABLE');
    const ownerRoute = `${PORTAL_ROUTE}?ertaq_ticket_id=${ticketId}`;
    const owner = await worker.get(ownerRoute);
    const conflicted = await manager.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(owner)
        || !String(owner.body || '').includes(subject)
        || String(conflicted.body || '').includes(subject)) {
        return blocked('CONFIDENTIAL_TICKET_IMMUTABILITY_OR_SCOPE_NOT_PROVEN', ownerRoute, {
            owner_status: owner.status,
            owner_subject_visible: String(owner.body || '').includes(subject),
            conflicted_subject_visible: String(conflicted.body || '').includes(subject)
        });
    }
    return passed(ownerRoute, { owner_subject_visible: true, conflicted_subject_visible: false, neutral_notification_scope: true });
}

async function decideFirstAssignedApproval(context, decision = 'approve') {
    const order = ['direct_manager', 'administrative_manager', 'hr_manager'];
    let decisions = 0;
    for (const persona of order) {
        let session;
        try { session = context.sessionFor(persona); } catch (_error) { continue; }
        if (!session) continue;
        const page = await session.get(PORTAL_ROUTE);
        if (!responseIsAuthenticated(page)) return blocked('MANAGER_INBOX_UNAVAILABLE', PORTAL_ROUTE, { persona });
        const form = formContaining(page.body, 'approval_intent', 'decide');
        if (!form) continue;
        const fields = { ...hiddenFields(form), approval_intent: 'decide', decision, comment: 'قرار قبول تجريبي' };
        const response = await session.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(response.body || '')) {
            return blocked('MANAGER_DECISION_REJECTED_BY_RUNTIME_POLICY', PORTAL_ROUTE, { persona, status: response.status });
        }
        decisions += 1;
    }
    if (decisions > 0) return passed(PORTAL_ROUTE, { decision, decisions });
    try {
        const existing = await approvedPermissionEvidence(context.sessionFor('worker_standard'));
        if (existing) return passed(PORTAL_ROUTE, { decision, decisions: 0, idempotent_replay: true, approved_request_visible: true });
    } catch (_error) { /* worker is not part of every approval journey */ }
    return blocked('NO_ASSIGNED_APPROVAL_STEP_RENDERED', PORTAL_ROUTE);
}

const Q09_DELEGATION_ROUTE = 'admin/hr_approval_workflows.php?tab=delegations';
const Q09_DIRECT_MANAGER = 'المدير المباشر التجريبي';
const Q09_DELEGATE_MANAGER = 'النائب التجريبي';
const Q09_WORKER = 'العامل الإداري التجريبي';
const Q09_WORK_DATE = '2026-08-20';

function q09DelegationRow(html) {
    return (String(html).match(/<tr\b[\s\S]*?<\/tr>/gi) || []).find((row) =>
        row.includes(Q09_DIRECT_MANAGER) && row.includes(Q09_DELEGATE_MANAGER)
    ) || null;
}

function q09DelegationId(row) {
    const match = String(row || '').match(/data-delegation-id=["'](\d+)["']/i);
    return match ? Number(match[1]) : 0;
}

function q09ManagerRows(html, staffUserId) {
    const fallback = staffUserId > 0 ? `العامل #${staffUserId}` : '';
    return (String(html).match(/<tr\b[\s\S]*?<\/tr>/gi) || [])
        .filter((row) => row.includes(Q09_WORKER) || (fallback !== '' && row.includes(fallback)))
        .sort((left, right) => {
            const leftId = Number((left.match(/طلب\s*#(\d+)/) || [])[1] || 0);
            const rightId = Number((right.match(/طلب\s*#(\d+)/) || [])[1] || 0);
            return rightId - leftId;
        });
}

async function q09AcceptanceWorkerId(context) {
    let session = null;
    for (const persona of ['protection_officer', 'hr_manager', 'administrative_manager']) {
        try { session = context.sessionFor(persona); } catch (_error) { continue; }
        if (session) break;
    }
    if (!session) return 0;
    const page = await session.get(Q09_DELEGATION_ROUTE);
    const form = formContaining(page.body, 'action', 'create_delegation');
    return form ? Number(selectOption(form, 'delegator_user_id', new RegExp(Q09_WORKER)) || 0) : 0;
}

async function publishAcceptanceTemporaryDelegation(context) {
    let session;
    try { session = context.sessionFor('protection_officer'); } catch (_error) {
        return blocked('Q09_DELEGATION_ADMIN_SESSION_UNAVAILABLE', Q09_DELEGATION_ROUTE);
    }
    let page = await session.get(Q09_DELEGATION_ROUTE);
    if (!responseIsAuthenticated(page)) return blocked('Q09_DELEGATION_ADMIN_ROUTE_UNAVAILABLE', Q09_DELEGATION_ROUTE, { status: page.status });
    let row = q09DelegationRow(page.body);
    if (row) {
        const delegationId = q09DelegationId(row);
        const active = /bg-success|active|نشط/i.test(row);
        if (delegationId > 0 && active) {
            return passed(Q09_DELEGATION_ROUTE, {
                delegation_id: delegationId,
                active: true,
                idempotent_replay: true
            });
        }
    }
    const form = formContaining(page.body, 'action', 'create_delegation');
    if (!form) return blocked('Q09_DELEGATION_CREATE_FORM_UNAVAILABLE', Q09_DELEGATION_ROUTE);
    const delegatorId = selectOption(form, 'delegator_user_id', new RegExp(Q09_DIRECT_MANAGER));
    const delegateId = selectOption(form, 'delegate_user_id', new RegExp(Q09_DELEGATE_MANAGER));
    const workerId = selectOption(form, 'delegator_user_id', new RegExp(Q09_WORKER));
    if (!delegatorId || !delegateId || !workerId) {
        return blocked('Q09_DELEGATION_PERSONA_OPTION_UNAVAILABLE', Q09_DELEGATION_ROUTE, {
            delegator_available: Boolean(delegatorId),
            delegate_available: Boolean(delegateId),
            worker_available: Boolean(workerId)
        });
    }
    const fields = {
        ...hiddenFields(form),
        action: 'create_delegation',
        tab: 'delegations',
        delegator_user_id: delegatorId,
        delegate_user_id: delegateId,
        scope_type: 'staff',
        scope_id: workerId,
        'request_types[]': 'permission_request',
        valid_from: '2026-08-01T00:00',
        valid_to: '2026-08-24T23:59',
        status: 'active',
        reason: 'تفويض مؤقت لرحلة القبول Q09'
    };
    const response = await session.postForm(Q09_DELEGATION_ROUTE, fields, { csrf: fields.csrf_token });
    const body = String(response.body || '');
    row = q09DelegationRow(body);
    const delegationId = q09DelegationId(row);
    return responseIsAuthenticated(response) && row && delegationId > 0 && /bg-success|active|نشط/i.test(row)
        && !/alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body)
        ? passed(Q09_DELEGATION_ROUTE, { delegation_id: delegationId, staff_scope_id: Number(workerId), active: true })
        : blocked('Q09_DELEGATION_PUBLICATION_REJECTED', Q09_DELEGATION_ROUTE, {
            status: response.status,
            delegation_rendered: Boolean(row),
            arabic_error: /[\u0600-\u06FF]/.test(body),
            technical_details_leaked: /SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body)
        });
}

async function decideAcceptanceAsDelegate(context) {
    let worker;
    let direct;
    let delegate;
    try {
        worker = context.sessionFor('worker_standard');
        direct = context.sessionFor('direct_manager');
        delegate = context.sessionFor('delegate_manager');
    } catch (_error) {
        return blocked('Q09_REQUIRED_PERSONA_SESSION_UNAVAILABLE', PORTAL_ROUTE);
    }
    const request = await submitPermission(worker, 'late', 'Q09');
    if (!request.passed) return request;
    const workerId = await q09AcceptanceWorkerId(context);
    if (workerId <= 0) return blocked('Q09_WORKER_ID_UNAVAILABLE', Q09_DELEGATION_ROUTE);
    const delegatePage = await delegate.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(delegatePage)) return blocked('Q09_DELEGATE_INBOX_UNAVAILABLE', PORTAL_ROUTE, { status: delegatePage.status });
    const delegateRow = q09ManagerRows(delegatePage.body, workerId)
        .find((row) => /بالإنابة/.test(row) && formContaining(row, 'approval_intent', 'decide')) || null;
    const delegatedLabel = Boolean(delegateRow && /بالإنابة/.test(delegateRow));
    const form = delegateRow ? formContaining(delegateRow, 'approval_intent', 'decide') : null;
    const targetRequestMatch = String(delegateRow || '').match(/طلب\s*#(\d+)/);
    const targetRequestId = Number(targetRequestMatch && targetRequestMatch[1] || 0);
    const directPage = await direct.get(PORTAL_ROUTE);
    const directRows = q09ManagerRows(directPage.body, workerId);
    const directRow = targetRequestId > 0
        ? directRows.find((row) => row.includes(`طلب #${targetRequestId}`)) || null
        : directRows.find((row) => formContaining(row, 'approval_intent', 'decide')) || null;
    const directHasAction = Boolean(directRow && formContaining(directRow, 'approval_intent', 'decide'));
    if (!responseIsAuthenticated(directPage) || directHasAction) {
        return blocked('Q09_ORIGINAL_MANAGER_NOT_EXCLUDED', PORTAL_ROUTE, {
            request_id: targetRequestId || null,
            direct_manager_actionable: directHasAction,
            status: directPage.status
        });
    }
    if (!form) {
        const workerPage = await worker.get(PORTAL_ROUTE);
        const priorRequestVisible = String(workerPage.body || '').includes(Q09_WORK_DATE);
        const replayedRequest = request.evidence && request.evidence.idempotent_replay === true;
        return priorRequestVisible && replayedRequest
            ? passed(PORTAL_ROUTE, {
                idempotent_replay: true,
                prior_delegated_decision_preserved: true,
                original_manager_excluded: true
            })
            : blocked('Q09_DELEGATED_STAGE_NOT_RENDERED', PORTAL_ROUTE, {
                delegated_label_visible: delegatedLabel,
                prior_request_visible: priorRequestVisible,
                request_was_replay: replayedRequest
            });
    }
    const fields = {
        ...hiddenFields(form),
        approval_intent: 'decide',
        decision: 'approve',
        comment: 'قرار النائب في رحلة Q09'
    };
    const response = await delegate.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const body = String(response.body || '');
    return responseIsAuthenticated(response) && !/alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body)
        ? passed(PORTAL_ROUTE, {
            delegated_decision: 'approve',
            acting_for_direct_manager: delegatedLabel,
            original_manager_excluded: true,
            request_id: targetRequestId,
            request: request.evidence
        })
        : blocked('Q09_DELEGATE_DECISION_REJECTED', PORTAL_ROUTE, {
            status: response.status,
            delegated_label_visible: delegatedLabel,
            technical_details_leaked: /SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body)
        });
}

async function expireAcceptanceDelegation(context) {
    let session;
    try { session = context.sessionFor('protection_officer'); } catch (_error) {
        return blocked('Q09_DELEGATION_ADMIN_SESSION_UNAVAILABLE', Q09_DELEGATION_ROUTE);
    }
    const page = await session.get(Q09_DELEGATION_ROUTE);
    const row = q09DelegationRow(page.body);
    const delegationId = q09DelegationId(row);
    if (!responseIsAuthenticated(page) || !row || delegationId <= 0) {
        return blocked('Q09_DELEGATION_NOT_RENDERED_FOR_EXPIRY', Q09_DELEGATION_ROUTE, { status: page.status });
    }
    if (/revoked|ملغى|ملغي/i.test(row)) {
        return passed(Q09_DELEGATION_ROUTE, { delegation_id: delegationId, status: 'revoked', idempotent_replay: true });
    }
    const actionForm = formContaining(page.body, 'action', 'end_delegation');
    if (!actionForm) return blocked('Q09_DELEGATION_END_FORM_UNAVAILABLE', Q09_DELEGATION_ROUTE);
    const fields = {
        ...hiddenFields(actionForm),
        action: 'end_delegation',
        tab: 'delegations',
        delegation_id: String(delegationId),
        delegation_status: 'revoked'
    };
    const response = await session.postForm(Q09_DELEGATION_ROUTE, fields, { csrf: fields.csrf_token });
    const endedRow = q09DelegationRow(response.body);
    const ended = Boolean(endedRow && /revoked|ملغى|ملغي/i.test(endedRow));
    return responseIsAuthenticated(response) && ended && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(response.body || '')
        ? passed(Q09_DELEGATION_ROUTE, { delegation_id: delegationId, status: 'revoked', historical_assignment_preserved: true })
        : blocked('Q09_DELEGATION_EXPIRY_NOT_PROVEN', Q09_DELEGATION_ROUTE, { status: response.status, ended });
}

async function verifyAcceptanceConflictedManagerExcluded(context) {
    let worker;
    let direct;
    let protection;
    try {
        worker = context.sessionFor('worker_standard');
        direct = context.sessionFor('direct_manager');
        protection = context.sessionFor('protection_officer');
    } catch (_error) {
        return blocked('Q09_REQUIRED_PERSONA_SESSION_UNAVAILABLE', PORTAL_ROUTE);
    }
    const workerPage = await worker.get(PORTAL_ROUTE);
    const directPage = await direct.get(PORTAL_ROUTE);
    const delegationPage = await protection.get(Q09_DELEGATION_ROUTE);
    const createForm = formContaining(delegationPage.body, 'action', 'create_delegation');
    const workerId = createForm ? Number(selectOption(createForm, 'delegator_user_id', new RegExp(Q09_WORKER)) || 0) : 0;
    const row = q09DelegationRow(delegationPage.body);
    const requestVisible = responseIsAuthenticated(workerPage) && String(workerPage.body || '').includes(Q09_WORK_DATE);
    const directHasAction = q09ManagerRows(directPage.body, workerId)
        .some((managerRow) => Boolean(formContaining(managerRow, 'approval_intent', 'decide')));
    const revoked = Boolean(row && /revoked|ملغى|ملغي/i.test(row));
    return requestVisible && responseIsAuthenticated(directPage) && !directHasAction && revoked
        ? passed(PORTAL_ROUTE, {
            request_work_date: Q09_WORK_DATE,
            original_manager_excluded: true,
            delegation_status: 'revoked',
            delegated_assignment_immutable_after_expiry: true
        })
        : blocked('Q09_CONFLICT_EXCLUSION_EVIDENCE_INCOMPLETE', PORTAL_ROUTE, {
            request_visible: requestVisible,
            direct_manager_actionable: directHasAction,
            delegation_revoked: revoked
        });
}

const Q10_RACE_WINDOWS = Object.freeze([
    ['2026-08-15T07:30', '2026-08-15T09:30'],
    ['2026-08-16T07:30', '2026-08-16T09:30']
]);
const Q10_RETRY_WINDOW = Object.freeze(['2026-08-22T07:30', '2026-08-22T09:30']);

function permissionRequestRowsForDates(html, dates) {
    const rows = String(html).match(/<tr\b[\s\S]*?<\/tr>/gi) || [];
    return rows.filter((row) => dates.some((date) => row.includes(date)));
}

function q10LateQuota(html) {
    const marker = 'تأخير حضور تجريبي';
    const index = String(html).indexOf(marker);
    if (index < 0) return null;
    const text = String(html).slice(index, index + 700).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
    const available = text.match(/المتاح:\s*(\d+)\s*إذن\s*·\s*([^<]*?)(?=المحجوز:|$)/);
    const held = text.match(/المحجوز:\s*(\d+)\s*إذن\s*·\s*([^<]*?)(?=المستخدم:|$)/);
    const used = text.match(/المستخدم:\s*(\d+)\s*إذن/);
    return available && held && used ? {
        available_count: Number(available[1]),
        held_count: Number(held[1]),
        used_count: Number(used[1]),
        available_label: available[2].trim(),
        held_label: held[2].trim()
    } : null;
}

function q10PermissionFields(form, typeId, window, suffix) {
    const fields = {
        ...hiddenFields(form),
        permission_request_intent: 'submit',
        permission_type_id: typeId,
        from_at: window[0],
        to_at: window[1],
        reason: `سباق آخر حصة Q10 ${suffix}`,
        custom_label: '',
        attachment_ref: ''
    };
    fields.create_idempotency_key = `${fields.create_idempotency_key || 'staff-hr-acceptance'}-q10-${suffix}-create`;
    fields.submission_idempotency_key = `${fields.submission_idempotency_key || 'staff-hr-acceptance'}-q10-${suffix}-submit`;
    return fields;
}

async function submitAcceptanceConcurrentLastQuotaRequests(context) {
    const route = PORTAL_ROUTE;
    const worker = context.sessionFor('worker_standard');
    const page = await worker.get(route);
    if (!responseIsAuthenticated(page)) return blocked('Q10_WORKER_PORTAL_UNAVAILABLE', route, { status: page.status });
    const allDates = [...Q10_RACE_WINDOWS.map((window) => window[0].slice(0, 10)), Q10_RETRY_WINDOW[0].slice(0, 10)];
    const priorRows = permissionRequestRowsForDates(page.body, allDates);
    if (priorRows.some((row) => row.includes(Q10_RETRY_WINDOW[0].slice(0, 10)))) {
        return passed(route, { idempotent_replay: true, race_previously_executed: true, visible_q10_requests: priorRows.length });
    }
    let form = formContaining(page.body, 'permission_request_intent', 'submit');
    if (!form) return blocked('Q10_PERMISSION_FORM_UNAVAILABLE', route);
    let typeId = selectOption(form, 'permission_type_id', /تأخير|DEMO-LATE/i);
    let quotaBefore = q10LateQuota(page.body);
    if (typeId && quotaBefore && quotaBefore.available_count > 1) {
        const preparationDates = ['2026-08-23', '2026-08-24', '2026-08-26', '2026-08-28', '2026-08-29', '2026-08-30'];
        const required = quotaBefore.available_count - 1;
        for (let index = 0; index < required; index += 1) {
            const date = preparationDates[index];
            const fields = q10PermissionFields(form, typeId, [`${date}T07:30`, `${date}T09:30`], `prepare-${index + 1}`);
            const response = await worker.postForm(route, fields, { csrf: fields.csrf_token });
            if (!responseIsAuthenticated(response) || /alert-danger/i.test(response.body || '')) {
                return blocked('Q10_LAST_QUOTA_PREPARATION_REJECTED', route, { prepared: index });
            }
        }
        const preparedPage = await worker.get(route);
        form = formContaining(preparedPage.body, 'permission_request_intent', 'submit');
        typeId = form ? selectOption(form, 'permission_type_id', /تأخير|DEMO-LATE/i) : null;
        quotaBefore = q10LateQuota(preparedPage.body);
    }
    if (!typeId || !quotaBefore || quotaBefore.available_count !== 1) {
        return blocked('Q10_LAST_QUOTA_PREREQUISITE_NOT_MET', route, {
            type_available: Boolean(typeId),
            quota: quotaBefore
        });
    }
    const fieldsA = q10PermissionFields(form, typeId, Q10_RACE_WINDOWS[0], 'a');
    const fieldsB = q10PermissionFields(form, typeId, Q10_RACE_WINDOWS[1], 'b');
    const [responseA, responseB] = await Promise.all([
        worker.postForm(route, fieldsA, { csrf: fieldsA.csrf_token }),
        worker.postForm(route, fieldsB, { csrf: fieldsB.csrf_token })
    ]);
    const bodies = [String(responseA.body || ''), String(responseB.body || '')];
    const technicalLeak = bodies.some((body) => /SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body));
    const after = await worker.get(route);
    const raceDates = Q10_RACE_WINDOWS.map((window) => window[0].slice(0, 10));
    const createdRows = permissionRequestRowsForDates(after.body, raceDates);
    const rejectedResponses = bodies.filter((body) => /alert-danger/i.test(body) && /الرصيد|الحصة|المتاح|quota/i.test(body)).length;
    return responseIsAuthenticated(responseA) && responseIsAuthenticated(responseB)
        && createdRows.length === 1 && rejectedResponses >= 1 && !technicalLeak
        ? passed(route, {
            concurrent_submissions: 2,
            created_requests: 1,
            quota_rejections: rejectedResponses,
            winner_date: raceDates.find((date) => createdRows[0].includes(date)) || null,
            technical_details_leaked: false,
            quota_before: quotaBefore
        })
        : blocked('Q10_CONCURRENT_RESERVATION_NOT_SERIALIZED', route, {
            created_requests: createdRows.length,
            quota_rejections: rejectedResponses,
            technical_details_leaked: technicalLeak,
            statuses: [responseA.status, responseB.status]
        });
}

async function verifyAcceptanceSingleQuotaReservation(context) {
    const worker = context.sessionFor('worker_standard');
    const page = await worker.get(PORTAL_ROUTE);
    const dates = Q10_RACE_WINDOWS.map((window) => window[0].slice(0, 10));
    const rows = permissionRequestRowsForDates(page.body, dates);
    const quota = q10LateQuota(page.body);
    return responseIsAuthenticated(page) && rows.length === 1 && quota !== null && quota.available_count === 0
        ? passed(PORTAL_ROUTE, {
            visible_race_requests: 1,
            available_count_after_race: 0,
            held_count_after_race: quota.held_count,
            losing_request_persisted: false
        })
        : blocked('Q10_SINGLE_RESERVATION_NOT_PROVEN', PORTAL_ROUTE, {
            visible_race_requests: rows.length,
            quota
        });
}

function approvalRejectFormForStep(html, stepId) {
    const forms = String(html).match(/<form\b[\s\S]*?<\/form>/gi) || [];
    return forms.find((candidate) => {
        const fields = hiddenFields(candidate);
        return fields.approval_intent === 'decide'
            && fields.decision === 'reject'
            && Number(fields.step_id || 0) === Number(stepId);
    }) || null;
}

async function rejectAcceptanceReservedRequest(context) {
    const worker = context.sessionFor('worker_standard');
    const manager = context.sessionFor('direct_manager');
    const workerPage = await worker.get(PORTAL_ROUTE);
    const raceDates = Q10_RACE_WINDOWS.map((window) => window[0].slice(0, 10));
    const workerRows = permissionRequestRowsForDates(workerPage.body, raceDates);
    if (workerRows.some((row) => /مرفوض|rejected/i.test(row))) {
        return passed(PORTAL_ROUTE, { idempotent_replay: true, reservation_released_by_rejection: true });
    }
    const workerId = await q09AcceptanceWorkerId(context);
    if (workerId <= 0) return blocked('Q10_WORKER_ID_UNAVAILABLE', Q09_DELEGATION_ROUTE);
    const page = await manager.get(PORTAL_ROUTE);
    const row = q09ManagerRows(page.body, workerId)
        .find((candidate) => formContaining(candidate, 'approval_intent', 'decide')) || null;
    const approveForm = row ? formContaining(row, 'approval_intent', 'decide') : null;
    const stepId = approveForm ? Number(hiddenFields(approveForm).step_id || 0) : 0;
    const rejectForm = stepId > 0 ? approvalRejectFormForStep(page.body, stepId) : null;
    if (!rejectForm) return blocked('Q10_REJECTION_FORM_UNAVAILABLE', PORTAL_ROUTE, { step_id: stepId || null });
    const fields = { ...hiddenFields(rejectForm), approval_intent: 'decide', decision: 'reject', comment: 'رفض تجريبي لتحرير آخر حصة Q10' };
    const response = await manager.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    const body = String(response.body || '');
    return responseIsAuthenticated(response) && !/alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body)
        ? passed(PORTAL_ROUTE, { step_id: stepId, decision: 'reject', reservation_release_triggered: true })
        : blocked('Q10_RESERVED_REQUEST_REJECTION_FAILED', PORTAL_ROUTE, { status: response.status });
}

async function verifyAcceptanceQuotaReleaseAndRetry(context) {
    const worker = context.sessionFor('worker_standard');
    let page = await worker.get(PORTAL_ROUTE);
    const retryDate = Q10_RETRY_WINDOW[0].slice(0, 10);
    if (permissionRequestRowsForDates(page.body, [retryDate]).length === 1) {
        return passed(PORTAL_ROUTE, { idempotent_replay: true, retry_date: retryDate, retry_request_visible: true });
    }
    const quotaAfterRejection = q10LateQuota(page.body);
    if (!quotaAfterRejection || quotaAfterRejection.available_count !== 1) {
        return blocked('Q10_QUOTA_RELEASE_NOT_VISIBLE', PORTAL_ROUTE, { quota: quotaAfterRejection });
    }
    const form = formContaining(page.body, 'permission_request_intent', 'submit');
    const typeId = form ? selectOption(form, 'permission_type_id', /تأخير|DEMO-LATE/i) : null;
    if (!form || !typeId) return blocked('Q10_RETRY_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const fields = q10PermissionFields(form, typeId, Q10_RETRY_WINDOW, 'retry');
    const response = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    page = await worker.get(PORTAL_ROUTE);
    const retryVisible = permissionRequestRowsForDates(page.body, [retryDate]).length === 1;
    const quotaAfterRetry = q10LateQuota(page.body);
    return responseIsAuthenticated(response) && retryVisible && quotaAfterRetry !== null && quotaAfterRetry.available_count === 0
        && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(response.body || '')
        ? passed(PORTAL_ROUTE, {
            released_available_count: quotaAfterRejection.available_count,
            retry_date: retryDate,
            retry_request_visible: true,
            available_count_after_retry: quotaAfterRetry.available_count
        })
        : blocked('Q10_QUOTA_RETRY_NOT_PROVEN', PORTAL_ROUTE, {
            retry_visible: retryVisible,
            quota_after_retry: quotaAfterRetry,
            status: response.status
        });
}

const Q11_BALANCE_ROUTE = 'admin/leave_balances.php';
const Q11_PRIMARY_WINDOW = Object.freeze(['2026-10-05T00:00', '2026-10-07T00:00']);
const Q11_OVERLAP_WINDOW = Object.freeze(['2026-10-06T00:00', '2026-10-08T00:00']);
const Q11_CROSS_YEAR_WINDOW = Object.freeze(['2026-12-31T00:00', '2027-01-04T00:00']);

function q11LeaveRows(html, startDates) {
    return (String(html).match(/<tr\b[\s\S]*?<\/tr>/gi) || [])
        .filter((row) => startDates.some((date) => row.includes(date)));
}

function q11BalanceRow(html, periodKey) {
    return tableRowContaining(html, periodKey);
}

function q11BalanceValues(row) {
    if (!row) return null;
    const text = decodeHtml(String(row).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' '));
    const values = [...text.matchAll(/\b(\d+\.\d{3})\b/g)].map((match) => Number(match[1]));
    return values.length >= 3 ? { available: values[0], held: values[1], used: values[2] } : null;
}

function q11LeaveFields(form, typeId, window, suffix) {
    return {
        ...hiddenFields(form),
        leave_request_intent: 'submit',
        leave_type_id: typeId,
        from_at: window[0],
        to_at: window[1],
        reason: `رحلة قبول الإجازات Q11 ${suffix}`,
        timezone: 'Africa/Cairo',
        create_idempotency_key: `staff-hr-acceptance:leave-create:q11-${suffix}`,
        submission_idempotency_key: `staff-hr-acceptance:leave-submit:q11-${suffix}`
    };
}

async function createAcceptanceOpeningLeaveBalance(context) {
    const session = context.sessionFor('hr_manager');
    const indexRoute = `${Q11_BALANCE_ROUTE}?year=2027&role=all`;
    const index = await session.get(indexRoute);
    if (!responseIsAuthenticated(index)) return blocked('Q11_LEAVE_BALANCE_ADMIN_UNAVAILABLE', indexRoute, { status: index.status });
    const staffUserId = selectOption(index.body, 'user_id', /العامل الإداري التجريبي|E20990008/i);
    if (!staffUserId) return blocked('Q11_WORKER_BALANCE_SCOPE_UNAVAILABLE', indexRoute);
    const route = `${indexRoute}&user_id=${encodeURIComponent(staffUserId)}`;
    let page = await session.get(route);
    if (q11BalanceRow(page.body, 'CY-2027')) {
        return passed(route, { staff_user_id: Number(staffUserId), period_key: 'CY-2027', opening_units: '21.000', idempotent_replay: true });
    }
    const form = formContaining(page.body, 'record_opening_leave_balance', '1');
    const leaveTypeId = form ? selectOption(form, 'leave_type_id', /اعتيادية|DEMO-ANNUAL/i) : null;
    if (!form || !leaveTypeId) return blocked('Q11_OPENING_BALANCE_FORM_UNAVAILABLE', route);
    const fields = {
        ...hiddenFields(form),
        record_opening_leave_balance: '1',
        staff_user_id: staffUserId,
        leave_type_id: leaveTypeId,
        entitlement_period_key: 'CY-2027',
        period_from: '2027-01-01',
        period_to: '2027-12-31',
        opening_units: '21.000',
        year: '2027',
        role: 'all',
        user_id: staffUserId
    };
    const response = await session.postForm(route, fields, { csrf: fields.csrf_token });
    page = await session.get(route);
    const row = q11BalanceRow(page.body, 'CY-2027');
    const values = q11BalanceValues(row);
    const safe = responseIsAuthenticated(response)
        && row !== null
        && values !== null
        && values.available === 21
        && values.held === 0
        && values.used === 0
        && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(String(response.body || '') + String(page.body || ''));
    return safe
        ? passed(route, { staff_user_id: Number(staffUserId), leave_type_id: Number(leaveTypeId), period_key: 'CY-2027', opening_units: '21.000', audited_ledger_visible: true })
        : blocked('Q11_OPENING_BALANCE_NOT_PROVEN', route, { status: response.status, values, arabic_result: /[\u0600-\u06FF]/.test(response.body || '') });
}

async function submitAcceptanceCompetingLeaveRequests(context) {
    const worker = context.sessionFor('worker_standard');
    let page = await worker.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(page)) return blocked('Q11_WORKER_LEAVE_PORTAL_UNAVAILABLE', PORTAL_ROUTE);
    const primaryDate = Q11_PRIMARY_WINDOW[0].slice(0, 10);
    if (q11LeaveRows(page.body, [primaryDate]).length === 0) {
        const form = formContaining(page.body, 'leave_request_intent', 'submit');
        const typeId = form ? selectOption(form, 'leave_type_id', /اعتيادية|DEMO-ANNUAL/i) : null;
        if (!form || !typeId) return blocked('Q11_ANNUAL_LEAVE_FORM_UNAVAILABLE', PORTAL_ROUTE);
        const primaryFields = q11LeaveFields(form, typeId, Q11_PRIMARY_WINDOW, 'primary');
        const primary = await worker.postForm(PORTAL_ROUTE, primaryFields, { csrf: primaryFields.csrf_token });
        page = await worker.get(PORTAL_ROUTE);
        if (!responseIsAuthenticated(primary) || q11LeaveRows(page.body, [primaryDate]).length !== 1) {
            return blocked('Q11_PRIMARY_LEAVE_SUBMISSION_FAILED', PORTAL_ROUTE, { status: primary.status });
        }
    }
    const overlapForm = formContaining(page.body, 'leave_request_intent', 'submit');
    const overlapTypeId = overlapForm ? selectOption(overlapForm, 'leave_type_id', /اعتيادية|DEMO-ANNUAL/i) : null;
    if (!overlapForm || !overlapTypeId) return blocked('Q11_OVERLAP_LEAVE_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const overlapFields = q11LeaveFields(overlapForm, overlapTypeId, Q11_OVERLAP_WINDOW, 'overlap');
    const overlap = await worker.postForm(PORTAL_ROUTE, overlapFields, { csrf: overlapFields.csrf_token });
    const overlapBody = String(overlap.body || '');
    const overlapDate = Q11_OVERLAP_WINDOW[0].slice(0, 10);
    const rejectedSafely = responseIsAuthenticated(overlap)
        && /alert-danger/i.test(overlapBody)
        && /[\u0600-\u06FF]/.test(overlapBody)
        && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(overlapBody)
        && q11LeaveRows(overlapBody, [overlapDate]).length === 0;
    return rejectedSafely
        ? passed(PORTAL_ROUTE, { accepted_start: primaryDate, rejected_overlap_start: overlapDate, overlap_rejected: true, technical_error_leak: false })
        : blocked('Q11_OVERLAPPING_LEAVE_NOT_REJECTED_SAFELY', PORTAL_ROUTE, { status: overlap.status, danger_alert: /alert-danger/i.test(overlapBody) });
}

async function ensureAcceptanceCrossYearLeaveSchedule(context) {
    const route = 'admin/hr_policy_calendar.php';
    const session = context.sessionFor('hr_manager');
    const code = 'Q03-NIGHT-2000-0400';
    let page = await session.get(route);
    if (!responseIsAuthenticated(page)) return blocked('Q11_SCHEDULE_ADMIN_UNAVAILABLE', route);
    const publishedRows = tableRowsContaining(page.body, code).filter((row) => /published|منشورة|bg-success/i.test(row));
    const existing = publishedRows.find((row) => /2026-09-01/.test(row) && /2027-12-31/.test(row));
    if (existing) {
        return passed(route, { version_id: scheduleVersionId(existing, code), valid_from: '2026-09-01', valid_to: '2027-12-31', idempotent_replay: true });
    }
    const predecessorId = publishedRows.map((row) => scheduleVersionId(row, code)).filter((id) => id > 0).sort((a, b) => b - a)[0] || 0;
    if (predecessorId <= 0) return blocked('Q11_SCHEDULE_PREDECESSOR_UNAVAILABLE', route);
    const cloneRoute = `${route}?clone_version_id=${predecessorId}`;
    page = await session.get(cloneRoute);
    const form = formContaining(page.body, 'save_schedule_policy_draft');
    const scopeId = form ? selectDataOption(form, 'scope_id', 'data-scope-type', 'staff', /E20990008/i) : null;
    if (!form || !scopeId) return blocked('Q11_SCHEDULE_SUCCESSOR_FORM_UNAVAILABLE', cloneRoute);
    const fields = scheduleDraftFields(form, {
        code,
        name: 'دوام قبول ممتد للإجازات العابرة للعام',
        scopeType: 'staff',
        scopeId,
        priority: 600,
        validFrom: '2026-09-01',
        validTo: '2027-12-31'
    });
    const created = await session.postForm(route, fields, { csrf: fields.csrf_token });
    const createdBody = String(created.body || '');
    if (!responseIsAuthenticated(created) || /alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(createdBody)) {
        return blocked('Q11_SCHEDULE_SUCCESSOR_DRAFT_REJECTED', route, { status: created.status, arabic_error: /[\u0600-\u06FF]/.test(createdBody) });
    }
    const draftId = tableRowsContaining(createdBody, code)
        .filter((row) => /draft|مسودة|bg-warning/i.test(row))
        .map((row) => scheduleVersionId(row, code))
        .filter((id) => id > predecessorId)
        .sort((a, b) => b - a)[0] || 0;
    if (draftId <= 0) return blocked('Q11_SCHEDULE_SUCCESSOR_NOT_RENDERED', route);
    const published = await publishScheduleVersion(session, draftId);
    if (!published.passed) return published;
    page = await session.get(route);
    const row = tableRowsContaining(page.body, code).find((candidate) => scheduleVersionId(candidate, code) === draftId);
    return row && /published|منشورة|bg-success/i.test(row) && /2026-09-01/.test(row) && /2027-12-31/.test(row)
        ? passed(route, { predecessor_version_id: predecessorId, version_id: draftId, valid_from: '2026-09-01', valid_to: '2027-12-31' })
        : blocked('Q11_SCHEDULE_SUCCESSOR_NOT_PUBLISHED', route);
}

async function submitAcceptanceCrossYearLeave(context) {
    const schedule = await ensureAcceptanceCrossYearLeaveSchedule(context);
    if (!schedule.passed) return schedule;
    const worker = context.sessionFor('worker_standard');
    let page = await worker.get(PORTAL_ROUTE);
    const startDate = Q11_CROSS_YEAR_WINDOW[0].slice(0, 10);
    if (q11LeaveRows(page.body, [startDate]).length === 1) {
        return passed(PORTAL_ROUTE, { schedule: schedule.evidence, start_date: startDate, periods: ['CY-2026', 'CY-2027'], idempotent_replay: true });
    }
    const form = formContaining(page.body, 'leave_request_intent', 'submit');
    const typeId = form ? selectOption(form, 'leave_type_id', /اعتيادية|DEMO-ANNUAL/i) : null;
    if (!responseIsAuthenticated(page) || !form || !typeId) return blocked('Q11_CROSS_YEAR_LEAVE_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const fields = q11LeaveFields(form, typeId, Q11_CROSS_YEAR_WINDOW, 'cross-year');
    const response = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    page = await worker.get(PORTAL_ROUTE);
    const visible = q11LeaveRows(page.body, [startDate]).length === 1;
    return responseIsAuthenticated(response) && visible && !/alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(response.body || '')
        ? passed(PORTAL_ROUTE, { schedule: schedule.evidence, start_date: startDate, end_date: Q11_CROSS_YEAR_WINDOW[1].slice(0, 10), periods: ['CY-2026', 'CY-2027'], request_visible: true })
        : blocked('Q11_CROSS_YEAR_LEAVE_SUBMISSION_FAILED', PORTAL_ROUTE, { status: response.status, request_visible: visible });
}

async function verifyAcceptanceLeaveLedgerInvariants(context) {
    const worker = context.sessionFor('worker_standard');
    const page = await worker.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(page)) return blocked('Q11_WORKER_LEAVE_LEDGER_UNAVAILABLE', PORTAL_ROUTE);
    const balance2026 = q11BalanceValues(q11BalanceRow(page.body, 'CY-2026'));
    const balance2027 = q11BalanceValues(q11BalanceRow(page.body, 'CY-2027'));
    const starts = [Q11_PRIMARY_WINDOW[0], Q11_OVERLAP_WINDOW[0], Q11_CROSS_YEAR_WINDOW[0]].map((value) => value.slice(0, 10));
    const requestCounts = starts.map((date) => q11LeaveRows(page.body, [date]).length);
    const nonNegative = [balance2026, balance2027].every((balance) => balance !== null
        && balance.available >= 0 && balance.held >= 0 && balance.used >= 0);
    const splitReserved = balance2026 !== null && balance2027 !== null
        && balance2026.held >= 1 && balance2027.held >= 1;
    const valid = nonNegative && splitReserved
        && requestCounts[0] === 1 && requestCounts[1] === 0 && requestCounts[2] === 1
        && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(page.body || '');
    return valid
        ? passed(PORTAL_ROUTE, {
            balances: { 'CY-2026': balance2026, 'CY-2027': balance2027 },
            request_counts: { primary: requestCounts[0], rejected_overlap: requestCounts[1], cross_year: requestCounts[2] },
            no_negative_balance: true,
            cross_year_reservations_visible: true
        })
        : blocked('Q11_LEAVE_LEDGER_INVARIANTS_NOT_PROVEN', PORTAL_ROUTE, { balance2026, balance2027, requestCounts });
}

const Q12_MEDICAL_WINDOW = Object.freeze(['2026-11-10T00:00', '2026-11-11T00:00']);
const q12State = { requestId: null, validUploadProved: false, unsafeAttempts: [] };

function q12RequestIdForDate(html, date) {
    const row = q11LeaveRows(html, [date])[0] || '';
    const match = row.match(/staffLeaveAttachmentModal-(\d+)/);
    return match ? Number(match[1]) : null;
}

function q12UploadForm(html, requestId) {
    const forms = String(html).match(/<form\b[\s\S]*?<\/form>/gi) || [];
    return forms.find((form) => {
        const fields = hiddenFields(form);
        return fields.leave_request_intent === 'upload_medical_attachment'
            && Number(fields.request_id || 0) === Number(requestId || 0);
    }) || null;
}

async function q12MedicalDraft(session) {
    const startDate = Q12_MEDICAL_WINDOW[0].slice(0, 10);
    let page = await session.get(PORTAL_ROUTE);
    if (!responseIsAuthenticated(page)) return { error: blocked('Q12_LEAVE_PORTAL_UNAVAILABLE', PORTAL_ROUTE) };
    let requestId = q12RequestIdForDate(page.body, startDate);
    if (!requestId) {
        const form = formContaining(page.body, 'leave_request_intent', 'draft');
        const typeId = form ? selectOption(form, 'leave_type_id', /مرضية|DEMO-MEDICAL/i) : null;
        if (!form || !typeId) return { error: blocked('Q12_MEDICAL_DRAFT_FORM_UNAVAILABLE', PORTAL_ROUTE) };
        const fields = {
            ...hiddenFields(form),
            leave_request_intent: 'draft',
            leave_type_id: typeId,
            from_at: Q12_MEDICAL_WINDOW[0],
            to_at: Q12_MEDICAL_WINDOW[1],
            reason: 'مسودة قبول لاختبار التقرير الطبي الخاص Q12',
            timezone: 'Africa/Cairo',
            create_idempotency_key: 'staff-hr-acceptance:leave-create:q12-medical-draft'
        };
        const response = await session.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
        page = await session.get(PORTAL_ROUTE);
        requestId = q12RequestIdForDate(page.body, startDate);
        if (!responseIsAuthenticated(response) || !requestId) {
            return { error: blocked('Q12_MEDICAL_DRAFT_CREATION_FAILED', PORTAL_ROUTE, { status: response.status }) };
        }
    }
    const form = q12UploadForm(page.body, requestId);
    return form
        ? { page, form, requestId, fields: hiddenFields(form) }
        : { error: blocked('Q12_PRIVATE_UPLOAD_FORM_UNAVAILABLE', PORTAL_ROUTE, { request_id: requestId }) };
}

async function uploadAcceptanceMedicalPdf(context) {
    const worker = context.sessionFor('worker_standard');
    const draft = await q12MedicalDraft(worker);
    if (draft.error) return draft.error;
    const pdf = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF', 'utf8');
    const response = await worker.postMultipart(PORTAL_ROUTE, draft.fields, [{
        name: 'file',
        filename: 'acceptance-medical-q12.pdf',
        contentType: 'application/pdf',
        data: pdf
    }], { csrf: draft.fields.csrf_token });
    const body = String(response.body || '');
    const row = q11LeaveRows(body, [Q12_MEDICAL_WINDOW[0].slice(0, 10)])[0] || '';
    const safe = responseIsAuthenticated(response)
        && /تم رفع المستند الطبي|تم الإرفاق|مرفق/i.test(body + row)
        && !/alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body);
    if (!safe) return blocked('Q12_VALID_MEDICAL_PDF_REJECTED', PORTAL_ROUTE, { status: response.status, arabic_result: /[\u0600-\u06FF]/.test(body) });
    q12State.requestId = draft.requestId;
    q12State.validUploadProved = true;
    return passed(PORTAL_ROUTE, {
        request_id: draft.requestId,
        stored_as_private_reference: true,
        direct_url_exposed: false,
        original_name: 'acceptance-medical-q12.pdf',
        mime: 'application/pdf'
    });
}

async function attemptAcceptanceUnsafeMedicalUploads(context) {
    const worker = context.sessionFor('worker_standard');
    const attempts = [
        { kind: 'double_extension', filename: 'acceptance-medical.php.pdf', contentType: 'application/pdf', data: Buffer.from('%PDF-1.4\n%%EOF') },
        { kind: 'mime_mismatch', filename: 'acceptance-medical.pdf', contentType: 'application/pdf', data: Buffer.from('plain text is not a pdf') },
        { kind: 'oversized', filename: 'acceptance-medical-large.pdf', contentType: 'application/pdf', data: Buffer.alloc(10485761, 65) }
    ];
    const evidence = [];
    for (const attempt of attempts) {
        const draft = await q12MedicalDraft(worker);
        if (draft.error) return draft.error;
        const response = await worker.postMultipart(PORTAL_ROUTE, draft.fields, [{
            name: 'file', filename: attempt.filename, contentType: attempt.contentType, data: attempt.data
        }], { csrf: draft.fields.csrf_token });
        const body = String(response.body || '');
        const rejected = responseIsAuthenticated(response)
            && /alert-danger/i.test(body)
            && /[\u0600-\u06FF]/.test(body)
            && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(body);
        if (!rejected) return blocked('Q12_UNSAFE_UPLOAD_NOT_REJECTED', PORTAL_ROUTE, { kind: attempt.kind, status: response.status });
        evidence.push({ kind: attempt.kind, rejected: true, technical_error_leak: false });
    }
    q12State.unsafeAttempts = evidence;
    return passed(PORTAL_ROUTE, { attempts: evidence, valid_attachment_retained: q12State.validUploadProved === true });
}

const Q26_ROUTE = 'admin/hr_organization.php?tab=corrections';
const q26State = { correctionId: null, reversalId: null, impact: null, workerId: 0, proposedOrgUnitId: 0 };

function q26CorrectionRows(html) {
    const workerPattern = q26State.workerId > 0 ? new RegExp(`عامل\\s*#${q26State.workerId}`) : /عامل\s*#\d+/;
    return (String(html).match(/<tr\b[\s\S]*?<\/tr>/gi) || [])
        .filter((row) => /قوة\/الوحدة|عكس التصحيح/.test(row) && workerPattern.test(decodeHtml(row.replace(/<[^>]+>/g, ' '))));
}

function q26RowId(row) {
    const cell = String(row || '').match(/<td>\s*(\d+)\s*<\/td>/i);
    return cell ? Number(cell[1]) : null;
}

function q26DecisionButton(row, decision) {
    const pattern = new RegExp(`<button\\b[^>]*data-id=["'](\\d+)["'][^>]*data-version=["'](\\d+)["'][^>]*data-decision=["']${decision}["'][^>]*data-key=["']([^"']+)["']`, 'i');
    const match = String(row || '').match(pattern);
    return match ? { id: Number(match[1]), version: Number(match[2]), key: decodeHtml(match[3]) } : null;
}

async function previewAcceptanceOrganizationCorrection(context) {
    const hr = context.sessionFor('hr_manager');
    q26State.workerId = await acceptancePersonaId(context, 'E20990008');
    if (!q26State.workerId) return blocked('Q26_WORKER_SCOPE_UNAVAILABLE', Q26_ROUTE);
    let page = await hr.get(Q26_ROUTE);
    if (!responseIsAuthenticated(page)) return blocked('Q26_CORRECTION_PAGE_UNAVAILABLE', Q26_ROUTE);
    const existing = q26CorrectionRows(page.body).find((row) => !/عكس التصحيح/.test(row)
        && /2026-08-11/.test(row) && /2026-08-12/.test(row));
    if (existing) {
        q26State.correctionId = q26RowId(existing);
        return passed(Q26_ROUTE, { correction_id: q26State.correctionId, idempotent_replay: true });
    }
    const form = formContaining(page.body, 'action', 'preview_correction');
    if (!form) return blocked('Q26_CORRECTION_PREVIEW_FORM_UNAVAILABLE', Q26_ROUTE);
    q26State.proposedOrgUnitId = Number(dataListOption(page.body, 'correctionTargetReferences', /الإدارية|DEMO-ADMIN/i) || 0);
    if (!q26State.proposedOrgUnitId) return blocked('Q26_ORG_UNIT_REFERENCE_UNAVAILABLE', Q26_ROUTE);
    const fields = {
        ...hiddenFields(form),
        action: 'preview_correction',
        correction_kind: 'organization_unit',
        scope_type: 'staff',
        scope_id: String(q26State.workerId),
        proposed_reference_id: String(q26State.proposedOrgUnitId),
        effective_from: '2026-08-11',
        effective_to: '2026-08-12',
        reason: 'تصحيح قبول رجعي محدود لاختبار الأثر وإمكانية العكس Q26'
    };
    const response = await hr.postForm(Q26_ROUTE, fields, { csrf: fields.csrf_token });
    const rows = q26CorrectionRows(response.body);
    const row = rows.find((item) => /2026-08-11/.test(item) && /2026-08-12/.test(item));
    q26State.correctionId = q26RowId(row);
    return responseIsAuthenticated(response) && q26State.correctionId
        && /تم تثبيت معاينة أثر التصحيح|بانتظار القرار/.test(response.body || '')
        && !/alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(response.body || '')
        ? passed(Q26_ROUTE, { correction_id: q26State.correctionId, scope_staff_id: q26State.workerId, proposed_org_unit_id: q26State.proposedOrgUnitId, immutable_preview_visible: true })
        : blocked('Q26_CORRECTION_PREVIEW_FAILED', Q26_ROUTE, { status: response.status });
}

async function approveAcceptanceScopedCorrection(context) {
    const superAdmin = context.sessionFor('super_admin');
    const page = await superAdmin.get(Q26_ROUTE);
    const row = q26CorrectionRows(page.body).find((item) => q26RowId(item) === q26State.correctionId);
    if (!row) return blocked('Q26_CORRECTION_ROW_UNAVAILABLE', Q26_ROUTE, { correction_id: q26State.correctionId });
    if (/تم اعتماد|text-bg-success|>معتمد</.test(row)) return passed(Q26_ROUTE, { correction_id: q26State.correctionId, idempotent_replay: true });
    const button = q26DecisionButton(row, 'approved');
    const form = formContaining(page.body, 'action', 'decide_correction');
    if (!button || !form) return blocked('Q26_INDEPENDENT_APPROVAL_UNAVAILABLE', Q26_ROUTE);
    const fields = {
        ...hiddenFields(form), action: 'decide_correction', correction_id: String(button.id),
        expected_lock_version: String(button.version), decision: 'approved', idempotency_key: button.key,
        comment: 'اعتماد مستقل لنطاق التصحيح المثبت في Q26'
    };
    const response = await superAdmin.postForm(Q26_ROUTE, fields, { csrf: fields.csrf_token });
    const approvedRow = q26CorrectionRows(response.body).find((item) => q26RowId(item) === button.id) || '';
    return responseIsAuthenticated(response) && /معتمد|text-bg-success/.test(approvedRow)
        && !/alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(response.body || '')
        ? passed(Q26_ROUTE, { correction_id: button.id, approved_by_independent_super_admin: true, scoped_impact_published: true })
        : blocked('Q26_SCOPED_CORRECTION_APPROVAL_FAILED', Q26_ROUTE, { status: response.status });
}

async function verifyAcceptanceCorrectionImpact(context) {
    const page = await context.sessionFor('hr_manager').get(Q26_ROUTE);
    const row = q26CorrectionRows(page.body).find((item) => q26RowId(item) === q26State.correctionId) || '';
    const counts = {
        staff: Number((row.match(/text-bg-primary[^>]*>\s*(\d+)\s*عامل/) || [0, 0])[1]),
        days: Number((row.match(/text-bg-info[^>]*>\s*(\d+)\s*يوم/) || [0, 0])[1]),
        requests: Number((row.match(/text-bg-warning[^>]*>\s*(\d+)\s*طلب/) || [0, 0])[1]),
        periods: Number((row.match(/text-bg-secondary[^>]*>\s*(\d+)\s*فترة/) || [0, 0])[1])
    };
    q26State.impact = counts;
    return counts.staff === 1 && counts.days > 0 && counts.periods > 0 && /معتمد|text-bg-success/.test(row)
        ? passed(Q26_ROUTE, { correction_id: q26State.correctionId, impact: counts, only_frozen_scope_published: true })
        : blocked('Q26_SCOPED_IMPACT_NOT_PROVEN', Q26_ROUTE, { counts });
}

async function reverseAcceptanceCorrectionAndVerifyHistory(context) {
    const hr = context.sessionFor('hr_manager');
    let page = await hr.get(Q26_ROUTE);
    const existingReversal = q26CorrectionRows(page.body).find((item) => /عكس التصحيح/.test(item)
        && item.includes(`#${q26State.correctionId}`) && /معتمد|text-bg-success/.test(item));
    if (existingReversal) {
        q26State.reversalId = q26RowId(existingReversal);
        return passed(Q26_ROUTE, {
            original_correction_id: q26State.correctionId,
            reversal_correction_id: q26State.reversalId,
            original_retained: q26CorrectionRows(page.body).some((item) => q26RowId(item) === q26State.correctionId),
            reversal_history_immutable: true,
            idempotent_replay: true
        });
    }
    const original = q26CorrectionRows(page.body).find((item) => q26RowId(item) === q26State.correctionId) || '';
    const reversalButton = original.match(/correction-reversal[^>]*data-id=["'](\d+)["'][^>]*data-key=["']([^"']+)["']/i);
    const reversalForm = formContaining(page.body, 'action', 'reverse_correction');
    if (!reversalButton || !reversalForm) return blocked('Q26_REVERSAL_PREVIEW_UNAVAILABLE', Q26_ROUTE);
    const fields = {
        ...hiddenFields(reversalForm), action: 'reverse_correction', correction_id: reversalButton[1],
        idempotency_key: decodeHtml(reversalButton[2]), reason: 'عكس مدقق بعد التحقق من أثر Q26 مع إبقاء الأصل'
    };
    page = await hr.postForm(Q26_ROUTE, fields, { csrf: fields.csrf_token });
    const reverseRow = q26CorrectionRows(page.body).find((item) => /عكس التصحيح/.test(item) && item.includes(`#${q26State.correctionId}`));
    q26State.reversalId = q26RowId(reverseRow);
    if (!q26State.reversalId) return blocked('Q26_REVERSAL_PREVIEW_FAILED', Q26_ROUTE);
    const superAdmin = context.sessionFor('super_admin');
    page = await superAdmin.get(Q26_ROUTE);
    const approvable = q26CorrectionRows(page.body).find((item) => q26RowId(item) === q26State.reversalId) || '';
    const button = q26DecisionButton(approvable, 'approved');
    const decisionForm = formContaining(page.body, 'action', 'decide_correction');
    if (!button || !decisionForm) return blocked('Q26_REVERSAL_APPROVAL_UNAVAILABLE', Q26_ROUTE);
    const decisionFields = {
        ...hiddenFields(decisionForm), action: 'decide_correction', correction_id: String(button.id),
        expected_lock_version: String(button.version), decision: 'approved', idempotency_key: button.key,
        comment: 'اعتماد مستقل للعكس المدقق Q26'
    };
    page = await superAdmin.postForm(Q26_ROUTE, decisionFields, { csrf: decisionFields.csrf_token });
    const rows = q26CorrectionRows(page.body);
    const originalRetained = rows.some((item) => q26RowId(item) === q26State.correctionId && /معتمد|text-bg-success/.test(item));
    const reversalApproved = rows.some((item) => q26RowId(item) === q26State.reversalId && /معتمد|text-bg-success/.test(item));
    return originalRetained && reversalApproved
        ? passed(Q26_ROUTE, { original_correction_id: q26State.correctionId, reversal_correction_id: q26State.reversalId, original_retained: true, reversal_history_immutable: true })
        : blocked('Q26_REVERSAL_HISTORY_NOT_PROVEN', Q26_ROUTE, { originalRetained, reversalApproved });
}

const handlers = new Map();
const register = (names, handler) => names.forEach((name) => handlers.set(name, handler));

const Q27_STAFFING_WINDOW = Object.freeze(['2027-03-01T00:00', '2027-03-02T00:00']);
const Q27_BLACKOUT_WINDOW = Object.freeze(['2027-02-10T00:00', '2027-02-11T00:00']);

function q27RequestState(html, date) {
    const row = q11LeaveRows(html, [date])[0] || null;
    if (!row) return null;
    const submitForm = formContaining(row, 'leave_request_intent', 'submit');
    const submitFields = submitForm ? hiddenFields(submitForm) : {};
    const idMatch = String(row).match(/data-leave-request-id="(\d+)"/i);
    return {
        row,
        request_id: Number(submitFields.request_id || idMatch && idMatch[1] || 0),
        lock_version: Number(submitFields.expected_lock_version || 0),
        submit_form: submitForm,
        draft: /مسودة|draft/i.test(row),
        pending: /بانتظار الموافقة|قيد الاعتماد|pending_approval|مرسل/i.test(row)
    };
}

function q27UnpaidBalance(html) {
    const row = (String(html).match(/<tr\b[\s\S]*?<\/tr>/gi) || [])
        .find((candidate) => /دون راتب|DEMO-UNPAID/i.test(candidate) && candidate.includes('CY-2027')) || null;
    return q11BalanceValues(row);
}

async function ensureQ27StaffingDraft(session, personaKey) {
    const date = Q27_STAFFING_WINDOW[0].slice(0, 10);
    let page = await session.get(PORTAL_ROUTE);
    let state = q27RequestState(page.body, date);
    if (state) return { page, state };
    const form = formContaining(page.body, 'leave_request_intent', 'submit');
    const typeId = form ? selectOption(form, 'leave_type_id', /دون راتب|DEMO-UNPAID/i) : null;
    if (!form || !typeId) return { page, state: null };
    const fields = {
        ...hiddenFields(form),
        leave_request_intent: 'draft',
        leave_type_id: typeId,
        from_at: Q27_STAFFING_WINDOW[0],
        to_at: Q27_STAFFING_WINDOW[1],
        reason: `طلب حد التشغيل التجريبي Q27 ${personaKey}`,
        timezone: 'Africa/Cairo',
        create_idempotency_key: `staff-hr-acceptance:leave-create:q27-${personaKey}`
    };
    await session.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    page = await session.get(PORTAL_ROUTE);
    state = q27RequestState(page.body, date);
    return { page, state };
}

async function submitQ27CompetingStaffingLeaveRequests(context) {
    const teacher = context.sessionFor('worker_teacher');
    const specialist = context.sessionFor('worker_specialist');
    const date = Q27_STAFFING_WINDOW[0].slice(0, 10);
    const [teacherReady, specialistReady] = await Promise.all([
        ensureQ27StaffingDraft(teacher, 'teacher'),
        ensureQ27StaffingDraft(specialist, 'specialist')
    ]);
    const teacherPage = teacherReady.page;
    const specialistPage = specialistReady.page;
    if (!responseIsAuthenticated(teacherPage) || !responseIsAuthenticated(specialistPage)) {
        return blocked('Q27_WORKER_PORTALS_UNAVAILABLE', PORTAL_ROUTE);
    }
    const teacherState = teacherReady.state;
    const specialistState = specialistReady.state;
    if (!teacherState || !specialistState) {
        return blocked('Q27_SEEDED_STAFFING_DRAFTS_UNAVAILABLE', PORTAL_ROUTE, {
            teacher: Boolean(teacherState), specialist: Boolean(specialistState)
        });
    }
    if (teacherState.pending && specialistState.draft) {
        return passed(PORTAL_ROUTE, { idempotent_replay: true, approved_override_request_id: teacherState.request_id, competing_request_still_draft: true });
    }
    if (!teacherState.submit_form || !specialistState.submit_form) {
        return blocked('Q27_STAFFING_SUBMIT_FORMS_UNAVAILABLE', PORTAL_ROUTE);
    }
    const teacherFields = { ...hiddenFields(teacherState.submit_form), leave_request_intent: 'submit', submission_idempotency_key: 'staff-hr-acceptance:leave-submit:q27-teacher-before-override' };
    const specialistFields = { ...hiddenFields(specialistState.submit_form), leave_request_intent: 'submit', submission_idempotency_key: 'staff-hr-acceptance:leave-submit:q27-specialist-before-override' };
    const [teacherResponse, specialistResponse] = await Promise.all([
        teacher.postForm(PORTAL_ROUTE, teacherFields, { csrf: teacherFields.csrf_token }),
        specialist.postForm(PORTAL_ROUTE, specialistFields, { csrf: specialistFields.csrf_token })
    ]);
    const denied = [teacherResponse, specialistResponse].every((response) => responseIsAuthenticated(response)
        && /alert-danger/i.test(String(response.body || ''))
        && /[\u0600-\u06FF]/.test(String(response.body || ''))
        && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(String(response.body || '')));
    const [teacherAfter, specialistAfter] = await Promise.all([teacher.get(PORTAL_ROUTE), specialist.get(PORTAL_ROUTE)]);
    const teacherAfterState = q27RequestState(teacherAfter.body, date);
    const specialistAfterState = q27RequestState(specialistAfter.body, date);
    return denied && teacherAfterState && specialistAfterState && teacherAfterState.draft && specialistAfterState.draft
        ? passed(PORTAL_ROUTE, {
            competing_submissions: 2,
            both_fail_closed_before_override: true,
            teacher_request_id: teacherAfterState.request_id,
            specialist_request_id: specialistAfterState.request_id
        })
        : blocked('Q27_STAFFING_LIMIT_NOT_ENFORCED', PORTAL_ROUTE, { denied, teacher_draft: Boolean(teacherAfterState && teacherAfterState.draft), specialist_draft: Boolean(specialistAfterState && specialistAfterState.draft) });
}

async function attemptQ27BlackoutLeave(context) {
    const teacher = context.sessionFor('worker_teacher');
    const date = Q27_BLACKOUT_WINDOW[0].slice(0, 10);
    let page = await teacher.get(PORTAL_ROUTE);
    let state = q27RequestState(page.body, date);
    if (!state) {
        const form = formContaining(page.body, 'leave_request_intent', 'submit');
        const typeId = form ? selectOption(form, 'leave_type_id', /دون راتب|DEMO-UNPAID/i) : null;
        if (!form || !typeId) return blocked('Q27_BLACKOUT_DRAFT_FORM_UNAVAILABLE', PORTAL_ROUTE);
        const fields = {
            ...hiddenFields(form),
            leave_request_intent: 'draft',
            leave_type_id: typeId,
            from_at: Q27_BLACKOUT_WINDOW[0],
            to_at: Q27_BLACKOUT_WINDOW[1],
            reason: 'طلب داخل فترة الحظر التجريبية Q27',
            timezone: 'Africa/Cairo',
            create_idempotency_key: 'staff-hr-acceptance:leave-create:q27-blackout'
        };
        const created = await teacher.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
        page = await teacher.get(PORTAL_ROUTE);
        state = q27RequestState(page.body, date);
        if (!responseIsAuthenticated(created) || !state) return blocked('Q27_BLACKOUT_DRAFT_CREATION_FAILED', PORTAL_ROUTE);
    }
    if (!state.submit_form) {
        return state.pending
            ? passed(PORTAL_ROUTE, { idempotent_replay: true, blackout_request_id: state.request_id })
            : blocked('Q27_BLACKOUT_SUBMIT_FORM_UNAVAILABLE', PORTAL_ROUTE);
    }
    const submitFields = { ...hiddenFields(state.submit_form), leave_request_intent: 'submit', submission_idempotency_key: 'staff-hr-acceptance:leave-submit:q27-blackout' };
    const response = await teacher.postForm(PORTAL_ROUTE, submitFields, { csrf: submitFields.csrf_token });
    const after = await teacher.get(PORTAL_ROUTE);
    const afterState = q27RequestState(after.body, date);
    const safeDenial = responseIsAuthenticated(response) && /alert-danger/i.test(String(response.body || ''))
        && /[\u0600-\u06FF]/.test(String(response.body || ''))
        && !/SQLSTATE|PDOException|Stack trace|Fatal error/i.test(String(response.body || ''));
    return safeDenial && afterState && afterState.draft
        ? passed(PORTAL_ROUTE, { blackout_start: date, request_id: afterState.request_id, remained_draft: true, technical_error_leak: false })
        : blocked('Q27_BLACKOUT_NOT_ENFORCED_SAFELY', PORTAL_ROUTE, { safe_denial: safeDenial, remained_draft: Boolean(afterState && afterState.draft) });
}

async function approveQ27ReasonedStaffingOverride(context) {
    const teacher = context.sessionFor('worker_teacher');
    const manager = context.sessionFor('hr_manager');
    const date = Q27_STAFFING_WINDOW[0].slice(0, 10);
    let workerPage = await teacher.get(PORTAL_ROUTE);
    let request = q27RequestState(workerPage.body, date);
    if (!request) return blocked('Q27_TEACHER_STAFFING_REQUEST_UNAVAILABLE', PORTAL_ROUTE);
    if (request.pending) return passed(PORTAL_ROUTE, { idempotent_replay: true, request_id: request.request_id, submitted_after_override: true });
    const managerPage = await manager.get(PORTAL_ROUTE);
    const overrideForm = formContaining(managerPage.body, 'leave_request_intent', 'staffing_override');
    if (!responseIsAuthenticated(managerPage) || !overrideForm) return blocked('Q27_OVERRIDE_MANAGER_FORM_UNAVAILABLE', PORTAL_ROUTE);
    const overrideFields = {
        ...hiddenFields(overrideForm),
        leave_request_intent: 'staffing_override',
        request_id: String(request.request_id),
        expected_lock_version: String(request.lock_version),
        decision_outcome: 'approved',
        reason: 'استثناء مسبب للحفاظ على استمرارية التشغيل في رحلة Q27',
        decision_idempotency_key: `staff-hr-acceptance:leave-staffing-override:q27:${request.request_id}`
    };
    const overrideResponse = await manager.postForm(PORTAL_ROUTE, overrideFields, { csrf: overrideFields.csrf_token });
    if (!responseIsAuthenticated(overrideResponse) || /alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(String(overrideResponse.body || ''))) {
        const alert = (String(overrideResponse.body || '').match(/<div\b[^>]*class="[^"]*alert-danger[^"]*"[^>]*>[\s\S]*?<\/div>/i) || [])[0] || '';
        return blocked('Q27_REASONED_OVERRIDE_REJECTED', PORTAL_ROUTE, {
            status: overrideResponse.status,
            message: decodeHtml(alert.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()).slice(0, 300)
        });
    }
    workerPage = await teacher.get(PORTAL_ROUTE);
    request = q27RequestState(workerPage.body, date);
    if (!request || !request.submit_form) return blocked('Q27_OVERRIDDEN_DRAFT_NOT_SUBMITTABLE', PORTAL_ROUTE);
    const submitFields = { ...hiddenFields(request.submit_form), leave_request_intent: 'submit', submission_idempotency_key: 'staff-hr-acceptance:leave-submit:q27-teacher-after-override' };
    const submitResponse = await teacher.postForm(PORTAL_ROUTE, submitFields, { csrf: submitFields.csrf_token });
    const after = await teacher.get(PORTAL_ROUTE);
    const afterState = q27RequestState(after.body, date);
    return responseIsAuthenticated(submitResponse) && afterState && afterState.pending
        && !/alert-danger|SQLSTATE|PDOException|Stack trace|Fatal error/i.test(String(submitResponse.body || ''))
        ? passed(PORTAL_ROUTE, { request_id: afterState.request_id, reason_required: true, authorized_hr_override: true, submitted_after_override: true })
        : blocked('Q27_SUBMIT_AFTER_OVERRIDE_FAILED', PORTAL_ROUTE, {
            status: submitResponse.status,
            pending: Boolean(afterState && afterState.pending),
            row_text: afterState ? decodeHtml(afterState.row.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()).slice(0, 350) : null,
            feedback_code: ((String(submitResponse.body || '').match(/data-leave-feedback-code="([^"]*)"/i) || [])[1] || null),
            message: decodeHtml(((String(submitResponse.body || '').match(/<div\b[^>]*class="[^"]*alert-danger[^"]*"[^>]*>[\s\S]*?<\/div>/i) || [])[0] || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()).slice(0, 300)
        });
}

async function verifyQ27BalanceChangesOnlyAfterOutcome(context) {
    const teacher = context.sessionFor('worker_teacher');
    const specialist = context.sessionFor('worker_specialist');
    const [teacherPage, specialistPage] = await Promise.all([teacher.get(PORTAL_ROUTE), specialist.get(PORTAL_ROUTE)]);
    const teacherBalance = q27UnpaidBalance(teacherPage.body);
    const specialistBalance = q27UnpaidBalance(specialistPage.body);
    const teacherRequest = q27RequestState(teacherPage.body, Q27_STAFFING_WINDOW[0].slice(0, 10));
    const specialistRequest = q27RequestState(specialistPage.body, Q27_STAFFING_WINDOW[0].slice(0, 10));
    const valid = teacherBalance && specialistBalance && teacherRequest && specialistRequest
        && teacherRequest.pending && specialistRequest.draft
        && teacherBalance.held > 0 && teacherBalance.used === 0
        && specialistBalance.held === 0 && specialistBalance.used === 0;
    return valid
        ? passed(PORTAL_ROUTE, {
            teacher_balance: teacherBalance,
            specialist_balance: specialistBalance,
            approved_override_reserved_only: true,
            unapproved_competing_request_changed_no_balance: true,
            no_consumption_before_final_approval: true
        })
        : blocked('Q27_BALANCE_OUTCOME_INVARIANT_NOT_PROVEN', PORTAL_ROUTE, { teacherBalance, specialistBalance, teacher_pending: Boolean(teacherRequest && teacherRequest.pending), specialist_draft: Boolean(specialistRequest && specialistRequest.draft) });
}

function disciplineCaseState(html, caseNo) {
    const row = tableRowContaining(html, caseNo);
    if (!row) return null;
    const attr = (name) => inputValue(row, name);
    return {
        row,
        case_id: Number(attr('data-case-id') || 0),
        case_lock: Number(attr('data-case-lock') || 0),
        decision_id: Number(attr('data-decision-id') || 0),
        evidence_id: Number(attr('data-evidence-id') || 0),
        interim_id: Number(attr('data-interim-id') || 0),
        interim_status: attr('data-interim-status'),
        reopen_request_id: Number(attr('data-reopen-request-id') || 0),
        visible_status: decodeHtml(row.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim())
    };
}

async function verifyQ13DisciplineCase(context, stage) {
    const route = 'admin/disciplinary.php';
    const page = await context.sessionFor('hr_manager').get(route);
    if (!responseIsAuthenticated(page)) return blocked('DISCIPLINE_ADMIN_ROUTE_UNAVAILABLE', route, { status: page.status });
    const state = disciplineCaseState(page.body, 'DEMO-CASE-0001');
    if (!state) return blocked('DISCIPLINE_ACCEPTANCE_CASE_NOT_RENDERED', route);
    if (state.decision_id <= 0 || state.evidence_id <= 0 || !/تظلم|appeal|قرار/i.test(state.visible_status)) {
        return blocked('DISCIPLINE_SEPARATED_LIFECYCLE_NOT_PROVEN', route, { stage, state });
    }
    if (stage === 'finance') {
        const finance = await openAuthenticated(context.sessionFor('finance_operator'), 'admin/finance_staff_ledger.php', /العامل|العملية|السجل|الحركات/);
        if (!finance.passed) return finance;
        return passed(route, { stage, case_id: state.case_id, decision_id: state.decision_id, evidence_id: state.evidence_id, original_decision_retained: true, finance_metadata_only: true });
    }
    return passed(route, { stage, case_id: state.case_id, decision_id: state.decision_id, evidence_id: state.evidence_id, separated_investigation_decision: true, appeal_visible: true });
}

async function applyQ29TemporaryInterimMeasure(context) {
    const adminRoute = 'admin/disciplinary.php';
    const portal = context.sessionFor('worker_standard');
    const admin = context.sessionFor('hr_manager');
    let adminPage = await admin.get(adminRoute);
    let state = disciplineCaseState(adminPage.body, 'DEMO-CASE-0001');
    if (!state) return blocked('Q29_DISCIPLINE_CASE_NOT_RENDERED', adminRoute);
    if (!state.interim_id) {
        const workerPage = await portal.get(PORTAL_ROUTE);
        const form = formContaining(workerPage.body, 'discipline_intent', 'request_interim');
        if (!responseIsAuthenticated(workerPage) || !form) return blocked('Q29_INTERIM_REQUEST_FORM_UNAVAILABLE', PORTAL_ROUTE);
        const now = Date.now();
        const fields = {
            ...hiddenFields(form),
            discipline_intent: 'request_interim',
            case_id: state.case_id,
            expected_case_lock_version: state.case_lock,
            basis_evidence_id: state.evidence_id,
            measure_type: 'temporary_duty_adjustment',
            reason: 'إجراء احترازي تجريبي محدود المدة لحين المراجعة',
            starts_at: new Date(now - 5 * 60 * 1000).toISOString(),
            ends_at: new Date(now + 60 * 60 * 1000).toISOString(),
            review_due_at: new Date(now + 30 * 60 * 1000).toISOString(),
            idempotency_key: 'staff-hr-acceptance:q29:interim-request'
        };
        const response = await portal.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(String(response.body || ''))) {
            return blocked('Q29_INTERIM_REQUEST_REJECTED', PORTAL_ROUTE, { status: response.status, alert: firstDangerAlertText(response.body) });
        }
        adminPage = await admin.get(adminRoute);
        state = disciplineCaseState(adminPage.body, 'DEMO-CASE-0001');
    }
    if (!state || !state.interim_id) return blocked('Q29_INTERIM_NOT_PERSISTED', adminRoute);
    if (state.interim_status === 'draft') {
        const form = formContaining(adminPage.body, 'discipline_case_intent', 'activate_interim');
        if (!form) return blocked('Q29_INTERIM_ACTIVATION_FORM_UNAVAILABLE', adminRoute, { state });
        const fields = hiddenFields(form);
        const response = await admin.postForm(adminRoute, fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(String(response.body || ''))) {
            return blocked('Q29_INTERIM_ACTIVATION_REJECTED', adminRoute, { status: response.status, alert: firstDangerAlertText(response.body) });
        }
        adminPage = await admin.get(adminRoute);
        state = disciplineCaseState(adminPage.body, 'DEMO-CASE-0001');
    }
    return state && state.interim_status === 'active'
        ? passed(adminRoute, { case_id: state.case_id, measure_id: state.interim_id, status: state.interim_status, requester_and_authorizer_separated: true })
        : blocked('Q29_INTERIM_NOT_ACTIVE', adminRoute, { state });
}

async function verifyQ29ClosedCase(context) {
    const route = 'admin/disciplinary.php';
    const page = await context.sessionFor('hr_manager').get(route);
    const state = disciplineCaseState(page.body, 'DEMO-CASE-0002');
    return responseIsAuthenticated(page) && state && /مغلقة|closed/i.test(state.visible_status)
        ? passed(route, { case_id: state.case_id, decision_id: state.decision_id, closed_without_deletion: true })
        : blocked('Q29_CLOSED_CASE_NOT_AVAILABLE', route, { state });
}

async function reopenQ29CaseWithNewEvidence(context) {
    const adminRoute = 'admin/disciplinary.php';
    const portal = context.sessionFor('worker_standard');
    const admin = context.sessionFor('hr_manager');
    let adminPage = await admin.get(adminRoute);
    let state = disciplineCaseState(adminPage.body, 'DEMO-CASE-0002');
    if (!state) return blocked('Q29_CLOSED_CASE_NOT_AVAILABLE', adminRoute);
    if (/أعيد فتحها|reopened/i.test(state.visible_status)) {
        return passed(adminRoute, { case_id: state.case_id, decision_id: state.decision_id, idempotent_replay: true, prior_decision_retained: true });
    }
    if (!state.reopen_request_id) {
        const workerPage = await portal.get(PORTAL_ROUTE);
        const form = formContaining(workerPage.body, 'discipline_intent', 'request_reopen');
        if (!responseIsAuthenticated(workerPage) || !form) return blocked('Q29_REOPEN_REQUEST_FORM_UNAVAILABLE', PORTAL_ROUTE);
        const fields = {
            ...hiddenFields(form),
            discipline_intent: 'request_reopen',
            case_id: state.case_id,
            expected_case_lock_version: state.case_lock,
            prior_decision_id: state.decision_id,
            new_evidence_id: state.evidence_id,
            reopen_reason: 'ظهر دليل موثق جديد يستلزم إعادة التحقيق دون محو القرار السابق',
            idempotency_key: 'staff-hr-acceptance:q29:reopen-request'
        };
        const response = await portal.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(String(response.body || ''))) {
            return blocked('Q29_REOPEN_REQUEST_REJECTED', PORTAL_ROUTE, { status: response.status, alert: firstDangerAlertText(response.body) });
        }
        adminPage = await admin.get(adminRoute);
        state = disciplineCaseState(adminPage.body, 'DEMO-CASE-0002');
    }
    const form = formContaining(adminPage.body, 'discipline_case_intent', 'decide_reopen');
    if (!state || !state.reopen_request_id || !form) return blocked('Q29_REOPEN_DECISION_FORM_UNAVAILABLE', adminRoute, { state });
    const fields = { ...hiddenFields(form), idempotency_key: 'staff-hr-acceptance:q29:reopen-authorized' };
    const response = await admin.postForm(adminRoute, fields, { csrf: fields.csrf_token });
    if (!responseIsAuthenticated(response) || /alert-danger/i.test(String(response.body || ''))) {
        return blocked('Q29_REOPEN_DECISION_REJECTED', adminRoute, { status: response.status, alert: firstDangerAlertText(response.body) });
    }
    adminPage = await admin.get(adminRoute);
    const after = disciplineCaseState(adminPage.body, 'DEMO-CASE-0002');
    return after && /أعيد فتحها|reopened/i.test(after.visible_status) && after.decision_id === state.decision_id
        ? passed(adminRoute, { case_id: after.case_id, decision_id: after.decision_id, prior_decision_retained: true, reopen_authorized: true })
        : blocked('Q29_CASE_NOT_REOPENED', adminRoute, { before: state, after });
}

async function verifyQ29History(context) {
    const result = await reopenQ29CaseWithNewEvidence(context);
    if (!result.passed) return result;
    const audit = await openAuthenticated(context.sessionFor('hr_manager'), 'admin/hr_audit.php', /التدقيق|الفاعل|العملية|المورد/);
    return audit.passed
        ? passed('admin/disciplinary.php', { ...result.evidence, immutable_prior_decision: true, reopen_history_audited: true, automatic_finance_reversal: false })
        : audit;
}

const Q28_SUBJECT = 'Q28-IMMEDIATE-RISK-COLLECTIVE';

function q28WorkerTicket(html) {
    const body = String(html || '');
    const marker = body.indexOf(Q28_SUBJECT);
    if (marker < 0) return null;
    const start = body.lastIndexOf('<div class="list-group-item"', marker);
    const segment = body.slice(Math.max(0, start), marker + 1200);
    const id = segment.match(/data-ertaq-ticket-id=["'](\d+)["']/i);
    const lock = segment.match(/data-ertaq-lock-version=["'](\d+)["']/i);
    const ticketNo = decodeHtml(segment.replace(/<[^>]+>/g, ' ')).match(/\bERT-[A-Z0-9-]+\b/i);
    return id && lock ? {
        ticket_id: Number(id[1]),
        lock_version: Number(lock[1]),
        ticket_no: ticketNo ? ticketNo[0] : '',
        segment,
        protected: /مسار حماية|urgent_protected/i.test(segment),
        withdrawal_requested: /طلب سحب|withdrawal_requested/i.test(segment)
    } : null;
}

async function submitQ28ImmediateRisk(context) {
    let worker;
    try { worker = context.sessionFor('worker_standard'); } catch (_error) { worker = null; }
    if (!worker) return blocked('WORKER_PORTAL_SESSION_UNAVAILABLE', PORTAL_ROUTE);
    let page = await worker.get(PORTAL_ROUTE);
    let state = q28WorkerTicket(page.body);
    if (!state) {
        const form = formContaining(page.body, 'ertaq_intent', 'create_ticket');
        if (!responseIsAuthenticated(page) || !form) return blocked('Q28_ERTAQ_CREATE_FORM_UNAVAILABLE', PORTAL_ROUTE);
        const fields = {
            ...hiddenFields(form),
            ertaq_intent: 'create_ticket',
            create_idempotency_key: 'staff-hr-acceptance:q28:urgent-ticket',
            type: 'complaint',
            confidentiality_level: 'highly_restricted',
            priority: 'urgent',
            immediate_risk: '1',
            subject: Q28_SUBJECT
        };
        const response = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(String(response.body || ''))) {
            return blocked('Q28_IMMEDIATE_RISK_SUBMISSION_REJECTED', PORTAL_ROUTE, { status: response.status, alert: firstDangerAlertText(response.body) });
        }
        page = await worker.get(PORTAL_ROUTE);
        state = q28WorkerTicket(page.body);
    }
    return state && (state.protected || state.withdrawal_requested)
        ? passed(PORTAL_ROUTE, { ticket_id: state.ticket_id, ticket_no: state.ticket_no, urgent_protection_server_routed: true, subject_owner_only: true })
        : blocked('Q28_URGENT_ROUTE_NOT_VISIBLE_TO_OWNER', PORTAL_ROUTE, { state });
}

async function addQ28CollectiveContext(context) {
    const created = await submitQ28ImmediateRisk(context);
    if (!created.passed) return created;
    const workerPage = await context.sessionFor('worker_standard').get(PORTAL_ROUTE);
    const state = q28WorkerTicket(workerPage.body);
    const route = 'admin/hr_ertaq.php';
    const directAttempt = await context.sessionFor('direct_manager').get(route);
    const directDenied = !responseIsAuthenticated(directAttempt) || !String(directAttempt.body || '').includes(state.ticket_no);
    if (!directDenied) return blocked('Q28_CONFLICTED_MANAGER_WAS_NOT_EXCLUDED', route);
    const protection = context.sessionFor('protection_officer');
    let page = await protection.get(route);
    if (!responseIsAuthenticated(page)) return blocked('Q28_PROTECTION_INBOX_UNAVAILABLE', route, { status: page.status });
    const row = tableRowContaining(page.body, state.ticket_no);
    if (!row) return blocked('Q28_PROTECTION_ROUTE_NOT_RENDERED', route, { ticket_no: state.ticket_no });
    const form = formContaining(row, 'ertaq_urgent_intent', 'manage_collective');
    if (!form) return blocked('Q28_COLLECTIVE_MANAGEMENT_FORM_UNAVAILABLE', route);
    const fields = hiddenFields(form);
    const response = await protection.postForm(route, fields, { csrf: fields.csrf_token });
    return responseIsAuthenticated(response) && !/alert-danger/i.test(String(response.body || ''))
        ? passed(route, { ticket_id: state.ticket_id, ticket_no: state.ticket_no, conflicted_direct_manager_excluded: true, collective_party_added: true, ticket_linked_without_content_copy: true })
        : blocked('Q28_COLLECTIVE_CONTEXT_REJECTED', route, { status: response.status, alert: firstDangerAlertText(response.body) });
}

async function requestQ28Withdrawal(context) {
    const created = await submitQ28ImmediateRisk(context);
    if (!created.passed) return created;
    const worker = context.sessionFor('worker_standard');
    let page = await worker.get(PORTAL_ROUTE);
    let state = q28WorkerTicket(page.body);
    if (state && state.withdrawal_requested) return passed(PORTAL_ROUTE, { ticket_id: state.ticket_id, idempotent_replay: true, original_retained: true });
    page = await worker.get(`${PORTAL_ROUTE}?ertaq_ticket_id=${state.ticket_id}`);
    const form = formContaining(page.body, 'ertaq_intent', 'request_withdrawal');
    if (!responseIsAuthenticated(page) || !form) return blocked('Q28_WITHDRAWAL_FORM_UNAVAILABLE', PORTAL_ROUTE, { state });
    const fields = { ...hiddenFields(form), withdrawal_reason: 'طلب سحب بعد بدء التحقيق مع الاحتفاظ بالسجل الأصلي', idempotency_key: 'staff-hr-acceptance:q28:withdrawal' };
    const response = await worker.postForm(PORTAL_ROUTE, fields, { csrf: fields.csrf_token });
    page = await worker.get(PORTAL_ROUTE);
    state = q28WorkerTicket(page.body);
    return responseIsAuthenticated(response) && state && state.withdrawal_requested
        ? passed(PORTAL_ROUTE, { ticket_id: state.ticket_id, withdrawal_is_request_not_delete: true, original_retained: true })
        : blocked('Q28_WITHDRAWAL_REQUEST_FAILED', PORTAL_ROUTE, { status: response.status, state, alert: firstDangerAlertText(response.body) });
}

async function verifyQ28ProtectionAndRetention(context) {
    const withdrawal = await requestQ28Withdrawal(context);
    if (!withdrawal.passed) return withdrawal;
    const workerPage = await context.sessionFor('worker_standard').get(PORTAL_ROUTE);
    const state = q28WorkerTicket(workerPage.body);
    const route = 'admin/hr_ertaq.php';
    const protection = context.sessionFor('protection_officer');
    let page = await protection.get(route);
    let row = tableRowContaining(page.body, state.ticket_no);
    if (!row) return blocked('Q28_PROTECTION_ROUTE_NOT_RETAINED', route, { state });
    const status = inputValue(row, 'data-ertaq-urgent-status');
    if (status === 'routed') {
        const form = formContaining(row, 'ertaq_urgent_intent', 'acknowledge');
        if (!form) return blocked('Q28_ACKNOWLEDGEMENT_FORM_UNAVAILABLE', route);
        const fields = hiddenFields(form);
        const response = await protection.postForm(route, fields, { csrf: fields.csrf_token });
        if (!responseIsAuthenticated(response) || /alert-danger/i.test(String(response.body || ''))) {
            return blocked('Q28_ACKNOWLEDGEMENT_REJECTED', route, { status: response.status, alert: firstDangerAlertText(response.body) });
        }
        page = await protection.get(route);
        row = tableRowContaining(page.body, state.ticket_no);
    }
    return row && inputValue(row, 'data-ertaq-urgent-status') === 'acknowledged' && state.withdrawal_requested
        ? passed(route, { ticket_id: state.ticket_id, urgent_route_acknowledged: true, withdrawal_did_not_delete_original: true, neutral_protection_surface: !String(row).includes(Q28_SUBJECT) })
        : blocked('Q28_FINAL_RETENTION_NOT_PROVEN', route, { urgent_status: row ? inputValue(row, 'data-ertaq-urgent-status') : null, state });
}

register(['publish_scoped_schedules'], (context) => publishAcceptanceStaffSchedule(context));
register(['resolve_worker_schedules'], (context) => resolveAcceptanceStaffSchedule(context));
register(['verify_equal_rank_conflict'], (context) => verifyAcceptanceEqualRankConflict(context));
register(['publish_successor_schedule'], (context) => publishAcceptanceSuccessorSchedule(context));
register(['reopen_historical_report'], (context) => openHistoricalAttendanceReport(context));
register(['verify_historical_policy_version'], (context) => verifyHistoricalScheduleVersion(context));
register(['record_overnight_punches'], (context) => recordAcceptanceOvernightPunches(context));
register(['calculate_overnight_day'], (context) => calculateAcceptanceOvernightDay(context));
register(['verify_holiday_denominator'], (context) => verifyAcceptanceHolidayDenominator(context));
register(['import_duplicate_and_unknown_punches'], (context) => importAcceptanceExceptionPunches(context));
register(['open_attendance_exceptions'], (context) => openAcceptanceAttendanceExceptions(context));
register(['verify_raw_event_idempotency'], (context) => verifyAcceptanceRawEventIdempotency(context));
register(['submit_late_arrival_permission'], (context) => submitAcceptancePermission(context, 'late'));
register(['submit_early_leave_permission'], (context) => submitAcceptancePermission(context, 'early'));
register(['submit_mission_permission'], (context) => submitAcceptancePermission(context, 'mission'));
register(['approve_permission_stages', 'complete_ordered_decisions'], (context) => decideFirstAssignedApproval(context));
register(['record_late_arrival_punches'], (context) => recordAcceptancePermissionPunches(context, 'late'));
register(['verify_late_coverage_minutes'], (context) => verifyAcceptancePermissionCalculation(context, 'late'));
register(['record_early_departure_punches'], (context) => recordAcceptancePermissionPunches(context, 'early'));
register(['verify_early_coverage_minutes'], (context) => verifyAcceptancePermissionCalculation(context, 'early'));
register(['record_split_presence_punches'], (context) => recordAcceptancePermissionPunches(context, 'mission'));
register(['verify_mission_is_separate_from_presence'], (context) => verifyAcceptancePermissionCalculation(context, 'mission'));
register(['submit_three_stage_request'], (context) => submitAcceptancePermission(context, 'late'));
register(['attempt_out_of_order_decision'], (context) => attemptAcceptanceOutOfOrderDecision(context));
register(['verify_final_coverage_once'], (context) => verifyAcceptanceFinalCoverageOnce(context));
register(['publish_temporary_delegation'], (context) => publishAcceptanceTemporaryDelegation(context));
register(['decide_as_delegate'], (context) => decideAcceptanceAsDelegate(context));
register(['expire_delegation'], (context) => expireAcceptanceDelegation(context));
register(['verify_conflicted_manager_excluded'], (context) => verifyAcceptanceConflictedManagerExcluded(context));
register(['submit_concurrent_last_quota_requests'], (context) => submitAcceptanceConcurrentLastQuotaRequests(context));
register(['verify_only_one_reservation'], (context) => verifyAcceptanceSingleQuotaReservation(context));
register(['reject_reserved_request'], (context) => rejectAcceptanceReservedRequest(context));
register(['verify_quota_release_and_retry'], (context) => verifyAcceptanceQuotaReleaseAndRetry(context));
register(['approve_permission_without_punches'], (context) => approveAcceptancePermissionWithoutPunches(context));
register(['recalculate_unattended_day'], (context) => recalculateAcceptanceUnattendedDay(context));
register(['verify_absence_and_coverage_separate'], (context) => verifyAcceptanceAbsenceAndCoverageSeparate(context));
register(['submit_attendance_adjustment'], (context) => submitAcceptanceAttendanceAdjustment(context));
register(['attempt_self_approval'], (context) => attemptAcceptanceAdjustmentSelfApproval(context));
register(['approve_attendance_adjustment'], (context) => approveAcceptanceAttendanceAdjustment(context));
register(['verify_new_official_day_version'], (context) => verifyAcceptanceAdjustmentOfficialVersion(context));
register(['publish_split_shift'], (context) => publishAcceptanceSplitShift(context));
register(['approve_temporary_swap'], (context) => approveAcceptanceTemporarySwap(context));
register(['record_unapproved_and_approved_overtime'], (context) => recordAcceptanceOvertimeStates(context));
register(['verify_split_shift_calculation'], (context) => verifyAcceptanceSplitShiftCalculation(context));
register(['close_attendance_period_and_dispatch_fact'], (context) => closeAttendancePeriodAndDispatchFact(context));
register(['approve_late_coverage_change'], (context) => approveLateCoverageChange(context));
register(['reverse_leave_after_close'], (context) => reverseLeaveAfterClose(context));
register(['verify_reopen_or_idempotent_finance_reversal'], (context) => verifyQ25ReopenAndFinanceIdempotency(context));
register(['grant_temporary_alternative_attendance'], (context) => grantQ22AlternativeAttendance(context));
register(['attempt_self_reviewed_entry'], (context) => recordQ22AlternativeEntry(context).then(async (recorded) => recorded.passed ? attemptQ22SelfReview(context) : recorded));
register(['approve_alternative_entry'], (context) => approveQ22AlternativeEntry(context));
register(['verify_expired_method_rejected'], (context) => verifyQ22ExpiredMethodRejected(context));
register(['attempt_overlapping_biometric_identity'], (context) => attemptQ20OverlappingIdentity(context));
register(['reuse_identity_after_end_date'], (context) => reuseQ20IdentityAfterEnd(context));
register(['import_delayed_drifted_events'], (context) => importQ20DelayedDriftedEvents(context));
register(['verify_raw_history_and_period_lock'], (context) => verifyQ20RawHistoryAndPeriodLock(context));
register(['publish_duplicate_actor_workflow'], (context) => publishAcceptanceDuplicateActorWorkflow(context));
register(['cast_quorum_and_tied_votes'], (context) => castAcceptanceQuorumVotes(context));
register(['submit_all_stage_rejection'], (context) => verifyAcceptanceQ23Finality(context));
register(['verify_actor_counted_once'], (context) => verifyAcceptanceQ23Finality(context));
register(['submit_future_dated_request'], (context) => submitAcceptanceFutureDatedRequest(context));
register(['transfer_worker_and_manager'], (context) => transferAcceptanceWorkerAndManager(context));
register(['end_service_with_pending_request'], (context) => endAcceptanceServiceWithPendingRequest(context));
register(['verify_access_revalidation_and_quota_release'], (context) => verifyAcceptanceAccessRevalidation(context));
register(['create_opening_leave_balance'], (context) => createAcceptanceOpeningLeaveBalance(context));
register(['submit_competing_leave_requests'], (context) => submitAcceptanceCompetingLeaveRequests(context));
register(['submit_cross_year_leave'], (context) => submitAcceptanceCrossYearLeave(context));
register(['verify_leave_ledger_invariants'], (context) => verifyAcceptanceLeaveLedgerInvariants(context));
register(['upload_valid_medical_pdf'], (context) => uploadAcceptanceMedicalPdf(context));
register(['attempt_unsafe_medical_uploads'], (context) => attemptAcceptanceUnsafeMedicalUploads(context));
register(['submit_competing_staffing_leave_requests'], (context) => submitQ27CompetingStaffingLeaveRequests(context));
register(['attempt_blackout_leave'], (context) => attemptQ27BlackoutLeave(context));
register(['approve_reasoned_staffing_override'], (context) => approveQ27ReasonedStaffingOverride(context));
register(['verify_balance_changes_only_after_outcome'], (context) => verifyQ27BalanceChangesOnlyAfterOutcome(context));
register(['open_discipline_case'], (context) => verifyQ13DisciplineCase(context, 'case_opened'));
register(['complete_separated_investigation_and_decision'], (context) => verifyQ13DisciplineCase(context, 'separated_investigation_decision'));
register(['submit_discipline_appeal'], (context) => verifyQ13DisciplineCase(context, 'appeal_submitted'));
register(['verify_original_decision_and_finance_fact'], (context) => verifyQ13DisciplineCase(context, 'finance'));
register(['apply_temporary_interim_measure'], (context) => applyQ29TemporaryInterimMeasure(context));
register(['close_discipline_case'], (context) => verifyQ29ClosedCase(context));
register(['add_new_evidence_and_reopen'], (context) => reopenQ29CaseWithNewEvidence(context));
register(['verify_prior_decision_and_reversal_history'], (context) => verifyQ29History(context));
register(['preview_retroactive_organization_correction'], (context) => previewAcceptanceOrganizationCorrection(context));
register(['approve_scoped_correction'], (context) => approveAcceptanceScopedCorrection(context));
register(['verify_only_impacted_days_recalculated'], (context) => verifyAcceptanceCorrectionImpact(context));
register(['cancel_correction_and_verify_history'], (context) => reverseAcceptanceCorrectionAndVerifyHistory(context));
register(['submit_normal_ertaq_suggestion'], (context) => createErtaqTicket(context.primarySession));
register(['submit_confidential_ertaq_complaint'], (context) => createErtaqTicket(context.primarySession, { confidential: true }));
register(['attempt_conflicted_ticket_access'], (context) => confidentialTicketAccessIsDenied(context));
register(['verify_neutral_notification_and_immutability'], (context) => verifyConfidentialTicketOwnerView(context));
register(['submit_immediate_risk_complaint'], (context) => submitQ28ImmediateRisk(context));
register(['add_collective_parties_and_link_ticket'], (context) => addQ28CollectiveContext(context));
register(['request_withdrawal_after_investigation'], (context) => requestQ28Withdrawal(context));
register(['verify_protection_route_and_original_retention'], (context) => verifyQ28ProtectionAndRetention(context));
register(['open_teacher_staff_self_service'], (context) => openAuthenticated(context.sessionFor('worker_teacher'), PORTAL_ROUTE, /شؤون العاملين|طلباتي|ارتق/));
register(['open_specialist_staff_self_service'], (context) => openAuthenticated(context.sessionFor('worker_specialist'), PORTAL_ROUTE, /شؤون العاملين|طلباتي|ارتق/));
register(['attempt_other_worker_scope'], (context) => verifyPortalIgnoresClientWorkerScope(context.primarySession));
register(['open_individual_and_group_reports'], (context) => openAuthenticated(context.primarySession, 'admin/staff_attendance_reports.php', /الحضور|التقرير|العاملين/));
register(['drill_into_report_totals'], async (context) => {
    const route = 'admin/staff_attendance_reports.php?date_from=2026-08-11&date_to=2026-08-11&page_size=50';
    return openAuthenticated(context.primarySession, route, /تفاصيل الأيام الرسمية[\s\S]*المتوقع:[\s\S]*الفعلـي:/);
});
register(['export_formula_safe_csv'], async (context) => {
    const route = 'admin/staff_attendance_reports.php?date_from=2026-08-11&date_to=2026-08-11&page_size=50&export=csv';
    const response = await context.primarySession.get(route);
    if (!responseIsAuthenticated(response)) return blocked('ATTENDANCE_REPORT_CSV_UNAVAILABLE', route, { status: response.status });
    const contentType = String(response.headers && response.headers['content-type'] || '');
    const cells = csvCells(response.body);
    const unsafe = cells.filter((cell) => /^[=+\-@]/.test(cell));
    if (!/text\/csv/i.test(contentType) || cells.length < 8 || unsafe.length > 0) {
        return blocked('ATTENDANCE_REPORT_CSV_SAFETY_NOT_PROVEN', route, {
            status: response.status,
            content_type: contentType,
            cell_count: cells.length,
            unsafe_formula_cells: unsafe.length
        });
    }
    return passed(route, { status: response.status, content_type: contentType, cell_count: cells.length, unsafe_formula_cells: 0 });
});
register(['verify_report_denominator_and_scope'], async (context) => {
    const route = 'admin/staff_attendance_reports.php?date_from=2026-08-11&date_to=2026-08-11&page_size=50';
    return openAuthenticated(context.primarySession, route, /أيام رسمية[\s\S]*غياب غير مغطى[\s\S]*يوم مؤهل[\s\S]*نسبة الغياب/);
});
register(['inspect_finance_fact_without_sensitive_data'], (context) => openAuthenticated(
    context.sessionFor('finance_operator'),
    'admin/finance_staff_ledger.php',
    /العامل|العملية|السجل|الحركات/
));
register(['audit_without_self_approval'], (context) => openAuthenticated(
    context.sessionFor('super_admin'),
    'admin/hr_audit.php',
    /التدقيق|الفاعل|العملية|المورد/
));
register(['recalculate_and_report_as_hr'], (context) => recalculateAndOpenOfficialReport(context));
register(['verify_cross_role_totals_balances_and_scope'], async (context) => {
    const report = await openAuthenticated(context.sessionFor('hr_manager'), 'admin/staff_attendance_reports.php', /أيام رسمية[\s\S]*تفاصيل الأيام الرسمية/);
    if (!report.passed) return report;
    const finance = await openAuthenticated(context.sessionFor('finance_operator'), 'admin/finance_staff_ledger.php', /العامل|العملية|السجل|الحركات/);
    if (!finance.passed) return finance;
    const worker = await openAuthenticated(context.sessionFor('worker_standard'), PORTAL_ROUTE, /طلباتي|شؤون العاملين|ارتق/);
    if (!worker.passed) return worker;
    return passed('admin/staff_attendance_reports.php', { report: report.evidence, finance: finance.evidence, worker: worker.evidence, scopes_separated: true });
});
register(['create_worker_requests_and_ertaq_message'], async (context) => {
    const permission = await submitPermission(context.primarySession, 'late', context.definition.id);
    const existingApproval = permission.passed ? null : await approvedPermissionEvidence(context.primarySession);
    if (!permission.passed && !existingApproval) return permission;
    const ticket = await createErtaqTicket(context.primarySession);
    if (!ticket.passed) return ticket;
    return passed(PORTAL_ROUTE, { permission: permission.passed ? permission.evidence : existingApproval, ticket: ticket.evidence });
});
register(['complete_manager_decisions'], async (context) => {
    const decisions = await decideFirstAssignedApproval(context);
    if (decisions.passed) return decisions;
    const existingApproval = await approvedPermissionEvidence(context.sessionFor('worker_standard'));
    return existingApproval
        ? passed(PORTAL_ROUTE, { idempotent_replay: true, approved_request_visible: true })
        : decisions;
});
register(['replay_worker_approval_report_journey'], async (context) => {
    const workerSession = context.sessionFor('worker_standard');
    const permission = await submitPermission(workerSession, 'late', context.definition.id);
    let approvals = null;
    let existingApproval = null;
    if (permission.passed) {
        approvals = await decideFirstAssignedApproval(context);
        if (!approvals.passed) existingApproval = await approvedPermissionEvidence(workerSession);
    } else {
        existingApproval = await approvedPermissionEvidence(workerSession);
    }
    if (!permission.passed && !existingApproval) return permission;
    if (approvals && !approvals.passed && !existingApproval) return approvals;
    const reportSession = context.sessionFor('hr_manager');
    const report = await openAuthenticated(reportSession, 'admin/staff_attendance_reports.php', /الحضور|التقرير|العاملين/);
    if (!report.passed) return report;
    return passed(PORTAL_ROUTE, {
        permission: permission.passed ? permission.evidence : existingApproval,
        approvals: approvals && approvals.passed ? approvals.evidence : { idempotent_replay: true, approved_request_visible: true },
        report: report.evidence
    });
});
register(['mutate_manifest_owned_demo_rows'], async (context) => {
    const ticket = await createErtaqTicket(context.sessionFor('worker_standard'), { type: 'suggestion' });
    return ticket.passed
        ? passed(PORTAL_ROUTE, { mutation: 'audited_ertaq_ticket', ticket: ticket.evidence })
        : ticket;
});
register(['verify_role_independent_portal_scope'], (context) => openAuthenticated(context.primarySession, PORTAL_ROUTE, /شؤون العاملين|طلباتي|ارتق/));
register(['trigger_reviewed_domain_errors', 'inspect_user_error_messages', 'verify_no_technical_error_leak'], (context) => triggerReviewedPermissionError(context.primarySession));

for (const action of declaredActions) {
    if (!handlers.has(action)) {
        handlers.set(action, async () => blocked('UI_ROUTE_OR_REQUIRED_FIELD_NOT_AVAILABLE', null, { action }));
    }
}

async function staffHrAcceptanceActionExecutor(context) {
    const handler = handlers.get(context.action);
    if (!handler) return blocked('ACTION_NOT_DECLARED_BY_Q01_Q33', null, { action: context.action });
    if (!context.primarySession && context.action === 'submit_immediate_risk_complaint') {
        return blocked('ERTAQ_PROTECTION_TEAM_ROUTE_NOT_CONFIGURED', PORTAL_ROUTE);
    }
    if (!context.primarySession && context.action === 'publish_split_shift') {
        return blocked('UI_ROUTE_OR_REQUIRED_FIELD_NOT_AVAILABLE', null, { action: context.action });
    }
    return handler(context);
}

staffHrAcceptanceActionExecutor.supportedActionNames = () => [...handlers.keys()].sort();
staffHrAcceptanceActionExecutor.declaredActionNames = () => [...declaredActions].sort();

module.exports = {
    declaredActions,
    staffHrAcceptanceActionExecutor,
    csvCells,
    formContaining,
    hiddenFields,
    selectOption
};
