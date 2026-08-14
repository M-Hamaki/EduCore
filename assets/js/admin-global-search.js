(function () {
    'use strict';

    let searchInstanceCounter = 0;

    function normalize(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFKD')
            .replace(/[\u064b-\u065f\u0670]/g, '')
            .replace(/[أإآء]/g, 'ا')
            .replace(/ؤ/g, 'و')
            .replace(/[ئى]/g, 'ي')
            .replace(/ة/g, 'ه')
            .replace(/[^\p{L}\p{N}\s]/gu, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function safeHref(value, fallback) {
        const href = String(value || '').trim();
        if (!href || /^(?:javascript|data|vbscript):/i.test(href) || href.startsWith('//')) {
            return fallback;
        }
        return href;
    }

    function initSearchInstance(legacyInput) {
        if (!legacyInput) return;
        const input = legacyInput.cloneNode(true);
        legacyInput.replaceWith(input);
        const container = input.closest('.search-bar-ms');
        if (!container) return;

        container.style.position = 'relative';

        let results = container.querySelector('.admin-global-search-results');
        if (!results) {
            results = document.createElement('div');
            results.className = 'admin-global-search-results shadow';
            results.setAttribute('role', 'listbox');
            results.hidden = true;
            container.appendChild(results);
        }
        searchInstanceCounter += 1;
        if (!results.id) {
            results.id = 'adminGlobalSearchResults' + searchInstanceCounter;
        }
        results.setAttribute('aria-live', 'polite');
        input.setAttribute('aria-controls', results.id);
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');

        // Build rich search index including ALL parent section/category names and
        // accordion parent titles so deeply nested pages are correctly attributed
        // to their top-level section (e.g. "شؤون الطلاب" not just "بيانات الطلاب").
        const links = [];
        const sectionSeen = {};

        // Build the index defensively: an unexpected DOM shape or a stray
        // malformed id must NEVER prevent the input listeners below from
        // being bound. A partial/empty index still leaves the dropdown usable
        // (it will show the DB-backed results and "no matches" otherwise).
        try {
        Array.from(document.querySelectorAll('#adminSidebar a.nav-link[href]'))
            .filter(function (link) {
                const href = (link.getAttribute('href') || '').trim();
                return href && !href.startsWith('javascript:');
            })
            .forEach(function (link) {
                const href = (link.getAttribute('href') || '').trim();
                const text = link.textContent.replace(/\s+/g, ' ').trim();

                if (!href.startsWith('#')) {
                    // Leaf page: collect the FULL chain of ancestor collapse titles
                    // (innermost -> outermost) so a page nested as
                    // #studentsMenu > #studentDataMenu > students.php carries BOTH
                    // "بيانات الطلاب" and "شؤون الطلاب" as searchable context.
                    let categories = [];
                    let parentCollapse = link.closest('.collapse');
                    while (parentCollapse && parentCollapse.id) {
                        const toggleBtn = document.querySelector('#adminSidebar a[href="#' + parentCollapse.id + '"]');
                        if (toggleBtn) {
                            const cat = toggleBtn.textContent.replace(/\s+/g, ' ').trim();
                            if (cat) { categories.push(cat); }
                        }
                        // Walk up to the next enclosing collapse (outer parent).
                        parentCollapse = parentCollapse.parentElement
                            ? parentCollapse.parentElement.closest('.collapse')
                            : null;
                    }
                    // Category header fallback (used by specialist/student-affairs roles).
                    if (!categories.length) {
                        let prev = link.closest('.nav-item') ? link.closest('.nav-item').previousElementSibling : null;
                        while (prev) {
                            if (prev.classList && prev.classList.contains('sidebar-category-header')) {
                                const cat = prev.textContent.replace(/\s+/g, ' ').trim();
                                if (cat) { categories.push(cat); }
                                break;
                            }
                            prev = prev.previousElementSibling;
                        }
                    }
                    // Outermost parent first, then inner, then the leaf text.
                    const category = categories.filter(Boolean).reverse().join(' ');
                    const searchableText = (category ? category + ' ' : '') + text;
                    links.push({
                        link: link,
                        href: href,
                        text: text,
                        category: category,
                        type: 'page',
                        normalized: normalize(searchableText)
                    });
                } else if (href.startsWith('#')) {
                    // Accordion toggle (section header). Register the section itself
                    // as a clickable result that navigates to its first leaf page,
                    // so searching "شؤون الطلاب" surfaces the section directly.
                    // Use getElementById (never throws) instead of building a
                    // dynamic CSS selector, so an unusual collapse id cannot
                    // break the whole index build.
                    const collapseId = href.slice(1);
                    const collapseEl = collapseId ? document.getElementById(collapseId) : null;
                    const firstLeaf = collapseEl
                        ? collapseEl.querySelector('a.nav-link[href]:not([href^="#"]):not([href^="javascript:"])')
                        : null;
                    if (firstLeaf && text) {
                        const key = collapseId + '::' + text;
                        if (!sectionSeen[key]) {
                            sectionSeen[key] = true;
                            links.push({
                                link: firstLeaf,
                                href: (firstLeaf.getAttribute('href') || '').trim(),
                                text: text,
                                category: '',
                                type: 'section',
                                normalized: normalize(text)
                            });
                        }
                    }
                }
            });
        } catch (indexError) {
            // Defensive: never let index construction break the whole search
            // instance. Log and continue with whatever links were collected.
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('admin-global-search: index build failed, continuing with partial index', indexError);
            }
        }

        let activeIndex = -1;
        let current = [];
        let debounceTimer = null;
        let activeRequest = null;
        let requestSerial = 0;

        function close() {
            clearTimeout(debounceTimer);
            if (activeRequest) {
                activeRequest.abort();
                activeRequest = null;
            }
            results.hidden = true;
            results.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            activeIndex = -1;
            current = [];
        }

        function renderQuickShortcuts() {
            const topLinks = links.slice(0, 6);
            if (!topLinks.length) { close(); return; }
            current = topLinks;
            results.innerHTML = '<div class="px-3 py-2 text-muted fw-bold small border-bottom bg-light d-flex align-items-center justify-content-between"><span><i class="fas fa-bolt text-warning me-1"></i>روابط سريعة</span><kbd class="small text-muted border px-1 rounded bg-white" style="font-size:0.65rem;">Esc للإغلاق</kbd></div>' +
                topLinks.map(function (item, index) {
                    const catBadge = item.category ? '<small class="text-muted ms-1">(' + escapeHtml(item.category) + ')</small> ' : '';
                    return '<a class="admin-search-result d-flex align-items-center justify-content-between text-decoration-none py-2 px-3 border-bottom" role="option" data-index="' + index + '" href="' + escapeHtml(safeHref(item.href, '#')) + '"><span><i class="fas fa-circle-dot me-2 text-primary small"></i><strong>' + escapeHtml(item.text) + '</strong> ' + catBadge + '</span><i class="fas fa-arrow-left text-muted small"></i></a>';
                }).join('');
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            activeIndex = -1;
        }

        function renderResults(query, dbData, status) {
            const tokens = query.split(' ').filter(Boolean);
            // Score every indexed item (pages AND sections share the same index;
            // sections carry type:'section', pages carry type:'page').
            const allMatches = links
                .map(function (item) {
                    const matched = tokens.every(function (token) { return item.normalized.includes(token); });
                    const starts = item.normalized.startsWith(query) ? 3 : 0;
                    const exact = item.normalized === query ? 5 : 0;
                    // Sections get a small boost so the section header appears
                    // above its child pages when both match (e.g. "شؤون الطلاب").
                    const sectionBoost = item.type === 'section' ? 1 : 0;
                    return Object.assign({}, item, { score: matched ? 1 + starts + exact + sectionBoost : 0 });
                })
                .filter(function (item) { return item.score > 0; })
                .sort(function (a, b) { return b.score - a.score || a.text.localeCompare(b.text, 'ar'); });

            const sectionMatches = allMatches.filter(function (i) { return i.type === 'section'; }).slice(0, 3);
            const pageMatches = allMatches.filter(function (i) { return i.type !== 'section'; }).slice(0, 8);

            let html = '';
            current = [];

            // 1. Section Matches (top-level sections like "شؤون الطلاب")
            if (sectionMatches.length) {
                html += '<div class="px-3 py-1 text-muted fw-bold small border-bottom bg-light d-flex align-items-center"><i class="fas fa-folder-tree text-purple me-2"></i>الأقسام (' + sectionMatches.length + ')</div>';
                sectionMatches.forEach(function (item) {
                    const idx = current.length;
                    current.push(item);
                    html += '<a class="admin-search-result d-flex align-items-center justify-content-between text-decoration-none py-2 px-3 border-bottom" role="option" data-index="' + idx + '" href="' + escapeHtml(safeHref(item.href, '#')) + '"><span><i class="fas fa-folder me-2" style="color:#6f42c1;"></i><strong>' + escapeHtml(item.text) + '</strong></span><i class="fas fa-chevron-left text-muted small"></i></a>';
                });
            }

            // 2. Page Matches
            if (pageMatches.length) {
                html += '<div class="px-3 py-1 text-muted fw-bold small border-bottom bg-light d-flex align-items-center"><i class="fas fa-layer-group text-primary me-2"></i>الصفحات (' + pageMatches.length + ')</div>';
                pageMatches.forEach(function (item) {
                    const idx = current.length;
                    current.push(item);
                    const catBadge = item.category ? '<small class="text-muted ms-1">(' + escapeHtml(item.category) + ')</small>' : '';
                    html += '<a class="admin-search-result d-flex align-items-center justify-content-between text-decoration-none py-2 px-3 border-bottom" role="option" data-index="' + idx + '" href="' + escapeHtml(safeHref(item.href, '#')) + '"><span><i class="fas fa-file-alt me-2 text-primary"></i><strong>' + escapeHtml(item.text) + '</strong> ' + catBadge + '</span><i class="fas fa-chevron-left text-muted small"></i></a>';
                });
            }

            // 3. Student Matches
            if (dbData && dbData.students && dbData.students.length) {
                html += '<div class="px-3 py-1 text-muted fw-bold small border-bottom bg-light d-flex align-items-center"><i class="fas fa-user-graduate text-success me-2"></i>الطلاب (' + dbData.students.length + ')</div>';
                dbData.students.forEach(function (st) {
                    const idx = current.length;
                    const fallbackHref = 'students.php?action=view&id=' + encodeURIComponent(st.id || '');
                    const href = safeHref(st.url, fallbackHref);
                    const item = { href: href, text: st.name, type: 'student' };
                    current.push(item);
                    const meta = (st.class_name ? st.class_name : '') + (st.student_code ? ' | كود: ' + st.student_code : '');
                    const metaHtml = meta ? ' <small class="text-muted ms-1">(' + escapeHtml(meta) + ')</small>' : '';
                    html += '<a class="admin-search-result d-flex align-items-center justify-content-between text-decoration-none py-2 px-3 border-bottom" role="option" data-index="' + idx + '" href="' + escapeHtml(href) + '"><span><i class="fas fa-user-graduate me-2 text-success"></i><strong>' + escapeHtml(st.name) + '</strong>' + metaHtml + '</span><i class="fas fa-arrow-left text-muted small"></i></a>';
                });
            }

            // 4. Staff Matches
            if (dbData && dbData.staff && dbData.staff.length) {
                html += '<div class="px-3 py-1 text-muted fw-bold small border-bottom bg-light d-flex align-items-center"><i class="fas fa-user-tie text-info me-2"></i>الكادر والمُعلمون (' + dbData.staff.length + ')</div>';
                dbData.staff.forEach(function (stf) {
                    const idx = current.length;
                    const fallbackHref = 'staff.php?action=view&id=' + encodeURIComponent(stf.id || '');
                    const href = safeHref(stf.url, fallbackHref);
                    const item = { href: href, text: stf.name, type: 'staff' };
                    current.push(item);
                    const roleName = stf.job_title || (stf.role === 'teacher' ? 'معلم' : (stf.role === 'specialist' ? 'أخصائي' : 'موظف'));
                    const meta = (roleName || '') + (stf.employee_code ? ' | كود: ' + stf.employee_code : '');
                    html += '<a class="admin-search-result d-flex align-items-center justify-content-between text-decoration-none py-2 px-3 border-bottom" role="option" data-index="' + idx + '" href="' + escapeHtml(href) + '"><span><i class="fas fa-user-tie me-2 text-info"></i><strong>' + escapeHtml(stf.name) + '</strong> <small class="text-muted ms-1">(' + escapeHtml(meta) + ')</small></span><i class="fas fa-arrow-left text-muted small"></i></a>';
                });
            }

            // 5. Class Matches
            if (dbData && dbData.classes && dbData.classes.length) {
                html += '<div class="px-3 py-1 text-muted fw-bold small border-bottom bg-light d-flex align-items-center"><i class="fas fa-school text-primary me-2"></i>الفصول الدراسية (' + dbData.classes.length + ')</div>';
                dbData.classes.forEach(function (c) {
                    const idx = current.length;
                    const href = safeHref(c.url, 'class_lists.php?class_id=' + encodeURIComponent(c.id || ''));
                    const item = { href: href, text: c.name, type: 'class' };
                    current.push(item);
                    const meta = (c.grade_name ? c.grade_name : '') + (c.stage_name ? ' | ' + c.stage_name : '');
                    const metaHtml = meta ? ' <small class="text-muted ms-1">(' + escapeHtml(meta) + ')</small>' : '';
                    html += '<a class="admin-search-result d-flex align-items-center justify-content-between text-decoration-none py-2 px-3 border-bottom" role="option" data-index="' + idx + '" href="' + escapeHtml(href) + '"><span><i class="fas fa-school me-2 text-primary"></i><strong>' + escapeHtml(c.name) + '</strong>' + metaHtml + '</span><i class="fas fa-arrow-left text-muted small"></i></a>';
                });
            }

            // 6. Subject Matches
            if (dbData && dbData.subjects && dbData.subjects.length) {
                html += '<div class="px-3 py-1 text-muted fw-bold small border-bottom bg-light d-flex align-items-center"><i class="fas fa-book me-2" style="color:#6f42c1;"></i>المواد الدراسية (' + dbData.subjects.length + ')</div>';
                dbData.subjects.forEach(function (s) {
                    const idx = current.length;
                    const href = safeHref(s.url, 'subjects.php');
                    const item = { href: href, text: s.name, type: 'subject' };
                    current.push(item);
                    const meta = s.code ? 'الكود: ' + s.code : '';
                    const metaHtml = meta ? ' <small class="text-muted ms-1">(' + escapeHtml(meta) + ')</small>' : '';
                    html += '<a class="admin-search-result d-flex align-items-center justify-content-between text-decoration-none py-2 px-3 border-bottom" role="option" data-index="' + idx + '" href="' + escapeHtml(href) + '"><span><i class="fas fa-book me-2" style="color:#6f42c1;"></i><strong>' + escapeHtml(s.name) + '</strong>' + metaHtml + '</span><i class="fas fa-arrow-left text-muted small"></i></a>';
                });
            }

            // 7. Bus Matches
            if (dbData && dbData.buses && dbData.buses.length) {
                html += '<div class="px-3 py-1 text-muted fw-bold small border-bottom bg-light d-flex align-items-center"><i class="fas fa-bus text-warning me-2"></i>الحافلات والنقل (' + dbData.buses.length + ')</div>';
                dbData.buses.forEach(function (b) {
                    const idx = current.length;
                    const href = safeHref(b.url, 'transport_statistics.php');
                    const item = { href: href, text: 'حافلة ' + b.bus_number, type: 'bus' };
                    current.push(item);
                    const capacity = b.capacity ? 'سعة ' + b.capacity + ' طالب' : '';
                    const capacityHtml = capacity ? ' <small class="text-muted ms-1">(' + escapeHtml(capacity) + ')</small>' : '';
                    html += '<a class="admin-search-result d-flex align-items-center justify-content-between text-decoration-none py-2 px-3 border-bottom" role="option" data-index="' + idx + '" href="' + escapeHtml(href) + '"><span><i class="fas fa-bus me-2 text-warning"></i><strong>حافلة رقم ' + escapeHtml(b.bus_number) + '</strong>' + capacityHtml + '</span><i class="fas fa-arrow-left text-muted small"></i></a>';
                });
            }

            if (status) {
                const statusType = status.type || 'loading';
                const statusConfig = statusType === 'forbidden'
                    ? { icon: 'fa-shield-alt', color: 'text-warning', message: status.message || 'لا يتيح دورك الحالي عرض نتائج الأشخاص.' }
                    : (statusType === 'error'
                        ? { icon: 'fa-triangle-exclamation', color: 'text-danger', message: status.message || 'تعذر تحميل نتائج البيانات الآن. أعد المحاولة.' }
                        : { icon: 'fa-spinner fa-spin', color: 'text-primary', message: status.message || 'جاري البحث في بيانات النظام…' });
                html += '<div class="admin-search-status px-3 py-2 small border-top bg-light ' + statusConfig.color + '"><i class="fas ' + statusConfig.icon + ' me-2"></i>' + escapeHtml(statusConfig.message) + '</div>';
            }

            if (!current.length && !status) {
                html = '<div class="admin-search-empty p-3 text-muted text-center"><i class="fas fa-search me-2"></i>لا توجد نتائج مطابقة</div>';
            }

            results.innerHTML = html;
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            input.removeAttribute('aria-activedescendant');
            activeIndex = -1;
        }

        function render() {
            const query = normalize(input.value);
            clearTimeout(debounceTimer);
            if (activeRequest) {
                activeRequest.abort();
                activeRequest = null;
            }
            requestSerial += 1;
            if (!query) { renderQuickShortcuts(); return; }

            renderResults(query, null);

            if (query.length >= 2) {
                const requestQuery = query;
                const requestValue = input.value.trim();
                const serial = requestSerial;
                debounceTimer = setTimeout(function () {
                    renderResults(requestQuery, null, { type: 'loading' });
                    activeRequest = new AbortController();
                    fetch('../includes/ajax_handlers.php?action=global_deep_search&q=' + encodeURIComponent(requestValue), {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: activeRequest.signal
                    })
                        .then(function (res) {
                            return res.json()
                                .catch(function () { return null; })
                                .then(function (json) {
                                    return { response: res, json: json };
                                });
                        })
                        .then(function (payload) {
                            if (serial !== requestSerial || normalize(input.value) !== requestQuery) {
                                return;
                            }
                            activeRequest = null;
                            if (!payload.response.ok || !payload.json || payload.json.success !== true) {
                                const type = payload.response.status === 401 || payload.response.status === 403
                                    ? 'forbidden'
                                    : 'error';
                                renderResults(requestQuery, null, {
                                    type: type,
                                    message: payload.json && payload.json.message ? payload.json.message : ''
                                });
                                return;
                            }
                            renderResults(requestQuery, payload.json.data || null);
                        })
                        .catch(function (error) {
                            if (error && error.name === 'AbortError') {
                                return;
                            }
                            if (serial === requestSerial && normalize(input.value) === requestQuery) {
                                activeRequest = null;
                                renderResults(requestQuery, null, { type: 'error' });
                            }
                        });
                }, 220);
            }
        }

        function setActive(index) {
            const options = Array.from(results.querySelectorAll('.admin-search-result'));
            options.forEach(function (option) { option.classList.remove('active'); });
            if (!options.length) return;
            activeIndex = (index + options.length) % options.length;
            options[activeIndex].classList.add('active');
            if (!options[activeIndex].id) {
                options[activeIndex].id = results.id + '-option-' + activeIndex;
            }
            input.setAttribute('aria-activedescendant', options[activeIndex].id);
        }

        input.addEventListener('input', render);
        input.addEventListener('focus', function () { render(); });
        input.addEventListener('keydown', function (event) {
            if (results.hidden) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); setActive(activeIndex + 1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); setActive(activeIndex - 1); }
            else if (event.key === 'Enter' && current.length) {
                event.preventDefault();
                location.href = current[activeIndex >= 0 ? activeIndex : 0].href;
            } else if (event.key === 'Escape') { close(); input.blur(); }
        });

        document.addEventListener('click', function (event) {
            if (!container.contains(event.target)) close();
        });
    }

    // Global Ctrl+K / Cmd+K shortcut listener
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            const desktopInput = document.querySelector('.d-none.d-lg-flex .search-bar-ms input[type="search"]');
            const mobileBtn = document.getElementById('mobileSearchToggleBtn');
            const mobileBar = document.getElementById('mobileSearchBar');
            const mobileInput = document.getElementById('mobileSearchInput');

            if (window.innerWidth >= 992 && desktopInput) {
                desktopInput.focus();
                desktopInput.select();
            } else if (mobileBtn && mobileBar && mobileInput) {
                if (mobileBar.style.display !== 'block') {
                    mobileBtn.click();
                }
                mobileInput.focus();
                mobileInput.select();
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        const inputs = Array.from(document.querySelectorAll('.search-bar-ms input[type="search"]'));
        inputs.forEach(initSearchInstance);
    });

    // Run immediately if DOM already loaded
    if (document.readyState !== 'loading') {
        const inputs = Array.from(document.querySelectorAll('.search-bar-ms input[type="search"]'));
        inputs.forEach(initSearchInstance);
    }
})();
