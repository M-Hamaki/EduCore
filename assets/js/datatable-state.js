(function (window, document) {
    'use strict';

    var RETURN_PREFIX = 'educore:datatable-return:v2:';
    var LEGACY_PREFIX = 'educore:datatable-state:v1:';
    var FALLBACK_SCOPE_KEY = RETURN_PREFIX + 'session-scope';
    var RETURN_TTL_MS = 2 * 60 * 1000;
    var ACTION_JOURNEY_TTL_MS = 30 * 60 * 1000;
    var liveStates = {};
    var pendingRowContexts = {};
    var lastActionRow = null;
    var installed = false;
    var ROW_ID_ATTRIBUTES = [
        'data-datatable-row-key',
        'data-user-id',
        'data-staff-id',
        'data-student-id',
        'data-teacher-id',
        'data-employee-id',
        'data-account-id',
        'data-record-id',
        'data-item-id',
        'data-row-id',
        'data-id'
    ];
    var ROW_FIELD_NAMES = [
        'user_id',
        'staff_id',
        'student_id',
        'teacher_id',
        'employee_id',
        'account_id',
        'record_id',
        'item_id',
        'id'
    ];
    var ROW_HIGHLIGHT_CLASS = 'datatable-return-row-highlight';

    function hash(value) {
        var result = 2166136261;
        var text = String(value || '');

        for (var index = 0; index < text.length; index++) {
            result ^= text.charCodeAt(index);
            result += (result << 1) + (result << 4) + (result << 7) + (result << 8) + (result << 24);
        }

        return ('00000000' + (result >>> 0).toString(16)).slice(-8);
    }

    function storage() {
        try {
            var sessionStore = window.sessionStorage;
            var probeKey = RETURN_PREFIX + 'probe';
            sessionStore.setItem(probeKey, '1');
            sessionStore.removeItem(probeKey);
            return sessionStore;
        } catch (error) {
            return null;
        }
    }

    function portalName() {
        var body = document.body;
        if (!body) return 'unknown';
        if (body.classList.contains('admin-page')) return 'admin';
        if (body.classList.contains('teacher-page')) return 'teacher';
        if (body.classList.contains('student-page')) return 'student';
        if (body.classList.contains('specialist-page')) return 'specialist';
        return 'shared';
    }

    function sessionScope(sessionStore) {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
        if (csrfToken) {
            return hash(portalName() + '|csrf|' + csrfToken);
        }

        var fallback = sessionStore.getItem(FALLBACK_SCOPE_KEY);
        if (!fallback) {
            fallback = hash(
                portalName() + '|' + Date.now() + '|' + Math.random() + '|' +
                String(window.location && window.location.pathname || '')
            );
            sessionStore.setItem(FALLBACK_SCOPE_KEY, fallback);
        }

        return hash(portalName() + '|fallback|' + fallback);
    }

    function scopePrefix() {
        var sessionStore = storage();
        if (!sessionStore) return null;
        return RETURN_PREFIX + sessionScope(sessionStore) + ':';
    }

    function normalizedContext(locationLike) {
        var targetLocation = locationLike || window.location;
        var pathname = targetLocation && targetLocation.pathname
            ? targetLocation.pathname
            : '/';
        var search = targetLocation && targetLocation.search
            ? targetLocation.search
            : '';
        var ignored = {
            csrf_token: true,
            draw: true,
            start: true,
            length: true,
            _: true
        };
        var pairs = [];

        try {
            var params = new URLSearchParams(search);
            params.forEach(function (value, key) {
                if (!ignored[key]) pairs.push([key, value]);
            });
            pairs.sort(function (left, right) {
                var leftValue = left[0] + '\u0000' + left[1];
                var rightValue = right[0] + '\u0000' + right[1];
                return leftValue < rightValue ? -1 : (leftValue > rightValue ? 1 : 0);
            });
        } catch (error) {
            return pathname;
        }

        return pathname + (pairs.length
            ? '?' + pairs.map(function (pair) {
                return encodeURIComponent(pair[0]) + '=' + encodeURIComponent(pair[1]);
            }).join('&')
            : '');
    }

    function tableElementIdentifier(table, settings) {
        if (!table) return 'unknown-table';

        var explicitKey = table.getAttribute('data-table-state-key');
        if (explicitKey) return explicitKey;
        if (table.id) return table.id;
        if (settings && settings.sTableId) return settings.sTableId;

        var tables = Array.prototype.slice.call(document.querySelectorAll('table'));
        var position = tables.indexOf(table);
        return 'table-' + (position >= 0 ? position : 'unknown');
    }

    function tableIdentifier(settings) {
        var table = settings && settings.nTable ? settings.nTable : null;
        return tableElementIdentifier(table, settings);
    }

    function schemaFingerprint(settings) {
        var table = settings && settings.nTable ? settings.nTable : null;
        if (!table) return 'no-schema';

        var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
        var signature = headers.map(function (header, index) {
            return header.getAttribute('data-name')
                || header.getAttribute('data-column')
                || String(header.textContent || '').replace(/\s+/g, ' ').trim()
                || ('column-' + index);
        }).join('|');

        return hash(headers.length + '|' + signature);
    }

    function stateKeyFor(tableId, context) {
        var prefix = scopePrefix();
        if (!prefix) return null;
        return prefix + hash(context) + ':' + encodeURIComponent(tableId);
    }

    function stateKey(settings) {
        return stateKeyFor(tableIdentifier(settings), normalizedContext());
    }

    function falseAttribute(element, name) {
        if (!element || !element.getAttribute) return false;
        var value = String(element.getAttribute(name) || '').toLowerCase();
        return value === 'false' || value === '0' || value === 'off' || value === 'no';
    }

    function trueAttribute(element, name) {
        if (!element || !element.getAttribute) return false;
        var value = String(element.getAttribute(name) || '').toLowerCase();
        return value === 'true' || value === '1' || value === 'on' || value === 'yes';
    }

    function cleanIdentityValue(value) {
        value = String(value == null ? '' : value).trim();
        return value && value.length <= 200 ? value : '';
    }

    function appendIdentity(identities, kind, name, value) {
        value = cleanIdentityValue(value);
        if (!value) return;
        var duplicate = identities.some(function (identity) {
            return identity.kind === kind && identity.name === name && identity.value === value;
        });
        if (!duplicate && identities.length < 20) {
            identities.push({ kind: kind, name: name, value: value });
        }
    }

    function linkQueryIdentities(link) {
        var identities = [];
        if (!link || !link.getAttribute) return identities;

        var href = String(link.getAttribute('href') || '').trim();
        if (!href || href.charAt(0) === '#' || /^(?:javascript|mailto|tel):/i.test(href)) {
            return identities;
        }

        try {
            var currentUrl = new URL(window.location.href);
            var linkUrl = new URL(href, currentUrl.href);
            if (linkUrl.origin !== currentUrl.origin) return identities;

            ROW_FIELD_NAMES.forEach(function (fieldName) {
                linkUrl.searchParams.getAll(fieldName).forEach(function (value) {
                    appendIdentity(identities, 'query', fieldName, value);
                });
            });
        } catch (error) {
            return identities;
        }

        return identities;
    }

    function collectRowIdentities(row, preferredTarget) {
        var identities = [];
        if (!row || !row.querySelectorAll) return identities;

        var preferredLink = preferredTarget && preferredTarget.closest
            ? preferredTarget.closest('a[href]')
            : null;
        linkQueryIdentities(preferredLink).forEach(function (identity) {
            appendIdentity(identities, identity.kind, identity.name, identity.value);
        });

        ROW_ID_ATTRIBUTES.forEach(function (attribute) {
            var preferredOwner = preferredTarget && preferredTarget.closest
                ? preferredTarget.closest('[' + attribute + ']')
                : null;
            if (preferredOwner) {
                appendIdentity(identities, 'attribute', attribute, preferredOwner.getAttribute(attribute));
            }
        });

        var rowId = cleanIdentityValue(row.id);
        if (rowId && rowId.indexOf('DataTables_Table_') !== 0) {
            appendIdentity(identities, 'row-id', 'id', rowId);
        }

        ROW_ID_ATTRIBUTES.forEach(function (attribute) {
            appendIdentity(identities, 'attribute', attribute, row.getAttribute(attribute));
            Array.prototype.slice.call(row.querySelectorAll('[' + attribute + ']')).forEach(function (element) {
                appendIdentity(identities, 'attribute', attribute, element.getAttribute(attribute));
            });
        });

        Array.prototype.slice.call(row.querySelectorAll('a[href]')).forEach(function (link) {
            linkQueryIdentities(link).forEach(function (identity) {
                appendIdentity(identities, identity.kind, identity.name, identity.value);
            });
        });

        Array.prototype.slice.call(row.querySelectorAll('input[value], button[value], option[value]')).forEach(function (element) {
            var fieldName = cleanIdentityValue(element.getAttribute('name'));
            var isSelection = element.classList && element.classList.contains('row-select-cb');
            if (isSelection || ROW_FIELD_NAMES.indexOf(fieldName) !== -1) {
                appendIdentity(identities, 'field', fieldName || 'row-select', element.value);
            }
        });

        return identities;
    }

    function sanitizeRowContext(context) {
        if (!context || typeof context !== 'object') return null;
        var tableId = cleanIdentityValue(context.tableId);
        var identities = Array.isArray(context.identities) ? context.identities : [];
        var safeIdentities = [];

        identities.forEach(function (identity) {
            if (!identity || typeof identity !== 'object') return;
            var kind = String(identity.kind || '');
            var name = cleanIdentityValue(identity.name);
            if (['row-id', 'attribute', 'field', 'query'].indexOf(kind) === -1 || !name) return;
            if (kind === 'attribute' && ROW_ID_ATTRIBUTES.indexOf(name) === -1) return;
            if (kind === 'field' && name !== 'row-select' && ROW_FIELD_NAMES.indexOf(name) === -1) return;
            if (kind === 'query' && ROW_FIELD_NAMES.indexOf(name) === -1) return;
            appendIdentity(safeIdentities, kind, name, identity.value);
        });

        return tableId && safeIdentities.length
            ? { tableId: tableId, identities: safeIdentities }
            : null;
    }

    function rememberActionRow(target) {
        var row = target && target.closest ? target.closest('tbody tr') : null;
        var table = row && row.closest ? row.closest('table') : null;
        if (!row || !table) return null;

        var context = sanitizeRowContext({
            tableId: tableElementIdentifier(table, null),
            identities: collectRowIdentities(row, target)
        });
        if (!context) return null;

        context.expiresAt = Date.now() + RETURN_TTL_MS;
        lastActionRow = context;
        return context;
    }

    function formTargetIdentity(form) {
        if (!form || !form.getAttribute) return null;
        var explicitKey = cleanIdentityValue(form.getAttribute('data-datatable-return-row-key'));
        if (explicitKey) {
            return { kind: 'attribute', name: 'data-datatable-row-key', value: explicitKey };
        }

        var requestedField = cleanIdentityValue(form.getAttribute('data-datatable-return-row-field'));
        var fields = form.elements ? Array.prototype.slice.call(form.elements) : [];
        var allowedNames = requestedField ? [requestedField] : ROW_FIELD_NAMES;
        for (var index = 0; index < allowedNames.length; index++) {
            var fieldName = allowedNames[index];
            var field = fields.find(function (candidate) {
                return cleanIdentityValue(candidate && candidate.name) === fieldName;
            });
            var value = field ? cleanIdentityValue(field.value) : '';
            if (value) return { kind: 'field', name: fieldName, value: value };
        }

        return null;
    }

    function rowContextForForm(form) {
        var targetIdentity = formTargetIdentity(form);
        var explicitTable = cleanIdentityValue(form && form.getAttribute
            ? form.getAttribute('data-datatable-return-table')
            : '');
        var activeContext = lastActionRow && Number(lastActionRow.expiresAt) >= Date.now()
            ? sanitizeRowContext(lastActionRow)
            : null;

        if (activeContext && !targetIdentity && !trueAttribute(form, 'data-datatable-return-last-row')) {
            activeContext = null;
        }
        if (activeContext && explicitTable && activeContext.tableId !== explicitTable) {
            activeContext = null;
        }
        if (activeContext && targetIdentity) {
            var matchesTarget = activeContext.identities.some(function (identity) {
                return identity.value === targetIdentity.value;
            });
            if (!matchesTarget) activeContext = null;
        }
        if (activeContext) return activeContext;
        if (!targetIdentity) return null;

        var tableId = explicitTable;
        if (!tableId) {
            var liveTableIds = Object.keys(liveStates).map(function (key) {
                return liveStates[key] && liveStates[key].tableId;
            }).filter(function (value, index, allValues) {
                return value && allValues.indexOf(value) === index;
            });
            if (liveTableIds.length === 1) tableId = liveTableIds[0];
        }

        return sanitizeRowContext({ tableId: tableId, identities: [targetIdentity] });
    }

    function identityMatchesRow(row, identity) {
        if (!row || !identity) return false;
        if (identity.kind === 'row-id') return cleanIdentityValue(row.id) === identity.value;

        if (identity.kind === 'attribute') {
            if (cleanIdentityValue(row.getAttribute(identity.name)) === identity.value) return true;
            return Array.prototype.slice.call(row.querySelectorAll('[' + identity.name + ']')).some(function (element) {
                return cleanIdentityValue(element.getAttribute(identity.name)) === identity.value;
            });
        }

        if (identity.kind === 'query') {
            return Array.prototype.slice.call(row.querySelectorAll('a[href]')).some(function (link) {
                return linkQueryIdentities(link).some(function (candidate) {
                    return candidate.name === identity.name && candidate.value === identity.value;
                });
            });
        }

        return Array.prototype.slice.call(row.querySelectorAll('input[value], button[value], option[value]')).some(function (element) {
            var fieldName = cleanIdentityValue(element.getAttribute('name'));
            var isSelection = element.classList && element.classList.contains('row-select-cb');
            var nameMatches = identity.name === 'row-select'
                ? isSelection
                : fieldName === identity.name || (isSelection && ROW_FIELD_NAMES.indexOf(identity.name) !== -1);
            return nameMatches && cleanIdentityValue(element.value) === identity.value;
        });
    }

    function findContextRow(table, context) {
        if (!table || !context) return null;
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
        for (var identityIndex = 0; identityIndex < context.identities.length; identityIndex++) {
            for (var rowIndex = 0; rowIndex < rows.length; rowIndex++) {
                if (identityMatchesRow(rows[rowIndex], context.identities[identityIndex])) {
                    return rows[rowIndex];
                }
            }
        }
        return null;
    }

    function rowNeedsScroll(row) {
        if (!row || !row.getBoundingClientRect) return true;
        var rect = row.getBoundingClientRect();
        var viewportHeight = window.innerHeight || (document.documentElement && document.documentElement.clientHeight) || 0;
        var topBoundary = 80;
        return rect.top < topBoundary || rect.bottom > viewportHeight;
    }

    function announceRowReturn() {
        var region = document.getElementById('datatableReturnAnnouncement');
        if (!region) {
            region = document.createElement('div');
            region.id = 'datatableReturnAnnouncement';
            region.className = 'visually-hidden';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }
        region.textContent = '';
        defer(function () {
            region.textContent = 'تم الرجوع إلى السجل الذي تم تحديثه.';
        });
    }

    function presentReturnedRow(row) {
        var reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var previousTabindex = row.getAttribute('tabindex');
        var cleaned = false;
        var cleanup = function () {
            if (cleaned) return;
            cleaned = true;
            row.classList.remove(ROW_HIGHLIGHT_CLASS);
            if (previousTabindex === null) row.removeAttribute('tabindex');
            else row.setAttribute('tabindex', previousTabindex);
        };

        row.classList.remove(ROW_HIGHLIGHT_CLASS);
        void row.offsetWidth;
        row.classList.add(ROW_HIGHLIGHT_CLASS);
        row.setAttribute('tabindex', '-1');
        if (rowNeedsScroll(row) && row.scrollIntoView) {
            row.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
        }
        if (row.focus) {
            try { row.focus({ preventScroll: true }); } catch (error) { row.focus(); }
        }
        announceRowReturn();
        row.addEventListener('animationend', cleanup, { once: true });
        window.setTimeout(cleanup, reduceMotion ? 1800 : 4200);
    }

    function restoreRowContext(settings) {
        var table = settings && settings.nTable ? settings.nTable : null;
        var tableId = tableIdentifier(settings);
        var pending = pendingRowContexts[tableId];
        if (!table || !pending) return false;

        pending.attempts = Number(pending.attempts || 0) + 1;
        var row = findContextRow(table, pending);
        if (!row) {
            if (pending.attempts >= 4) delete pendingRowContexts[tableId];
            return false;
        }

        delete pendingRowContexts[tableId];
        var schedule = window.requestAnimationFrame || function (callback) { window.setTimeout(callback, 0); };
        schedule(function () { schedule(function () { presentReturnedRow(row); }); });
        return true;
    }

    function dataTableApi(tableId) {
        var $ = window.jQuery;
        var table = tableId ? document.getElementById(tableId) : null;
        if (!table || !$ || !$.fn || !$.fn.dataTable || !$.fn.dataTable.isDataTable) return null;
        if (!$.fn.dataTable.isDataTable(table)) return null;

        try {
            return $(table).DataTable();
        } catch (error) {
            return null;
        }
    }

    function setAjaxBusy(form, submitter, busy) {
        if (!form) return;
        if (busy) form.setAttribute('aria-busy', 'true');
        else form.removeAttribute('aria-busy');
        Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], input[type="submit"]')).forEach(function (control) {
            if (busy) {
                control.setAttribute('data-datatable-was-disabled', control.disabled ? '1' : '0');
                control.disabled = true;
            } else {
                if (control.getAttribute('data-datatable-was-disabled') === '0') control.disabled = false;
                control.removeAttribute('data-datatable-was-disabled');
            }
        });
        if (!busy && submitter && submitter.getAttribute('data-datatable-was-disabled') === '0') {
            submitter.disabled = false;
            submitter.removeAttribute('data-datatable-was-disabled');
        }
    }

    function removeAjaxFeedback(scope) {
        if (!scope || !scope.querySelectorAll) return;
        Array.prototype.slice.call(scope.querySelectorAll('[data-datatable-ajax-feedback]')).forEach(function (element) {
            element.remove();
        });
    }

    function createFeedback(type, message) {
        var feedback = document.createElement('div');
        feedback.className = 'alert alert-' + (type === 'success' ? 'success' : 'danger') + ' alert-dismissible fade show';
        feedback.setAttribute('role', type === 'success' ? 'status' : 'alert');
        feedback.setAttribute('data-datatable-ajax-feedback', 'true');

        var icon = document.createElement('i');
        icon.className = 'fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + ' me-2';
        feedback.appendChild(icon);
        feedback.appendChild(document.createTextNode(String(message || 'تعذر تنفيذ العملية.')));

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('data-bs-dismiss', 'alert');
        close.setAttribute('aria-label', 'إغلاق');
        feedback.appendChild(close);
        return feedback;
    }

    function showModalFeedback(form, message) {
        var modal = form && form.closest ? form.closest('.modal') : null;
        var body = modal && modal.querySelector ? modal.querySelector('.modal-body') : null;
        if (!body) return;
        removeAjaxFeedback(body);
        var feedback = createFeedback('error', message);
        body.insertBefore(feedback, body.firstChild);
        if (feedback.focus) {
            feedback.setAttribute('tabindex', '-1');
            feedback.focus();
        }
    }

    function showPageFeedback(message) {
        removeAjaxFeedback(document);
        var feedback = createFeedback('success', message);
        var heading = document.querySelector('.admin-page-heading, .d-flex.border-bottom');
        if (heading && heading.parentNode) heading.parentNode.insertBefore(feedback, heading.nextSibling);
        else if (document.body) document.body.insertBefore(feedback, document.body.firstChild);
    }

    function updateSummary(summary) {
        if (!summary || typeof summary !== 'object') return;
        Object.keys(summary).forEach(function (key) {
            var value = Number(summary[key]);
            if (!Number.isFinite(value)) return;
            Array.prototype.slice.call(document.querySelectorAll('[data-datatable-summary-key="' + key + '"]')).forEach(function (element) {
                element.setAttribute('data-target', String(value));
                element.textContent = String(value);
            });
            Array.prototype.slice.call(document.querySelectorAll('[data-datatable-summary-visible="' + key + '"]')).forEach(function (element) {
                element.classList.toggle('d-none', value <= 0);
            });
        });
    }
    function closeOwningModal(form) {
        var modal = form && form.closest ? form.closest('.modal') : null;
        if (!modal || !window.bootstrap || !window.bootstrap.Modal) return;
        var instance = window.bootstrap.Modal.getInstance(modal);
        if (instance) instance.hide();
    }

    function dispatchAjaxResult(form, detail) {
        if (!form || typeof window.CustomEvent !== 'function') return;
        form.dispatchEvent(new window.CustomEvent('educore:datatable-action-complete', {
            bubbles: true,
            detail: detail
        }));
    }

    function submitAjax(form, event) {
        if (!form || !event || event.defaultPrevented || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') return false;
        if (!formShouldCapture(form)) return false;

        var rowContext = rowContextForForm(form);
        var api = rowContext ? dataTableApi(rowContext.tableId) : null;
        if (!rowContext || !api || !api.ajax || typeof api.ajax.reload !== 'function') return false;

        var submitter = event.submitter || (form.querySelector ? form.querySelector('button[type="submit"], input[type="submit"]') : null);
        var actionUrl;
        try {
            actionUrl = new URL(form.getAttribute('action') || window.location.href, window.location.href);
            if (actionUrl.origin !== window.location.origin) return false;
        } catch (error) {
            return false;
        }

        event.preventDefault();
        setAjaxBusy(form, submitter, true);
        removeAjaxFeedback(form);

        var body = new window.FormData(form);
        body.set('datatable_ajax', '1');
        window.fetch(actionUrl.href, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('اكتملت استجابة غير متوقعة؛ حدّث الجدول قبل إعادة المحاولة.');
            }).then(function (payload) {
                if (!response.ok || !payload || payload.success !== true) {
                    throw new Error(payload && payload.message ? payload.message : 'تعذر تنفيذ العملية.');
                }
                return payload;
            });
        }).then(function (payload) {
            closeOwningModal(form);
            updateSummary(payload.summary);
            showPageFeedback(payload.message || 'تم حفظ التغييرات بنجاح.');
            pendingRowContexts[rowContext.tableId] = Object.assign({ attempts: 0 }, rowContext);
            api.ajax.reload(function () {
                var settings = typeof api.settings === 'function' ? api.settings()[0] : null;
                restoreRowContext(settings);
            }, false);
            dispatchAjaxResult(form, { success: true, payload: payload, rowContext: rowContext });
        }).catch(function (error) {
            showModalFeedback(form, error && error.message ? error.message : 'تعذر تنفيذ العملية.');
            dispatchAjaxResult(form, { success: false, message: error && error.message ? error.message : '' });
        }).then(function () {
            setAjaxBusy(form, submitter, false);
        });
        return true;
    }

    function stateDisabled(settings) {
        var table = settings && settings.nTable ? settings.nTable : null;
        if (!table) return true;

        return falseAttribute(table, 'data-state-save')
            || falseAttribute(table, 'data-datatable-return-state')
            || !!(settings.oFeatures && settings.oFeatures.bStateSave === false);
    }

    function normalizedSearch(search) {
        search = search && typeof search === 'object' ? search : {};
        return {
            search: String(search.search || '').slice(0, 1000),
            smart: search.smart !== false,
            regex: search.regex === true,
            caseInsensitive: search.caseInsensitive !== false
        };
    }

    function safeState(data, settings) {
        data = data && typeof data === 'object' ? data : {};
        var columnCount = settings && settings.nTable
            ? settings.nTable.querySelectorAll('thead th').length
            : 0;
        var order = Array.isArray(data.order) ? data.order.filter(function (entry) {
            return Array.isArray(entry)
                && Number.isInteger(Number(entry[0]))
                && Number(entry[0]) >= 0
                && (!columnCount || Number(entry[0]) < columnCount)
                && (entry[1] === 'asc' || entry[1] === 'desc');
        }).map(function (entry) {
            return [Number(entry[0]), entry[1]];
        }) : [];
        var start = Number(data.start);
        var length = Number(data.length);

        return {
            time: Date.now(),
            start: Number.isFinite(start) && start >= 0 ? Math.floor(start) : 0,
            length: Number.isFinite(length) && length >= -1 && length <= 5000
                ? Math.floor(length)
                : 25,
            order: order,
            search: normalizedSearch(data.search),
            _educore: {
                version: 2,
                schema: schemaFingerprint(settings),
                context: hash(normalizedContext())
            }
        };
    }

    function rememberState(settings, data) {
        var key = stateKey(settings);
        if (!key) return;

        if (stateDisabled(settings)) {
            delete liveStates[key];
            return;
        }

        liveStates[key] = {
            state: safeState(data, settings),
            tableId: tableIdentifier(settings)
        };
    }

    function returnContexts(form) {
        var currentContext = normalizedContext();
        var contexts = [currentContext];
        if (!form || !form.getAttribute) return contexts;

        try {
            var currentUrl = new URL(window.location.href);
            var actionUrl = new URL(form.getAttribute('action') || currentUrl.href, currentUrl.href);
            if (actionUrl.origin === currentUrl.origin && actionUrl.pathname === currentUrl.pathname) {
                contexts.push(normalizedContext(actionUrl));
            }

            var explicitReturn = form.getAttribute('data-datatable-return-url');
            if (explicitReturn) {
                var returnUrl = new URL(explicitReturn, currentUrl.href);
                if (returnUrl.origin === currentUrl.origin) {
                    contexts.push(normalizedContext(returnUrl));
                }
            }
        } catch (error) {
            return contexts;
        }

        return contexts.filter(function (context, index, allContexts) {
            return allContexts.indexOf(context) === index;
        });
    }

    function removeStoredState(sessionStore, key, saved) {
        var prefix = scopePrefix();
        var aliases = saved && saved._educore && Array.isArray(saved._educore.aliases)
            ? saved._educore.aliases
            : [];

        sessionStore.removeItem(key);
        if (!prefix) return;
        aliases.forEach(function (alias) {
            if (typeof alias === 'string' && alias.indexOf(prefix) === 0) {
                sessionStore.removeItem(alias);
            }
        });
    }

    function captureStatesForContexts(contexts, rowContext, ttlMs, tableId) {
        var sessionStore = storage();
        if (!sessionStore) return 0;

        var captured = 0;
        var expiresAt = Date.now() + ttlMs;
        Object.keys(liveStates).forEach(function (sourceKey) {
            var entry = liveStates[sourceKey];
            if (!entry || !entry.state || !entry.tableId) return;
            if (tableId && entry.tableId !== tableId) return;

            var aliases = contexts.map(function (context) {
                return stateKeyFor(entry.tableId, context);
            }).filter(function (key, index, allKeys) {
                return key && allKeys.indexOf(key) === index;
            });
            var storedAny = false;

            aliases.forEach(function (key, index) {
                var pendingState = Object.assign({}, entry.state, {
                    _educore: Object.assign({}, entry.state._educore, {
                        aliases: aliases,
                        context: hash(contexts[index]),
                        expiresAt: expiresAt,
                        rowContext: rowContext && rowContext.tableId === entry.tableId
                            ? rowContext
                            : null
                    })
                });

                try {
                    sessionStore.setItem(key, JSON.stringify(pendingState));
                    storedAny = true;
                } catch (error) {
                    sessionStore.removeItem(key);
                }
            });
            if (storedAny) captured++;
        });

        return captured;
    }

    function captureReturnStates(form) {
        return captureStatesForContexts(
            returnContexts(form),
            rowContextForForm(form),
            RETURN_TTL_MS,
            null
        );
    }

    function actionLinkReturnContexts(link) {
        var contexts = [normalizedContext()];
        if (!link || !link.getAttribute) return contexts;

        try {
            var currentUrl = new URL(window.location.href);
            var actionUrl = new URL(link.getAttribute('href') || currentUrl.href, currentUrl.href);
            var explicitReturn = link.getAttribute('data-datatable-return-url');
            if (explicitReturn) {
                var returnUrl = new URL(explicitReturn, currentUrl.href);
                if (returnUrl.origin === currentUrl.origin) {
                    contexts.push(normalizedContext(returnUrl));
                }
            }

            if (actionUrl.origin === currentUrl.origin && actionUrl.searchParams.has('action')) {
                actionUrl.searchParams.delete('action');
                ROW_FIELD_NAMES.forEach(function (fieldName) {
                    actionUrl.searchParams.delete(fieldName);
                });
                actionUrl.hash = '';
                contexts.push(normalizedContext(actionUrl));
            }
        } catch (error) {
            return contexts;
        }

        return contexts.filter(function (context, index, allContexts) {
            return allContexts.indexOf(context) === index;
        });
    }

    function actionLinkShouldCapture(link, event) {
        if (!link || !link.getAttribute) return false;
        if (falseAttribute(link, 'data-datatable-return')) return false;
        if (event && (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
            return false;
        }

        var target = String(link.getAttribute('target') || '').toLowerCase();
        if (target && target !== '_self') return false;
        if (link.hasAttribute && link.hasAttribute('download')) return false;

        var row = link.closest ? link.closest('tbody tr') : null;
        var table = row && row.closest ? row.closest('table') : null;
        if (!row || !table || falseAttribute(table, 'data-datatable-return-state')) return false;

        var href = String(link.getAttribute('href') || '').trim();
        if (!href || href.charAt(0) === '#' || /^(?:javascript|mailto|tel):/i.test(href)) return false;

        try {
            var currentUrl = new URL(window.location.href);
            var actionUrl = new URL(href, currentUrl.href);
            if (actionUrl.origin !== currentUrl.origin) return false;
            if (trueAttribute(link, 'data-datatable-return')) return true;

            var className = String(link.getAttribute('class') || '');
            var identities = linkQueryIdentities(link);
            var isActionButton = /(?:^|\s)btn(?:\s|$)/.test(className);
            var hasActionIdentity = actionUrl.searchParams.has('action') && identities.length > 0;
            var hasActionPath = /(?:^|[\/_.-])(?:edit|view|details?|profile|manage|settings|assign|permissions?)(?:[\/_.-]|$)/i.test(actionUrl.pathname)
                && identities.length > 0;
            return isActionButton || hasActionIdentity || hasActionPath;
        } catch (error) {
            return false;
        }
    }

    function captureActionLinkState(link, rowContext) {
        rowContext = sanitizeRowContext(rowContext);
        if (!rowContext) return 0;
        return captureStatesForContexts(
            actionLinkReturnContexts(link),
            rowContext,
            ACTION_JOURNEY_TTL_MS,
            rowContext.tableId
        );
    }

    function loadReturnState(settings) {
        var sessionStore = storage();
        var key = stateKey(settings);
        if (!sessionStore || !key) return null;

        try {
            var saved = JSON.parse(sessionStore.getItem(key) || 'null');
            removeStoredState(sessionStore, key, saved);
            if (stateDisabled(settings)) return null;
            if (!saved || !saved._educore) return null;
            if (saved._educore.version !== 2) return null;
            if (!Number.isFinite(Number(saved._educore.expiresAt))) return null;
            if (Number(saved._educore.expiresAt) < Date.now()) return null;
            if (saved._educore.schema !== schemaFingerprint(settings)) return null;
            if (saved._educore.context !== hash(normalizedContext())) return null;
            var rowContext = sanitizeRowContext(saved._educore.rowContext);
            if (rowContext && rowContext.tableId === tableIdentifier(settings)) {
                pendingRowContexts[rowContext.tableId] = Object.assign({ attempts: 0 }, rowContext);
            }
            return saved;
        } catch (error) {
            removeStoredState(sessionStore, key, null);
            return null;
        }
    }

    function clearState(settings) {
        var sessionStore = storage();
        var key = stateKey(settings);
        if (!key) return;
        delete liveStates[key];
        if (sessionStore) {
            try {
                var saved = JSON.parse(sessionStore.getItem(key) || 'null');
                removeStoredState(sessionStore, key, saved);
            } catch (error) {
                removeStoredState(sessionStore, key, null);
            }
        }
    }

    function removeByPrefix(sessionStore, prefix) {
        for (var index = sessionStore.length - 1; index >= 0; index--) {
            var key = sessionStore.key(index);
            if (key && key.indexOf(prefix) === 0) {
                sessionStore.removeItem(key);
            }
        }
    }

    function clearLegacyStates() {
        var sessionStore = storage();
        if (sessionStore) removeByPrefix(sessionStore, LEGACY_PREFIX);
    }

    function clearAllStates() {
        var sessionStore = storage();
        liveStates = {};
        pendingRowContexts = {};
        lastActionRow = null;
        if (!sessionStore) return;
        removeByPrefix(sessionStore, RETURN_PREFIX);
        removeByPrefix(sessionStore, LEGACY_PREFIX);
    }

    function formShouldCapture(form) {
        if (!form || !form.getAttribute) return false;
        if (falseAttribute(form, 'data-datatable-return')) return false;

        var method = String(form.getAttribute('method') || form.method || 'get').toLowerCase();
        if (method !== 'post') return false;

        var target = String(form.getAttribute('target') || '').toLowerCase();
        if (target && target !== '_self') return false;

        try {
            var currentUrl = new URL(window.location.href);
            var actionUrl = new URL(form.getAttribute('action') || currentUrl.href, currentUrl.href);
            if (actionUrl.origin !== currentUrl.origin) return false;
            return trueAttribute(form, 'data-datatable-return')
                || actionUrl.pathname === currentUrl.pathname;
        } catch (error) {
            return false;
        }
    }

    function defer(callback) {
        if (typeof window.queueMicrotask === 'function') {
            window.queueMicrotask(callback);
            return;
        }
        if (window.Promise && typeof window.Promise.resolve === 'function') {
            window.Promise.resolve().then(callback);
            return;
        }
        window.setTimeout(callback, 0);
    }

    function stateOptions() {
        return {
            stateSave: true,
            stateDuration: -1,
            stateSaveCallback: rememberState,
            stateLoadCallback: loadReturnState
        };
    }

    function apply(options, table) {
        options = options && typeof options === 'object' ? options : {};

        if (options.stateSave === false
            || falseAttribute(table, 'data-state-save')
            || falseAttribute(table, 'data-datatable-return-state')) {
            options.stateSave = false;
            return options;
        }

        var defaults = stateOptions();
        Object.keys(defaults).forEach(function (key) {
            if (typeof options[key] === 'undefined') options[key] = defaults[key];
        });
        return options;
    }

    function install() {
        var $ = window.jQuery;
        if (installed) return true;
        if (!$ || !$.fn || !$.fn.dataTable || !$.fn.dataTable.defaults) return false;

        $.extend(true, $.fn.dataTable.defaults, stateOptions());
        $(document).on('init.dt.educoreReturn draw.dt.educoreReturn', function (event, settings) {
            restoreRowContext(settings);
        });
        installed = true;
        return true;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (trueAttribute(form, 'data-datatable-ajax') && submitAjax(form, event)) return;
        if (!formShouldCapture(form)) return;

        defer(function () {
            if (!event.defaultPrevented) {
                captureReturnStates(form);
                lastActionRow = null;
            }
        });
    }, false);

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[href]') : null;
        var href = link ? String(link.getAttribute('href') || '') : '';
        if (link && /(^|\/)(logout|select_role)\.php(?:[?#]|$)/i.test(href)) {
            clearAllStates();
            return;
        }

        var rowContext = rememberActionRow(event.target);
        if (!actionLinkShouldCapture(link, event)) return;
        defer(function () {
            if (event.defaultPrevented) return;
            captureActionLinkState(link, rowContext);
            lastActionRow = null;
        });
    }, true);

    clearLegacyStates();

    window.EduCoreDataTableState = {
        apply: apply,
        captureLink: captureActionLinkState,
        capture: captureReturnStates,
        clear: clearState,
        clearAll: clearAllStates,
        install: install,
        keyFor: stateKey,
        load: loadReturnState,
        remember: rememberState,
        rememberRow: rememberActionRow,
        restoreRow: restoreRowContext,
        rowContextForForm: rowContextForForm,
        sanitizeRowContext: sanitizeRowContext,
        sanitize: safeState,
        submitAjax: submitAjax,
        shouldCaptureLink: actionLinkShouldCapture,
        shouldCaptureForm: formShouldCapture
    };

    if (!install()) {
        document.addEventListener('DOMContentLoaded', install, { once: true });
    }
})(window, document);
