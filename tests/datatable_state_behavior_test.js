'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');

function createSessionStorage() {
    const values = new Map();
    return {
        get length() { return values.size; },
        clear() { values.clear(); },
        getItem(key) { return values.has(String(key)) ? values.get(String(key)) : null; },
        key(index) { return Array.from(values.keys())[index] || null; },
        removeItem(key) { values.delete(String(key)); },
        setItem(key, value) { values.set(String(key), String(value)); }
    };
}

const sessionStorage = createSessionStorage();
sessionStorage.setItem('educore:datatable-state:v1:legacy', '{"start":50}');

const documentEvents = {};
const jqueryEvents = {};
const queuedMicrotasks = [];
const createdElements = {};
let ajaxApi = null;
let rowScrolled = false;
let rowFocused = false;
let rowLinks = [];
let headers = [
    { textContent: '#', getAttribute() { return null; } },
    { textContent: 'الكود', getAttribute() { return null; } },
    { textContent: 'الاسم', getAttribute() { return null; } },
    { textContent: 'الدور', getAttribute() { return null; } }
];
const tableAttributes = {};
const rowAttributes = {};
const rowClasses = new Set();
const rowCheckbox = {
    value: '1089',
    classList: { contains(name) { return name === 'row-select-cb'; } },
    getAttribute(name) { return name === 'name' ? 'selected_ids[]' : null; }
};
const row = {
    id: '',
    offsetWidth: 100,
    classList: {
        add(name) { rowClasses.add(name); },
        remove(name) { rowClasses.delete(name); }
    },
    addEventListener() {},
    closest(selector) { return selector === 'table' ? table : null; },
    focus() { rowFocused = true; },
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(rowAttributes, name) ? rowAttributes[name] : null; },
    getBoundingClientRect() { return { top: 900, bottom: 940 }; },
    querySelectorAll(selector) {
        if (selector === 'a[href]') return rowLinks;
        return selector === 'input[value], button[value], option[value]' ? [rowCheckbox] : [];
    },
    removeAttribute(name) { delete rowAttributes[name]; },
    scrollIntoView() { rowScrolled = true; },
    setAttribute(name, value) { rowAttributes[name] = String(value); }
};
const table = {
    id: 'staffAccountsTable',
    getAttribute(name) { return tableAttributes[name] || null; },
    querySelectorAll(selector) {
        if (selector === 'thead th') return headers;
        if (selector === 'tbody tr') return [row];
        return [];
    }
};
const rowActionLinkAttributes = {
    href: 'staff_accounts.php?action=edit&id=1089&tab=academics',
    class: 'btn btn-action-pills btn-edit'
};
const rowActionLink = {
    closest(selector) {
        if (selector === 'a[href]') return this;
        if (selector === 'tbody tr') return row;
        return null;
    },
    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(rowActionLinkAttributes, name)
            ? rowActionLinkAttributes[name]
            : null;
    },
    hasAttribute(name) {
        return Object.prototype.hasOwnProperty.call(rowActionLinkAttributes, name);
    }
};
rowLinks = [rowActionLink];
const csrfMeta = {
    getAttribute(name) { return name === 'content' ? 'test-session-token' : null; }
};
const documentMock = {
    body: {
        classList: {
            contains(name) { return name === 'admin-page'; }
        },
        appendChild(element) { if (element.id) createdElements[element.id] = element; },
        insertBefore() {},
        firstChild: null
    },
    documentElement: { clientHeight: 700 },
    addEventListener(name, callback) { documentEvents[name] = callback; },
    createElement() {
        const attributes = {};
        return {
            id: '',
            className: '',
            style: {},
            textContent: '',
            appendChild() {},
            getAttribute(name) { return Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null; },
            remove() {},
            removeAttribute(name) { delete attributes[name]; },
            setAttribute(name, value) { attributes[name] = String(value); }
        };
    },
    createTextNode(text) { return { textContent: String(text) }; },
    getElementById(id) { return id === table.id ? table : (createdElements[id] || null); },
    querySelector(selector) {
        return selector === 'meta[name="csrf-token"]' ? csrfMeta : null;
    },
    querySelectorAll(selector) {
        return selector === 'table' ? [table] : [];
    }
};
const windowMock = {
    document: documentMock,
    location: {
        href: 'http://localhost/EduCore/admin/staff_accounts.php',
        origin: 'http://localhost',
        pathname: '/EduCore/admin/staff_accounts.php',
        search: ''
    },
    Promise,
    innerHeight: 700,
    matchMedia() { return { matches: false }; },
    queueMicrotask(callback) { queuedMicrotasks.push(callback); },
    requestAnimationFrame(callback) { callback(); },
    sessionStorage,
    setTimeout(callback) { callback(); }
};

