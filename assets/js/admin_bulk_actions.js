/**
 * EduCore Admin Bulk Actions Handler
 * Manages DataTables row checkboxes, pagination persistence, filter change clearing,
 * bulk toolbar display, and AJAX command submission without DataTable reinitialization.
 */
(function (window, document, $) {
    'use strict';

    window.AdminBulkActions = function (options) {
        var tableSelector = options.tableSelector;
        var barSelector = options.barSelector;
        var endpointUrl = options.endpointUrl;
        var filterFormSelector = options.filterFormSelector;
        var filterInputSelectors = options.filterInputSelectors || [];
        var getFilterData = options.getFilterData || function () { return {}; };

        var selectedIds = new Set();
        var isFilteredMode = false;
        var dataTable = null;

        function getTable() {
            if (!dataTable && $.fn.DataTable && $.fn.DataTable.isDataTable(tableSelector)) {
                dataTable = $(tableSelector).DataTable();
            }
            return dataTable;
        }

        function updateUI() {
            var dt = getTable();
            var totalFilteredRecords = dt ? dt.page.info().recordsTotal : 0;
            if (dt && dt.page.info().recordsFiltered !== undefined) {
                totalFilteredRecords = dt.page.info().recordsFiltered;
            }

            var count = isFilteredMode ? totalFilteredRecords : selectedIds.size;
            var $bar = $(barSelector);

            if (count > 0) {
                $bar.removeClass('d-none');
                $bar.find('.bulk-selected-count').text(count);
                if (isFilteredMode) {
                    $bar.find('.bulk-mode-label').text('(كل النتائج المطابقة للفلاتر)');
                    $bar.find('.btn-select-all-filtered').addClass('d-none');
                } else {
                    $bar.find('.bulk-mode-label').text('(المحددة يدويًا)');
                    if (totalFilteredRecords > selectedIds.size) {
                        $bar.find('.btn-select-all-filtered')
                            .removeClass('d-none')
                            .find('.filtered-count-badge').text(totalFilteredRecords);
                    } else {
                        $bar.find('.btn-select-all-filtered').addClass('d-none');
                    }
                }
            } else {
                $bar.addClass('d-none');
            }

            // Sync page header select all checkbox
            var $headerCb = $(tableSelector + ' th .select-all-page');
            if ($headerCb.length) {
                var $pageCbs = $(tableSelector + ' tbody .row-select-cb');
                if ($pageCbs.length > 0) {
                    var allChecked = true;
                    $pageCbs.each(function () {
                        if (!selectedIds.has(String(this.value))) {
                            allChecked = false;
                        }
                    });
                    $headerCb.prop('checked', allChecked);
                } else {
                    $headerCb.prop('checked', false);
                }
            }
        }

        function syncRowCheckboxes() {
            $(tableSelector + ' tbody .row-select-cb').each(function () {
                var val = String(this.value);
                $(this).prop('checked', isFilteredMode || selectedIds.has(val));
            });
        }

        function clearSelection(showToast) {
            selectedIds.clear();
            isFilteredMode = false;
            syncRowCheckboxes();
            updateUI();

            if (showToast) {
                var $toast = $('#bulkFilterResetNotice');
                if ($toast.length) {
                    $toast.removeClass('d-none').hide().fadeIn(200).delay(2500).fadeOut(300);
                }
            }
        }

        // Initialize table event listeners
        $(document).ready(function () {
            $(tableSelector).on('draw.dt', function () {
                syncRowCheckboxes();
                updateUI();
            });

            // DataTables global search is a filter; stale hidden selections must not survive it.
            $(tableSelector).on('search.dt', function () {
                if (selectedIds.size > 0 || isFilteredMode) {
                    clearSelection(true);
                }
            });

            // Header Select All Page checkbox click
            $(document).on('change', tableSelector + ' th .select-all-page', function () {
                var isChecked = this.checked;
                isFilteredMode = false;
                $(tableSelector + ' tbody .row-select-cb').each(function () {
                    var val = String(this.value);
                    if (isChecked) {
                        selectedIds.add(val);
                    } else {
                        selectedIds.delete(val);
                    }
                    $(this).prop('checked', isChecked);
                });
                updateUI();
            });

            // Individual row checkbox click
            $(document).on('change', tableSelector + ' tbody .row-select-cb', function () {
                var val = String(this.value);
                if (isFilteredMode) {
                    // Exclusions are not part of the filtered-selection contract.
                    // Convert safely to the currently rendered page's manual selection.
                    isFilteredMode = false;
                    selectedIds.clear();
                    $(tableSelector + ' tbody .row-select-cb:checked').each(function () {
                        selectedIds.add(String(this.value));
                    });
                } else {
                    if (this.checked) {
                        selectedIds.add(val);
                    } else {
                        selectedIds.delete(val);
                    }
                }
                updateUI();
            });

            // Select all filtered results button click
            $(document).on('click', barSelector + ' .btn-select-all-filtered', function () {
                isFilteredMode = true;
                selectedIds.clear();
                syncRowCheckboxes();
                updateUI();
            });

            // Clear selection button click
            $(document).on('click', barSelector + ' .btn-clear-selection', function () {
                clearSelection(false);
            });

            // Bind filter change listeners to reset selection
            var filterSelectorsStr = filterInputSelectors.join(', ');
            if (filterFormSelector) {
                filterSelectorsStr += (filterSelectorsStr ? ', ' : '') + filterFormSelector + ' select, ' + filterFormSelector + ' input';
            }
            if (filterSelectorsStr) {
                $(document).on('change input', filterSelectorsStr, function () {
                    if (selectedIds.size > 0 || isFilteredMode) {
                        clearSelection(true);
                    }
                });
            }
        });

        function executeAction(action, extraParams, $modal, submitButtonSelector) {
            var csrfToken = (window.EduCore && window.EduCore.csrfToken) || $('meta[name="csrf-token"]').attr('content') || $('input[name="csrf_token"]').val();
            var payloadFilters = getFilterData() || {};
            var dt = getTable();
            if (dt && typeof dt.search === 'function') {
                payloadFilters.search_value = String(dt.search() || '').trim();
            }
            var payload = {
                csrf_token: csrfToken,
                action: action,
                selection_mode: isFilteredMode ? 'filtered' : 'selected',
                ids: Array.from(selectedIds),
                filters: payloadFilters
            };

            if (extraParams) {
                $.extend(payload, extraParams);
            }

            var $btn = submitButtonSelector ? $($modal).find(submitButtonSelector) : null;
            var originalBtnHtml = $btn ? $btn.html() : '';
            if ($btn) {
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>جاري التنفيذ…');
            }

            $.ajax({
                url: endpointUrl,
                type: 'POST',
                data: payload,
                dataType: 'json',
                success: function (res) {
                    if ($btn) {
                        $btn.prop('disabled', false).html(originalBtnHtml);
                    }
                    if ($modal) {
                        var modalInst = bootstrap.Modal.getInstance($modal[0]) || new bootstrap.Modal($modal[0]);
                        modalInst.hide();
                    }

                    if (res && res.success) {
                        clearSelection(false);
                        var dt = getTable();
                        if (dt) {
                            dt.ajax.reload(null, false);
                        }

                        // Trigger download if CSV provided
                        if (res.download_url) {
                            window.location.href = res.download_url;
                        }

                        var msg = res.message || 'تمت العملية بنجاح.';
                        if (window.showUndoToast) {
                            window.showUndoToast(msg, 'success');
                        } else {
                            alert(msg);
                        }
                    } else {
                        var errMsg = (res && res.message) ? res.message : 'حدث خطأ أثناء تنفيذ الإجراء.';
                        alert(errMsg);
                    }
                },
                error: function (xhr) {
                    if ($btn) {
                        $btn.prop('disabled', false).html(originalBtnHtml);
                    }
                    var errMsg = 'تعذر تنفيذ الإجراء الجماعي بسبب خطأ في الخادم.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errMsg = xhr.responseJSON.message;
                    }
                    alert(errMsg);
                }
            });
        }

        return {
            getSelectedCount: function () {
                var dt = getTable();
                return isFilteredMode ? (dt ? dt.page.info().recordsFiltered : 0) : selectedIds.size;
            },
            clearSelection: clearSelection,
            executeAction: executeAction
        };
    };
})(window, document, jQuery);
