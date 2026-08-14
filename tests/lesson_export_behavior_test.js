const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/lesson-export.js'), 'utf8');
const sandbox = {
    window: {
        LessonExportConfig: {
            endpoint: 'shared_lesson_export.php',
            publicToken: 'public-test-token'
        }
    },
    Set,
    Array,
    Number,
    String,
    Object,
    console
};

vm.runInNewContext(source, sandbox, { filename: 'lesson-export.js' });

const api = sandbox.window.LessonExport.__test;
const generationMappings = {
    lessonPlanContent: 'lesson_plan',
    questionBankContent: 'question_bank',
    visualMaterialsContent: 'visual_materials',
    classActivitiesContent: 'class_activities',
    mindMapsContent: 'mind_maps',
    lessonSummaryContent: 'lesson_summary',
    educationalStoriesContent: 'educational_stories',
    customContentArea: 'custom_content',
    examPreviewContent: 'exam'
};
const archiveMappings = {
    'lesson-plan': 'lesson_plan',
    'question-bank': 'question_bank',
    'visual-materials': 'visual_materials',
    'class-activities': 'class_activities',
    'mind-maps': 'mind_maps',
    'lesson-summary': 'lesson_summary',
    'educational-stories': 'educational_stories',
    'custom-content': 'custom_content',
    'exam-preview': 'exam'
};
const aggregatePanel = { remove() { this.removed = true; } };
const duplicatePanel = { remove() { this.removed = true; } };
api.removeDuplicateAggregatePanels({
    querySelector(selector) {
        return selector === '.sub-tab-content[id$="-all"]' ? aggregatePanel : null;
    },
    querySelectorAll() {
        return [aggregatePanel, duplicatePanel];
    }
});
const checks = {
    generation_tab_maps_to_one_key:
        api.normalizeKey('lessonPlanContent') === 'lesson_plan',
    archive_tab_maps_to_same_key:
        api.normalizeKey('lesson-plan') === 'lesson_plan',
    every_generation_tab_has_exact_key:
        Object.entries(generationMappings).every(([container, key]) => api.normalizeKey(container) === key),
    every_archive_tab_has_exact_key:
        Object.entries(archiveMappings).every(([container, key]) => api.normalizeKey(container) === key),
    repeated_selected_values_are_unique:
        JSON.stringify(api.uniqueKeys([
            'lessonPlanContent',
            'lesson-plan',
            'questionBankContent',
            'question-bank'
        ])) === JSON.stringify(['lesson_plan', 'question_bank']),
    repeated_key_is_removed:
        api.dedupeSections([
            { key: 'lesson_plan', text: 'خطة فريدة', html: '<section>one</section>' },
            { key: 'lesson_plan', text: 'نسخة أخرى', html: '<section>two</section>' }
        ]).length === 1,
    repeated_content_is_removed:
        api.dedupeSections([
            { key: 'lesson_plan', text: 'المحتوى نفسه', html: '<section>one</section>' },
            { key: 'question_bank', text: 'المحتوى نفسه', html: '<section>two</section>' }
        ]).length === 1,
    aggregate_panel_prevents_inner_tab_duplication:
        aggregatePanel.removed !== true && duplicatePanel.removed === true,
    distinct_sections_are_preserved:
        api.dedupeSections([
            { key: 'lesson_plan', text: 'خطة', html: '<section>one</section>' },
            { key: 'question_bank', text: 'أسئلة', html: '<section>two</section>' },
            { key: 'custom_content', text: 'مخصص', html: '<section>three</section>' }
        ]).length === 3,
    public_export_uses_token_endpoint_without_lesson_id:
        api.exportTransport().lessonId === 0
        && api.exportTransport().endpoint === 'shared_lesson_export.php'
        && api.exportTransport().publicToken === 'public-test-token',
    general_print_export_is_available:
        typeof sandbox.window.exportAllToPrint === 'function'
};

for (const [name, passed] of Object.entries(checks)) {
    console.log(`${name}:${passed ? 'PASS' : 'FAIL'}`);
}

process.exit(Object.values(checks).every(Boolean) ? 0 : 1);
