(function () {
    'use strict';

    const DRAFT_MAX_AGE = 7 * 24 * 60 * 60 * 1000;
    const SAVE_DELAY = 500;
    const forms = Array.from(document.querySelectorAll('form')).filter(function (form) {
        if ((form.method || '').toLowerCase() !== 'post' || form.dataset.noFormSafety === 'true') return false;
        if (form.querySelector('input[type="file"]') && form.querySelectorAll('input:not([type="hidden"]), select, textarea').length < 2) return false;
        return form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea').length >= 3;
    });

    if (!forms.length) return;

    let pageSubmitting = false;

    function controls(form) {
        return Array.from(form.elements).filter(function (field) {
            if (!field.name || field.disabled || field.type === 'hidden' || field.type === 'password' || field.type === 'file') return false;
            return !['csrf_token', 'password', 'password_confirmation'].includes(field.name)
                && !['submit', 'button', 'reset'].includes(field.type);
        });
    }

    function values(form) {
        const result = {};
        controls(form).forEach(function (field) {
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
            if (Object.prototype.hasOwnProperty.call(result, field.name)) {
                if (!Array.isArray(result[field.name])) result[field.name] = [result[field.name]];
                result[field.name].push(field.value);
            } else {
                result[field.name] = field.value;
            }
        });
        return result;
    }

    function stable(value) {
        const ordered = {};
        Object.keys(value).sort().forEach(function (key) { ordered[key] = value[key]; });
        return JSON.stringify(ordered);
    }

    function draftKey(form, index) {
        const identity = form.id || form.getAttribute('action') || ('form-' + index);
        const record = form.querySelector('[name="user_id"], [name="id"]');
        const scope = form.dataset.draftScope || (record ? record.value : 'new');
        return 'educore:draft:' + location.pathname + ':' + identity + ':' + index + ':' + scope;
    }

    function labelFor(field) {
        if (field.id) {
            const label = field.form.querySelector('label[for="' + CSS.escape(field.id) + '"]');
            if (label) return label.textContent.trim();
        }
        return field.getAttribute('aria-label') || field.placeholder || field.name;
    }

    function applyDraft(form, data) {
        controls(form).forEach(function (field) {
            if (!Object.prototype.hasOwnProperty.call(data, field.name)) return;
            const expected = Array.isArray(data[field.name]) ? data[field.name].map(String) : [String(data[field.name])];
            if (field.type === 'checkbox' || field.type === 'radio') {
                field.checked = expected.includes(String(field.value));
            } else {
                field.value = expected[0] ?? '';
            }
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function showDraftBar(form, draft, key) {
        const bar = document.createElement('div');
        bar.className = 'alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 form-safety-draft';
        bar.innerHTML = '<span><i class="fas fa-file-alt me-2"></i>توجد مسودة غير محفوظة لهذا النموذج.</span>'
            + '<span class="d-flex gap-2"><button type="button" class="btn btn-sm btn-warning restore-draft">استعادة</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary discard-draft">تجاهل</button></span>';
        form.prepend(bar);
        bar.querySelector('.restore-draft').addEventListener('click', function () {
            applyDraft(form, draft.data);
            form.dataset.formDirty = 'true';
            bar.remove();
        });
        bar.querySelector('.discard-draft').addEventListener('click', function () {
            sessionStorage.removeItem(key);
            bar.remove();
        });
    }

    forms.forEach(function (form, index) {
        const key = draftKey(form, index);
        const baseline = values(form);
        let timer = null;

        try {
            const stored = JSON.parse(sessionStorage.getItem(key) || 'null');
            if (stored && stored.savedAt && Date.now() - stored.savedAt <= DRAFT_MAX_AGE) {
                if (stable(stored.data) === stable(baseline)) {
                    sessionStorage.removeItem(key);
                } else {
                    showDraftBar(form, stored, key);
                }
            } else if (stored) {
                sessionStorage.removeItem(key);
            }
        } catch (e) {
            sessionStorage.removeItem(key);
        }

        function markDirty() {
            form.dataset.formDirty = stable(values(form)) === stable(baseline) ? 'false' : 'true';
            clearTimeout(timer);
            timer = setTimeout(function () {
                if (form.dataset.formDirty === 'true') {
                    try {
                        sessionStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), data: values(form) }));
                    } catch (e) {
                        // بعض المتصفحات تمنع التخزين؛ يستمر تحذير المغادرة دون المسودة.
                    }
                }
            }, SAVE_DELAY);
        }

        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('reset', function () {
            setTimeout(function () {
                form.dataset.formDirty = 'false';
                sessionStorage.removeItem(key);
            }, 0);
        });

        form.addEventListener('submit', function () {
            pageSubmitting = true;
            clearTimeout(timer);
            sessionStorage.removeItem(key);
            form.dataset.formDirty = 'false';
        }, true);
    });

    window.addEventListener('beforeunload', function (event) {
        if (pageSubmitting || !forms.some(function (form) { return form.dataset.formDirty === 'true'; })) return;
        event.preventDefault();
        event.returnValue = '';
    });
})();
