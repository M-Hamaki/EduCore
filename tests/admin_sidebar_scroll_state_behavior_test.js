'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'premium-dashboard.js'),
    'utf8'
);

function createSessionStorage(initialValues = {}) {
    const values = new Map(Object.entries(initialValues));
    return {
        entries() { return Array.from(values.entries()); },
        getItem(key) { return values.has(String(key)) ? values.get(String(key)) : null; },
        removeItem(key) { values.delete(String(key)); },
        setItem(key, value) { values.set(String(key), String(value)); }
    };
}

function runScenario({
    initialScrollValue = null,
    providedStorage = null,
    linkHrefs = ['role_dashboard.php', 'students.php', 'student_data_completeness.php']
} = {}) {
    const documentEvents = {};
    const sidebarEvents = {};
    const windowEvents = {};
    const animationFrames = [];
    let activeLinkRevealed = false;

    const sessionStorage = providedStorage || createSessionStorage();
    if (initialScrollValue !== null) {
        sessionStorage.setItem('educore:admin-sidebar-scroll-top:v1', String(initialScrollValue));
    }
    const navigationLinks = linkHrefs.map((href) => {
        const attributes = { href };
        return {
            getAttribute(name) {
                return Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null;
            },
            removeAttribute(name) { delete attributes[name]; },
            setAttribute(name, value) { attributes[name] = String(value); }
        };
    });
    const activeLink = navigationLinks[navigationLinks.length - 1];
    activeLink.getBoundingClientRect = function() { return { top: 900, bottom: 940 }; };
    activeLink.scrollIntoView = function(options) {
        activeLinkRevealed = options.block === 'nearest' && options.inline === 'nearest';
    };
    const sidebar = {
        scrollTop: 0,
        addEventListener(name, callback) { sidebarEvents[name] = callback; },
        getBoundingClientRect() { return { top: 60, bottom: 760 }; },
        querySelectorAll(selector) {
            if (selector === 'a.nav-link[href]') return navigationLinks;
            if (selector === '.nav-link.active') return [activeLink];
            if (selector === '.nav-link[aria-current]') {
                return navigationLinks.filter((link) => link.getAttribute('aria-current') !== null);
            }
            return [];
        }
    };
    const documentMock = {
        addEventListener(name, callback) {
            documentEvents[name] = documentEvents[name] || [];
            documentEvents[name].push(callback);
        },
        createElement() { return {}; },
        createTextNode(text) { return { textContent: String(text) }; },
        getElementById(id) { return id === 'adminSidebar' ? sidebar : null; },
        querySelectorAll() { return []; }
    };
    const windowMock = {
        addEventListener(name, callback) { windowEvents[name] = callback; },
        matchMedia() { return { matches: false }; },
        requestAnimationFrame(callback) { animationFrames.push(callback); },
        sessionStorage
    };
    const context = {
        console,
        document: documentMock,
        window: windowMock,
        IntersectionObserver: class {
            observe() {}
            unobserve() {}
        },
        requestAnimationFrame(callback) { animationFrames.push(callback); }
    };

    vm.runInNewContext(source, context, { filename: 'premium-dashboard.js' });
    assert.ok(documentEvents.DOMContentLoaded, 'The sidebar state must initialize after DOMContentLoaded.');
    documentEvents.DOMContentLoaded.forEach((callback) => callback());

    while (animationFrames.length) animationFrames.shift()();

    return {
        activeLinkRevealed: () => activeLinkRevealed,
        activeLink,
        sessionStorage,
        sidebar,
        sidebarEvents,
        windowEvents
    };
}

const restored = runScenario({ initialScrollValue: 325 });
const scopedScrollEntry = restored.sessionStorage.entries().find(([key]) => (
    key.startsWith('educore:admin-sidebar-scroll-top:v1:')
));
assert.strictEqual(restored.sidebar.scrollTop, 325, 'The saved sidebar position must be restored.');
assert.strictEqual(restored.activeLinkRevealed(), true, 'The active page must remain visible after restoring scroll.');
assert.strictEqual(restored.activeLink.getAttribute('aria-current'), 'page', 'The active page must be exposed to assistive technology.');
assert.ok(scopedScrollEntry, 'Legacy scroll state must migrate to a sidebar-specific key.');
assert.strictEqual(
    restored.sessionStorage.getItem('educore:admin-sidebar-scroll-top:v1'),
    null,
    'The unscoped legacy key must be removed after migration.'
);

restored.sidebar.scrollTop = 740;
restored.sidebarEvents.scroll();
assert.strictEqual(
    restored.sessionStorage.getItem(scopedScrollEntry[0]),
    '740',
    'Sidebar scrolling must update the session value.'
);

restored.sidebar.scrollTop = 815;
restored.windowEvents.pagehide();
assert.strictEqual(
    restored.sessionStorage.getItem(scopedScrollEntry[0]),
    '815',
    'The latest position must be saved before leaving the page.'
);

const firstVisit = runScenario();
assert.strictEqual(firstVisit.activeLinkRevealed(), true, 'The active link must be revealed when no saved position exists.');

const sharedStorage = createSessionStorage();
const studentAffairsSidebar = runScenario({
    providedStorage: sharedStorage,
    linkHrefs: ['role_dashboard.php', 'students.php', 'student_data_completeness.php']
});
studentAffairsSidebar.sidebar.scrollTop = 610;
studentAffairsSidebar.sidebarEvents.scroll();

const differentRoleSidebar = runScenario({
    providedStorage: sharedStorage,
    linkHrefs: ['role_dashboard.php', 'staff.php', 'finance_dashboard.php']
});
assert.strictEqual(
    differentRoleSidebar.sidebar.scrollTop,
    0,
    'A different sidebar variant must not inherit another role scroll position.'
);
assert.strictEqual(
    differentRoleSidebar.activeLinkRevealed(),
    true,
    'A different sidebar variant must reveal its own active page.'
);

process.stdout.write('admin_sidebar_scroll_state_behavior:PASS\n');
