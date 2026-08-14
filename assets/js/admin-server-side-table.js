(function (window, document, $) {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function updateSummary(summary) {
        if (!summary || typeof summary !== 'object') return;
        Object.keys(summary).forEach(function (key) {
            var value = Number(summary[key]);
            if (!Number.isFinite(value)) return;
            document.querySelectorAll('[data-datatable-summary-key="' + key + '"]').forEach(function (element) {
                element.setAttribute('data-target', String(value));
                element.textContent = String(value);
            });
            document.querySelectorAll('[data-datatable-summary-visible="' + key + '"]').forEach(function (element) {
                element.classList.toggle('d-none', value <= 0);
            });
        });
    }

    function init(options) {
        if (!$ || !$.fn || !$.fn.DataTable) return null;

        var table = $(options.selector);
        if (!table.length || $.fn.dataTable.isDataTable(table)) return null;

        table.find('tbody').empty();
        var headerClasses = table.find('thead th').map(function () { return this.className; }).get();
        var configuration = {
            processing: true,
            serverSide: true,
            pageLength: 50,
            lengthMenu: [[10, 25, 50, 100, 200, 500, -1], [10, 25, 50, 100, 200, 500, 'الكل']],
            order: options.order || [[0, 'asc']],
            autoWidth: false,
            responsive: true,
            dom: '<"row dt-toolbar-top"<"col-sm-6"l><"col-sm-6"f>><"row dt-table-row"<"col-sm-12"tr>><"dt-footer-bar"ip>',
            ajax: {
                url: options.url,
                type: 'POST',
                data: function (data) {
                    data.csrf_token = csrfToken();
                    var extra = typeof options.requestData === 'function' ? options.requestData() : {};
                    Object.keys(extra || {}).forEach(function (key) { data[key] = extra[key]; });
                },
                dataSrc: function (response) {
                    updateSummary(response && response.summary);
                    return response && Array.isArray(response.data) ? response.data : [];
                }
            },
            language: $.extend(true, {
                search: 'البحث:',
                lengthMenu: 'عرض _MENU_ سجل',
                info: 'عرض _START_ إلى _END_ من أصل _TOTAL_ سجل',
                infoEmpty: 'عرض 0 إلى 0 من أصل 0 سجل',
                infoFiltered: '(منقح من _MAX_ سجل إجمالي)',
                processing: '<div class="admin-list-loading"><i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل البيانات…</div>',
                zeroRecords: 'لم يتم العثور على أي سجلات مطابقة',
                emptyTable: 'لا توجد بيانات متاحة في الجدول',
                paginate: { first: 'الأول', last: 'الأخير', next: 'التالي', previous: 'السابق' }
            }, options.language || {}),
            createdRow: function (row) {
                $(row).children('td').each(function (index) {
                    if (headerClasses[index]) this.className = headerClasses[index];
                });
                if (typeof options.decorateRow === 'function') options.decorateRow(row);
            },
            drawCallback: function () {
                document.querySelectorAll(options.selector + ' [data-bs-toggle="tooltip"]').forEach(function (el) {
                    bootstrap.Tooltip.getOrCreateInstance(el);
                });
                document.querySelectorAll(options.selector + ' [data-bs-toggle="popover"]').forEach(function (el) {
                    bootstrap.Popover.getOrCreateInstance(el, { sanitize: false });
                });
                var filterInput = document.querySelector(options.selector + '_filter input');
                if (filterInput && !filterInput.placeholder) {
                    filterInput.placeholder = 'الاسم أو الكود';
                }
                if (typeof options.onDraw === 'function') options.onDraw(this.api());
            }
        };

        // دمج خيارات DataTables الإضافية التي يمررها المستدعي (مثل searching: false)
        if (options.dtOptions && typeof options.dtOptions === 'object') {
            $.extend(true, configuration, options.dtOptions);
        }
        // دعم الاختصار المباشر: searching
        if (options.searching === false) {
            configuration.searching = false;
            // إزالة حقل البحث من dom
            configuration.dom = configuration.dom.replace('<"col-sm-6"f>', '');
        }

        return table.DataTable(configuration);
    }

    window.AdminServerSideTable = { init: init, updateSummary: updateSummary };
})(window, document, window.jQuery);
