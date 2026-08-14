const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/lesson-sharing.js'), 'utf8');

function createSandbox(options = {}) {
    const messages = [];
    const appendedToBody = [];
    const input = {
        value: 'https://school.example.test/EduCore/shared_lesson.php?token=test',
        focus() {},
        select() {}
    };
    const message = {
        textContent: '',
        classList: {
            toggle(name, enabled) {
                messages.push({ name, enabled });
            }
        }
    };
    const modalParent = {};
    const modal = { parentElement: modalParent };
    const body = {
        appendChild(element) {
            element.parentElement = body;
            appendedToBody.push(element);
        }
    };
    const elements = {
        lessonShareUrl: input,
        lessonShareMessage: message,
        lessonShareRevokeModal: modal
    };
    const document = {
        readyState: 'loading',
        title: 'درس اختبار',
        body,
        getElementById(id) {
            return elements[id] || null;
        },
        addEventListener() {},
        execCommand() {
            return options.execCommandResult === true;
        }
    };
    const navigator = options.navigator || {};
    const window = { document, navigator };
    const sandbox = {
        window,
        document,
        navigator,
        FormData,
        console,
        Number,
        String
    };
    vm.runInNewContext(source, sandbox, { filename: 'lesson-sharing.js' });
    return { window, input, message, messages, modal, body, appendedToBody };
}

(async () => {
    const clipboardWrites = [];
    const clipboardSandbox = createSandbox({
        navigator: {
            clipboard: {
                async writeText(value) {
                    clipboardWrites.push(value);
                }
            }
        }
    });
    const clipboardResult = await clipboardSandbox.window.LessonSharing.__test.copyLink();

    const fallbackSandbox = createSandbox({ execCommandResult: true });
    const fallbackResult = await fallbackSandbox.window.LessonSharing.__test.copyLink();

    const failedSandbox = createSandbox({ execCommandResult: false });
    const failedResult = await failedSandbox.window.LessonSharing.__test.copyLink();

    const modalSandbox = createSandbox();
    const movedModal = modalSandbox.window.LessonSharing.__test.ensureModalAtBodyLevel();
    modalSandbox.window.LessonSharing.__test.ensureModalAtBodyLevel();

    const checks = {
        clipboard_api_copies_exact_link:
            clipboardResult === true
            && clipboardWrites[0] === clipboardSandbox.input.value
            && clipboardSandbox.message.textContent === 'تم نسخ رابط الدرس.',
        legacy_fallback_reports_real_success:
            fallbackResult === true
            && fallbackSandbox.message.textContent === 'تم نسخ رابط الدرس.',
        failed_copy_does_not_claim_success:
            failedResult === false
            && failedSandbox.message.textContent.includes('تعذر نسخ الرابط'),
        revoke_modal_moves_above_the_backdrop_once:
            movedModal === modalSandbox.modal
            && modalSandbox.modal.parentElement === modalSandbox.body
            && modalSandbox.appendedToBody.length === 1
    };

    for (const [name, passed] of Object.entries(checks)) {
        console.log(`${name}:${passed ? 'PASS' : 'FAIL'}`);
    }

    process.exit(Object.values(checks).every(Boolean) ? 0 : 1);
})();