function fakeJQuery(target) {
    if (target === documentMock) {
        return {
            on(names, callback) { jqueryEvents[names] = callback; }
        };
    }
    if (target === table && ajaxApi) {
        return { DataTable() { return ajaxApi; } };
    }
    return {};
}
fakeJQuery.fn = { dataTable: { defaults: {}, isDataTable() { return false; } } };
fakeJQuery.extend = function (deep, target, source) {
    Object.keys(source).forEach((key) => { target[key] = source[key]; });
    return target;
};

function makeForm(attributes, elements = []) {
    return {
        elements,
        method: attributes.method || 'get',
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(attributes, name)
                ? attributes[name]
                : null;
        }
    };
}

function flushMicrotasks() {
    while (queuedMicrotasks.length) queuedMicrotasks.shift()();
}

const source = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'datatable-state.js'),
    'utf8'
);
const context = {
    URL,
    URLSearchParams,
    Date,
    Math,
    Number,
    Array,
    Object,
    String,
    JSON,
    encodeURIComponent,
    window: windowMock,
    document: documentMock
};
vm.runInNewContext(source, context, { filename: 'datatable-state.js' });

assert.strictEqual(
    sessionStorage.getItem('educore:datatable-state:v1:legacy'),
    null,
    'Legacy persistent state must be removed during the upgrade.'
);
assert.ok(windowMock.EduCoreDataTableState, 'The shared return-state API must be exported.');
assert.ok(documentEvents.DOMContentLoaded, 'Loading before DataTables must defer installation.');
assert.ok(documentEvents.submit, 'POST submissions must be observed centrally.');
assert.ok(documentEvents.click, 'Full-page row action links must be observed centrally.');
assert.ok(
    windowMock.EduCoreDataTableState.sanitizeRowContext({
        tableId: 'staffAccountsTable',
        identities: [{ kind: 'field', name: 'row-select', value: '1089' }]
    }),
    'The existing row-selection identity fallback must remain compatible.'
);

windowMock.jQuery = fakeJQuery;
documentEvents.DOMContentLoaded();

const defaults = fakeJQuery.fn.dataTable.defaults;
assert.strictEqual(defaults.stateSave, true, 'DataTables callbacks must be enabled centrally.');
assert.strictEqual(defaults.stateDuration, -1, 'The one-shot bridge must use sessionStorage.');
assert.strictEqual(typeof defaults.stateSaveCallback, 'function');
assert.strictEqual(typeof defaults.stateLoadCallback, 'function');

const settings = {
    nTable: table,
    sTableId: table.id,
    oFeatures: { bStateSave: true }
};
const currentState = {
    start: 100,
    length: 50,
    order: [[2, 'asc']],
    search: { search: 'محمد', smart: true, regex: false, caseInsensitive: true },
    columns: [{ visible: false }],
    select: { rows: [44] }
};

defaults.stateSaveCallback(settings, currentState);
const sourceStateKey = windowMock.EduCoreDataTableState.keyFor(settings);
assert.strictEqual(
    sessionStorage.getItem(sourceStateKey),
    null,
    'Changing page or page length alone must not persist state.'
);
assert.strictEqual(
    defaults.stateLoadCallback(settings),
    null,
    'A normal revisit without an action must start from the table defaults.'
);

documentEvents.click({
    target: rowActionLink,
    button: 0,
    defaultPrevented: false,
    metaKey: false,
    ctrlKey: false,
    shiftKey: false,
    altKey: false
});
flushMicrotasks();
const linkSourceState = JSON.parse(sessionStorage.getItem(sourceStateKey));
assert.ok(linkSourceState, 'A full-page row action must capture the source list state.');
assert.ok(
    linkSourceState._educore.expiresAt - Date.now() > 20 * 60 * 1000,
    'A full-page edit journey must remain available long enough to complete the form.'
);
assert.strictEqual(linkSourceState._educore.rowContext.identities[0].kind, 'query');
assert.strictEqual(linkSourceState._educore.rowContext.identities[0].name, 'id');
assert.strictEqual(linkSourceState._educore.rowContext.identities[0].value, '1089');

windowMock.location.href = 'http://localhost/EduCore/admin/staff_accounts.php?tab=academics';
windowMock.location.search = '?tab=academics';
const linkReturnStateKey = windowMock.EduCoreDataTableState.keyFor(settings);
assert.ok(
    sessionStorage.getItem(linkReturnStateKey),
    'The action URL must infer the canonical list return query as a linked alias.'
);
const linkRestored = defaults.stateLoadCallback(settings);
assert.strictEqual(linkRestored.start, 100);
assert.strictEqual(linkRestored.length, 50);
assert.strictEqual(windowMock.EduCoreDataTableState.restoreRow(settings), true);
assert.strictEqual(sessionStorage.getItem(sourceStateKey), null);
assert.strictEqual(sessionStorage.getItem(linkReturnStateKey), null);

