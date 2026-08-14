(function () {
    'use strict';

    const toast = document.getElementById('undoToast');
    const body = document.getElementById('undoToastBody');
    if (!toast || !body || typeof bootstrap === 'undefined') return;

    let processing = false;
    let currentUndoId = null;
    const csrf = function () {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    };
    const setVariant = function (variant, darkText) {
        toast.className = toast.className.replace(/bg-\w+/g, '').replace(/text-(?:white|dark)/g, '')
            + ' bg-' + variant + ' text-' + (darkText ? 'dark' : 'white');
    };
    const showText = function (message, variant, icon, darkText) {
        setVariant(variant, darkText);
        body.replaceChildren();
        const symbol = document.createElement('i');
        symbol.className = 'fas ' + icon + ' me-2';
        body.append(symbol, document.createTextNode(message));
        bootstrap.Toast.getOrCreateInstance(toast).show();
    };

    const executeUndo = function () {
        if (processing || !currentUndoId) return;
        processing = true;
        const token = csrf();
        fetch('../api/undo.php?action=undo', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token},
            body: 'csrf_token=' + encodeURIComponent(token) + '&undo_id=' + encodeURIComponent(currentUndoId)
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (data.success) {
                showText(data.message + (data.description ? ' - ' + data.description : ''), 'success', 'fa-undo', false);
                window.setTimeout(function () { location.reload(); }, 1500);
            } else {
                showText(data.message || 'لا توجد عملية قابلة للتراجع', 'warning', 'fa-info-circle', true);
            }
        }).catch(function () {
            showText('حدث خطأ في الاتصال', 'danger', 'fa-exclamation-circle', false);
        }).finally(function () {
            processing = false;
        });
    };

    document.addEventListener('keydown', function (event) {
        if (!(event.ctrlKey || event.metaKey) || event.shiftKey || !['z', 'Z', 'ئ'].includes(event.key)) return;
        const target = event.target;
        const tag = (target.tagName || '').toLowerCase();
        if (['input', 'textarea', 'select'].includes(tag) || target.isContentEditable) return;
        event.preventDefault();
        executeUndo();
    });

    window.checkUndoState = function (forceShow) {
        fetch('../api/undo.php?action=check', {credentials: 'same-origin'})
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success || !data.has_undo || !data.id || Number(data.expires_in) <= 0) return;
                currentUndoId = Number(data.id);

                setVariant('primary', false);
                body.replaceChildren();
                const label = document.createElement('span');
                label.className = 'me-2';
                const actionLabels = {
                    insert: {prefix: 'إضافة', completed: 'تمت إضافة'},
                    update: {prefix: 'تعديل', completed: 'تم تعديل'},
                    delete: {prefix: 'حذف', completed: 'تم حذف'}
                };
                const description = String(data.description || '').trim();
                const actionLabel = actionLabels[data.action_type];
                label.textContent = actionLabel && description.startsWith(actionLabel.prefix)
                    ? actionLabel.completed + description.slice(actionLabel.prefix.length)
                    : (actionLabel ? actionLabel.completed + ': ' : 'تم تنفيذ العملية: ') + description;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-sm btn-light';
                button.textContent = 'تراجع';
                button.addEventListener('click', executeUndo);
                body.append(label, button);
                bootstrap.Toast.getOrCreateInstance(toast).show();
            }).catch(function () {});
    };

    window.checkUndoState();
})();
