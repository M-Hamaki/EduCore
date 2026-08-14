'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const pagePath = path.join(__dirname, '..', 'admin', 'assessment_calendar.php');
const datePickerPath = path.join(__dirname, '..', 'assets', 'js', 'air-datepicker-init.js');
const page = fs.readFileSync(pagePath, 'utf8');
const datePicker = fs.readFileSync(datePickerPath, 'utf8');
const scripts = Array.from(page.matchAll(/<script>([\s\S]*?)<\/script>/g), (match) => match[1]);
const calendarScript = scripts.find((script) => script.includes('quick-add-next-week-btn'));

assert.ok(calendarScript, 'Quick-week inline script was not found.');
assert.doesNotThrow(
    () => new Function(calendarScript),
    'Quick-week inline JavaScript must remain syntactically valid.'
);
assert.doesNotThrow(
    () => new Function(datePicker),
    'Shared Air Datepicker initialization must remain syntactically valid.'
);

console.log('assessment_calendar_quick_week_javascript_syntax: PASS');