const ordinaryRowLinkAttributes = { href: 'staff_reports.php?id=1089', class: 'text-decoration-none' };
const ordinaryRowLink = {
    closest(selector) {
        if (selector === 'a[href]') return this;
        if (selector === 'tbody tr') return row;
        return null;
    },
    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(ordinaryRowLinkAttributes, name)
            ? ordinaryRowLinkAttributes[name]
            : null;
    },
    hasAttribute(name) {
        return Object.prototype.hasOwnProperty.call(ordinaryRowLinkAttributes, name);
    }
};
assert.strictEqual(
    windowMock.EduCoreDataTableState.shouldCaptureLink(ordinaryRowLink, { button: 0 }),
    false,
    'An ordinary row navigation link must not become a persistent table preference.'
);

windowMock.location.href = 'http://localhost/EduCore/admin/staff_accounts.php';
windowMock.location.search = '';
assert.strictEqual(
    defaults.stateLoadCallback(settings),
    null,
    'Returning normally after the action state was consumed must start from defaults.'
);

const samePagePost = makeForm({
    method: 'post',
    action: 'staff_accounts.php?tab=academics',
    'data-datatable-ajax': 'true',
    'data-datatable-return-table': 'staffAccountsTable',
    'data-datatable-return-row-field': 'user_id'
}, [{ name: 'user_id', value: '1089' }]);
documentEvents.submit({ target: samePagePost, defaultPrevented: false });
flushMicrotasks();

windowMock.location.href = 'http://localhost/EduCore/admin/staff_accounts.php?tab=academics';
windowMock.location.search = '?tab=academics';
const stateKey = windowMock.EduCoreDataTableState.keyFor(settings);
assert.notStrictEqual(
    stateKey,
    sourceStateKey,
    'The canonical PRG query must have its own scoped key.'
);
assert.ok(
    sessionStorage.getItem(sourceStateKey),
    'The source URL alias must remain available until the PRG destination consumes the return state.'
);
const stored = JSON.parse(sessionStorage.getItem(stateKey));
assert.strictEqual(stored.start, 100);
assert.strictEqual(stored.length, 50);
assert.deepStrictEqual(Array.from(stored.order[0]), [2, 'asc']);
assert.strictEqual(stored.search.search, 'محمد');
assert.strictEqual(Object.prototype.hasOwnProperty.call(stored, 'columns'), false);
assert.strictEqual(Object.prototype.hasOwnProperty.call(stored, 'select'), false);
assert.ok(stored._educore.expiresAt > Date.now());
assert.strictEqual(stored._educore.rowContext.tableId, 'staffAccountsTable');
assert.strictEqual(stored._educore.rowContext.identities[0].value, '1089');

const restored = defaults.stateLoadCallback(settings);
assert.strictEqual(restored.start, 100);
assert.strictEqual(restored.length, 50);
assert.strictEqual(sessionStorage.getItem(stateKey), null, 'Return state must be consumed once.');
assert.strictEqual(
    sessionStorage.getItem(sourceStateKey),
    null,
    'Consuming the PRG destination must remove the source URL alias too.'
);
assert.strictEqual(
    windowMock.EduCoreDataTableState.restoreRow(settings),
    true,
    'The restored action context must resolve the updated row by stable identity.'
);
assert.strictEqual(rowScrolled, true, 'An off-screen updated row must be scrolled into view.');
assert.strictEqual(rowFocused, true, 'The updated row must receive temporary keyboard focus.');
assert.strictEqual(
    defaults.stateLoadCallback(settings),
    null,
    'Refreshing or revisiting again must not restore the consumed state.'
);

defaults.stateSaveCallback(settings, currentState);
const preventedSubmit = { target: samePagePost, defaultPrevented: false };
documentEvents.submit(preventedSubmit);
preventedSubmit.defaultPrevented = true;
flushMicrotasks();
assert.strictEqual(sessionStorage.getItem(stateKey), null, 'AJAX-prevented forms must not create return state.');

const getForm = makeForm({ method: 'get', action: 'staff_accounts.php?tab=academics' });
documentEvents.submit({ target: getForm, defaultPrevented: false });
flushMicrotasks();
assert.strictEqual(sessionStorage.getItem(stateKey), null, 'GET filters must not create return state.');

const otherPagePost = makeForm({ method: 'post', action: 'staff.php' });
documentEvents.submit({ target: otherPagePost, defaultPrevented: false });
flushMicrotasks();
assert.strictEqual(sessionStorage.getItem(stateKey), null, 'Leaving through another page must not create return state.');

