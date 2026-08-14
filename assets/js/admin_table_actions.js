(function (window) {
    'use strict';

    function getTable(tableId) {
        return document.getElementById(tableId);
    }

    function isElementHidden(element) {
        if (!element) {
            return true;
        }

        if (element.style.display === 'none') {
            return true;
        }

        return window.getComputedStyle(element).display === 'none';
    }

    function getExportRows(table) {
        return Array.prototype.slice.call(table.querySelectorAll('tr')).filter(function (row) {
            return !isElementHidden(row);
        });
    }

    function buildVisibleTableHtml(table, excludeLastColumn) {
        var clonedTable = table.cloneNode(true);

        Array.prototype.slice.call(clonedTable.querySelectorAll('tr')).forEach(function (row) {
            if (isElementHidden(row)) {
                row.remove();
                return;
            }

            var cells = Array.prototype.slice.call(row.children);

            cells.forEach(function (cell) {
                if (isElementHidden(cell)) {
                    cell.remove();
                }
            });

            if (excludeLastColumn && row.lastElementChild) {
                row.lastElementChild.remove();
            }
        });

        return clonedTable.outerHTML;
    }

    function exportTableToCsv(tableId, filename, options) {
        var table = getTable(tableId);
        if (!table) {
            return;
        }

        var settings = options || {};
        var excludeLastColumn = settings.excludeLastColumn !== false;
        var rows = getExportRows(table);
        var csv = [];

        rows.forEach(function (row) {
            var cells = Array.prototype.slice.call(row.querySelectorAll('th, td')).filter(function (cell) {
                return !isElementHidden(cell);
            });

            if (excludeLastColumn && cells.length > 0) {
                cells.pop();
            }

            var line = cells.map(function (cell) {
                var text = (cell.innerText || '')
                    .replace(/(\r\n|\n|\r)/gm, ' ')
                    .replace(/"/g, '""')
                    .trim();
                return '"' + text + '"';
            });

            if (line.length > 0) {
                csv.push(line.join(','));
            }
        });

        var blob = new Blob(["\uFEFF" + csv.join("\n")], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        var url = URL.createObjectURL(blob);

        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function buildTableExportGrid(table, excludeLastColumn) {
        var allRows = Array.prototype.slice.call(table.querySelectorAll('tr'));
        var logicalGrid = [];

        allRows.forEach(function (row, rowIndex) {
            logicalGrid[rowIndex] = logicalGrid[rowIndex] || [];
            var columnIndex = 0;
            Array.prototype.slice.call(row.querySelectorAll(':scope > th, :scope > td')).forEach(function (cell) {
                // الجداول الرسمية تدمج المرحلة والصف بإخفاء الخلايا المكررة بعد إنشاء rowspan.
                // معالجة تلك النسخ المخفية كانت تزحزح بقية القيم إلى أعمدة خاطئة في Excel.
                if (isElementHidden(cell)) {
                    return;
                }
                while (typeof logicalGrid[rowIndex][columnIndex] !== 'undefined') {
                    columnIndex += 1;
                }

                var rowSpan = Math.max(parseInt(cell.getAttribute('rowspan') || '1', 10), 1);
                var colSpan = Math.max(parseInt(cell.getAttribute('colspan') || '1', 10), 1);
                var value = (cell.innerText || cell.textContent || '')
                    .replace(/(\r\n|\n|\r)/gm, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
                for (var rowOffset = 0; rowOffset < rowSpan; rowOffset += 1) {
                    logicalGrid[rowIndex + rowOffset] = logicalGrid[rowIndex + rowOffset] || [];
                    for (var colOffset = 0; colOffset < colSpan; colOffset += 1) {
                        logicalGrid[rowIndex + rowOffset][columnIndex + colOffset] = {
                            // Rowspans are repeated so every exported record is self-contained;
                            // colspan padding stays empty instead of duplicating totals/titles.
                            value: colOffset === 0 ? value : '',
                            visible: true
                        };
                    }
                }
                columnIndex += colSpan;
            });
        });

        var headerRow = table.querySelector('thead tr') || allRows[0];
        var headerIndex = Math.max(allRows.indexOf(headerRow), 0);
        var headerGrid = logicalGrid[headerIndex] || [];
        var includedColumns = headerGrid.map(function (entry, index) {
            return entry && entry.visible ? index : -1;
        }).filter(function (index) {
            return index >= 0;
        });

        if (excludeLastColumn && includedColumns.length > 0) {
            includedColumns.pop();
        }

        return allRows.reduce(function (rows, row, rowIndex) {
            if (isElementHidden(row)) {
                return rows;
            }
            var source = logicalGrid[rowIndex] || [];
            var values = includedColumns.map(function (columnIndex) {
                return source[columnIndex] ? source[columnIndex].value : '';
            });
            if (values.some(function (value) { return value !== ''; })) {
                rows.push(values);
            }
            return rows;
        }, []);
    }

    function exportTableToXlsx(tableId, filename, title, options) {
        var table = getTable(tableId);
        if (!table) {
            return Promise.reject(new Error('تعذر العثور على الجدول المطلوب.'));
        }

        var settings = options || {};
        var rows = buildTableExportGrid(table, settings.excludeLastColumn !== false);
        if (!rows.length) {
            return Promise.reject(new Error('لا توجد بيانات ظاهرة قابلة للتصدير.'));
        }

        var endpoint = settings.endpoint || 'export_table_xlsx.php';
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' };
        if (tokenMeta && tokenMeta.content) {
            headers['X-CSRF-Token'] = tokenMeta.content;
        }

        return window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify({
                report_key: settings.reportKey || 'report',
                title: title || 'تقرير',
                rows: rows
            })
        }).then(function (response) {
            if (!response.ok) {
                return response.text().then(function (message) {
                    throw new Error(message || 'تعذر إنشاء ملف Excel.');
                });
            }
            return response.blob();
        }).then(function (blob) {
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url;
            link.download = filename;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        });
    }

    function exportTableToPdf(tableId, title, options) {
        var table = getTable(tableId);
        if (!table) {
            return;
        }

        var settings = options || {};
        var excludeLastColumn = settings.excludeLastColumn !== false;
        var printableHtml = buildVisibleTableHtml(table, excludeLastColumn);
        var printWindow = window.open('', '_blank', 'width=1200,height=900');

        if (!printWindow) {
            alert('تعذر فتح نافذة التصدير. تأكد من السماح بالنوافذ المنبثقة.');
            return;
        }

        printWindow.document.open();
        printWindow.document.write(
            '<!DOCTYPE html>' +
            '<html lang="ar" dir="rtl">' +
            '<head>' +
            '<meta charset="UTF-8">' +
            '<title>' + title + '</title>' +
            '<style>' +
            'body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#1f2937;direction:rtl;}' +
            'h1{font-size:24px;margin:0 0 16px;text-align:right;}' +
            'p{margin:0 0 20px;color:#6b7280;font-size:13px;}' +
            'table{width:100%;border-collapse:collapse;font-size:13px;}' +
            'th,td{border:1px solid #d1d5db;padding:8px 10px;text-align:right;vertical-align:top;}' +
            'th{background:#eff6ff;color:#1d4ed8;font-weight:700;}' +
            '.badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #d1d5db;}' +
            '</style>' +
            '</head>' +
            '<body>' +
            '<h1>' + title + '</h1>' +
            '<p>تاريخ التصدير: ' + new Date().toLocaleString('ar-EG') + '</p>' +
            printableHtml +
            '</body>' +
            '</html>'
        );
        printWindow.document.close();

        printWindow.onload = function () {
            printWindow.focus();
            printWindow.print();
        };
    }

    function setColumnVisibility(table, columnIndex, visible) {
        // إذا كان الجدول مُهيّأً كـ DataTable، استخدم API الرسمي لضمان
        // بقاء الإخفاء بعد إعادة الرسم (search/sort/draw).
        var tableId = table.id;
        if (tableId && typeof $ !== 'undefined' && typeof $.fn !== 'undefined' &&
            typeof $.fn.dataTable !== 'undefined' && $.fn.dataTable.isDataTable('#' + tableId)) {
            try {
                var api = $('#' + tableId).DataTable();
                var col = api.column(columnIndex);
                if (col && typeof col.visible === 'function') {
                    col.visible(visible);
                    return;
                }
            } catch (e) {
                // تجاهل والانتقال للطريقة اليدوية
            }
        }

        // Fallback للجداول العادية (بدون DataTables)
        Array.prototype.slice.call(table.querySelectorAll('tr')).forEach(function (row) {
            if (row.children[columnIndex]) {
                row.children[columnIndex].style.display = visible ? '' : 'none';
            }
        });
    }

    function initializeTableColumnSettings(tableId, mapping, storageKey) {
        var table = getTable(tableId);
        if (!table) {
            return;
        }

        var saved = null;
        try {
            saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
        } catch (error) {
            saved = {};
        }

        // هل الجدول مرشّح ليُهيّأ كـ DataTable؟
        // لو نعم، يجب تأجيل التطبيق الأولي حتى تنتهي تهيئة DataTables
        // (لأن inline style يُلغى بعد draw()). نستخدم init event الخاص بـ DataTables.
        var isDataTableCandidate = table.classList.contains('datatable') ||
            (typeof $ !== 'undefined' && typeof $.fn !== 'undefined' &&
             typeof $.fn.dataTable !== 'undefined' && $.fn.dataTable.isDataTable('#' + tableId));

        function applyAll() {
            Object.keys(mapping).forEach(function (checkboxId) {
                var checkbox = document.getElementById(checkboxId);
                if (!checkbox) {
                    return;
                }
                setColumnVisibility(table, mapping[checkboxId], checkbox.checked);
            });
        }

        Object.keys(mapping).forEach(function (checkboxId) {
            var checkbox = document.getElementById(checkboxId);
            if (!checkbox) {
                return;
            }

            if (Object.prototype.hasOwnProperty.call(saved, checkboxId)) {
                checkbox.checked = !!saved[checkboxId];
            }

            // Toggle immediately on change and persist to localStorage
            checkbox.addEventListener('change', function () {
                setColumnVisibility(table, mapping[checkboxId], checkbox.checked);
                var current = {};
                try {
                    current = JSON.parse(localStorage.getItem(storageKey) || '{}');
                } catch (e) {
                    current = {};
                }
                current[checkboxId] = checkbox.checked;
                localStorage.setItem(storageKey, JSON.stringify(current));
            });
        });

        // التطبيق الأولي: فوري للجداول العادية، مؤجّل لجداول DataTables
        if (isDataTableCandidate && typeof $ !== 'undefined' && typeof $.fn !== 'undefined' &&
            typeof $.fn.dataTable !== 'undefined') {
            // التطبيق عند أول draw بعد التهيئة (يضمن بقاء الإخفاء عبر API الرسمي)
            $(document).one('init.dt', function () { applyAll(); });
            // fallback: إذا لم يُطلق init.dt (الجدول مُهيّأ بالفعل)، طبّق مباشرة
            setTimeout(function () {
                if ($.fn.dataTable.isDataTable('#' + tableId)) { applyAll(); }
            }, 0);
        } else {
            applyAll();
        }
    }

    function applyTableColumnSettings(tableId, mapping, storageKey, modalId) {
        var table = getTable(tableId);
        if (!table) {
            return;
        }

        var saved = {};

        Object.keys(mapping).forEach(function (checkboxId) {
            var checkbox = document.getElementById(checkboxId);
            if (!checkbox) {
                return;
            }

            saved[checkboxId] = checkbox.checked;
            setColumnVisibility(table, mapping[checkboxId], checkbox.checked);
        });

        localStorage.setItem(storageKey, JSON.stringify(saved));

        if (modalId && typeof bootstrap !== 'undefined') {
            var modalElement = document.getElementById(modalId);
            if (modalElement) {
                var modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }
            }
        }
    }

    window.exportTableToCsv = exportTableToCsv;
    window.exportTableToXlsx = exportTableToXlsx;
    window.exportTableToPdf = exportTableToPdf;
    window.initializeTableColumnSettings = initializeTableColumnSettings;
    window.applyTableColumnSettings = applyTableColumnSettings;
})(window);
