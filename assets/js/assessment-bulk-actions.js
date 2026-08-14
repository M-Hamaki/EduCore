(function () {
    'use strict';

    function dataTableFor(table) {
        if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable || !jQuery.fn.DataTable.isDataTable(table)) {
            return null;
        }
        return jQuery(table).DataTable();
    }

    function rowCheckboxes(root) {
        var table = root.querySelector('table');
        var dataTable = table ? dataTableFor(table) : null;
        if (dataTable) {
            return Array.from(dataTable.rows().nodes()).map(function (row) {
                return row.querySelector('.assessment-row-select');
            }).filter(function (checkbox) {
                return checkbox && !checkbox.disabled;
            });
        }
        return Array.from(root.querySelectorAll('.assessment-row-select'));
    }

    function currentPageCheckboxes(root) {
        var table = root.querySelector('table');
        var dataTable = table ? dataTableFor(table) : null;
        if (dataTable) {
            return Array.from(dataTable.rows({ page: 'current', search: 'applied' }).nodes()).map(function (row) {
                return row.querySelector('.assessment-row-select');
            }).filter(function (checkbox) {
                return checkbox && !checkbox.disabled;
            });
        }
        return rowCheckboxes(root).filter(function (checkbox) {
            var row = checkbox.closest('tr');
            return row && row.style.display !== 'none';
        });
    }

    function selectedIds(root) {
        return rowCheckboxes(root).filter(function (checkbox) {
            return checkbox.checked;
        }).map(function (checkbox) {
            return checkbox.value;
        });
    }

    function updateState(root) {
        var ids = selectedIds(root);
        var count = root.querySelector('[data-assessment-selected-count]');
        var pageCheckboxes = currentPageCheckboxes(root);
        var pageSelected = pageCheckboxes.filter(function (checkbox) { return checkbox.checked; }).length;
        var selectAll = root.querySelector('.assessment-select-page');
        var actionBar = root.querySelector('.admin-bulk-action-bar');

        if (count) {
            count.textContent = String(ids.length);
        }
        if (actionBar) {
            actionBar.classList.toggle('d-none', ids.length === 0);
        }
        root.querySelectorAll('.assessment-bulk-trigger').forEach(function (button) {
            button.disabled = ids.length === 0;
        });
        if (selectAll) {
            selectAll.checked = pageCheckboxes.length > 0 && pageSelected === pageCheckboxes.length;
            selectAll.indeterminate = pageSelected > 0 && pageSelected < pageCheckboxes.length;
        }
    }

    function configureModal(root, ids, operation, name, active) {
        var modal = document.getElementById(root.dataset.bulkModal || 'assessmentBulkActionModal');
        if (!modal || !ids.length) {
            return;
        }
        var input = modal.querySelector('[name="selected_ids"]');
        var count = modal.querySelector('[data-bulk-modal-count]');
        var title = modal.querySelector('[data-bulk-modal-title]');
        var message = modal.querySelector('[data-bulk-modal-message]');
        var deactivateButton = modal.querySelector('[data-bulk-deactivate-submit]');
        var deleteButton = modal.querySelector('[data-bulk-delete-submit]');
        var entityLabel = root.dataset.entityLabel || 'السجلات';
        var deactivateLabel = root.dataset.deactivateLabel || 'تعطيل';
        var single = ids.length === 1 && name;

        input.value = ids.join(',');
        if (count) count.textContent = String(ids.length);
        if (title) title.textContent = operation === 'deactivate' ? deactivateLabel + ' ' + entityLabel : 'حذف ' + entityLabel;

        if (operation === 'deactivate') {
            if (message) message.textContent = single
                ? 'سيتم ' + deactivateLabel + ' «' + name + '» مع الاحتفاظ بالبيانات التاريخية.'
                : 'سيتم ' + deactivateLabel + ' جميع السجلات المحددة داخل عملية واحدة.';
            if (deactivateButton) {
                deactivateButton.classList.remove('d-none');
                deactivateButton.innerHTML = '<i class="fas fa-ban me-1"></i>' + deactivateLabel;
            }
            if (deleteButton) deleteButton.classList.add('d-none');
        } else {
            if (message) message.textContent = single
                ? 'سيحاول النظام حذف «' + name + '». إذا كان نشطًا فسيتم ' + deactivateLabel + ' أولًا، ولن يحدث أي تغيير إذا وُجد ارتباط يمنع الحذف.'
                : 'العملية ذرية: تُفحص كل السجلات أولًا، ثم تُعطّل/تُغلق السجلات النشطة وتُحذف الدفعة كاملة. وجود ارتباط مانع في أي سجل يلغي العملية كلها.';
            if (deactivateButton) {
                deactivateButton.classList.toggle('d-none', active === false);
                deactivateButton.innerHTML = '<i class="fas fa-ban me-1"></i>' + deactivateLabel + ' فقط';
            }
            if (deleteButton) {
                deleteButton.classList.remove('d-none');
                deleteButton.innerHTML = '<i class="fas fa-trash me-1"></i>' + (active ? deactivateLabel + ' ثم حذف' : 'حذف');
            }
        }

        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-assessment-bulk-root]').forEach(function (root) {
            var selectAll = root.querySelector('.assessment-select-page');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    currentPageCheckboxes(root).forEach(function (checkbox) {
                        checkbox.checked = selectAll.checked;
                    });
                    updateState(root);
                });
            }

            root.addEventListener('change', function (event) {
                if (event.target.classList.contains('assessment-row-select')) {
                    updateState(root);
                }
            });

            root.querySelectorAll('.assessment-bulk-trigger').forEach(function (button) {
                button.addEventListener('click', function () {
                    var ids = selectedIds(root);
                    if (this.dataset.copyTarget) {
                        var copyInput = document.getElementById(this.dataset.copyTarget);
                        var copyModal = document.getElementById(this.dataset.copyModal || 'bulkCopySchemeModal');
                        if (copyInput && copyModal && ids.length) {
                            copyInput.value = ids.join(',');
                            var copyCount = copyModal.querySelector('[data-bulk-copy-count]');
                            if (copyCount) copyCount.textContent = String(ids.length);
                            bootstrap.Modal.getOrCreateInstance(copyModal).show();
                        }
                        return;
                    }
                    configureModal(root, ids, this.dataset.operation || 'deactivate', '', null);
                });
            });

            root.querySelectorAll('.assessment-smart-delete').forEach(function (button) {
                button.addEventListener('click', function () {
                    configureModal(
                        root,
                        [this.dataset.rowId],
                        'delete',
                        this.dataset.rowName || '',
                        this.dataset.rowActive === '1'
                    );
                });
            });

            var table = root.querySelector('table');
            if (table && window.jQuery) {
                jQuery(table).on('draw.dt', function () { updateState(root); });
            }
            updateState(root);
        });
    });
})();
