(function (root) {
    'use strict';

    var panel;
    var stateLoadedForLessonId = 0;

    function element(id) {
        return document.getElementById(id);
    }

    function getCurrentLessonId() {
        var panelId = panel ? Number(panel.dataset.lessonId || 0) : 0;
        if (panelId > 0) return panelId;
        if (typeof root.currentLessonId !== 'undefined' && Number(root.currentLessonId) > 0) {
            return Number(root.currentLessonId);
        }
        try {
            if (typeof currentLessonId !== 'undefined' && Number(currentLessonId) > 0) {
                return Number(currentLessonId);
            }
        } catch (error) {
            // A missing global lesson identifier is handled by the caller.
        }
        return 0;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function setLoading(loading) {
        if (!panel) return;
        panel.classList.toggle('is-loading', loading);
        element('lessonShareCreateBtn').disabled = loading;
        element('lessonShareRevokeBtn').disabled = loading;
    }

    function showMessage(message, success) {
        var target = element('lessonShareMessage');
        if (!target) return;
        target.textContent = message || '';
        target.classList.toggle('d-none', !message);
        target.classList.toggle('text-success', !!success);
        target.classList.toggle('text-danger', !success);
    }

    function ensureModalAtBodyLevel() {
        var modalElement = element('lessonShareRevokeModal');
        if (modalElement && modalElement.parentElement !== document.body) {
            document.body.appendChild(modalElement);
        }
        return modalElement;
    }

    function renderState(state) {
        var enabled = !!state.enabled && !!state.share_url;
        var badge = element('lessonShareStatusBadge');
        var createButton = element('lessonShareCreateBtn');
        var linkArea = element('lessonShareLinkArea');
        var revokeButton = element('lessonShareRevokeBtn');
        var urlInput = element('lessonShareUrl');
        var openButton = element('lessonShareOpenBtn');

        badge.textContent = enabled ? 'مشارك' : 'غير مشارك';
        badge.classList.toggle('text-bg-success', enabled);
        badge.classList.toggle('text-bg-secondary', !enabled);
        createButton.querySelector('span').textContent = enabled ? 'تجديد رابط المشاركة' : 'إنشاء رابط مشاركة';
        createButton.querySelector('i').className = enabled ? 'fas fa-rotate me-1' : 'fas fa-link me-1';
        linkArea.classList.toggle('d-none', !enabled);
        revokeButton.classList.toggle('d-none', !enabled);

        if (enabled) {
            urlInput.value = state.share_url;
            openButton.href = state.share_url;
        } else {
            urlInput.value = '';
            openButton.href = '#';
        }
    }

    async function request(action) {
        var lessonId = getCurrentLessonId();
        if (!lessonId) {
            showMessage('يجب توليد الدرس وحفظه أولًا قبل إنشاء رابط المشاركة.', false);
            return null;
        }

        setLoading(true);
        showMessage('', true);
        try {
            var formData = new FormData();
            formData.append('lesson_id', String(lessonId));
            formData.append('action', action);
            formData.append('csrf_token', csrfToken());

            var response = await fetch('ajax/lesson_share.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            var result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'تعذر تحديث رابط المشاركة');
            }

            stateLoadedForLessonId = lessonId;
            panel.dataset.lessonId = String(lessonId);
            renderState(result);
            if (result.message) showMessage(result.message, true);
            return result;
        } catch (error) {
            showMessage(error.message || 'تعذر الاتصال بالخادم', false);
            return null;
        } finally {
            setLoading(false);
        }
    }

    async function copyLink() {
        var input = element('lessonShareUrl');
        if (!input || !input.value) return;

        var copied = false;
        try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                await navigator.clipboard.writeText(input.value);
                copied = true;
            }
        } catch (error) {
            copied = false;
        }

        if (!copied) {
            input.focus();
            input.select();
            try {
                copied = typeof document.execCommand === 'function'
                    && document.execCommand('copy') === true;
            } catch (error) {
                copied = false;
            }
        }

        if (!copied) {
            showMessage('تعذر نسخ الرابط تلقائيًا. حدده وانسخه يدويًا.', false);
            return false;
        }

        showMessage('تم نسخ رابط الدرس.', true);
        return true;
    }

    async function nativeShare() {
        var input = element('lessonShareUrl');
        if (!input || !input.value) return;
        if (typeof navigator.share === 'function') {
            try {
                await navigator.share({ title: document.title, url: input.value });
                return;
            } catch (error) {
                if (error && error.name === 'AbortError') return;
            }
        }
        await copyLink();
    }

    async function refresh() {
        var lessonId = getCurrentLessonId();
        if (!lessonId || stateLoadedForLessonId === lessonId) return;
        await request('status');
    }

    async function shareCurrentLesson() {
        var lessonId = getCurrentLessonId();
        if (!lessonId) {
            showMessage('يجب توليد الدرس وحفظه أولًا قبل إنشاء رابط المشاركة.', false);
            if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        var input = element('lessonShareUrl');
        if (!input || !input.value) {
            var state = await request('status');
            if (!state) return;
            if (!state.enabled || !state.share_url) {
                state = await request('enable');
            }
            if (!state || !state.share_url) return;
        }

        if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        await nativeShare();
    }

    function init() {
        panel = element('lessonSharePanel');
        if (!panel) return;

        element('lessonShareCreateBtn').addEventListener('click', function () {
            request('enable');
        });
        element('lessonShareCopyBtn').addEventListener('click', copyLink);
        element('lessonShareNativeBtn').addEventListener('click', nativeShare);
        element('lessonShareRevokeForm').addEventListener('submit', async function (event) {
            event.preventDefault();
            var result = await request('revoke');
            if (result && root.bootstrap) {
                var modalElement = ensureModalAtBodyLevel();
                var modal = root.bootstrap.Modal.getOrCreateInstance(modalElement);
                if (modal) modal.hide();
            }
        });
        ensureModalAtBodyLevel();
        panel.addEventListener('pointerenter', refresh, { once: false });

        var results = element('resultsSection');
        if (results && root.MutationObserver) {
            new MutationObserver(function () {
                if (results.classList.contains('show')) refresh();
            }).observe(results, { attributes: true, attributeFilter: ['class'] });
        }

        refresh();
    }

    root.LessonSharing = {
        init: init,
        refresh: refresh,
        share: shareCurrentLesson,
        __test: {
            copyLink: copyLink,
            nativeShare: nativeShare,
            ensureModalAtBodyLevel: ensureModalAtBodyLevel
        }
    };
    root.shareLesson = shareCurrentLesson;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
