
                    // Simple QR code using a free API
                    const qrImg = document.createElement('img');
                    qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(text);
                    qrImg.alt = 'QR Code';
                    qrImg.style.cssText = 'width: 200px; height: 200px; border-radius: 10px; border: 3px solid #e2e8f0;';
                    container.appendChild(qrImg);
                }

                // =============================================
                // Share Feature
                // =============================================
                async function shareLesson() {
                    if (window.LessonSharing && typeof window.LessonSharing.share === 'function') {
                        return window.LessonSharing.share();
                    }
                    alert('تعذر تحميل أداة المشاركة. أعد تحميل الصفحة ثم حاول مرة أخرى.');
                }

                function copyShareLink() {
                    return shareLesson();
                }

                // =============================================
                // Drag and drop for File Upload
                // =============================================
                document.addEventListener('DOMContentLoaded', function () {
                    ['pdfUploadArea', 'imageUploadArea'].forEach(function (id) {
                        var area = document.getElementById(id);
                        if (!area) return;

                        area.addEventListener('dragover', function (e) {
                            e.preventDefault();
                            area.classList.add('dragover');
                        });

                        area.addEventListener('dragleave', function () {
                            area.classList.remove('dragover');
                        });

                        area.addEventListener('drop', function (e) {
                            e.preventDefault();
                            area.classList.remove('dragover');

                            var inputId = (id === 'pdfUploadArea') ? 'pdfInput' : 'imageInput';
                            var input = document.getElementById(inputId);
                            input.files = e.dataTransfer.files;

                            // Trigger the handler
                            if (id === 'pdfUploadArea') {
                                handlePdfSelect(input);
                            } else {
                                handleImageSelect(input);
                            }
                        });
                    });
                });

                // Tab switching
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    btn.addEventListener('click', function () {
                        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

                        this.classList.add('active');
                        document.getElementById(this.dataset.tab).classList.add('active');

                        // تحديث أزرار التصدير بناءً على التاب النشط
                        updateDynamicExportButtons(this.dataset.tab);
                    });
                });

                // دالة تحديث أزرار التصدير الديناميكية
                function updateDynamicExportButtons(activeTab) {
                    const section = document.getElementById('dynamicExportSection');
                    let html = '';
                    const tabExports = {
                        lessonPlan: ['lessonPlanContent', 'التحضير'],
                        questionBank: ['questionBankContent', 'بنك الأسئلة'],
                        visualMaterials: ['visualMaterialsContent', 'المواد البصرية'],
                        mindMaps: ['mindMapsContent', 'الخرائط الذهنية'],
                        classActivities: ['classActivitiesContent', 'الأنشطة الصفية'],
                        lessonSummary: ['lessonSummaryContent', 'ملخص الدرس'],
                        educationalStories: ['educationalStoriesContent', 'القصة التربوية'],
                        customContent: ['customContentArea', 'المحتوى المخصص']
                    };

                    if (tabExports[activeTab]) {
                        const [containerId, label] = tabExports[activeTab];
                        html = `
                            <button class="btn-export btn-export-html" onclick="exportTabToHtml('${containerId}')"><i class="fas fa-code"></i> ${label} HTML</button>
                            <button class="btn-export btn-export-pdf" onclick="exportTabToPdf('${containerId}')"><i class="fas fa-file-pdf"></i> ${label} PDF</button>
                            <button class="btn-export btn-export-word" onclick="exportTabToWord('${containerId}')"><i class="fas fa-file-word"></i> ${label} Word</button>
                            <button class="btn-export btn-export-exam" onclick="exportTabToPrint('${containerId}')"><i class="fas fa-print"></i> طباعة ${label}</button>
                        `;
                    } else if (activeTab === 'examPreview') {
                        html = `
                            <button class="btn-export btn-export-exam" onclick="downloadAllModels()"><i class="fas fa-download"></i> تحميل جميع النماذج</button>
                            <button class="btn-export btn-export-keys" onclick="downloadAllAnswerKeys()"><i class="fas fa-key"></i> تحميل جميع الإجابات</button>
                            <button class="btn-export btn-export-online" onclick="publishExamOnline()"><i class="fas fa-globe"></i> نشر أونلاين</button>
                        `;
                    } else if (activeTab === 'powerPointPreview') {
                        html = '';
                    } else {
                        html = '';
                    }

                    section.innerHTML = html;
                    section.style.display = html ? 'flex' : 'none';
                }

                // دوال التصدير حسب التاب
                function exportTabToHtml(containerId, filename) {
                    const content = document.getElementById(containerId).innerHTML;
                    const html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>تصدير</title><style>body{font-family:Arial,sans-serif;padding:40px;direction:rtl}table{width:100%;border-collapse:collapse;margin:20px 0}th,td{border:1px solid #ddd;padding:12px;text-align:right}th{background:#10b981;color:white}.question-card{background:#f8f8f8;padding:15px;margin:10px 0;border-radius:8px}.correct{background:#dcfce7;color:#166534}</style></head><body>' + content + '</body></html>';
                    downloadFile(html, filename + '.html', 'text/html');
                }

                function exportTabToPdf(containerId) {
                    const content = document.getElementById(containerId).innerHTML;
                    const html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>تصدير</title><style>body{font-family:Arial,sans-serif;padding:40px;direction:rtl}table{width:100%;border-collapse:collapse;margin:20px 0;page-break-inside:auto}th,td{border:1px solid #ddd;padding:12px;text-align:right}th{background:#10b981!important;color:white!important;-webkit-print-color-adjust:exact}@media print{body{padding:20px}}</style></head><body>' + content + '<script>window.print();<\\/script></body></html>';
                    const win = window.open('', '_blank');
                    win.document.write(html);
                    win.document.close();
                }

                function exportTabToWord(containerId, filename) {
                    const content = document.getElementById(containerId).innerHTML;
                    const html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;direction:rtl}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:10px}th{background:#10b981;color:white}</style></head><body>' + content + '</body></html>';
                    downloadFile(html, filename + '.doc', 'application/msword');
                }

                // دالة تصدير الكل (للتاب تصدير الدرس)
                // Export element selection functions
                function getSelectedExportElements() {
                    const checkboxes = document.querySelectorAll('.export-element-checkbox:checked');
                    return Array.from(checkboxes).map(cb => cb.value);
                }

                function toggleAllExportElements() {
                    const checkboxes = document.querySelectorAll('.export-element-checkbox:not(:disabled)');
                    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                    checkboxes.forEach(cb => cb.checked = !allChecked);
                    updateExportToggleBtn();
                }

                function updateExportToggleBtn() {
                    const checkboxes = document.querySelectorAll('.export-element-checkbox:not(:disabled)');
                    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                    const btn = document.getElementById('exportToggleAllBtn');
                    if (btn) {
                        btn.innerHTML = allChecked
                            ? '<i class="fas fa-times-circle"></i> إلغاء تحديد الكل'
                            : '<i class="fas fa-check-circle"></i> تحديد الكل';
                    }
                }

                // Attach change listeners
                document.querySelectorAll('.export-element-checkbox').forEach(cb => {
                    cb.addEventListener('change', updateExportToggleBtn);
                });

                function getSelectedContent() {
                    const selectedElements = getSelectedExportElements();
                    if (selectedElements.length === 0) {
                        alert('يرجى اختيار عنصر واحد على الأقل للتصدير');
                        return null;
                    }
                    let content = '';
                    selectedElements.forEach(elId => {
                        if (elId === 'exam') return; // الامتحان يُعالج بشكل منفصل
                        const el = document.getElementById(elId);
                        if (el) content += el.innerHTML;
                    });
                    return content;
                }

                function exportSelectedToHtml() {
                    const content = getSelectedContent();
                    if (content === null) return;
                    const html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>تحضير الدرس الكامل</title><style>body{font-family:Arial,sans-serif;padding:40px;direction:rtl}table{width:100%;border-collapse:collapse;margin:20px 0}th,td{border:1px solid #ddd;padding:12px;text-align:right}th{background:#10b981;color:white}.question-card{background:#f8f8f8;padding:15px;margin:10px 0;border-radius:8px}.correct{background:#dcfce7;color:#166534}</style></head><body>' + content + '</body></html>';
                    downloadFile(html, 'lesson_complete.html', 'text/html');
                }

                function exportSelectedToPdf() {
                    const content = getSelectedContent();
                    if (content === null) return;
                    const html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>تحضير الدرس الكامل</title><style>body{font-family:Arial,sans-serif;padding:40px;direction:rtl}table{width:100%;border-collapse:collapse;margin:20px 0;page-break-inside:auto}th,td{border:1px solid #ddd;padding:12px;text-align:right}th{background:#10b981!important;color:white!important;-webkit-print-color-adjust:exact}@media print{body{padding:20px}}</style></head><body>' + content + '<script>window.print();<\/script></body></html>';
                    const win = window.open('', '_blank');
                    win.document.write(html);
                    win.document.close();
                }

                function exportSelectedToWord() {
                    const content = getSelectedContent();
                    if (content === null) return;
                    const html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;direction:rtl}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:10px}th{background:#10b981;color:white}</style></head><body>' + content + '</body></html>';
                    downloadFile(html, 'lesson_complete.doc', 'application/msword');
                }

                function exportSelectedToPrint() {
                    const content = getSelectedContent();
                    if (content === null) return;
                    const printWindow = window.open('', '_blank');
                    printWindow.document.write('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>تحضير الدرس</title><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet"><style>body{font-family:Cairo,sans-serif;padding:30px;direction:rtl;} .question-item,.plan-item,.visual-item{background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:15px;} .section-title{font-size:1.4rem;font-weight:700;margin:25px 0 15px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;} .sub-tabs-container{display:none;}</style></head><body>' + content + '</body></html>');
                    printWindow.document.close();
                    setTimeout(() => { printWindow.print(); }, 500);
                }

                // Legacy export functions (kept for bottom bar compatibility)
                function exportAllToHtml() {
                    // Select all checkboxes temporarily, export, then restore
                    const content = document.getElementById('lessonPlanContent').innerHTML +
                        document.getElementById('questionBankContent').innerHTML +
                        document.getElementById('visualMaterialsContent').innerHTML +
                        document.getElementById('mindMapsContent').innerHTML +
                        document.getElementById('classActivitiesContent').innerHTML;
                    const html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>تحضير الدرس الكامل</title><style>body{font-family:Arial,sans-serif;padding:40px;direction:rtl}table{width:100%;border-collapse:collapse;margin:20px 0}th,td{border:1px solid #ddd;padding:12px;text-align:right}th{background:#10b981;color:white}.question-card{background:#f8f8f8;padding:15px;margin:10px 0;border-radius:8px}.correct{background:#dcfce7;color:#166534}</style></head><body>' + content + '</body></html>';
                    downloadFile(html, 'lesson_complete.html', 'text/html');
                }

                function exportAllToPdf() {
                    const content = document.getElementById('lessonPlanContent').innerHTML +
                        document.getElementById('questionBankContent').innerHTML +
                        document.getElementById('visualMaterialsContent').innerHTML +
                        document.getElementById('mindMapsContent').innerHTML +
                        document.getElementById('classActivitiesContent').innerHTML;
                    const html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>تحضير الدرس الكامل</title><style>body{font-family:Arial,sans-serif;padding:40px;direction:rtl}table{width:100%;border-collapse:collapse;margin:20px 0;page-break-inside:auto}th,td{border:1px solid #ddd;padding:12px;text-align:right}th{background:#10b981!important;color:white!important;-webkit-print-color-adjust:exact}@media print{body{padding:20px}}</style></head><body>' + content + '<script>window.print();<\/script></body></html>';
                    const win = window.open('', '_blank');
                    win.document.write(html);
                    win.document.close();
                }

                function exportAllToWord() {
                    const content = document.getElementById('lessonPlanContent').innerHTML +
                        document.getElementById('questionBankContent').innerHTML +
                        document.getElementById('visualMaterialsContent').innerHTML +
                        document.getElementById('mindMapsContent').innerHTML +
                        document.getElementById('classActivitiesContent').innerHTML;
                    const html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;direction:rtl}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:10px}th{background:#10b981;color:white}</style></head><body>' + content + '</body></html>';
                    downloadFile(html, 'lesson_complete.doc', 'application/msword');
                }

                function exportFullLessonPdf() {
                    var sections = [];
                    var lp = document.getElementById('lessonPlanContent');
                    var qb = document.getElementById('questionBankContent');
                    var vm = document.getElementById('visualMaterialsContent');
                    var mm = document.getElementById('mindMapsContent');
                    var ca = document.getElementById('classActivitiesContent');
                    if (lp && lp.innerHTML.trim()) sections.push('<h1 style="color:#10b981;border-bottom:3px solid #10b981;padding-bottom:10px;">تحضير الدرس</h1>' + lp.innerHTML);
                    if (qb && qb.innerHTML.trim()) sections.push('<h1 style="color:#3b82f6;border-bottom:3px solid #3b82f6;padding-bottom:10px;">بنك الأسئلة</h1>' + qb.innerHTML);
                    if (vm && vm.innerHTML.trim()) sections.push('<h1 style="color:#8b5cf6;border-bottom:3px solid #8b5cf6;padding-bottom:10px;">المواد البصرية</h1>' + vm.innerHTML);
                    if (mm && mm.innerHTML.trim()) sections.push('<h1 style="color:#f59e0b;border-bottom:3px solid #f59e0b;padding-bottom:10px;">الخرائط الذهنية</h1>' + mm.innerHTML);
                    if (ca && ca.innerHTML.trim()) sections.push('<h1 style="color:#ef4444;border-bottom:3px solid #ef4444;padding-bottom:10px;">الأنشطة الصفية</h1>' + ca.innerHTML);
                    if (sections.length === 0) { alert('لا يوجد محتوى للتصدير'); return; }
                    var title = document.getElementById('title')?.value || 'الدرس';
                    var html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>' + title + '</title>' +
                        '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">' +
                        '<style>body{font-family:Cairo,sans-serif;padding:40px;direction:rtl;color:#1e293b}' +
                        'table{width:100%;border-collapse:collapse;margin:20px 0;page-break-inside:auto}' +
                        'th,td{border:1px solid #d1d5db;padding:12px;text-align:right}' +
                        'th{background:#10b981!important;color:white!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
                        '.question-card,.plan-item{background:#f8fafc;border-radius:8px;padding:15px;margin:10px 0}' +
                        '.correct{background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px}' +
                        '.sub-tabs-container,.btn-regenerate-section,.btn-inline-edit,.section-actions{display:none!important}' +
                        'h1{page-break-before:auto;margin-top:30px}' +
                        '@media print{body{padding:20px}.no-print{display:none!important}}' +
                        '</style></head><body>' +
                        '<div style="text-align:center;margin-bottom:30px;padding:20px;background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border-radius:12px;">' +
                        '<h1 style="margin:0;color:#166534;border:none;">' + title + '</h1></div>' +
                        sections.join('<div style="page-break-before:always;"></div>') +
                        '<script>setTimeout(function(){window.print();},500);<\/script></body></html>';
                    var win = window.open('', '_blank');
                    win.document.write(html);
                    win.document.close();
                }

                // Sub-tab switching helper
                // switchSubTab - now in lesson_display.js

                // دالة إرسال النموذج - تُستدعى من زر التوليد
                async function submitLessonForm() {
                    const content = document.getElementById('content').value.trim();

                    if (!content && !pdfFile && !imageFile) {
                        alert('يرجى إدخال محتوى تعليمي أو رفع ملف');
                        return;
                    }

                    // التحقق من عنوان الدرس
                    const title = document.getElementById('title').value.trim();
                    if (!title) {
                        alert('يرجى إدخال عنوان الدرس');
                        document.getElementById('title').focus();
                        return;
                    }

                    // التحقق من وجود درس مكرر بنفس العنوان (قبل بدء التوليد)
                    if (!forceDuplicate) {
                        try {
                            const dupCheck = new FormData();
                            dupCheck.append('title', title);
                            const dupRes = await fetch('ajax/check_duplicate_title.php', { method: 'POST', body: dupCheck });
                            const dupData = await dupRes.json();

                            if (dupData.exists) {
                                const confirmResult = await LessonDialog.fire({
                                    title: '<span style="font-size:1.25rem;font-weight:700;background:linear-gradient(135deg,#667eea,#764ba2);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">درس موجود بنفس العنوان</span>',
                                    html: '<div style="text-align:right;direction:rtl;font-family:Cairo,sans-serif;padding:4px 0;">' +
                                        '<div style="background:linear-gradient(135deg,#f0f4ff,#ede9fe);padding:18px 20px;border-radius:14px;border:1px solid rgba(102,126,234,0.2);margin-bottom:18px;position:relative;overflow:hidden;">' +
                                        '<div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#667eea,#764ba2,#667eea);"></div>' +
                                        '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">' +
                                        '<div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-book-open" style="color:white;font-size:0.85rem;"></i></div>' +
                                        '<strong style="color:#1e293b;font-size:1rem;line-height:1.5;">' + title + '</strong>' +
                                        '</div>' +
                                        '<div style="display:flex;align-items:center;gap:7px;color:#64748b;font-size:0.82rem;padding-right:46px;">' +
                                        '<i class="fas fa-calendar-alt" style="color:#667eea;"></i>' +
                                        '<span>' + dupData.existing_date + '</span>' +
                                        '</div>' +
                                        '</div>' +
                                        '<p style="color:#475569;margin:0;line-height:1.7;font-size:0.92rem;">يوجد درس محفوظ مسبقاً بنفس العنوان.<br>هل تريد <strong style="color:#10b981;">إنشاء درس جديد</strong> أم <strong style="color:#667eea;">تغيير العنوان</strong>؟</p>' +
                                        '</div>',
                                    showCancelButton: true,
                                    showDenyButton: true,
                                    confirmButtonText: 'إنشاء درس جديد',
                                    denyButtonText: 'تغيير العنوان',
                                    cancelButtonText: 'إلغاء',
                                    confirmButtonColor: '#10b981',
                                    denyButtonColor: '#667eea',
                                    customClass: {
                                        popup: 'swal-dup-popup',
                                        title: 'swal-dup-title',
                                        confirmButton: 'swal-dup-btn',
                                        denyButton: 'swal-dup-btn',
                                        cancelButton: 'swal-dup-cancel-btn',
                                        icon: 'swal-dup-icon',
                                        actions: 'swal-dup-actions'
                                    },
                                    reverseButtons: true,
                                    focusCancel: true,
                                    backdrop: 'rgba(15,23,42,0.6)'
                                });

                                if (confirmResult.isConfirmed) {
                                    forceDuplicate = true;
                                    submitLessonForm();
                                    return;
                                } else {
                                    if (confirmResult.isDenied) {
                                        document.getElementById('title').focus();
                                        document.getElementById('title').select();
                                    }
                                    return;
                                }
                            }
                        } catch (dupErr) {
                            console.warn('Duplicate check failed:', dupErr);
                            // تجاهل خطأ الفحص ومتابعة التوليد
                        }
                    }
                    forceDuplicate = false;

                    // Initialize AbortController for this request
                    window.currentLessonGenerationController = new AbortController();
                    const signal = window.currentLessonGenerationController.signal;

                    const btn = document.getElementById('generateBtn');
                    const cancelBtn = document.getElementById('cancelOverlayBtn');
                    btn.classList.add('loading');
                    btn.disabled = true;
                    if (cancelBtn) cancelBtn.style.display = 'inline-flex';
                    document.getElementById('loadingOverlay').classList.add('show');
                    startLoadingTips();
                    clearGeneratedLessonDraft();
                    let lessonPersisted = false;

                    try {
                        const formData = new FormData();
                        formData.append('language', document.getElementById('language').value);
                        formData.append('duration', document.getElementById('duration').value);
                        formData.append('title', title);
                        formData.append('content', content);
                        formData.append('exam_duration', document.getElementById('examDuration').value);
                        formData.append('exam_models', document.getElementById('examModels').value);
                        formData.append('anti_cheat', document.getElementById('antiCheatEnabled').checked ? '1' : '0');
                        formData.append('student_info', document.getElementById('studentInfoEnabled').checked ? '1' : '0');
                        formData.append('mc_count', document.getElementById('mcCount').value);
                        formData.append('tf_count', document.getElementById('tfCount').value);
                        formData.append('essay_count', document.getElementById('essayCount').value);
                        formData.append('model_type', document.getElementById('modelType').value);
                        formData.append('answer_key', document.getElementById('answerKeyEnabled').checked ? '1' : '0');
                        formData.append('exam_theme', document.querySelector('input[name="exam_theme"]:checked')?.value || 'classic');

                        // بنك الأسئلة يتولّد تلقائياً بجميع الأنواع - لا حاجة لإرسال أعداد

                        // Send selected elements, sections, and phases
                        formData.append('elements', JSON.stringify(getSelectedElements()));
                        formData.append('sections', JSON.stringify(getSelectedSections()));
                        formData.append('phases', JSON.stringify(getSelectedPhases()));

                        // عمر الطلاب للقصة التربوية (يُرسل فقط إذا كان موجوداً ومعتمداً)
                        var storyAgeEl = document.getElementById('studentAge');
                        if (storyAgeEl && storyAgeEl.value) {
                            formData.append('student_age', storyAgeEl.value);
                        }

                        // الصف الدراسي (إن وُجد)
                        var gradeLevelEl = document.getElementById('gradeLevel');
                        if (gradeLevelEl && gradeLevelEl.value) {
                            formData.append('grade_level', gradeLevelEl.value);
                        }

                        // Send custom content tags
                        var tags = getCustomTags();
                        if (tags.length > 0) {
                            formData.append('custom_prompts', JSON.stringify(tags));
                        }

                        // Send PowerPoint settings
                        var pptCheckbox = document.getElementById('generatePowerPoint');
                        formData.append('generate_powerpoint', pptCheckbox && pptCheckbox.checked ? '1' : '0');
                        formData.append('powerpoint_theme', document.getElementById('powerPointTheme')?.value || 'modern');
                        formData.append('powerpoint_slides', document.getElementById('powerPointSlides')?.value || '12');
                        const mainCanvaEl = document.getElementById('powerPointCanvaTemplate');
                        if (mainCanvaEl && mainCanvaEl.value) {
                            formData.append('canva_template_id', mainCanvaEl.value);
                        }
                        const mainInternalTplEl = document.getElementById('powerPointInternalTemplate');
                        if (mainInternalTplEl && mainInternalTplEl.value) {
                            formData.append('internal_ppt_template_id', mainInternalTplEl.value);
                        }

                        if (pdfFile) {
                            formData.append('pdf', pdfFile);
                            console.log('Sending PDF:', pdfFile.name, pdfFile.size, 'bytes');
                        }
                        if (imageFile) {
                            formData.append('image', imageFile);
                            console.log('Sending Image:', imageFile.name, imageFile.size, 'bytes');
                        }
                        console.log('Submitting form with', pdfFile ? 'PDF' : 'no PDF', ',', imageFile ? 'Image' : 'no Image');

                        const response = await fetch('ajax/generate_lesson.php', {
                            method: 'POST',
                            body: formData,
                            signal: signal
                        });

                        const result = await response.json();

                        if (result.success) {
                            generatedData = result.data;
                            examHtml = result.exam_html;
                            currentLessonId = result.lesson_id;
                            window.currentLessonId = currentLessonId;
                            lessonPersisted = Boolean(currentLessonId);
                            isStandaloneMode = false;
                            isStandaloneQBMode = false;
                            clearAutoSaveDraft();
                            saveGeneratedLessonToLocalStorage();

                            // حفظ الأخطاء لعرضها في الأقسام المتأثرة
                            window._lastGenerationErrors = result.errors || [];
                            if (window._lastGenerationErrors.length > 0) {
                                console.warn('Generation errors:', window._lastGenerationErrors);
                            }

                            displayResults();

                            // Update PowerPoint preview content if generated successfully in full lesson flow
                            const pptPreviewContent = document.getElementById('powerPointPreviewContent');
                            if (pptPreviewContent) {
                                if (result.powerpoint_url) {
                                    pptPreviewContent.innerHTML = `
                                        <div style="text-align: center; padding: 20px;">
                                            <i class="fas fa-file-powerpoint" style="font-size: 4rem; color: #10b981; margin-bottom: 20px;"></i>
                                            <h3 style="color: #1e293b; margin-bottom: 10px;">تم توليد العرض التقديمي بنجاح!</h3>
                                            <p style="color: #64748b; margin-bottom: 20px;">يمكنك تحميل العرض التقديمي بصيغة PowerPoint وتعديله محلياً.</p>
                                            <a href="${result.powerpoint_url}" download="lesson_${result.lesson_id}.pptx" class="btn btn-success" style="padding: 10px 20px; font-size: 1rem; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; background: #10b981; color: white;">
                                                <i class="fas fa-download"></i> تحميل ملف PowerPoint (PPTX)
                                            </a>
                                        </div>
                                    `;
                                } else if (result.powerpoint_error) {
                                    pptPreviewContent.innerHTML = `
                                        <div class="alert alert-warning" style="text-align: center; padding: 20px;">
                                            <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: #f59e0b; margin-bottom: 15px;"></i>
                                            <h4 style="font-weight: 600;">تنبيه العرض التقديمي</h4>
                                            <p>${result.powerpoint_error}</p>
                                        </div>
                                    `;
                                } else {
                                    pptPreviewContent.innerHTML = `
                                        <div style="text-align: center; padding: 30px;">
                                            <i class="fas fa-file-powerpoint" style="font-size: 4rem; color: #6366f1; margin-bottom: 20px;"></i>
                                            <h3 style="color: #1e293b; margin-bottom: 10px;">العرض التقديمي PowerPoint</h3>
                                            <p style="color: #64748b; margin-bottom: 20px;">سيظهر العرض التقديمي هنا بعد توليد الدرس مع تفعيل خيار PowerPoint</p>
                                        </div>
                                    `;
                                }
                            }

                            document.getElementById('resultsSection').classList.add('show');
                            document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });

                            // عرض ملخص الأخطاء إذا فشلت أقسام متعددة
                            const allErrors = result.errors || [];
                            const customErr = result.custom_content_error;
                            const totalFailures = allErrors.length + (customErr ? 1 : 0);

                            if (totalFailures > 1) {
                                // أكثر من قسم فشل - عرض ملخص موحد
                                let errorItems = allErrors.map(e => '<li style="margin-bottom:5px;">' + e + '</li>').join('');
                                if (customErr) {
                                    errorItems += '<li style="margin-bottom:5px;">المحتوى المخصص: ' + customErr + '</li>';
                                }
                                LessonDialog.fire({
                                    icon: 'warning',
                                    title: 'تعذر توليد بعض الأقسام',
                                    html: '<div style="text-align:right;direction:rtl;font-size:0.9rem;">' +
                                        '<p style="margin-bottom:10px;">فشل توليد <strong>' + totalFailures + '</strong> أقسام. يمكنك إعادة توليد كل قسم على حدة:</p>' +
                                        '<ul style="padding-right:20px;color:#92400e;font-size:0.85rem;">' + errorItems + '</ul>' +
                                        '<p style="margin-top:12px;color:#64748b;font-size:0.85rem;"><i class="fas fa-lightbulb" style="color:#f59e0b;"></i> استخدم زر "إعادة توليد" في كل قسم فاشل لإعادة المحاولة.</p>' +
                                        '</div>',
                                    confirmButtonText: 'حسناً'
                                });
                            } else if (customErr) {
                                // المحتوى المخصص فقط فشل
                                LessonDialog.fire({
                                    icon: 'warning',
                                    title: 'تنبيه المحتوى المخصص',
                                    html: '<div style="text-align:right;direction:rtl;font-size:0.9rem;"><p>لم يتمكن الذكاء الاصطناعي من توليد المحتوى المخصص المطلوب.</p><p style="margin-top:8px;color:#64748b;">يمكنك إعادة المحاولة بمحتوى أقل تعقيداً أو تقليل عدد العناصر المخصصة.</p></div>',
                                    confirmButtonText: 'حسناً'
                                });
                            } else if (allErrors.length === 1) {
                                // قسم واحد فقط فشل
                                LessonDialog.fire({
                                    icon: 'info',
                                    title: 'تنبيه',
                                    html: '<div style="text-align:right;direction:rtl;font-size:0.9rem;"><p>' + allErrors[0] + '</p><p style="margin-top:8px;color:#64748b;">يمكنك إعادة توليد هذا القسم من زر إعادة التوليد.</p></div>',
                                    confirmButtonText: 'حسناً'
                                });
                            }

                            // عرض تحذيرات بنك الأسئلة إذا لم يتولد العدد المطلوب
                            if (result.qb_warnings && result.qb_warnings.length > 0) {
                                LessonDialog.fire({
                                    icon: 'info',
                                    title: 'تنبيه بنك الأسئلة',
                                    html: '<div style="text-align:right;direction:rtl;font-size:0.9rem;"><p>لم يتمكن الذكاء الاصطناعي من توليد العدد الكامل المطلوب:</p><ul style="padding-right:20px;margin-top:8px;">' + result.qb_warnings.map(w => '<li>' + w + '</li>').join('') + '</ul><p style="margin-top:10px;color:#64748b;">يمكنك إعادة التوليد بمحتوى أكثر تفصيلاً للحصول على أسئلة إضافية.</p></div>',
                                    confirmButtonText: 'حسناً'
                                });
                            }
                            // عرض تحذير إذا لم يتم توليد الامتحان
                            if (result.exam_warning) {
                                LessonDialog.fire({
                                    icon: 'warning',
                                    title: 'تنبيه الامتحان',
                                    html: '<div style="text-align:right;direction:rtl;font-size:0.9rem;"><p>' + result.exam_warning + '</p></div>',
                                    confirmButtonText: 'حسناً'
                                });
                            }
                        } else {
                            alert('خطأ: ' + result.message);
                        }
                    } catch (error) {
                        if (error.name === 'AbortError') {
                            console.log('Generation aborted by user');
                            // Notification handled by cancelLessonGeneration function
                        } else if (lessonPersisted) {
                            console.error('Lesson saved but rendering failed:', error);
                            LessonDialog.fire({
                                icon: 'warning',
                                title: 'تم حفظ الدرس وتعذر عرضه',
                                text: 'الدرس محفوظ في الأرشيف. حدّث الصفحة ثم افتحه من أرشيف الدروس.',
                                confirmButtonText: 'حسناً'
                            });
                        } else {
                            alert('حدث خطأ في الاتصال: ' + error.message);
                        }
                    } finally {
                        btn.classList.remove('loading');
                        btn.disabled = false;
                        if (cancelBtn) cancelBtn.style.display = 'none';
                        document.getElementById('loadingOverlay').classList.remove('show');
                        window.currentLessonGenerationController = null;
                    }
                }

                // دالة لإلغاء توليد الدرس
                function cancelLessonGeneration() {
                    if (window.currentLessonGenerationController) {
                        // This abort will trigger the catch block in submitLessonForm,
                        // which will then go to the finally block where UI elements (btn, overlay) are reset.
                        window.currentLessonGenerationController.abort();

                        // إظهار رسالة تفيد بالإلغاء
                        LessonDialog.fire({
                            iconHtml: '<div style="width:70px;height:70px;border-radius:50%;background:linear-gradient(135deg,#ef4444,#dc2626);display:flex;align-items:center;justify-content:center;box-shadow:0 8px 25px rgba(239,68,68,0.4);"><i class="fas fa-stop-circle" style="font-size:1.8rem;color:white;"></i></div>',
                            title: '<span style="font-size:1.25rem;font-weight:700;background:linear-gradient(135deg,#667eea,#764ba2);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">تم إلغاء التوليد</span>',
                            html: '<div style="text-align:center;direction:rtl;font-family:Cairo,sans-serif;padding:4px 0;">' +
                                '<div style="background:linear-gradient(135deg,#fef2f2,#fee2e2);padding:16px 20px;border-radius:14px;border:1px solid rgba(239,68,68,0.15);margin-bottom:14px;position:relative;overflow:hidden;">' +
                                '<div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#ef4444,#f97316,#ef4444);"></div>' +
                                '<div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-top:4px;">' +
                                '<i class="fas fa-info-circle" style="color:#ef4444;font-size:1.1rem;"></i>' +
                                '<span style="color:#991b1b;font-size:0.95rem;font-weight:600;">تم إيقاف عملية توليد الدرس بنجاح</span>' +
                                '</div>' +
                                '</div>' +
                                '<p style="color:#64748b;margin:0;font-size:0.88rem;">يمكنك إعادة المحاولة في أي وقت بالضغط على زر التوليد</p>' +
                                '</div>',
                            confirmButtonText: 'حسناً',
                            confirmButtonColor: '#10b981',
                            customClass: {
                                popup: 'swal-dup-popup',
                                title: 'swal-dup-title',
                                confirmButton: 'swal-dup-btn',
                                icon: 'swal-dup-icon'
                            },
                            backdrop: 'rgba(15,23,42,0.6)'
                        });
                    } else {
                        // Fallback UI reset just in case
                        const btn = document.getElementById('generateBtn');
                        const cancelBtn = document.getElementById('cancelOverlayBtn');
                        if (btn) {
                            btn.classList.remove('loading');
                            btn.disabled = false;
                        }
                        if (cancelBtn) cancelBtn.style.display = 'none';
                        document.getElementById('loadingOverlay').classList.remove('show');
                    }
                }

                // عرض ملخص الدرس
                // displayLessonSummary - now in lesson_display.js
                // buildSummarySection helper - now in lesson_display.js
                // buildSummaryBlock helper - now in lesson_display.js

                // Display results
                function displayResults() {
                    displayLessonPlan();
                    displayVisualMaterials();
                    displayMindMaps();
                    displayQuestionBank();
                    displayClassActivities();
                    displayLessonSummary();
                    displayEducationalStories();
                    displayCustomContent();
                    // إظهار تاب العرض التقديمي إذا كان الخيار مفعل
                    var pptTabBtn = document.getElementById('powerPointTab');
                    var pptCheckbox = document.getElementById('generatePowerPoint');
                    if (pptTabBtn && pptCheckbox && pptCheckbox.checked) {
                        pptTabBtn.style.display = '';
                    }
                    displayModelButtons(); // عرض أزرار النماذج
                    displayAnswerKeyButtons(); // عرض أزرار نموذج الإجابة
                    updateExportElementAvailability(); // تحديث توفر عناصر التصدير
                }

                // عرض المحتوى المخصص
                function displayCustomContent() {
                    const container = document.getElementById('customContentArea');
                    const tabBtn = document.getElementById('customContentTab');
                    const tags = getCustomTags();

                    if (!generatedData || !generatedData.custom_content || !Array.isArray(generatedData.custom_content) || generatedData.custom_content.length === 0) {
                        // إذا كان المستخدم قد حدد عناصر مخصصة لكنها لم تُولد، أظهر رسالة مع زر إعادة التوليد
                        if (tags.length > 0) {
                            if (tabBtn) tabBtn.style.display = '';
                            container.innerHTML = '<div class="alert alert-warning" style="text-align:center;padding:30px;">' +
                                '<i class="fas fa-exclamation-triangle" style="font-size:2rem;color:#f59e0b;display:block;margin-bottom:10px;"></i>' +
                                '<p style="font-weight:600;margin-bottom:5px;">لم يتمكن الذكاء الاصطناعي من توليد المحتوى المخصص</p>' +
                                '<p style="color:#64748b;font-size:0.9rem;margin-bottom:15px;">قد يكون السبب تجاوز حد الاستخدام أو طول المحتوى. يمكنك إعادة المحاولة.</p>' +
                                '<button class="btn-regenerate-section" onclick="regenerateCustomContent()" style="margin-top:5px;">' +
                                '<i class="fas fa-sync-alt"></i> إعادة توليد المحتوى المخصص</button></div>';
                        } else {
                            container.innerHTML = '';
                            if (tabBtn) tabBtn.style.display = 'none';
                        }
                        return;
                    }

                    if (tabBtn) tabBtn.style.display = '';
                    var items = generatedData.custom_content;

                    var html = '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-magic"></i> محتوى إضافي مخصص</h3>';
                    html += '<div style="display:flex;gap:8px;">';
                    html += '<button class="btn-quick-copy" onclick="quickCopySection(\'customContentArea\')" title="نسخ سريع"><i class="fas fa-copy"></i> نسخ</button>';
                    html += '<button class="btn-regenerate-section" onclick="regenerateCustomContent()" title="إعادة توليد"><i class="fas fa-sync-alt"></i> إعادة توليد</button>';
                    // زر التعديل المباشر — مطابق لنمط _buildSectionActions في lesson_display.js
                    if (window.currentLessonId) {
                        html += '<button class="btn-inline-edit" onclick="toggleInlineEdit(\'customContentArea\', \'custom_content\')" title="تعديل المحتوى" data-section="custom_content"><i class="fas fa-edit"></i> تعديل</button>';
                    }
                    html += '</div></div>';

                    items.forEach(function (item, idx) {
                        // فشل آمن عند وجود نسخة قديمة من lesson_display.js في cache:
                        // نعرض قيماً ثابتة آمنة بدل إيقاف عرض نتائج الدرس بالكامل.
                        var icon = typeof window.safeIconClass === 'function'
                            ? window.safeIconClass(item.icon)
                            : 'fa-file-alt';
                        var color = typeof window.safeColor === 'function'
                            ? window.safeColor(item.color)
                            : '#10b981';
                        // بطاقة بنفس class باقي التبويبات (.visual-item) لتوحيد الخط/الخلفية/dark mode،
                        // مع accent لوني لكل عنصر بدل البطاقة الخضراء الثابتة.
                        html += '<div class="visual-item" data-cc-index="' + idx + '" style="border-right: 4px solid ' + color + '; margin-bottom: 15px; text-align: right;">';
                        // ترويسة بسيطة: عنوان + أيقونة ملوّنة (بدل شريط gradient ثقيل).
                        // صنف cc-title-text يسمح لـ _makeEditable (lesson_display.js) بتفعيل التحرير المباشر.
                        html += '<h4 class="cc-title-text" style="margin: 0 0 12px 0; font-size: 1.1rem; font-weight: 700; color: ' + color + '; display: flex; align-items: center; gap: 8px;">';
                        html += '<i class="fas ' + icon + '"></i>';
                        html += escapeHtml(item.title || 'عنصر مخصص');
                        html += '</h4>';
                        // المحتوى يَرِث الخط من .visual-item/.tab-content (بدل تثبيت Cairo).
                        // صنف cc-body-text يسمح لـ _makeEditable بتفعيل التحرير المباشر على نص المحتوى.
                        html += '<div class="cc-body-text" style="line-height: 1.9; font-size: 1rem;">';
                        var safeContent = typeof window.sanitizeGeneratedHtml === 'function'
                            ? window.sanitizeGeneratedHtml(item.content_html)
                            : '<p>' + String(item.content_html || '')
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;')
                                .replace(/'/g, '&#039;')
                                .replace(/\n/g, '<br>') + '</p>';
                        html += safeContent || '<p style="color: #94a3b8;">لم يتم توليد محتوى لهذا العنصر</p>';
                        html += '</div></div>';
                    });

                    container.innerHTML = html;
                }

                // إعادة توليد المحتوى المخصص
                async function regenerateCustomContent() {
                    const tags = getCustomTags();
                    if (tags.length === 0) {
                        LessonDialog.fire({
                            icon: 'info',
                            title: 'لا توجد عناصر مخصصة',
                            text: 'أضف عناصر في قسم "محتوى إضافي مخصص" أولاً (مثل: ورقة عمل، خطة علاجية...)',
                            confirmButtonText: 'حسناً'
                        });
                        return;
                    }

                    if (!currentLessonId) {
                        LessonDialog.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى توليد التحضير أولاً', confirmButtonText: 'حسناً' });
                        return;
                    }

                    // Find regenerate button
                    const btns = document.querySelectorAll('.btn-regenerate-section');
                    let btn = null;
                    btns.forEach(b => {
                        if (b.getAttribute('onclick') && b.getAttribute('onclick').includes('regenerateCustomContent')) {
                            btn = b;
                        }
                    });
                    if (btn) {
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري...';
                    }

                    try {
                        const formData = new FormData();
                        formData.append('lesson_id', currentLessonId);
                        formData.append('section', 'custom_content');
                        formData.append('language', document.getElementById('language').value);
                        formData.append('duration', document.getElementById('duration').value);
                        formData.append('content', document.getElementById('content').value);
                        formData.append('custom_prompts', JSON.stringify(tags));

                        const response = await fetch('ajax/regenerate_section.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (result.success) {
                            generatedData.custom_content = result.data;
                            displayCustomContent();
                            saveGeneratedLessonToLocalStorage();
                            LessonDialog.fire({
                                icon: 'success',
                                title: 'تم التوليد بنجاح',
                                text: 'تم توليد المحتوى المخصص بنجاح',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else {
                            LessonDialog.fire({
                                icon: 'error',
                                title: 'فشل إعادة التوليد',
                                text: result.message || 'حدث خطأ غير متوقع',
                                confirmButtonText: 'حسناً'
                            });
                        }
                    } catch (error) {
                        LessonDialog.fire({
                            icon: 'error',
                            title: 'خطأ في الاتصال',
                            text: error.message,
                            confirmButtonText: 'حسناً'
                        });
                    } finally {
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-sync-alt"></i> إعادة توليد';
                        }
                    }
                }

                // تحديث حالة عناصر التصدير حسب المحتوى المتاح
                function updateExportElementAvailability() {
                    const elements = {
                        'exportEl_lessonPlan': 'lessonPlanContent',
                        'exportEl_questionBank': 'questionBankContent',
                        'exportEl_visualMaterials': 'visualMaterialsContent',
                        'exportEl_classActivities': 'classActivitiesContent',
                        'exportEl_mindMaps': 'mindMapsContent',
                        'exportEl_lessonSummary': 'lessonSummaryContent',
                        'exportEl_customContent': 'customContentArea',
                        'exportEl_exam': null // يُفحص عبر examHtml
                    };

                    for (const [labelId, contentId] of Object.entries(elements)) {
                        const label = document.getElementById(labelId);
                        if (!label) continue;

                        let hasContent = false;
                        if (contentId) {
                            const el = document.getElementById(contentId);
                            hasContent = el && el.innerHTML.trim().length > 0;
                        } else {
                            // الامتحان
                            hasContent = !!examHtml;
                        }

                        const checkbox = label.querySelector('.export-element-checkbox');
                        if (hasContent) {
                            label.classList.remove('export-element-disabled');
                            checkbox.disabled = false;
                            checkbox.checked = true;
                            // إزالة نص "(غير متوفر)" إن وجد
                            const span = label.querySelector('span');
                            if (span) span.textContent = span.textContent.replace(' (غير متوفر)', '');
                        } else {
                            label.classList.add('export-element-disabled');
                            checkbox.disabled = true;
                            checkbox.checked = false;
                            const span = label.querySelector('span');
                            if (span && !span.textContent.includes('(غير متوفر)')) {
                                span.textContent += ' (غير متوفر)';
                            }
                        }
                    }
                    updateExportToggleBtn();
                }
                // displayVisualMaterials - now in lesson_display.js
                // displayQuestionBank - now in lesson_display.js
                // displayClassActivities - now in lesson_display.js

                // عرض الخرائط الذهنية باستخدام EduVisual SVG Engine
                function displayMindMaps() {
                    const container = document.getElementById('mindMapsContent');
                    const mindMaps = generatedData.mind_maps;

                    if (!mindMaps) {
                        // البحث عن أخطاء الخرائط الذهنية من الاستجابة
                        let errorDetail = '';
                        if (window._lastGenerationErrors && window._lastGenerationErrors.length > 0) {
                            const mindMapErrors = window._lastGenerationErrors.filter(e => e.includes('الخرائط الذهنية') || e.includes('mind_map'));
                            if (mindMapErrors.length > 0) {
                                errorDetail = '<br><small style="color:#b45309;">' + mindMapErrors.join('<br>') + '</small>';
                            }
                        }
                        container.innerHTML = '<div class="alert alert-warning">' +
                            '<i class="fas fa-info-circle"></i> لم يتم توليد الخرائط الذهنية' + errorDetail +
                            '<br><button class="btn-regenerate-section" onclick="regenerateSection(\'mind_maps\')" style="margin-top:10px;">' +
                            '<i class="fas fa-sync-alt"></i> إعادة توليد الخرائط الذهنية</button></div>';
                        console.warn('Mind maps data is null/undefined. Check server response for errors.');
                        return;
                    }

                    // التحقق من وجود بيانات فعلية
                    const hasData = mindMaps.main_mind_map ||
                        (mindMaps.concept_maps && mindMaps.concept_maps.length > 0) ||
                        (mindMaps.fishbone_maps && mindMaps.fishbone_maps.length > 0) ||
                        (mindMaps.comparison_tables && mindMaps.comparison_tables.length > 0) ||
                        (mindMaps.cycle_maps && mindMaps.cycle_maps.length > 0) ||
                        (mindMaps.hierarchy_maps && mindMaps.hierarchy_maps.length > 0) ||
                        (mindMaps.flowchart_maps && mindMaps.flowchart_maps.length > 0) ||
                        (mindMaps.multi_flow_maps && mindMaps.multi_flow_maps.length > 0) ||
                        (mindMaps.pyramid_maps && mindMaps.pyramid_maps.length > 0) ||
                        (mindMaps.circle_maps && mindMaps.circle_maps.length > 0) ||
                        (mindMaps.visual_summaries && mindMaps.visual_summaries.length > 0);
                    if (!hasData) {
                        container.innerHTML = '<div class="alert alert-warning">' +
                            '<i class="fas fa-info-circle"></i> بيانات الخرائط الذهنية فارغة — جرب إعادة التوليد' +
                            '<br><button class="btn-regenerate-section" onclick="regenerateSection(\'mind_maps\')" style="margin-top:10px;">' +
                            '<i class="fas fa-sync-alt"></i> إعادة توليد الخرائط الذهنية</button></div>';
                        console.warn('Mind maps data exists but has no content:', mindMaps);
                        return;
                    }

                    // Header with actions
                    let html = '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-project-diagram"></i> الخرائط الذهنية التفاعلية</h3>';
                    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
                    html += '<button class="btn-quick-copy" onclick="quickCopySection(\'mindMapsContent\')" title="نسخ سريع"><i class="fas fa-copy"></i> نسخ</button>';
                    html += '<button class="btn-regenerate-section" onclick="saveMindMapEdits()" title="حفظ التعديلات" id="btnSaveMindMaps"><i class="fas fa-save"></i> حفظ التعديلات</button>';
                    html += '<button class="btn-regenerate-section" onclick="exportMindMapsJSON()" title="تصدير JSON"><i class="fas fa-download"></i> JSON</button>';
                    html += '<button class="btn-regenerate-section" onclick="importMindMapsJSON()" title="استيراد JSON"><i class="fas fa-upload"></i> استيراد</button>';
                    html += '<button class="btn-regenerate-section" onclick="regenerateSection(\'mind_maps\')" title="إعادة توليد"><i class="fas fa-sync-alt"></i> إعادة توليد</button>';
                    // زر التعديل المباشر — مطابق لنمط _buildSectionActions في lesson_display.js.
                    // ملاحظة: الخرائط الذهنية معقدة (SVG تفاعلية يديرها EduVisual)، لذا قد لا يعمل
                    // التعديل النصي على كل عناصرها بشكل مثالي، لكنه يسمح بتحرير النصوص القابلة للتعديل.
                    if (window.currentLessonId) {
                        html += '<button class="btn-inline-edit" onclick="toggleInlineEdit(\'mindMapsContent\', \'mind_maps\')" title="تعديل المحتوى" data-section="mind_maps"><i class="fas fa-edit"></i> تعديل</button>';
                    }
                    html += '</div></div>';
                    html += '<p style="color: #64748b; margin-bottom: 25px; font-size: 0.95rem;"><i class="fas fa-info-circle"></i> خرائط ذهنية SVG تفاعلية — اسحب للتحريك، عجلة الماوس للتكبير، أزرار تصدير PNG/SVG</p>';
                    html += '<div id="eduvisual-prep-root"></div>';
                    container.innerHTML = html;

                    // Render with EduVisual engine
                    if (window.EduVisual) {
                        try {
                            EduVisual.renderAll('eduvisual-prep-root', mindMaps, {
                                theme: document.body.classList.contains('dark-mode') ? 'dark' : 'modern',
                                animate: true,
                                interactive: true
                            });
                        } catch (err) {
                            console.error('EduVisual render error:', err);
                            document.getElementById('eduvisual-prep-root').innerHTML =
                                '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> حدث خطأ أثناء عرض الخرائط الذهنية. جرب إعادة التوليد.</div>';
                        }
                    } else {
                        console.error('EduVisual engine not loaded');
                        document.getElementById('eduvisual-prep-root').innerHTML =
                            '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> فشل تحميل محرك الرسوم — أعد تحميل الصفحة</div>';
                    }
                }

                // حفظ تعديلات الخرائط الذهنية في قاعدة البيانات
                async function saveMindMapEdits() {
                    if (!currentLessonId || !generatedData.mind_maps) {
                        if (window.EduVisual) EduVisual.showToast('لا يوجد درس محفوظ بعد', 'warning');
                        return;
                    }
                    const btn = document.getElementById('btnSaveMindMaps');
                    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...'; }
                    try {
                        const response = await fetch('ajax/save_mindmap.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>, lesson_id: currentLessonId, mind_maps: generatedData.mind_maps })
                        });
                        const result = await response.json();
                        if (result.success) {
                            if (window.EduVisual) EduVisual.showToast('تم حفظ التعديلات بنجاح');
                            saveGeneratedLessonToLocalStorage();
                        } else {
                            if (window.EduVisual) EduVisual.showToast(result.message || 'فشل الحفظ', 'warning');
                        }
                    } catch (err) {
                        if (window.EduVisual) EduVisual.showToast('خطأ في الاتصال بالخادم', 'warning');
                    } finally {
                        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> حفظ التعديلات'; }
                    }
                }

                // تصدير الخرائط كملف JSON
                function exportMindMapsJSON() {
                    if (!generatedData.mind_maps) {
                        if (window.EduVisual) EduVisual.showToast('لا توجد خرائط للتصدير', 'warning');
                        return;
                    }
                    if (window.EduVisual) EduVisual.exportJSON(generatedData.mind_maps, 'mindmaps-' + (currentLessonId || 'new') + '.json');
                }

                // استيراد خرائط من ملف JSON
                function importMindMapsJSON() {
                    if (window.EduVisual) {
                        EduVisual.importJSON((data) => {
                            generatedData.mind_maps = data;
                            displayMindMaps();
                            saveGeneratedLessonToLocalStorage();
                        });
                    }
                }

                // Download exam (single main model)
                const dlExamBtn = document.getElementById('downloadExamBtn');
                if (dlExamBtn) dlExamBtn.addEventListener('click', downloadAllModels);

                function downloadExam() {
                    if (!examHtml) {
                        alert('لم يتم توليد الامتحان بعد.\n\nتأكد من:\n- إدخال محتوى كافٍ للدرس\n- اكتمال عملية التوليد بنجاح\n- وجود أسئلة في بنك الأسئلة');
                        return;
                    }

                    const blob = new Blob([examHtml], { type: 'text/html;charset=utf-8' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'exam_' + (currentLessonId || 'new') + '.html';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }

                // نشر الامتحان أونلاين
                const pubOnlineBtn = document.getElementById('publishOnlineBtn');
                if (pubOnlineBtn) pubOnlineBtn.addEventListener('click', publishExamOnline);

                async function publishExamOnline() {
                    if (!currentLessonId) {
                        alert('يرجى توليد التحضير أولاً');
                        return;
                    }

                    if (!examHtml) {
                        alert('لا يمكن نشر الامتحان أونلاين.\nالسبب: لم يتم توليد بنك الأسئلة.\nالحل: أعد توليد الدرس مع إضافة محتوى تعليمي أكثر تفصيلاً.');
                        return;
                    }

                    // تعطيل جميع أزرار النشر
                    const publishBtns = document.querySelectorAll('[onclick*="publishExamOnline"], #publishOnlineBtn');
                    const originalTexts = [];
                    publishBtns.forEach((btn, i) => {
                        originalTexts[i] = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري النشر...';
                        btn.disabled = true;
                    });

                    try {
                        const response = await fetch('ajax/publish_exam.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                                lesson_id: currentLessonId,
                                exam_duration: getExamSettingValue('examDuration', 'saExamDuration'),
                                exam_models: getExamSettingValue('examModels', 'saExamModels'),
                                exam_theme: document.querySelector(isStandaloneMode ? 'input[name="sa_exam_theme"]:checked' : 'input[name="exam_theme"]:checked')?.value || 'classic',
                                mc_count: parseInt(getExamSettingValue('mcCount', 'saMcCount')) || 0,
                                tf_count: parseInt(getExamSettingValue('tfCount', 'saTfCount')) || 0,
                                essay_count: parseInt(getExamSettingValue('essayCount', 'saEssayCount')) || 0
                            })
                        });

                        const result = await response.json();

                        if (result.success) {
                            // حساب المسار الأساسي ديناميكياً من المسار الحالي
                            const basePath = window.location.pathname.replace(/\/teacher\/.*$/, '/');
                            const baseUrl = window.location.origin + basePath;
                            const examLink = baseUrl + 'take_exam.php?code=' + result.exam_code;

                            document.getElementById('examLinkInput').value = examLink;
                            document.getElementById('viewResultsLink').href = 'exam_results.php?exam_id=' + result.exam_id;
                            document.getElementById('onlineExamLink').style.display = 'block';

                            // Generate QR Code
                            generateQRCode(examLink, 'examQRCode');

                            publishBtns.forEach(btn => {
                                btn.innerHTML = '<i class="fas fa-check"></i> تم النشر';
                                btn.style.background = 'linear-gradient(135deg, #22c55e, #16a34a)';
                            });
                        } else {
                            alert('حدث خطأ: ' + result.message);
                            publishBtns.forEach((btn, i) => {
                                btn.innerHTML = originalTexts[i];
                                btn.disabled = false;
                            });
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert('حدث خطأ في الاتصال بالخادم');
                        publishBtns.forEach((btn, i) => {
                            btn.innerHTML = originalTexts[i];
                            btn.disabled = false;
                        });
                    }
                }

                // نسخ رابط الامتحان
                function copyExamLink(event) {
                    const input = document.getElementById('examLinkInput');
                    input.select();
                    input.setSelectionRange(0, 99999);
                    document.execCommand('copy');

                    // إظهار تأكيد النسخ
                    const btn = event.target.closest('button');
                    const originalHTML = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check"></i> تم النسخ!';
                    btn.style.background = 'linear-gradient(135deg, #22c55e, #16a34a)';

                    setTimeout(() => {
                        btn.innerHTML = originalHTML;
                        btn.style.background = 'linear-gradient(135deg, #3b82f6, #2563eb)';
                    }, 2000);
                }

                // Export functions - moved to dynamic tab system above
                // downloadFile utility function kept for the export functions
                function downloadFile(content, filename, type) {
                    const blob = new Blob(['\ufeff' + content], { type: type + ';charset=utf-8' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }

                // Theme Toggle
                (function () {
                    const themeToggle = document.getElementById('themeToggle');
                    const savedTheme = localStorage.getItem('theme');

                    if (savedTheme === 'dark') {
                        document.body.classList.add('dark-mode');
                        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                    }

                    themeToggle.addEventListener('click', () => {
                        document.body.classList.toggle('dark-mode');

                        if (document.body.classList.contains('dark-mode')) {
                            localStorage.setItem('theme', 'dark');
                            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                        } else {
                            localStorage.setItem('theme', 'light');
                            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                        }
                    });
                })();
            </script>
