(function (root) {
    'use strict';

    var registry = [
        { key: 'lesson_plan', label: 'تحضير الدرس', values: ['lessonPlanContent', 'lesson-plan'], ids: ['lessonPlanContent'] },
        { key: 'question_bank', label: 'بنك الأسئلة', values: ['questionBankContent', 'question-bank'], ids: ['questionBankContent'] },
        { key: 'visual_materials', label: 'المواد البصرية', values: ['visualMaterialsContent', 'visual-materials'], ids: ['visualMaterialsContent'] },
        { key: 'class_activities', label: 'الأنشطة الصفية', values: ['classActivitiesContent', 'class-activities'], ids: ['classActivitiesContent'] },
        { key: 'mind_maps', label: 'الخرائط الذهنية', values: ['mindMapsContent', 'mind-maps'], ids: ['mindMapsContent', 'eduvisual-root'] },
        { key: 'lesson_summary', label: 'ملخص الدرس', values: ['lessonSummaryContent', 'lesson-summary'], ids: ['lessonSummaryContent'] },
        { key: 'educational_stories', label: 'القصة التربوية', values: ['educationalStoriesContent', 'educational-stories'], ids: ['educationalStoriesContent'] },
        { key: 'custom_content', label: 'المحتوى المخصص', values: ['customContentArea', 'custom-content'], ids: ['customContentArea'] },
        { key: 'exam', label: 'الامتحان الإلكتروني', values: ['exam', 'examPreviewContent', 'exam-preview'], ids: ['examPreviewContent', 'exam-preview'] }
    ];

    function normalizeKey(value) {
        var match = registry.find(function (entry) {
            return entry.key === value || entry.values.indexOf(value) !== -1 || entry.ids.indexOf(value) !== -1;
        });
        return match ? match.key : null;
    }

    function uniqueKeys(values) {
        var seen = new Set();
        return values.map(normalizeKey).filter(function (key) {
            if (!key || seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    }

    function getLessonId() {
        if (Number(root.currentLessonId) > 0) return Number(root.currentLessonId);
        try {
            if (typeof currentLessonId !== 'undefined' && Number(currentLessonId) > 0) {
                return Number(currentLessonId);
            }
            if (typeof viewLessonId !== 'undefined' && Number(viewLessonId) > 0) {
                return Number(viewLessonId);
            }
        } catch (error) {
            return 0;
        }
        return 0;
    }

    function selectedKeys() {
        var checkboxes = Array.from(document.querySelectorAll('.export-element-checkbox:checked:not(:disabled)'));
        return uniqueKeys(checkboxes.map(function (checkbox) { return checkbox.value; }));
    }

    function allAvailableKeys() {
        return registry.filter(function (entry) {
            return !!findContentElement(entry) || (entry.key === 'exam' && !!getExamHtml());
        }).map(function (entry) { return entry.key; });
    }

    function findEntry(key) {
        return registry.find(function (entry) { return entry.key === key; }) || null;
    }

    function findContentElement(entry) {
        for (var i = 0; i < entry.ids.length; i += 1) {
            var node = document.getElementById(entry.ids[i]);
            if (node && hasMeaningfulContent(node)) return node;
        }
        return null;
    }

    function hasMeaningfulContent(node) {
        if (!node) return false;
        return (node.textContent || '').replace(/\s+/g, ' ').trim() !== ''
            || !!node.querySelector('img,svg,canvas,iframe');
    }

    function removeDuplicateAggregatePanels(clone) {
        var aggregate = clone.querySelector('.sub-tab-content[id$="-all"]');
        if (!aggregate) return;
        clone.querySelectorAll('.sub-tab-content').forEach(function (panel) {
            if (panel !== aggregate) panel.remove();
        });
    }

    function cleanClone(node) {
        var clone = node.cloneNode(true);
        clone.querySelectorAll(
            'script,iframe,object,embed,form,button,input,select,textarea,.section-actions,.section-header-actions,.btn-regenerate-section,.btn-inline-edit,.btn-quick-copy,.sub-tabs-container,.no-print'
        ).forEach(function (item) { item.remove(); });
        removeDuplicateAggregatePanels(clone);
        clone.querySelectorAll('[hidden],.sub-tab-content,.tab-pane').forEach(function (item) {
            item.removeAttribute('hidden');
            item.removeAttribute('aria-hidden');
            item.style.removeProperty('display');
        });
        clone.querySelectorAll('[contenteditable]').forEach(function (item) {
            item.removeAttribute('contenteditable');
        });
        clone.querySelectorAll('[id]').forEach(function (item) {
            item.removeAttribute('id');
        });
        return clone;
    }

    function getExamHtml() {
        try {
            if (typeof examHtml !== 'undefined' && typeof examHtml === 'string' && examHtml.trim()) {
                return examHtml;
            }
        } catch (error) {
            // The generation page does not always have an exam.
        }
        if (root.generatedData && typeof root.generatedData.exam_html === 'string') {
            return root.generatedData.exam_html;
        }
        var frame = document.querySelector('#exam-preview iframe[srcdoc], #examPreviewContent iframe[srcdoc]');
        return frame ? frame.getAttribute('srcdoc') || '' : '';
    }

    function staticExamContent() {
        var source = getExamHtml();
        if (!source) return null;
        var parsed = new DOMParser().parseFromString(source, 'text/html');
        parsed.querySelectorAll('script,style,link,meta,base,iframe,object,embed,button').forEach(function (node) {
            node.remove();
        });
        parsed.querySelectorAll('input').forEach(function (input) {
            var marker = parsed.createElement('span');
            marker.textContent = input.checked ? '☑ ' : '☐ ';
            input.replaceWith(marker);
        });
        parsed.querySelectorAll('form').forEach(function (form) {
            form.replaceWith.apply(form, Array.from(form.childNodes));
        });
        return parsed.body;
    }

    function sectionFromKey(key) {
        var entry = findEntry(key);
        if (!entry) return null;

        var contentNode = entry.key === 'exam' ? staticExamContent() : null;
        if (!contentNode) {
            var source = findContentElement(entry);
            if (!source) return null;
            contentNode = cleanClone(source);
        }

        var text = (contentNode.textContent || '').replace(/\s+/g, ' ').trim();
        if (!text && !contentNode.querySelector('img,svg,canvas')) return null;

        var wrapper = document.createElement('section');
        wrapper.className = 'lesson-export-section';
        wrapper.dataset.exportKey = entry.key;
        var heading = document.createElement('h1');
        heading.textContent = entry.label;
        wrapper.appendChild(heading);
        Array.from(contentNode.childNodes).forEach(function (child) {
            wrapper.appendChild(child.cloneNode(true));
        });

        return {
            key: entry.key,
            text: text,
            html: wrapper.outerHTML
        };
    }

    function fingerprint(section) {
        var normalized = (section.text || '').replace(/\s+/g, ' ').trim();
        return normalized || section.html.replace(/\s+/g, ' ').trim();
    }

    function dedupeSections(sections) {
        var seenKeys = new Set();
        var seenContent = new Set();
        return sections.filter(function (section) {
            if (!section || seenKeys.has(section.key)) return false;
            var contentKey = fingerprint(section);
            if (!contentKey || seenContent.has(contentKey)) return false;
            seenKeys.add(section.key);
            seenContent.add(contentKey);
            return true;
        });
    }

    function collect(keys) {
        return dedupeSections(uniqueKeys(keys).map(sectionFromKey).filter(Boolean));
    }

    function lessonTitle() {
        var input = document.getElementById('title');
        if (input && input.value.trim()) return input.value.trim();
        var heading = document.querySelector('.header-text h1, .page-header h1');
        return heading && heading.textContent.trim() ? heading.textContent.trim() : 'الدرس';
    }

    function message(text, icon) {
        if (root.LessonDialog && typeof root.LessonDialog.fire === 'function') {
            root.LessonDialog.fire({
                icon: icon || 'info',
                title: icon === 'error' ? 'تعذر التصدير' : 'التصدير',
                text: text,
                confirmButtonText: 'حسنًا'
            });
            return;
        }
        root.alert(text);
    }

    function printableDocument(title, sections) {
        var safeTitle = title.replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character];
        });
        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>'
            + safeTitle
            + '</title><style>@page{margin:18mm}body{font-family:Arial,sans-serif;direction:rtl;color:#1e293b;line-height:1.75}'
            + '.lesson-export-section{page-break-before:always}.lesson-export-section:first-child{page-break-before:auto}'
            + '.lesson-export-section>h1{color:#1e3a8a;border-bottom:3px solid #2563eb;padding-bottom:10px}'
            + 'table{width:100%;border-collapse:collapse}th,td{border:1px solid #cbd5e1;padding:9px;text-align:right}'
            + 'img{max-width:100%;height:auto}.sub-tab-content{display:block!important}</style></head><body>'
            + sections.map(function (section) { return section.html; }).join('')
            + '</body></html>';
    }

    function downloadBlob(blob, filename) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function filenameFromResponse(response, fallback) {
        var disposition = response.headers.get('Content-Disposition') || '';
        var utf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8) {
            try { return decodeURIComponent(utf8[1]); } catch (error) { return fallback; }
        }
        return fallback;
    }

    function exportTransport() {
        var config = root.LessonExportConfig && typeof root.LessonExportConfig === 'object'
            ? root.LessonExportConfig
            : {};
        return {
            lessonId: getLessonId(),
            publicToken: typeof config.publicToken === 'string' ? config.publicToken.trim() : '',
            endpoint: typeof config.endpoint === 'string' && config.endpoint.trim()
                ? config.endpoint.trim()
                : 'lesson_export.php'
        };
    }

    async function exportToServer(format, sections) {
        var transport = exportTransport();
        if (!transport.lessonId && !transport.publicToken) {
            message('يجب حفظ الدرس أولًا قبل إنشاء ملف التصدير.', 'warning');
            return;
        }

        var formData = new FormData();
        if (transport.lessonId) {
            formData.append('lesson_id', String(transport.lessonId));
        }
        if (transport.publicToken) {
            formData.append('token', transport.publicToken);
        }
        formData.append('format', format);
        formData.append('content_html', sections.map(function (section) { return section.html; }).join(''));
        if (transport.lessonId) {
            var csrf = document.querySelector('meta[name="csrf-token"]');
            formData.append('csrf_token', csrf ? csrf.content : '');
        }

        var response = await fetch(transport.endpoint, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        var contentType = response.headers.get('Content-Type') || '';
        if (!response.ok || contentType.indexOf('application/json') !== -1) {
            var errorResult = await response.json().catch(function () { return {}; });
            throw new Error(errorResult.message || 'تعذر إنشاء ملف التصدير');
        }

        var extension = format === 'word' ? 'doc' : format;
        var blob = await response.blob();
        downloadBlob(blob, filenameFromResponse(response, lessonTitle() + '.' + extension));
    }

    async function run(format, keys) {
        var sections = collect(keys);
        if (!sections.length) {
            message('لا يوجد محتوى متاح ضمن العناصر المحددة.', 'warning');
            return false;
        }

        if (format === 'print') {
            var printWindow = root.open('', '_blank');
            if (!printWindow) {
                message('اسمح بفتح النوافذ المنبثقة لإتمام الطباعة.', 'warning');
                return false;
            }
            printWindow.document.write(printableDocument(lessonTitle(), sections));
            printWindow.document.close();
            printWindow.addEventListener('load', function () { printWindow.print(); }, { once: true });
            return true;
        }

        try {
            await exportToServer(format, sections);
            return true;
        } catch (error) {
            message(error.message || 'تعذر إنشاء ملف التصدير.', 'error');
            return false;
        }
    }

    function singleContainerKeys(containerId) {
        var key = normalizeKey(containerId);
        return key ? [key] : [];
    }

    root.exportTabToHtml = function (containerId) { return run('html', singleContainerKeys(containerId)); };
    root.exportTabToPdf = function (containerId) { return run('pdf', singleContainerKeys(containerId)); };
    root.exportTabToWord = function (containerId) { return run('word', singleContainerKeys(containerId)); };
    root.exportTabToPrint = function (containerId) { return run('print', singleContainerKeys(containerId)); };
    root.exportSelectedToHtml = function () { return run('html', selectedKeys()); };
    root.exportSelectedToPdf = function () { return run('pdf', selectedKeys()); };
    root.exportSelectedToWord = function () { return run('word', selectedKeys()); };
    root.exportSelectedToPrint = function () { return run('print', selectedKeys()); };
    root.exportAllToHtml = function () { return run('html', allAvailableKeys()); };
    root.exportAllToPdf = function () { return run('pdf', allAvailableKeys()); };
    root.exportAllToWord = function () { return run('word', allAvailableKeys()); };
    root.exportAllToPrint = function () { return run('print', allAvailableKeys()); };
    root.exportFullLessonPdf = function () { return run('pdf', allAvailableKeys()); };
    root.exportContent = function (format) { return run(format, selectedKeys()); };

    root.LessonExport = {
        collectSelected: function () { return collect(selectedKeys()); },
        collectAll: function () { return collect(allAvailableKeys()); },
        __test: {
            normalizeKey: normalizeKey,
            uniqueKeys: uniqueKeys,
            dedupeSections: dedupeSections,
            removeDuplicateAggregatePanels: removeDuplicateAggregatePanels,
            exportTransport: exportTransport
        }
    };
})(window);