defaults.stateSaveCallback(settings, currentState);
documentEvents.submit({ target: samePagePost, defaultPrevented: false });
flushMicrotasks();
headers = headers.concat([{ textContent: 'الحالة', getAttribute() { return null; } }]);
assert.strictEqual(
    defaults.stateLoadCallback(settings),
    null,
    'A changed table schema must invalidate the one-shot state.'
);
assert.strictEqual(sessionStorage.getItem(stateKey), null);
headers = headers.slice(0, 4);

windowMock.location.href = 'http://localhost/EduCore/admin/staff_accounts.php?tab=employees';
windowMock.location.search = '?tab=employees';
assert.notStrictEqual(
    windowMock.EduCoreDataTableState.keyFor(settings),
    stateKey,
    'Different list contexts must not share return state.'
);
windowMock.location.href = 'http://localhost/EduCore/admin/staff_accounts.php?tab=academics';
windowMock.location.search = '?tab=academics';

tableAttributes['data-datatable-return-state'] = 'false';
defaults.stateSaveCallback(settings, currentState);
assert.strictEqual(defaults.stateLoadCallback(settings), null, 'Explicit table opt-out must be honored.');
delete tableAttributes['data-datatable-return-state'];

assert.strictEqual(source.includes('data-datatable-state-reset'), false);
assert.strictEqual(source.includes('إعادة ضبط العرض'), false);

sessionStorage.setItem('unrelated-key', 'keep');
defaults.stateSaveCallback(settings, currentState);
documentEvents.submit({ target: samePagePost, defaultPrevented: false });
flushMicrotasks();
windowMock.EduCoreDataTableState.clearAll();
assert.strictEqual(sessionStorage.getItem(stateKey), null);
assert.strictEqual(sessionStorage.getItem('unrelated-key'), 'keep');

(async function testProgressiveAjaxAction() {
    let reloadCalled = false;
    let resetPagingArgument = null;
    const submitterAttributes = {};
    const submitter = {
        disabled: false,
        getAttribute(name) { return submitterAttributes[name] || null; },
        removeAttribute(name) { delete submitterAttributes[name]; },
        setAttribute(name, value) { submitterAttributes[name] = String(value); }
    };
    const ajaxFormAttributes = {
        method: 'post',
        action: 'staff_accounts.php?tab=academics',
        'data-datatable-ajax': 'true',
        'data-datatable-return-table': 'staffAccountsTable',
        'data-datatable-return-row-field': 'user_id'
    };
    const ajaxFormState = {};
    const ajaxForm = {
        elements: [{ name: 'user_id', value: '1089' }],
        method: 'post',
        closest() { return null; },
        dispatchEvent() {},
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(ajaxFormAttributes, name) ? ajaxFormAttributes[name] : null; },
        querySelector() { return submitter; },
        querySelectorAll(selector) {
            return selector === 'button[type="submit"], input[type="submit"]' ? [submitter] : [];
        },
        removeAttribute(name) { delete ajaxFormState[name]; },
        setAttribute(name, value) { ajaxFormState[name] = String(value); }
    };
    class FakeFormData {
        constructor() { this.values = new Map(); }
        set(name, value) { this.values.set(name, value); }
    }

    fakeJQuery.fn.dataTable.isDataTable = (candidate) => candidate === table;
    ajaxApi = {
        ajax: {
            reload(callback, resetPaging) {
                reloadCalled = true;
                resetPagingArgument = resetPaging;
                callback();
            }
        },
        settings() { return [settings]; }
    };
    windowMock.FormData = FakeFormData;
    windowMock.fetch = () => Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true, message: 'تم الحفظ.', summary: { total: 12 } })
    });
    rowScrolled = false;
    rowFocused = false;
    let prevented = false;
    const ajaxEvent = {
        defaultPrevented: false,
        submitter,
        preventDefault() { prevented = true; this.defaultPrevented = true; }
    };

    assert.strictEqual(windowMock.EduCoreDataTableState.submitAjax(ajaxForm, ajaxEvent), true);
    assert.strictEqual(prevented, true, 'Eligible AJAX actions must prevent the native navigation.');
    await new Promise((resolve) => setImmediate(resolve));
    assert.strictEqual(reloadCalled, true, 'A successful AJAX action must reload the DataTable data.');
    assert.strictEqual(resetPagingArgument, false, 'AJAX refresh must preserve the current page.');
    assert.strictEqual(rowFocused, true, 'AJAX refresh must restore focus to the updated row.');
    assert.strictEqual(submitter.disabled, false, 'The submit button must be re-enabled after completion.');
    assert.strictEqual(ajaxFormState['aria-busy'], undefined, 'Busy state must be cleared after completion.');

    console.log('DataTable POST, full-page row action, and progressive AJAX return tests passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
