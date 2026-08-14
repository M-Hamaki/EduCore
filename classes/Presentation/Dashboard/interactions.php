<script>
    document.addEventListener('DOMContentLoaded', function () {
        const STORAGE_KEY = 'eduCoreDashboardMainPrefs';

        // Define Presets
        const presets = {
            all: {
                stages: true, grades: true, classes: true, subjects: true, staff: true, students: true,
                evaluation_types: true, total_evaluations: true, notifications: true,
                attendance_today: true, transport: true, external_teachers: true, training: true,
                library: true, clinic: true, graduates: true,
                admins: true, ai_lessons: true, internal_transfers: true, published_reports: true, activities: true,
                action_logs: true, materials: true, student_guardians: true, student_siblings: true, ai_api_logs: true,
                chart_students_stage: true, chart_students_grade: true, chart_evaluations: true, chart_staff: true,
                chart_students_per_class: true, chart_top_teachers: true, chart_top_classes_evals: true,
                chart_top_users_actions: true, chart_materials_per_grade: true, chart_ai_api_usage: true,
                quick_actions: true, ai_insights: true
            },
            academic: {
                stages: true, grades: true, classes: true, subjects: true, staff: false, students: true,
                evaluation_types: false, total_evaluations: false, notifications: false,
                attendance_today: true, transport: false, external_teachers: false, training: false,
                library: false, clinic: false, graduates: true,
                admins: false, ai_lessons: true, internal_transfers: true, published_reports: true, activities: true,
                action_logs: false, materials: true, student_guardians: false, student_siblings: true, ai_api_logs: true,
                chart_students_stage: true, chart_students_grade: true, chart_evaluations: false, chart_staff: false,
                chart_students_per_class: true, chart_top_teachers: false, chart_top_classes_evals: true,
                chart_top_users_actions: false, chart_materials_per_grade: true, chart_ai_api_usage: true,
                quick_actions: true, ai_insights: true
            },
            admin: {
                stages: false, grades: false, classes: false, subjects: false, staff: true, students: false,
                evaluation_types: true, total_evaluations: true, notifications: true,
                attendance_today: true, transport: true, external_teachers: true, training: true,
                library: true, clinic: true, graduates: false,
                admins: true, ai_lessons: false, internal_transfers: false, published_reports: true, activities: false,
                action_logs: true, materials: false, student_guardians: true, student_siblings: false, ai_api_logs: false,
                chart_students_stage: false, chart_students_grade: false, chart_evaluations: true, chart_staff: true,
                chart_students_per_class: false, chart_top_teachers: true, chart_top_classes_evals: false,
                chart_top_users_actions: true, chart_materials_per_grade: false, chart_ai_api_usage: false,
                quick_actions: true, ai_insights: true
            },
            minimal: {
                stages: false, grades: false, classes: false, subjects: false, staff: false, students: true,
                evaluation_types: false, total_evaluations: true, notifications: false,
                attendance_today: true, transport: false, external_teachers: false, training: false,
                library: false, clinic: false, graduates: false,
                admins: false, ai_lessons: false, internal_transfers: false, published_reports: false, activities: false,
                action_logs: false, materials: false, student_guardians: false, student_siblings: false, ai_api_logs: false,
                chart_students_stage: true, chart_students_grade: false, chart_evaluations: false, chart_staff: false,
                chart_students_per_class: false, chart_top_teachers: false, chart_top_classes_evals: false,
                chart_top_users_actions: false, chart_materials_per_grade: false, chart_ai_api_usage: false,
                quick_actions: true, ai_insights: false
            }
        };

        // SortableJS Drag & Drop and Resizing Logic
        const sortableContainer = document.getElementById('dashboard-sections-sortable');

        try {
            const savedOrder = localStorage.getItem('eduCoreDashboardOrder');
            if (savedOrder && sortableContainer) {
                const orderIds = savedOrder.split('|');
                orderIds.forEach(id => {
                    const section = sortableContainer.querySelector(`.dashboard-section[data-id="${id}"]`);
                    if (section) {
                        sortableContainer.appendChild(section);
                    }
                });
            }
        } catch (e) {
            console.warn("Error restoring section order:", e);
        }

        let sectionSizes = {};
        try {
            sectionSizes = JSON.parse(localStorage.getItem('eduCoreDashboardSizes') || '{}');
        } catch (e) {
            sectionSizes = {};
        }

        document.querySelectorAll('.dashboard-section').forEach(section => {
            const id = section.dataset.sectionId;
            const savedSize = sectionSizes[id];
            if (savedSize) {
                section.classList.remove('col-12', 'col-lg-8', 'col-lg-6', 'col-lg-4');
                section.classList.add(savedSize);
            }
        });

        let sortableInstance = null;
        try {
            if (sortableContainer && typeof Sortable !== 'undefined') {
                sortableInstance = new Sortable(sortableContainer, {
                    handle: '.header-drag-handle',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    dragClass: 'sortable-drag',
                    store: {
                        get: function (sortable) {
                            const order = localStorage.getItem('eduCoreDashboardOrder');
                            return order ? order.split('|') : [];
                        },
                        set: function (sortable) {
                            const order = sortable.toArray();
                            localStorage.setItem('eduCoreDashboardOrder', order.join('|'));
                        }
                    }
                });
            }
        } catch (e) {
            console.warn("SortableJS failed to initialize:", e);
        }

        // ── Drag-to-Resize from edges ──

        // Inject CSS for resize handles
        const _rsCSS = document.createElement('style');
        _rsCSS.textContent = `
            .dashboard-section { position: relative; transition: none; }
            .section-resize-handle {
                position: absolute; top: 0; left: 0; width: 7px; height: 100%;
                cursor: col-resize; z-index: 10; background: transparent;
                transition: background 0.15s;
            }
            .section-resize-handle:hover,
            .section-resize-handle.active {
                background: linear-gradient(180deg, rgba(59,130,246,0.08), rgba(59,130,246,0.18), rgba(59,130,246,0.08));
            }
            .section-resize-handle::after {
                content: ''; position: absolute; top: 50%; left: 50%;
                transform: translate(-50%, -50%); width: 3px; height: 36px;
                border-radius: 3px; background: rgba(59,130,246,0.45);
                opacity: 0; transition: opacity 0.15s;
            }
            .section-resize-handle:hover::after,
            .section-resize-handle.active::after { opacity: 1; }
            .resize-cols-badge {
                position: fixed; padding: 4px 12px; border-radius: 8px;
                background: rgba(30,58,138,0.9); color: #fff; font-size: 13px;
                font-weight: 700; pointer-events: none; z-index: 9999;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2); white-space: nowrap;
                transform: translate(-50%, -120%);
            }
            body.is-resizing-section { cursor: col-resize !important; user-select: none !important; }
            body.is-resizing-section * { cursor: col-resize !important; }
            body.is-resizing-section .dashboard-section,
            body.is-resizing-section .dashboard-section *,
            body.is-resizing-section .dashboard-section .card,
            body.is-resizing-section .dashboard-section .stat-card,
            body.is-resizing-section #dashboard-sections-sortable,
            body.is-resizing-section #dashboard-sections-sortable > * {
                transition: none !important;
                animation: none !important;
            }
        `;
        document.head.appendChild(_rsCSS);

        // Helper: resize chart inside a section
        function triggerChartResize(section) {
            const doResize = () => {
                try {
                    const canvas = section.querySelector('canvas');
                    if (canvas && typeof Chart !== 'undefined') {
                        const chart = Chart.getChart(canvas);
                        if (chart) { chart.resize(); chart.update(); }
                    }
                } catch (e) { }
            };
            doResize();
            setTimeout(doResize, 350);
        }

        // Add resize handles to each section
        document.querySelectorAll('.dashboard-section').forEach(section => {
            const handle = document.createElement('div');
            handle.className = 'section-resize-handle';
            handle.title = '\u0627\u0633\u062d\u0628 \u0644\u062a\u063a\u064a\u064a\u0631 \u0627\u0644\u062d\u062c\u0645';
            section.appendChild(handle);
        });

        // Drag state
        let _rs = null;

        // Floating badge
        const _rsBadge = document.createElement('div');
        _rsBadge.className = 'resize-cols-badge';
        _rsBadge.style.display = 'none';
        document.body.appendChild(_rsBadge);

        // Column labels
        const _colLabels = { 3: '\u0631\u0628\u0639', 4: '\u062b\u0644\u062b', 5: '5/12', 6: '\u0646\u0635\u0641', 7: '7/12', 8: '\u062b\u0644\u062b\u064a\u0646', 9: '\u00be', 10: '10/12', 11: '11/12', 12: '\u0643\u0627\u0645\u0644' };

        document.addEventListener('mousedown', function (e) {
            const handle = e.target.closest('.section-resize-handle');
            if (!handle) return;
            e.preventDefault();
            e.stopPropagation();
            const section = handle.closest('.dashboard-section');
            if (!section) return;
            const row = section.parentElement;
            handle.classList.add('active');
            document.body.classList.add('is-resizing-section');
            if (sortableInstance) {
                sortableInstance.option('disabled', true);
            }

            const initialWidth = section.getBoundingClientRect().width;
            section.style.width = initialWidth + 'px';
            section.style.flex = '0 0 auto';

            _rs = {
                section,
                handle,
                rowWidth: row.getBoundingClientRect().width,
                startX: e.clientX,
                startWidth: initialWidth
            };
        });

        document.addEventListener('mousemove', function (e) {
            if (!_rs) return;
            e.preventDefault();
            const { section, rowWidth, startX, startWidth } = _rs;
            const isRTL = getComputedStyle(document.documentElement).direction === 'rtl';

            const deltaX = e.clientX - startX;
            const deltaWidth = isRTL ? -deltaX : deltaX;
            let newWidth = startWidth + deltaWidth;
            newWidth = Math.max(rowWidth * (3 / 12), Math.min(rowWidth, newWidth));

            // Set inline width dynamically during drag without reflowing Bootstrap layout
            section.style.width = newWidth + 'px';

            // Real-time Chart Resize (Scales chart instantly during drag)
            try {
                const canvas = section.querySelector('canvas');
                if (canvas && typeof Chart !== 'undefined') {
                    const chart = Chart.getChart(canvas);
                    if (chart) {
                        chart.resize();
                    }
                }
            } catch (chartErr) { }

            let cols = Math.round((newWidth / rowWidth) * 12);
            cols = Math.max(3, Math.min(12, cols));

            // Update badge
            _rsBadge.textContent = (_colLabels[cols] || cols) + '  (' + cols + '/12)';
            _rsBadge.style.display = 'block';
            _rsBadge.style.left = e.clientX + 'px';
            _rsBadge.style.top = e.clientY + 'px';
        });

        document.addEventListener('mouseup', function () {
            if (!_rs) return;
            const { section, rowWidth } = _rs;

            const finalWidth = section.getBoundingClientRect().width;
            let cols = Math.round((finalWidth / rowWidth) * 12);
            cols = Math.max(3, Math.min(12, cols));

            const id = section.dataset.sectionId;
            sectionSizes[id] = cols >= 12 ? 'col-12' : 'col-lg-' + cols;
            localStorage.setItem('eduCoreDashboardSizes', JSON.stringify(sectionSizes));

            // Smooth snap transition to grid lines
            const targetWidth = rowWidth * (cols / 12);
            section.style.transition = 'width 0.2s cubic-bezier(0.25, 0.8, 0.25, 1)';
            section.style.width = targetWidth + 'px';

            setTimeout(() => {
                // Apply final grid class after snapping animation
                Array.from(section.classList).forEach(c => {
                    if (c.startsWith('col-')) section.classList.remove(c);
                });
                if (cols >= 12) {
                    section.classList.add('col-12');
                } else {
                    section.classList.add('col-12', 'col-lg-' + cols);
                }

                // Clean up inline styles
                section.style.width = '';
                section.style.flex = '';
                section.style.transition = '';

                triggerChartResize(section);
            }, 200);

            _rs.handle.classList.remove('active');
            document.body.classList.remove('is-resizing-section');
            if (sortableInstance) {
                sortableInstance.option('disabled', false);
            }
            _rsBadge.style.display = 'none';
            _rs = null;
        });

        // Load Saved Configuration
        let prefs = {};
        try {
            const storedPrefs = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            prefs = storedPrefs && typeof storedPrefs === 'object' && !Array.isArray(storedPrefs)
                ? storedPrefs
                : {};
        } catch (error) {
            localStorage.removeItem(STORAGE_KEY);
            prefs = {};
        }
        const hasPrefs = localStorage.getItem(STORAGE_KEY) !== null;

        // Add pulse effect if no preferences are saved yet
        const floatBtn = document.querySelector('.btn-gear-float');
        if (!hasPrefs && floatBtn) {
            floatBtn.classList.add('pulse-effect');
        }

        // Helper functions for smooth hiding/showing of cards
        const showWidget = (el) => {
            if (!el) return;
            el.style.display = ''; // Restore default display
            el.classList.add('card-fade-transition');
            el.classList.add('card-fade-hidden');
            // Force reflow
            el.offsetHeight;
            el.classList.remove('card-fade-hidden');
            setTimeout(() => {
                el.classList.remove('card-fade-transition');
            }, 300);
        };

        const hideWidget = (el) => {
            if (!el) return;
            el.classList.add('card-fade-transition');
            el.classList.add('card-fade-hidden');
            setTimeout(() => {
                el.style.display = 'none';
                el.classList.remove('card-fade-transition');
            }, 300);
        };

        // Update customization badge
        const updateBadge = () => {
            const badge = document.getElementById('customizer-badge');
            if (!badge) return;
            let hiddenCount = 0;

            // Count hidden cards
            document.querySelectorAll('.dashboard-card').forEach(card => {
                const id = card.dataset.cardId;
                if (prefs[id] === false) hiddenCount++;
            });

            // Count hidden sections
            document.querySelectorAll('.dashboard-section').forEach(section => {
                const id = section.dataset.sectionId;
                if (prefs[id] === false) hiddenCount++;
            });

            if (hiddenCount > 0) {
                badge.textContent = hiddenCount;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        };

        // Apply Preferences
        const applyPrefs = (animate = false) => {
            // Apply to Cards
            document.querySelectorAll('.dashboard-card').forEach(card => {
                const id = card.dataset.cardId;
                const isVisible = prefs[id] !== false;

                if (animate) {
                    if (isVisible) showWidget(card);
                    else hideWidget(card);
                } else {
                    card.style.display = isVisible ? 'block' : 'none';
                }

                const toggle = document.querySelector(`.widget-toggle[data-target="${id}"]`);
                if (toggle) toggle.checked = isVisible;
            });

            // Apply to Sections
            document.querySelectorAll('.dashboard-section').forEach(section => {
                const id = section.dataset.sectionId;
                const isVisible = prefs[id] !== false;

                if (animate) {
                    if (isVisible) showWidget(section);
                    else hideWidget(section);
                } else {
                    section.style.display = isVisible ? 'block' : 'none';
                }

                const toggle = document.querySelector(`.widget-toggle[data-section="${id}"]`);
                if (toggle) toggle.checked = isVisible;
            });

            updateBadge();
        };

        applyPrefs(false);

        // Handle Toggles
        document.querySelectorAll('.widget-toggle').forEach(input => {
            input.addEventListener('change', function () {
                const isChecked = this.checked;
                const targetId = this.dataset.target || this.dataset.section;

                if (targetId) {
                    // Remove pulse effect on interaction
                    if (floatBtn) floatBtn.classList.remove('pulse-effect');

                    // Update UI
                    const el = document.querySelector(`[data-card-id="${targetId}"], [data-section-id="${targetId}"]`);
                    if (el) {
                        if (isChecked) showWidget(el);
                        else hideWidget(el);
                    }

                    // Save to Prefs
                    prefs[targetId] = isChecked;
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
                    updateBadge();
                }
            });
        });

        // Handle Presets
        document.querySelectorAll('.preset-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const presetKey = this.dataset.preset;
                if (presets[presetKey]) {
                    // Remove pulse effect
                    if (floatBtn) floatBtn.classList.remove('pulse-effect');

                    prefs = { ...presets[presetKey] };
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
                    applyPrefs(true);
                }
            });
        });

        // Handle Reset Defaults
        const resetBtn = document.getElementById('reset-dashboard-prefs');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                localStorage.removeItem(STORAGE_KEY);
                localStorage.removeItem('eduCoreDashboardOrder');
                localStorage.removeItem('eduCoreDashboardSizes');
                prefs = {};
                sectionSizes = {};

                // Reset column classes to default
                document.querySelectorAll('.dashboard-section').forEach(section => {
                    const defaultSize = section.dataset.defaultSize || 'col-12';
                    section.classList.remove('col-12', 'col-lg-8', 'col-lg-6', 'col-lg-4');
                    section.classList.add(defaultSize);

                    const canvas = section.querySelector('canvas');
                    if (canvas) {
                        const chart = Chart.getChart(canvas);
                        if (chart) {
                            chart.resize();
                        }
                    }
                });

                if (floatBtn) floatBtn.classList.remove('pulse-effect');
                applyPrefs(true);
            });
        }
    });
</script>
