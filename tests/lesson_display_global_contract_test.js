'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/lesson_display.js'), 'utf8');
const context = {
    window: {},
    document: {},
    navigator: {},
    URL,
    Set,
    Array,
    String,
    console,
    setTimeout,
    clearTimeout
};

vm.createContext(context);
vm.runInContext(source, context, { filename: 'lesson_display.js' });

const checks = {
    safe_icon_is_explicitly_global: typeof context.window.safeIconClass === 'function',
    safe_color_is_explicitly_global: typeof context.window.safeColor === 'function',
    generated_html_sanitizer_is_explicitly_global:
        typeof context.window.sanitizeGeneratedHtml === 'function',
    unsafe_icon_falls_back: context.window.safeIconClass('bad" onclick="x') === 'fa-file-alt',
    unsafe_color_falls_back: context.window.safeColor('red;position:fixed') === '#10b981'
};

for (const [name, passed] of Object.entries(checks)) {
    process.stdout.write(`${name}:${passed ? 'PASS' : 'FAIL'}\n`);
}

process.exit(Object.values(checks).includes(false) ? 1 : 0);
