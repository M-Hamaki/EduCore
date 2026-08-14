(function (window, document, $) {
    'use strict';

    var budgetFilterStoragePrefix = 'educore_school_budget_filters_';
    var budgetFilterIds = {
        detailed: { stage: 'stageFilter', grade: 'gradeFilter', class: 'classFilter' },
        buffer: { stage: 'bufferStageFilter', grade: 'bufferGradeFilter', class: 'bufferClassFilter' },
        historical: { stage: 'historicalStageFilter', grade: 'historicalGradeFilter', class: 'historicalClassFilter' }
    };

    window.markBudgetStageBreaks = function (tableId) {
        var previousStage = null;
        $('#' + tableId + ' tbody tr').each(function (index) {
            var stage = this.getAttribute('data-budget-stage') || '';
            this.classList.toggle('budget-stage-break', index > 0 && stage !== previousStage);
            previousStage = stage;
        });
    };

    window.initializeSchoolBudgetFilters = function (tables) {
        var table = tables.detailed;
        var bufferTable = tables.buffer;
        var historicalTable = tables.historical;
        var historicalFilterBaseline = null;
        var historicalFilterDimensionActive = false;

        function normalizeBudgetFilterValues(value) {
            var values = Array.isArray(value) ? value : (value === undefined || value === null || value === '' ? [] : [value]);
            var seen = {};
            return values.map(function (item) {
                return String(item);
            }).filter(function (item) {
                if (!item || seen[item]) {
                    return false;
                }
                seen[item] = true;
                return true;
            });
        }

        function readBudgetFilterState(tabId) {
            var defaults = { stage: [], grade: [], class: [] };
            try {
                var raw = window.localStorage.getItem(budgetFilterStoragePrefix + tabId);
                var stored = raw ? JSON.parse(raw) : null;
                if (!stored || typeof stored !== 'object') {
                    return defaults;
                }
                return {
                    stage: normalizeBudgetFilterValues(stored.stage),
                    grade: normalizeBudgetFilterValues(stored.grade),
                    class: normalizeBudgetFilterValues(stored.class)
                };
            } catch (error) {
                return defaults;
            }
        }

        function writeBudgetFilterState(tabId, state) {
            try {
                window.localStorage.setItem(budgetFilterStoragePrefix + tabId, JSON.stringify({
                    stage: normalizeBudgetFilterValues(state.stage),
                    grade: normalizeBudgetFilterValues(state.grade),
                    class: normalizeBudgetFilterValues(state.class)
                }));
            } catch (error) {
                // تعمل الفلاتر خلال الجلسة حتى عند منع التخزين المحلي.
            }
        }

        function budgetFilterElement(tabId, type) {
            var ids = budgetFilterIds[tabId] || {};
            return document.getElementById(ids[type] || '');
        }

        function budgetFilterInputs(element) {
            return element ? Array.prototype.slice.call(element.querySelectorAll('input[data-budget-filter-option]')) : [];
        }

        function budgetFilterOptionItem(input) {
            return input ? input.closest('[data-budget-option-item]') || input.closest('label') || input.parentElement : null;
        }

        function selectedBudgetFilterValues(tabId, type) {
            return budgetFilterInputs(budgetFilterElement(tabId, type)).filter(function (input) {
                return input.checked && !input.disabled;
            }).map(function (input) {
                return String(input.value);
            });
        }

        function updateBudgetMultiSelectLabel(element, values) {
            if (!element) {
                return;
            }
            var label = element.querySelector('[data-budget-filter-label]');
            if (!label) {
                return;
            }
            var normalized = normalizeBudgetFilterValues(values);
            var toggle = element.querySelector('.budget-multiselect-toggle');
            if (toggle) {
                toggle.classList.toggle('active-filter', normalized.length > 0);
            }
            if (!normalized.length) {
                label.textContent = element.getAttribute('data-budget-all-label') || 'الكل';
                return;
            }
            var visibleInputs = budgetFilterInputs(element).filter(function (input) {
                return !input.disabled;
            });
            if (normalized.length === visibleInputs.length && visibleInputs.length > 0) {
                label.textContent = element.getAttribute('data-budget-all-label') || 'الكل';
                return;
            }
            var optionLabels = visibleInputs.filter(function (input) {
                return normalized.indexOf(String(input.value)) !== -1;
            }).map(function (input) {
                return input.getAttribute('data-budget-label') || input.value;
            });
            label.textContent = normalized.length <= 2 ? optionLabels.join('، ') : normalized.length + ' محددة';
        }

        function setBudgetFilterValues(tabId, type, values) {
            var element = budgetFilterElement(tabId, type);
            var normalized = normalizeBudgetFilterValues(values);
            budgetFilterInputs(element).forEach(function (input) {
                input.checked = normalized.indexOf(String(input.value)) !== -1 && !input.disabled;
            });
            updateBudgetMultiSelectLabel(element, selectedBudgetFilterValues(tabId, type));
        }

        function currentBudgetFilterState(tabId) {
            return {
                stage: selectedBudgetFilterValues(tabId, 'stage'),
                grade: selectedBudgetFilterValues(tabId, 'grade'),
                class: selectedBudgetFilterValues(tabId, 'class')
            };
        }

        // نفس آلية صفحة الطلاب المقيدين: المرحلة تحدد الصفوف المتاحة،
        // والصفوف تحدد الفصول المتاحة، مع الاحتفاظ بالاختيار المتعدد.
        function syncBudgetDependentOptions(tabId, stages, grades) {
            var selectedStages = normalizeBudgetFilterValues(stages);
            var selectedGrades = normalizeBudgetFilterValues(grades);
            var gradeElement = budgetFilterElement(tabId, 'grade');
            var classElement = budgetFilterElement(tabId, 'class');

            budgetFilterInputs(gradeElement).forEach(function (input) {
                var optionStage = input.getAttribute('data-budget-stage') || '';
                var visible = !selectedStages.length || selectedStages.indexOf(optionStage) !== -1;
                var optionItem = budgetFilterOptionItem(input);
                input.disabled = !visible;
                if (optionItem) {
                    optionItem.hidden = !visible;
                }
                if (!visible) {
                    input.checked = false;
                }
            });

            budgetFilterInputs(classElement).forEach(function (input) {
                var optionStage = input.getAttribute('data-budget-stage') || '';
                var optionGrade = input.getAttribute('data-budget-grade') || '';
                var visible = (!selectedStages.length || selectedStages.indexOf(optionStage) !== -1)
                    && (!selectedGrades.length || selectedGrades.indexOf(optionGrade) !== -1);
                var optionItem = budgetFilterOptionItem(input);
                input.disabled = !visible;
                if (optionItem) {
                    optionItem.hidden = !visible;
                }
                if (!visible) {
                    input.checked = false;
                }
            });

            setBudgetFilterValues(tabId, 'grade', selectedBudgetFilterValues(tabId, 'grade'));
            setBudgetFilterValues(tabId, 'class', selectedBudgetFilterValues(tabId, 'class'));
        }

        function applyBudgetFilterInputs(tabId, state) {
            ['stage', 'grade', 'class'].forEach(function (type) {
                setBudgetFilterValues(tabId, type, state[type]);
            });
            syncBudgetDependentOptions(tabId, state.stage || [], state.grade || []);
        }

        function escapeBudgetRegex(value) {
            return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        function exactBudgetColumnSearch(dataTable, columnIndex, values) {
            var selected = normalizeBudgetFilterValues(values);
            var search = selected.length ? '^(?:' + selected.map(escapeBudgetRegex).join('|') + ')$' : '';
            dataTable.column(columnIndex).search(search, true, false);
        }

        function applyDetailedBudgetFilters() {
            var currentState = currentBudgetFilterState('detailed');
            syncBudgetDependentOptions('detailed', currentState.stage, currentState.grade);
            var state = currentBudgetFilterState('detailed');
            writeBudgetFilterState('detailed', state);
            exactBudgetColumnSearch(table, 1, state.stage);
            exactBudgetColumnSearch(table, 2, state.grade);
            exactBudgetColumnSearch(table, 3, state.class);
            table.draw();
        }

        function budgetClassCountForSelection(rawCounts, selectedClasses) {
            var selected = normalizeBudgetFilterValues(selectedClasses);
            if (!selected.length) {
                return null;
            }
            try {
                var classCounts = JSON.parse(rawCounts || '{}');
                return selected.reduce(function (total, className) {
                    return total + (parseInt(classCounts[className] || 0, 10) || 0);
                }, 0);
            } catch (error) {
                return 0;
            }
        }

        function applyBufferBudgetFilters() {
            var currentState = currentBudgetFilterState('buffer');
            syncBudgetDependentOptions('buffer', currentState.stage, currentState.grade);
            var state = currentBudgetFilterState('buffer');
            writeBudgetFilterState('buffer', state);
            bufferTable.rows().every(function () {
                var rowApi = this;
                var row = this.node();
                var rowData = this.data();
                var count = parseInt(row.getAttribute('data-budget-base-count') || rowData[2] || 0, 10) || 0;
                var filteredCount = budgetClassCountForSelection(row.getAttribute('data-budget-class-counts'), state.class);
                if (filteredCount !== null) {
                    count = filteredCount;
                }
                rowData[2] = count;
                rowData[3] = Math.ceil(count * 1.10);
                rowApi.data(rowData);
            });
            bufferTable.draw();
        }

        function numericBudgetValue(value) {
            var normalized = String(value === undefined || value === null ? '' : value).replace(/<[^>]*>/g, '').replace(/[^0-9.-]/g, '');
            var parsed = parseInt(normalized, 10);
            return isNaN(parsed) ? 0 : parsed;
        }

        function captureHistoricalFilterBaseline() {
            historicalFilterBaseline = {};
            historicalTable.columns().every(function () {
                historicalFilterBaseline[this.index()] = this.visible();
            });
        }

        function restoreHistoricalFilterBaseline() {
            if (!historicalFilterBaseline) {
                return;
            }
            historicalTable.columns().every(function () {
                var index = this.index();
                this.visible(historicalFilterBaseline[index] !== false, false);
            });
        }

        function applyHistoricalBudgetFilters() {
            var state = currentBudgetFilterState('historical');
            syncBudgetDependentOptions('historical', state.stage, state.grade);
            state = currentBudgetFilterState('historical');
            writeBudgetFilterState('historical', state);
            var hasColumnFilter = state.stage.length > 0 || state.grade.length > 0;
            if (hasColumnFilter && !historicalFilterDimensionActive) {
                captureHistoricalFilterBaseline();
                historicalFilterDimensionActive = true;
            } else if (!hasColumnFilter && historicalFilterDimensionActive) {
                restoreHistoricalFilterBaseline();
                historicalFilterDimensionActive = false;
            }

            var selectedGradeColumns = [];
            document.querySelectorAll('#historicalTable thead th[data-budget-grade-id]').forEach(function (header) {
                var headerStage = header.getAttribute('data-budget-stage') || '';
                var gradeId = header.getAttribute('data-budget-grade-id') || '';
                var matches = (!state.stage.length || state.stage.indexOf(headerStage) !== -1)
                    && (!state.grade.length || state.grade.indexOf(gradeId) !== -1);
                if (matches) {
                    selectedGradeColumns.push(parseInt(header.cellIndex, 10));
                }
            });

            historicalTable.columns().every(function () {
                var header = this.header();
                var gradeId = header ? header.getAttribute('data-budget-grade-id') : '';
                var baselineVisible = !historicalFilterBaseline || historicalFilterBaseline[this.index()] !== false;
                this.visible(baselineVisible && (!gradeId || selectedGradeColumns.indexOf(this.index()) !== -1), false);
            });

            var totalColumnIndex = historicalTable.columns().count() - 1;
            historicalTable.rows().every(function () {
                var rowApi = this;
                var rowNode = rowApi.node();
                var rowData = this.data();
                document.querySelectorAll('#historicalTable thead th[data-budget-grade-id]').forEach(function (header) {
                    var columnIndex = parseInt(header.cellIndex, 10);
                    var cell = rowNode && rowNode.cells ? rowNode.cells[columnIndex] : null;
                    if (!cell) {
                        return;
                    }
                    var count = parseInt(cell.getAttribute('data-budget-base-count') || rowData[columnIndex] || 0, 10) || 0;
                    var filteredCount = budgetClassCountForSelection(cell.getAttribute('data-budget-class-counts'), state.class);
                    if (filteredCount !== null) {
                        count = filteredCount;
                    }
                    rowData[columnIndex] = count;
                });
                var total = 0;
                selectedGradeColumns.forEach(function (columnIndex) {
                    total += numericBudgetValue(rowData[columnIndex]);
                });
                rowData[totalColumnIndex] = total;
                rowApi.data(rowData);
            });
            historicalTable.draw(false);
        }

        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
            if (!settings.nTable || settings.nTable.id !== 'bufferTable') {
                return true;
            }
            var rowInfo = settings.aoData[dataIndex];
            var row = rowInfo && rowInfo.nTr;
            if (!row) {
                return true;
            }
            var state = currentBudgetFilterState('buffer');
            var classCount = budgetClassCountForSelection(row.getAttribute('data-budget-class-counts'), state.class);
            return (!state.stage.length || state.stage.indexOf(row.getAttribute('data-budget-stage') || '') !== -1)
                && (!state.grade.length || state.grade.indexOf(row.getAttribute('data-budget-grade') || '') !== -1)
                && (classCount === null || classCount > 0);
        });

        function applyBudgetFilterState(tabId) {
            var state = readBudgetFilterState(tabId);
            applyBudgetFilterInputs(tabId, state);
            if (tabId === 'detailed') {
                applyDetailedBudgetFilters();
            } else if (tabId === 'buffer') {
                applyBufferBudgetFilters();
            } else if (tabId === 'historical') {
                applyHistoricalBudgetFilters();
            }
        }

        function resetBudgetFilters(tabId) {
            var state = { stage: [], grade: [], class: [] };
            writeBudgetFilterState(tabId, state);
            applyBudgetFilterInputs(tabId, state);
            if (tabId === 'detailed') {
                applyDetailedBudgetFilters();
            } else if (tabId === 'buffer') {
                applyBufferBudgetFilters();
            } else if (tabId === 'historical') {
                applyHistoricalBudgetFilters();
            }
        }

        function closeBudgetMultiSelects(except) {
            document.querySelectorAll('.budget-multi-select').forEach(function (element) {
                if (element === except) {
                    return;
                }
                element.classList.remove('is-open');
                var toggle = element.querySelector('.budget-multiselect-toggle');
                var menu = element.querySelector('.budget-multiselect-menu');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
                if (menu) {
                    menu.classList.remove('show');
                }
            });
        }

        function refreshBudgetFilterTab(tabId) {
            if (tabId === 'detailed') {
                applyDetailedBudgetFilters();
            } else if (tabId === 'buffer') {
                applyBufferBudgetFilters();
            } else if (tabId === 'historical') {
                applyHistoricalBudgetFilters();
            }
        }

        $('.budget-multiselect-option input').on('change', function () {
            var wrapper = this.closest('.budget-multi-select');
            if (!wrapper) {
                return;
            }
            var tabId = wrapper.getAttribute('data-budget-tab') || '';
            updateBudgetMultiSelectLabel(wrapper, selectedBudgetFilterValues(tabId, wrapper.getAttribute('data-budget-filter') || ''));
            refreshBudgetFilterTab(tabId);
        });
        $('.budget-multiselect-clear').on('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var wrapper = this.closest('.budget-multi-select');
            if (!wrapper) {
                return;
            }
            var tabId = wrapper.getAttribute('data-budget-tab') || '';
            var filterType = wrapper.getAttribute('data-budget-filter') || '';
            budgetFilterInputs(wrapper).forEach(function (input) {
                input.checked = false;
            });
            updateBudgetMultiSelectLabel(wrapper, []);
            refreshBudgetFilterTab(tabId);
            setBudgetFilterValues(tabId, filterType, []);
        });
        $('#resetFilters').on('click', function () {
            resetBudgetFilters('detailed');
        });
        $('#resetBufferFilters').on('click', function () {
            resetBudgetFilters('buffer');
        });
        $('#resetHistoricalFilters').on('click', function () {
            resetBudgetFilters('historical');
        });
        $(document).on('click', function (event) {
            if (!event.target.closest('.budget-multi-select')) {
                closeBudgetMultiSelects(null);
            }
        });
        $(document).on('keydown', function (event) {
            if (event.key === 'Escape') {
                closeBudgetMultiSelects(null);
            }
        });

        window.captureHistoricalFilterBaseline = captureHistoricalFilterBaseline;
        window.applyBudgetFilterState = applyBudgetFilterState;
        window.resetBudgetFilters = resetBudgetFilters;
    };
}(window, document, window.jQuery));
