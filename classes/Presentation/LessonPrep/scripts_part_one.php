            <script>
                // Global variables - using window scope for shared lesson_display.js compatibility
                window.generatedData = null;
                let examHtml = null;
                let currentLessonId = null;
                let examModelsCount = 3; // عدد النماذج المختارة
                let isStandaloneMode = false; // هل تم التوليد من الامتحان المستقل
                let isStandaloneQBMode = false; // هل تم التوليد من بنك الأسئلة المستقل

                // =============================================
                // Auto-save form to localStorage
                // =============================================
                const AUTOSAVE_KEY = 'educore_lesson_draft_<?php echo $teacher_id; ?>';
                let autoSaveTimer = null;

                function autoSaveFormToLocalStorage() {
                    try {
                        var form = document.getElementById('lessonForm');
                        if (!form) return;
                        var draft = {
                            title: document.getElementById('title')?.value || '',
                            language: document.getElementById('language')?.value || 'ar',
                            duration: document.getElementById('duration')?.value || '45',
                            student_age: document.getElementById('studentAge')?.value || '',
                            grade_level: document.getElementById('gradeLevel')?.value || '',
                            content: document.getElementById('content')?.value || '',
                            elements: [],
                            phases: [],
                            savedAt: new Date().toISOString()
                        };
                        form.querySelectorAll('input[name="elements[]"]:checked').forEach(function (el) {
                            draft.elements.push(el.value);
                        });
                        form.querySelectorAll('input[name="phases[]"]:checked').forEach(function (el) {
                            draft.phases.push(el.value);
                        });
                        localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(draft));

                        // عرض مؤشر الحفظ التلقائي
                        var indicator = document.getElementById('autosaveIndicator');
                        if (indicator) {
                            indicator.style.opacity = '1';
                            setTimeout(function () { indicator.style.opacity = '0'; }, 2000);
                        }
                    } catch (e) { }
                }

                function restoreFormFromLocalStorage() {
                    try {
                        var saved = localStorage.getItem(AUTOSAVE_KEY);
                        if (!saved) return;
                        var draft = JSON.parse(saved);
                        if (!draft || !draft.title) return;

                        // تحقق أن المسودة ليست قديمة جداً (أكثر من 24 ساعة)
                        var savedTime = new Date(draft.savedAt);
                        var now = new Date();
                        if ((now - savedTime) > 24 * 60 * 60 * 1000) {
                            localStorage.removeItem(AUTOSAVE_KEY);
                            return;
                        }

                        // عرض إشعار للمستخدم
                        var restoreBar = document.createElement('div');
                        restoreBar.id = 'draftRestoreBar';
                        restoreBar.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;background:linear-gradient(135deg,#3b82f6,#2563eb);color:white;padding:15px 20px;border-radius:12px;box-shadow:0 8px 25px rgba(59,130,246,0.3);font-family:Cairo,sans-serif;display:flex;align-items:center;gap:12px;max-width:400px;animation:slideUp 0.3s ease;';
                        restoreBar.innerHTML = '<i class="fas fa-file-alt" style="font-size:1.3rem;"></i>' +
                            '<div><div style="font-weight:700;font-size:0.95rem;">مسودة محفوظة</div>' +
                            '<div style="font-size:0.8rem;opacity:0.9;">' + draft.title + '</div></div>' +
                            '<div style="display:flex;gap:6px;margin-right:auto;">' +
                            '<button onclick="applyDraft()" style="background:white;color:#2563eb;border:none;padding:6px 12px;border-radius:8px;cursor:pointer;font-family:Cairo;font-weight:600;font-size:0.8rem;">استعادة</button>' +
                            '<button onclick="dismissDraft()" style="background:rgba(255,255,255,0.2);color:white;border:none;padding:6px 12px;border-radius:8px;cursor:pointer;font-family:Cairo;font-size:0.8rem;">تجاهل</button></div>';
                        document.body.appendChild(restoreBar);
                    } catch (e) { }
                }

                function applyDraft() {
                    try {
                        var saved = localStorage.getItem(AUTOSAVE_KEY);
                        if (!saved) return;
                        var draft = JSON.parse(saved);

                        if (draft.title) document.getElementById('title').value = draft.title;
                        if (draft.language) document.getElementById('language').value = draft.language;
                        if (draft.duration) document.getElementById('duration').value = draft.duration;
                        if (draft.student_age && document.getElementById('studentAge')) {
                            document.getElementById('studentAge').value = draft.student_age;
                        }
                        if (draft.grade_level && document.getElementById('gradeLevel')) {
                            document.getElementById('gradeLevel').value = draft.grade_level;
                        }
                        if (draft.content && document.getElementById('content')) {
                            document.getElementById('content').value = draft.content;
                        }

                        var bar = document.getElementById('draftRestoreBar');
                        if (bar) bar.remove();
                    } catch (e) { }
                }

                function dismissDraft() {
                    localStorage.removeItem(AUTOSAVE_KEY);
                    var bar = document.getElementById('draftRestoreBar');
                    if (bar) bar.remove();
                }

                function clearAutoSaveDraft() {
                    localStorage.removeItem(AUTOSAVE_KEY);
                }

                // =============================================
                // Auto-save generated lesson to localStorage
                // =============================================
                const GENERATED_DRAFT_KEY = 'educore_generated_lesson_draft_<?php echo $teacher_id; ?>';

                function saveGeneratedLessonToLocalStorage() {
                    try {
                        if (window.generatedData) {
                            var draftData = {
                                generatedData: window.generatedData,
                                examHtml: window.examHtml || examHtml,
                                currentLessonId: window.currentLessonId || currentLessonId,
                                isStandaloneMode: window.isStandaloneMode || isStandaloneMode,
                                isStandaloneQBMode: window.isStandaloneQBMode || isStandaloneQBMode,
                                lastGenerationErrors: window._lastGenerationErrors || [],
                                savedAt: new Date().toISOString()
                            };
                            localStorage.setItem(GENERATED_DRAFT_KEY, JSON.stringify(draftData));
                        } else {
                            localStorage.removeItem(GENERATED_DRAFT_KEY);
                        }
                    } catch (e) {
                        console.error('Error autosaving generated lesson:', e);
                    }
                }

                function clearGeneratedLessonDraft() {
                    try {
                        localStorage.removeItem(GENERATED_DRAFT_KEY);
                    } catch (e) {}
                }

                function restoreGeneratedLessonFromLocalStorage() {
                    try {
                        var saved = localStorage.getItem(GENERATED_DRAFT_KEY);
                        if (!saved) return;
                        var draft = JSON.parse(saved);
                        
                        LessonDialog.fire({
                            title: 'استعادة آخر درس تم تحضيره',
                            text: 'تم العثور على مسودة لآخر درس قمت بتحضيره بالذكاء الاصطناعي. هل ترغب في استعادته وعرضه الآن؟',
                            icon: 'info',
                            showCancelButton: true,
                            confirmButtonColor: '#6366f1',
                            cancelButtonColor: '#94a3b8',
                            confirmButtonText: 'نعم، استعادة',
                            cancelButtonText: 'إلغاء وتجاهل'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.generatedData = draft.generatedData;
                                window.examHtml = draft.examHtml;
                                examHtml = draft.examHtml;
                                currentLessonId = draft.currentLessonId;
                                window.currentLessonId = draft.currentLessonId;
                                isStandaloneMode = draft.isStandaloneMode || false;
                                isStandaloneQBMode = draft.isStandaloneQBMode || false;
                                window._lastGenerationErrors = draft.lastGenerationErrors || [];
                                
                                if (isStandaloneMode) {
                                    if (isStandaloneQBMode) {
                                        displayQuestionBank();
                                    } else if (window.generatedData.lesson_plan === null && window.generatedData.question_bank) {
                                        displayQuestionBank();
                                        displayModelButtons();
                                        displayAnswerKeyButtons();
                                    }
                                } else {
                                    displayResults();
                                }
                                
                                const pptPreviewContent = document.getElementById('powerPointPreviewContent');
                                if (pptPreviewContent && window.generatedData.powerpoint_url) {
                                    pptPreviewContent.innerHTML = `
                                        <div style="text-align: center; padding: 20px;">
                                            <i class="fas fa-file-powerpoint" style="font-size: 4rem; color: #10b981; margin-bottom: 20px;"></i>
                                            <h3 style="color: #1e293b; margin-bottom: 10px;">تم توليد العرض التقديمي بنجاح!</h3>
                                            <p style="color: #64748b; margin-bottom: 20px;">يمكنك تحميل العرض التقديمي بصيغة PowerPoint وتعديله محلياً.</p>
                                            <a href="${window.generatedData.powerpoint_url}" download="lesson_${window.currentLessonId}.pptx" class="btn btn-success" style="padding: 10px 20px; font-size: 1rem; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; background: #10b981; color: white;">
                                                <i class="fas fa-download"></i> تحميل ملف PowerPoint (PPTX)
                                            </a>
                                        </div>
                                    `;
                                    var pptTabBtn = document.getElementById('powerPointTab');
                                    if (pptTabBtn) pptTabBtn.style.display = '';
                                }
                                
                                document.getElementById('resultsSection').classList.add('show');
                                document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });
                                
                                LessonDialog.fire({
                                    icon: 'success',
                                    title: 'تمت الاستعادة بنجاح',
                                    timer: 1500,
                                    showConfirmButton: false
                                });
                            } else {
                                localStorage.removeItem(GENERATED_DRAFT_KEY);
                            }
                        });
                    } catch (e) {
                        console.error('Error restoring generated lesson:', e);
                    }
                }

                // بدء الحفظ التلقائي والتعافي
                document.addEventListener('DOMContentLoaded', function () {
                    restoreFormFromLocalStorage();
                    restoreGeneratedLessonFromLocalStorage();

                    // حفظ تلقائي كل 30 ثانية
                    setInterval(autoSaveFormToLocalStorage, 30000);

                    // حفظ عند تغيير أي حقل
                    var form = document.getElementById('lessonForm');
                    if (form) {
                        form.addEventListener('input', function () {
                            clearTimeout(autoSaveTimer);
                            autoSaveTimer = setTimeout(autoSaveFormToLocalStorage, 3000);
                        });
                    }
                });

                // =============================================
                // Main Tab Switching
                // =============================================
                function switchMainTab(tabName) {
                    // Update tab buttons
                    document.querySelectorAll('.main-tab-btn').forEach(btn => btn.classList.remove('active'));
                    document.querySelector('.main-tab-btn[data-tab="' + tabName + '"]').classList.add('active');

                    // Update tab panels
                    document.querySelectorAll('.main-tab-panel').forEach(panel => panel.classList.remove('active'));
                    document.getElementById('tab' + tabName.charAt(0).toUpperCase() + tabName.slice(1)).classList.add('active');

                    // Scroll to top of tabs area
                    document.querySelector('.main-page-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' });
                }

                // دالة تبديل الوقت المفتوح/المحدد
                function toggleUnlimitedTime(durationInputId, checkboxId) {
                    const input = document.getElementById(durationInputId);
                    const checkbox = document.getElementById(checkboxId);
                    if (checkbox.checked) {
                        input.disabled = true;
                        input.value = '0';
                        input.min = '0';
                        input.style.opacity = '0.5';
                    } else {
                        input.disabled = false;
                        input.value = '5';
                        input.min = '1';
                        input.style.opacity = '1';
                        input.focus();
                    }
                }

                // دوال مساعدة لقراءة الإعدادات حسب الوضع (مستقل أو درس)
                function getExamSettingValue(lessonId, standaloneId) {
                    const el = document.getElementById(isStandaloneMode ? standaloneId : lessonId);
                    return el ? el.value : '';
                }
                function getExamSettingChecked(lessonId, standaloneId) {
                    const el = document.getElementById(isStandaloneMode ? standaloneId : lessonId);
                    return el ? el.checked : false;
                }

                // Note: pdfFile and imageFile are defined in <head> script

                // دالة عرض أزرار النماذج
                function displayModelButtons() {
                    const container = document.getElementById('modelButtonsContainer');
                    const modelsCount = parseInt(getExamSettingValue('examModels', 'saExamModels'));
                    examModelsCount = modelsCount;

                    const modelLetters = ['A', 'B', 'C', 'D'];
                    const modelColors = [
                        'linear-gradient(135deg, #3b82f6, #1d4ed8)', // أزرق
                        'linear-gradient(135deg, #10b981, #059669)', // أخضر
                        'linear-gradient(135deg, #f59e0b, #d97706)', // برتقالي
                        'linear-gradient(135deg, #ef4444, #dc2626)'  // أحمر
                    ];

                    let html = '';
                    for (let i = 0; i < modelsCount; i++) {
                        const letter = modelLetters[i];
                        html += `
                    <button onclick="downloadSingleModel('${letter}')" class="btn-export"
                            style="background: ${modelColors[i]}; min-width: 140px;">
                        <i class="fas fa-download"></i> النموذج ${letter}
                    </button>
                `;
                    }

                    // زر تحميل جميع النماذج
                    if (modelsCount > 1) {
                        html += `
                    <button onclick="downloadAllModels()" class="btn-export"
                            style="background: linear-gradient(135deg, #6366f1, #4f46e5); min-width: 180px;">
                        <i class="fas fa-download"></i> تحميل جميع النماذج
                    </button>
                `;
                    }

                    container.innerHTML = html;

                    // تحديث أزرار تاب التصدير
                    const exportList = document.getElementById('exportModelsList');
                    if (exportList) {
                        let exportListHtml = '';
                        for (let i = 0; i < modelsCount; i++) {
                            exportListHtml += `<button onclick="downloadSingleModel('${modelLetters[i]}')" class="btn-export" style="background: ${modelColors[i]}; min-width: 120px; font-size: 0.85rem; padding: 8px 15px;"><i class="fas fa-download"></i> النموذج ${modelLetters[i]}</button>`;
                        }
                        exportList.innerHTML = exportListHtml;
                    }
                }

                // دالة تحميل نموذج واحد
                async function downloadSingleModel(modelLetter) {
                    if (!currentLessonId) {
                        alert('يرجى توليد التحضير أولاً');
                        return;
                    }

                    try {
                        const response = await fetch('ajax/generate_single_model.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                                lesson_id: currentLessonId,
                                model: modelLetter,
                                exam_duration: getExamSettingValue('examDuration', 'saExamDuration'),
                                mc_count: parseInt(getExamSettingValue('mcCount', 'saMcCount')),
                                tf_count: parseInt(getExamSettingValue('tfCount', 'saTfCount')),
                                essay_count: parseInt(getExamSettingValue('essayCount', 'saEssayCount')),
                                model_type: getExamSettingValue('modelType', 'saModelType'),
                                anti_cheat: getExamSettingChecked('antiCheatEnabled', 'saAntiCheatEnabled') ? 1 : 0,
                                student_info: getExamSettingChecked('studentInfoEnabled', 'saStudentInfoEnabled') ? 1 : 0,
                                exam_theme: document.querySelector(isStandaloneMode ? 'input[name="sa_exam_theme"]:checked' : 'input[name="exam_theme"]:checked')?.value || 'classic'
                            })
                        });

                        const result = await response.json();

                        if (result.success) {
                            const blob = new Blob([result.exam_html], { type: 'text/html;charset=utf-8' });
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `exam_${currentLessonId}_model_${modelLetter}.html`;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                        } else {
                            alert('خطأ: ' + result.message);
                        }
                    } catch (error) {
                        alert('حدث خطأ في الاتصال: ' + error.message);
                    }
                }

                // دالة تحميل جميع النماذج
                async function downloadAllModels() {
                    if (!currentLessonId) {
                        alert('يرجى توليد التحضير أولاً');
                        return;
                    }

                    try {
                        const response = await fetch('ajax/generate_all_models.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                                lesson_id: currentLessonId,
                                exam_duration: getExamSettingValue('examDuration', 'saExamDuration'),
                                exam_models: parseInt(getExamSettingValue('examModels', 'saExamModels')),
                                mc_count: parseInt(getExamSettingValue('mcCount', 'saMcCount')),
                                tf_count: parseInt(getExamSettingValue('tfCount', 'saTfCount')),
                                essay_count: parseInt(getExamSettingValue('essayCount', 'saEssayCount')),
                                model_type: getExamSettingValue('modelType', 'saModelType'),
                                anti_cheat: getExamSettingChecked('antiCheatEnabled', 'saAntiCheatEnabled') ? 1 : 0,
                                student_info: getExamSettingChecked('studentInfoEnabled', 'saStudentInfoEnabled') ? 1 : 0,
                                exam_theme: document.querySelector(isStandaloneMode ? 'input[name="sa_exam_theme"]:checked' : 'input[name="exam_theme"]:checked')?.value || 'classic'
                            })
                        });
                        const result = await response.json();
                        if (result.success) {
                            const blob = new Blob([result.exam_html], { type: 'text/html;charset=utf-8' });
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `exam_${currentLessonId}_all_models.html`;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                        } else {
                            alert('خطأ: ' + result.message);
                        }
                    } catch (error) {
                        alert('حدث خطأ في الاتصال: ' + error.message);
                    }
                }

                // دالة عرض أزرار نموذج الإجابة
                function displayAnswerKeyButtons() {
                    const answerKeyEnabled = getExamSettingChecked('answerKeyEnabled', 'saAnswerKeyEnabled');
                    const section = document.getElementById('answerKeySection');
                    const exportBtn = document.getElementById('downloadAllAnswerKeysExportBtn');

                    if (!answerKeyEnabled) {
                        section.style.display = 'none';
                        if (exportBtn) exportBtn.style.display = 'none';
                        return;
                    }

                    section.style.display = 'block';
                    if (exportBtn) exportBtn.style.display = 'inline-flex';
                    const container = document.getElementById('answerKeyButtonsContainer');
                    const modelsCount = parseInt(getExamSettingValue('examModels', 'saExamModels'));
                    const modelLetters = ['A', 'B', 'C', 'D'];
                    const modelColors = [
                        'linear-gradient(135deg, #854d0e, #a16207)',
                        'linear-gradient(135deg, #065f46, #047857)',
                        'linear-gradient(135deg, #9a3412, #c2410c)',
                        'linear-gradient(135deg, #7f1d1d, #991b1b)'
                    ];

                    let html = '';
                    for (let i = 0; i < modelsCount; i++) {
                        const letter = modelLetters[i];
                        html += `
                    <button onclick="downloadAnswerKey('${letter}')" class="btn-export"
                            style="background: ${modelColors[i]}; min-width: 160px;">
                        <i class="fas fa-key"></i> إجابة النموذج ${letter}
                    </button>
                `;
                    }

                    // زر تحميل جميع نماذج الإجابة
                    if (modelsCount > 1) {
                        html += `
                    <button onclick="downloadAllAnswerKeys()" class="btn-export"
                            style="background: linear-gradient(135deg, #eab308, #ca8a04); min-width: 200px;">
                        <i class="fas fa-key"></i> تحميل جميع نماذج الإجابة
                    </button>
                `;
                    }

                    container.innerHTML = html;
                }

                // دالة تحميل نموذج الإجابة
                async function downloadAnswerKey(modelLetter) {
                    if (!currentLessonId) {
                        alert('يرجى توليد التحضير أولاً');
                        return;
                    }

                    try {
                        const response = await fetch('ajax/generate_answer_key.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                                lesson_id: currentLessonId,
                                model: modelLetter,
                                mc_count: parseInt(getExamSettingValue('mcCount', 'saMcCount')),
                                tf_count: parseInt(getExamSettingValue('tfCount', 'saTfCount')),
                                essay_count: parseInt(getExamSettingValue('essayCount', 'saEssayCount')),
                                model_type: getExamSettingValue('modelType', 'saModelType')
                            })
                        });

                        const result = await response.json();

                        if (result.success) {
                            const blob = new Blob([result.answer_key_html], { type: 'text/html;charset=utf-8' });
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `answer_key_${currentLessonId}_model_${modelLetter}.html`;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                        } else {
                            alert('خطأ: ' + result.message);
                        }
                    } catch (error) {
                        alert('حدث خطأ في الاتصال: ' + error.message);
                    }
                }

                // دالة تحميل جميع نماذج الإجابة
                async function downloadAllAnswerKeys() {
                    if (!currentLessonId) {
                        alert('يرجى توليد التحضير أولاً');
                        return;
                    }

                    const answerKeyEnabled = getExamSettingChecked('answerKeyEnabled', 'saAnswerKeyEnabled');
                    if (!answerKeyEnabled) {
                        alert('نموذج الإجابة غير مفعل');
                        return;
                    }

                    try {
                        const response = await fetch('ajax/generate_all_answer_keys.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                                lesson_id: currentLessonId,
                                mc_count: parseInt(getExamSettingValue('mcCount', 'saMcCount')),
                                tf_count: parseInt(getExamSettingValue('tfCount', 'saTfCount')),
                                essay_count: parseInt(getExamSettingValue('essayCount', 'saEssayCount')),
                                model_type: getExamSettingValue('modelType', 'saModelType')
                            })
                        });
                        const result = await response.json();
                        if (result.success) {
                            const blob = new Blob([result.answer_key_html], { type: 'text/html;charset=utf-8' });
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `answer_keys_${currentLessonId}_all_models.html`;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                        } else {
                            alert('خطأ: ' + result.message);
                        }
                    } catch (error) {
                        alert('حدث خطأ في الاتصال: ' + error.message);
                    }
                }

                // =============================================
                // Element Selection Helpers
                // =============================================
                function selectAllElements() {
                    document.querySelectorAll('.element-checkbox').forEach(cb => cb.checked = true);
                    updateToggleButtons();
                }
                function deselectAllElements() {
                    document.querySelectorAll('.element-checkbox').forEach(cb => cb.checked = false);
                    updateToggleButtons();
                }
                function getSelectedElements() {
                    const elements = [];
                    document.querySelectorAll('input[name="elements[]"]:checked').forEach(cb => elements.push(cb.value));
                    // Add lesson_phases if any phase is selected
                    const phases = getSelectedPhases();
                    if (phases.length > 0) {
                        elements.push('lesson_phases');
                    }
                    return elements;
                }
                function getSelectedSections() {
                    const sections = [];
                    document.querySelectorAll('input[name="sections[]"]:checked').forEach(cb => sections.push(cb.value));
                    return sections;
                }
                function getSelectedPhases() {
                    const phases = [];
                    document.querySelectorAll('input[name="phases[]"]:checked').forEach(cb => {
                        phases.push(cb.value);
                    });
                    return phases;
                }

                // =============================================
                // Custom Content Tags
                // =============================================
                var customTags = [];

                function handleCustomTagKey(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const input = e.target;
                        const val = input.value.trim();
                        if (val && !customTags.includes(val)) {
                            addCustomTag(val);
                        }
                        input.value = '';
                    }
                }

                function addCustomTag(text) {
                    text = text.trim();
                    if (!text || customTags.includes(text)) return;
                    if (customTags.length >= 6) {
                        LessonDialog.fire({ icon: 'info', title: 'الحد الأقصى', text: 'يمكنك إضافة 6 عناصر كحد أقصى', confirmButtonText: 'حسناً' });
                        return;
                    }
                    customTags.push(text);
                    renderCustomTags();
                }

                function removeCustomTag(index) {
                    customTags.splice(index, 1);
                    renderCustomTags();
                }

                function renderCustomTags() {
                    const container = document.getElementById('customTagsContainer');
                    const input = document.getElementById('customTagInput');
                    // Remove existing tags
                    container.querySelectorAll('.custom-tag-pill').forEach(el => el.remove());
                    // Add tag pills before the input
                    customTags.forEach((tag, i) => {
                        const pill = document.createElement('span');
                        pill.className = 'custom-tag-pill';
                        pill.style.cssText = 'display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: #065f46; border-radius: 20px; font-size: 0.88rem; font-weight: 600; border: 1px solid #a7f3d0; animation: fadeInUp 0.3s ease;';
                        pill.innerHTML = '<i class="fas fa-tag" style="font-size: 0.7rem; color: #10b981;"></i>' + escapeHtml(tag) + '<i class="fas fa-times" style="cursor: pointer; font-size: 0.7rem; color: #ef4444; margin-right: 2px;" onclick="removeCustomTag(' + i + ')"></i>';
                        container.insertBefore(pill, input);
                    });
                }

                function getCustomTags() {
                    return customTags;
                }

                // Dynamic toggle button states
                function updateToggleButtons() {
                    const allBoxes = document.querySelectorAll('#elementsSettingsSection .element-checkbox');
                    const total = allBoxes.length;
                    const checked = document.querySelectorAll('#elementsSettingsSection .element-checkbox:checked').length;
                    const btnSelect = document.getElementById('btnSelectAll');
                    const btnDeselect = document.getElementById('btnDeselectAll');
                    if (btnSelect) {
                        btnSelect.style.opacity = (checked === total) ? '0.45' : '1';
                        btnSelect.style.pointerEvents = (checked === total) ? 'none' : 'auto';
                    }
                    if (btnDeselect) {
                        btnDeselect.style.opacity = (checked === 0) ? '0.45' : '1';
                        btnDeselect.style.pointerEvents = (checked === 0) ? 'none' : 'auto';
                    }
                }
                // Attach change listeners to all checkboxes
                document.addEventListener('DOMContentLoaded', function () {
                    document.querySelectorAll('#elementsSettingsSection .element-checkbox').forEach(cb => {
                        cb.addEventListener('change', updateToggleButtons);
                    });
                    updateToggleButtons();
                });

                // =============================================
                // Standalone Exam Generation
                // =============================================
                let examPdfFile = null;
                let examImageFile = null;

                // Toggle functions removed - sections now in separate tabs

                function handleExamPdfSelect(input) {
                    if (input.files && input.files[0]) {
                        const file = input.files[0];
                        if (file.size > 10 * 1024 * 1024) {
                            alert('حجم الملف يتجاوز 10 ميجابايت');
                            return;
                        }
                        examPdfFile = file;
                        document.getElementById('examPdfFileName').textContent = file.name;
                        document.getElementById('examPdfPreview').style.display = 'block';
                    }
                }

                function handleExamImageSelect(input) {
                    if (input.files && input.files[0]) {
                        const file = input.files[0];
                        if (file.size > 5 * 1024 * 1024) {
                            alert('حجم الصورة يتجاوز 5 ميجابايت');
                            return;
                        }
                        examImageFile = file;
                        document.getElementById('examImageFileName').textContent = file.name;
                        document.getElementById('examImagePreview').style.display = 'block';
                    }
                }

                function removeExamFile(type) {
                    if (type === 'pdf') {
                        examPdfFile = null;
                        document.getElementById('examPdfInput').value = '';
                        document.getElementById('examPdfPreview').style.display = 'none';
                    } else {
                        examImageFile = null;
                        document.getElementById('examImageInput').value = '';
                        document.getElementById('examImagePreview').style.display = 'none';
                    }
                }

                async function submitStandaloneExam() {
                    const examContent = document.getElementById('examContent').value.trim();
                    const examTitle = document.getElementById('examTitle').value.trim();

                    if (!examContent && !examPdfFile && !examImageFile) {
                        alert('يرجى إدخال محتوى تعليمي أو رفع ملف لتوليد الامتحان');
                        return;
                    }
                    if (!examTitle) {
                        alert('يرجى إدخال عنوان الامتحان');
                        document.getElementById('examTitle').focus();
                        return;
                    }

                    const btn = document.getElementById('generateExamOnlyBtn');
                    const originalBtnHtml = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري توليد الامتحان...';
                    document.getElementById('loadingOverlay').classList.add('show');
                    startLoadingTips();
                    clearGeneratedLessonDraft();

                    try {
                        const formData = new FormData();
                        formData.append('mode', 'exam_only');
                        formData.append('exam_title', examTitle);
                        formData.append('exam_content', examContent);
                        formData.append('grade_level', document.getElementById('saGradeLevel')?.value || '');
                        formData.append('language', document.getElementById('saLanguage').value);
                        formData.append('exam_duration', document.getElementById('saExamDuration').value);
                        formData.append('exam_models', document.getElementById('saExamModels').value);
                        formData.append('anti_cheat', document.getElementById('saAntiCheatEnabled').checked ? '1' : '0');
                        formData.append('student_info', document.getElementById('saStudentInfoEnabled').checked ? '1' : '0');
                        formData.append('mc_count', document.getElementById('saMcCount').value);
                        formData.append('tf_count', document.getElementById('saTfCount').value);
                        formData.append('essay_count', document.getElementById('saEssayCount').value);
                        formData.append('model_type', document.getElementById('saModelType').value);
                        formData.append('answer_key', document.getElementById('saAnswerKeyEnabled').checked ? '1' : '0');
                        formData.append('exam_theme', document.querySelector('input[name="sa_exam_theme"]:checked')?.value || 'classic');

                        if (examPdfFile) {
                            formData.append('pdf', examPdfFile);
                        }
                        if (examImageFile) {
                            formData.append('image', examImageFile);
                        }

                        const response = await fetch('ajax/generate_exam_only.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (result.success) {
                            // Use existing exam display mechanism
                            generatedData = {
                                lesson_plan: null,
                                question_bank: result.data.question_bank,
                                visual_materials: null,
                                class_activities: null,
                                mind_maps: null
                            };
                            examHtml = result.exam_html;
                            currentLessonId = result.lesson_id;
                            window.currentLessonId = currentLessonId;
                            isStandaloneMode = true;
                            clearAutoSaveDraft();

                            // Display results - show only exam tab
                            displayQuestionBank();
                            displayModelButtons();
                            displayAnswerKeyButtons();

                            saveGeneratedLessonToLocalStorage();

                            document.getElementById('resultsSection').classList.add('show');
                            // Auto-click the exam tab
                            document.querySelectorAll('.tab-btn').forEach(t => t.classList.remove('active'));
                            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
                            const examTab = document.querySelector('[data-tab="examPreview"]');
                            if (examTab) {
                                examTab.classList.add('active');
                                document.getElementById('examPreview').classList.add('active');
                            }
                            document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });

                            if (result.qb_warnings && result.qb_warnings.length > 0) {
                                LessonDialog.fire({
                                    icon: 'info',
                                    title: 'تنبيه بنك الأسئلة',
                                    html: '<div style="text-align:right;direction:rtl;font-size:0.9rem;"><p>لم يتمكن الذكاء الاصطناعي من توليد العدد الكامل المطلوب:</p><ul style="padding-right:20px;margin-top:8px;">' + result.qb_warnings.map(w => '<li>' + w + '</li>').join('') + '</ul><p style="margin-top:10px;color:#64748b;">يمكنك إعادة التوليد بمحتوى أكثر تفصيلاً للحصول على أسئلة إضافية.</p></div>',
                                    confirmButtonText: 'حسناً'
                                });
                            } else if (result.exam_warning) {
                                alert('⚠️ تنبيه: ' + result.exam_warning);
                            }
                        } else {
                            alert('خطأ: ' + result.message);
                        }
                    } catch (error) {
                        alert('حدث خطأ في الاتصال: ' + error.message);
                    } finally {
                        btn.disabled = false;
                        btn.innerHTML = originalBtnHtml;
                        document.getElementById('loadingOverlay').classList.remove('show');
                    }
                }

                // =============================================
                // Standalone Question Bank Generation
                // =============================================
                let qbPdfFile = null;
                let qbImageFile = null;

                function handleQBPdfSelect(input) {
                    if (input.files && input.files[0]) {
                        const file = input.files[0];
                        if (file.size > 10 * 1024 * 1024) {
                            alert('حجم الملف يتجاوز 10 ميجابايت');
                            return;
                        }
                        qbPdfFile = file;
                        document.getElementById('qbPdfFileName').textContent = file.name;
                        document.getElementById('qbPdfPreview').style.display = 'block';
                    }
                }

                function handleQBImageSelect(input) {
                    if (input.files && input.files[0]) {
                        const file = input.files[0];
                        if (file.size > 5 * 1024 * 1024) {
                            alert('حجم الصورة يتجاوز 5 ميجابايت');
                            return;
                        }
                        qbImageFile = file;
                        document.getElementById('qbImageFileName').textContent = file.name;
                        document.getElementById('qbImagePreview').style.display = 'block';
                    }
                }

                function removeQBFile(type) {
                    if (type === 'pdf') {
                        qbPdfFile = null;
                        document.getElementById('qbPdfInput').value = '';
                        document.getElementById('qbPdfPreview').style.display = 'none';
                    } else {
                        qbImageFile = null;
                        document.getElementById('qbImageInput').value = '';
                        document.getElementById('qbImagePreview').style.display = 'none';
                    }
                }

                async function submitStandaloneQB() {
                    const qbContent = document.getElementById('qbStandaloneContent').value.trim();
                    const qbTitle = document.getElementById('qbStandaloneTitle').value.trim();

                    if (!qbContent && !qbPdfFile && !qbImageFile) {
                        alert('يرجى إدخال محتوى تعليمي أو رفع ملف لتوليد بنك الأسئلة');
                        return;
                    }
                    if (!qbTitle) {
                        alert('يرجى إدخال عنوان بنك الأسئلة');
                        document.getElementById('qbStandaloneTitle').focus();
                        return;
                    }

                    const btn = document.getElementById('generateQBOnlyBtn');
                    const originalBtnHtml = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري توليد بنك الأسئلة...';
                    document.getElementById('loadingOverlay').classList.add('show');
                    startLoadingTips();
                    clearGeneratedLessonDraft();

                    try {
                        const formData = new FormData();
                        formData.append('mode', 'qb_only');
                        formData.append('qb_title', qbTitle);
                        formData.append('qb_content', qbContent);
                        formData.append('grade_level', document.getElementById('sqbGradeLevel')?.value || '');
                        formData.append('language', document.getElementById('sqbLanguage').value);
                        // بنك الأسئلة يتولّد تلقائياً بجميع الأنواع - لا حاجة لإرسال أعداد

                        if (qbPdfFile) {
                            formData.append('pdf', qbPdfFile);
                        }
                        if (qbImageFile) {
                            formData.append('image', qbImageFile);
                        }

                        const response = await fetch('ajax/generate_qbank_only.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (result.success) {
                            // Store generated data
                            generatedData = {
                                lesson_plan: null,
                                question_bank: result.data.question_bank,
                                visual_materials: null,
                                class_activities: null,
                                mind_maps: null
                            };
                            examHtml = null;
                            currentLessonId = result.lesson_id;
                            window.currentLessonId = currentLessonId;
                            isStandaloneMode = true;
                            isStandaloneQBMode = true;
                            clearAutoSaveDraft();

                            // Display results - show only question bank tab
                            displayQuestionBank();
                            saveGeneratedLessonToLocalStorage();

                            document.getElementById('resultsSection').classList.add('show');
                            // Auto-click the question bank tab
                            document.querySelectorAll('.tab-btn').forEach(t => t.classList.remove('active'));
                            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
                            const qbTab = document.querySelector('[data-tab="questionBank"]');
                            if (qbTab) {
                                qbTab.classList.add('active');
                                document.getElementById('questionBank').classList.add('active');
                            }
                            document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });

                            // Show counts summary
                            const counts = result.counts;
                            if (counts) {
                                let typesHtml = '';
                                const typeConfig = [
                                    { key: 'mc', label: 'اختيار من متعدد', bg: '#eff6ff', border: '#93c5fd', numColor: '#1e40af', labelColor: '#3b82f6' },
                                    { key: 'tf', label: 'صح وخطأ', bg: '#f0fdf4', border: '#86efac', numColor: '#166534', labelColor: '#10b981' },
                                    { key: 'essay', label: 'مقالية', bg: '#f5f3ff', border: '#c4b5fd', numColor: '#5b21b6', labelColor: '#8b5cf6' },
                                    { key: 'short_answer', label: 'إجابة قصيرة', bg: '#fef3c7', border: '#fcd34d', numColor: '#92400e', labelColor: '#d97706' },
                                    { key: 'fill_blank', label: 'ملء فراغ', bg: '#e0f2fe', border: '#7dd3fc', numColor: '#075985', labelColor: '#0284c7' },
                                    { key: 'ordering', label: 'ترتيب', bg: '#fce7f3', border: '#f9a8d4', numColor: '#9d174d', labelColor: '#db2777' },
                                    { key: 'matching', label: 'توصيل', bg: '#ecfdf5', border: '#6ee7b7', numColor: '#065f46', labelColor: '#059669' }
                                ];
                                typeConfig.forEach(t => {
                                    if (counts[t.key] > 0) {
                                        typesHtml += '<div style="background:' + t.bg + ';padding:8px 12px;border-radius:10px;border:1px solid ' + t.border + ';text-align:center;min-width:80px;"><div style="font-size:1.3rem;font-weight:700;color:' + t.numColor + ';">' + counts[t.key] + '</div><div style="font-size:0.75rem;color:' + t.labelColor + ';">' + t.label + '</div></div>';
                                    }
                                });
                                LessonDialog.fire({
                                    icon: 'success',
                                    title: 'تم توليد بنك الأسئلة بنجاح!',
                                    html: '<div style="text-align:right;direction:rtl;font-size:0.95rem;">' +
                                        '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:10px;">' + typesHtml + '</div>' +
                                        '<div style="margin-top:15px;padding:8px;background:#f8fafc;border-radius:8px;text-align:center;"><strong style="color:#1e293b;">إجمالي الأسئلة: ' + counts.total + '</strong></div>' +
                                        '</div>',
                                    confirmButtonText: 'حسناً'
                                });
                            }

                            if (result.qb_warnings && result.qb_warnings.length > 0) {
                                setTimeout(() => {
                                    LessonDialog.fire({
                                        icon: 'info',
                                        title: 'تنبيه بنك الأسئلة',
                                        html: '<div style="text-align:right;direction:rtl;font-size:0.9rem;"><p>لم يتمكن الذكاء الاصطناعي من توليد العدد الكامل المطلوب:</p><ul style="padding-right:20px;margin-top:8px;">' + result.qb_warnings.map(w => '<li>' + w + '</li>').join('') + '</ul><p style="margin-top:10px;color:#64748b;">يمكنك إعادة التوليد بمحتوى أكثر تفصيلاً للحصول على أسئلة إضافية.</p></div>',
                                        confirmButtonText: 'حسناً'
                                    });
                                }, 500);
                            }
                        } else {
                            alert('خطأ: ' + result.message);
                        }
                    } catch (error) {
                        alert('حدث خطأ في الاتصال: ' + error.message);
                    } finally {
                        btn.disabled = false;
                        btn.innerHTML = originalBtnHtml;
                        document.getElementById('loadingOverlay').classList.remove('show');
                    }
                }

                // =============================================
                // Standalone PowerPoint Generation & File Uploads
                // =============================================
                let pptPdfFile = null;
                let pptImageFile = null;

                function handlePptPdfSelect(input) {
                    if (input.files && input.files[0]) {
                        const file = input.files[0];
                        if (file.size > 10 * 1024 * 1024) {
                            alert('حجم الملف يتجاوز 10 ميجابايت');
                            return;
                        }
                        pptPdfFile = file;
                        document.getElementById('pptPdfFileName').textContent = file.name;
                        document.getElementById('pptPdfPreview').style.display = 'block';
                    }
                }

                function handlePptImageSelect(input) {
                    if (input.files && input.files[0]) {
                        const file = input.files[0];
                        if (file.size > 5 * 1024 * 1024) {
                            alert('حجم الصورة يتجاوز 5 ميجابايت');
                            return;
                        }
                        pptImageFile = file;
                        document.getElementById('pptImageFileName').textContent = file.name;
                        document.getElementById('pptImagePreview').style.display = 'block';
                    }
                }

                function removePptFile(type) {
                    if (type === 'pdf') {
                        pptPdfFile = null;
                        document.getElementById('pptPdfInput').value = '';
                        document.getElementById('pptPdfPreview').style.display = 'none';
                    } else {
                        pptImageFile = null;
                        document.getElementById('pptImageInput').value = '';
                        document.getElementById('pptImagePreview').style.display = 'none';
                    }
                }



                function syncPptTemplateChoice(scope, changed) {
                    const canvaId = scope === 'standalone' ? 'pptCanvaTemplateStandalone' : 'powerPointCanvaTemplate';
                    const internalId = scope === 'standalone' ? 'pptInternalTemplateStandalone' : 'powerPointInternalTemplate';
                    const canvaEl = document.getElementById(canvaId);
                    const internalEl = document.getElementById(internalId);

                    if (changed === 'canva' && canvaEl && canvaEl.value && internalEl) {
                        internalEl.value = '';
                    }
                    if (changed === 'internal' && internalEl && internalEl.value && canvaEl) {
                        canvaEl.value = '';
                    }
                }

                // generateStandalonePPT implementation
                async function generateStandalonePPT() {
                    const title = document.getElementById('pptTitle').value.trim();
                    const content = document.getElementById('pptContent').value.trim();
                    const theme = document.getElementById('pptThemeStandalone').value;
                    const slides = document.getElementById('pptSlidesStandalone').value;
                    const language = document.getElementById('pptLanguageStandalone').value;

                    if (!title) {
                        alert('يرجى إدخال عنوان العرض التقديمي');
                        document.getElementById('pptTitle').focus();
                        return;
                    }
                    if (!content && !pptPdfFile && !pptImageFile) {
                        alert('يرجى إدخال المحتوى التعليمي أو رفع ملف PDF/صورة');
                        document.getElementById('pptContent').focus();
                        return;
                    }

                    const btn = document.getElementById('generatePptBtn');
                    const originalBtnHtml = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري توليد العرض التقديمي...';
                    document.getElementById('loadingOverlay').classList.add('show');
                    startLoadingTips();
                    clearGeneratedLessonDraft();

                    try {
                        const formData = new FormData();
                        formData.append('title', title);
                        formData.append('content', content);
                        formData.append('grade_level', document.getElementById('pptGradeLevelStandalone')?.value || '');
                        formData.append('theme', theme);
                        formData.append('slides', slides);
                        formData.append('language', language);

                        const canvaTemplateIdEl = document.getElementById('pptCanvaTemplateStandalone');
                        if (canvaTemplateIdEl && canvaTemplateIdEl.value) {
                            formData.append('canva_template_id', canvaTemplateIdEl.value);
                        }
                        const internalTemplateIdEl = document.getElementById('pptInternalTemplateStandalone');
                        if (internalTemplateIdEl && internalTemplateIdEl.value) {
                            formData.append('internal_ppt_template_id', internalTemplateIdEl.value);
                        }

                        if (pptPdfFile) {
                            formData.append('pdf', pptPdfFile);
                        }
                        if (pptImageFile) {
                            formData.append('image', pptImageFile);
                        }

                        const response = await fetch('ajax/generate_powerpoint_only.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (result.success) {
                            generatedData = {
                                lesson_plan: null,
                                question_bank: null,
                                visual_materials: null,
                                class_activities: null,
                                mind_maps: null
                            };
                            examHtml = null;
                            currentLessonId = result.lesson_id;
                            window.currentLessonId = currentLessonId;
                            isStandaloneMode = true;
                            clearAutoSaveDraft();

                            const pptPreviewContent = document.getElementById('powerPointPreviewContent');
                            if (pptPreviewContent) {
                                pptPreviewContent.innerHTML = `
                                    <div style="text-align: center; padding: 20px;">
                                        <i class="fas fa-file-powerpoint" style="font-size: 4rem; color: #10b981; margin-bottom: 20px;"></i>
                                        <h3 style="color: #1e293b; margin-bottom: 10px;">تم توليد العرض التقديمي بنجاح!</h3>
                                        <p style="color: #64748b; margin-bottom: 20px;">يمكنك تحميل العرض التقديمي بصيغة PowerPoint وتعديله محلياً.</p>
                                        <a href="${result.download_url}" download="lesson_${result.lesson_id}.pptx" class="btn btn-success" style="padding: 10px 20px; font-size: 1rem; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; background: #10b981; color: white;">
                                            <i class="fas fa-download"></i> تحميل ملف PowerPoint (PPTX)
                                        </a>
                                    </div>
                                `;
                            }

                            saveGeneratedLessonToLocalStorage();

                            document.getElementById('resultsSection').classList.add('show');

                            const pptTabBtn = document.getElementById('powerPointTab');
                            if (pptTabBtn) {
                                pptTabBtn.style.display = '';
                            }

                            document.querySelectorAll('.tab-btn').forEach(t => t.classList.remove('active'));
                            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
                            const pptTab = document.querySelector('[data-tab="powerPointPreview"]');
                            if (pptTab) {
                                pptTab.classList.add('active');
                                document.getElementById('powerPointPreview').classList.add('active');
                            }
                            document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });

                            LessonDialog.fire({
                                icon: 'success',
                                title: 'تم التوليد بنجاح!',
                                text: 'تم إنشاء العرض التقديمي بنجاح، يمكنك تحميل الملف من علامة التبويب "العرض التقديمي".',
                                confirmButtonText: 'حسناً'
                            });
                        } else {
                            LessonDialog.fire({
                                icon: 'error',
                                title: 'فشل التوليد',
                                text: result.message || 'حدث خطأ أثناء التوليد',
                                confirmButtonText: 'حسناً'
                            });
                        }
                    } catch (error) {
                        alert('حدث خطأ في الاتصال: ' + error.message);
                    } finally {
                        btn.disabled = false;
                        btn.innerHTML = originalBtnHtml;
                        document.getElementById('loadingOverlay').classList.remove('show');
                    }
                }

                // HTML escape utility
                // escapeHtml - now in lesson_display.js

                // تحميل البطاقة التعليمية كصورة PNG - تصميم أفقي يطابق العرض على الشاشة
                function downloadFlashCard(linkEl) {
                    const wrapper = linkEl.closest('.fc-card-wrapper');
                    if (!wrapper) return;

                    const cardBack = wrapper.querySelector('.fc-card-back');
                    const cardFront = wrapper.querySelector('.fc-card-front');
                    if (!cardBack) return;

                    const num = wrapper.querySelector('.fc-back-num')?.textContent?.replace(/[^0-9]/g, '').trim() || '1';

                    // استخراج بيانات البطاقة
                    const term = wrapper.querySelector('.fc-back-term')?.textContent || '';
                    const definition = wrapper.querySelector('.fc-back-definition')?.textContent || '';
                    const frontStyle = cardFront ? cardFront.style.background : 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                    const imgEl = cardBack.querySelector('.fc-back-image img');
                    const imgSrc = imgEl ? imgEl.src : '';

                    // إنشاء بطاقة أفقية (landscape) جاهزة للتصوير
                    const clone = document.createElement('div');
                    clone.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:580px;height:300px;display:flex;flex-direction:row;border-radius:16px;overflow:hidden;font-family:Cairo,sans-serif;direction:rtl;z-index:-1;box-shadow:0 4px 20px rgba(0,0,0,0.15);';

                    // الجانب الأيسر - التدرج اللوني مع المصطلح
                    const leftSide = document.createElement('div');
                    leftSide.style.cssText = 'width:200px;min-width:200px;background:' + frontStyle + ';display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 16px;text-align:center;color:white;position:relative;';
                    leftSide.innerHTML = '<div style="font-size:2.2rem;margin-bottom:14px;opacity:0.85;"><i class="fas fa-lightbulb"></i></div>' +
                        '<div style="font-size:1.2rem;font-weight:700;line-height:1.6;text-shadow:0 2px 4px rgba(0,0,0,0.15);">' + escapeHtml(term) + '</div>' +
                        '<div style="position:absolute;top:12px;right:14px;background:rgba(255,255,255,0.25);width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;">' + num + '</div>';

                    // الجانب الأيمن - التعريف
                    const rightSide = document.createElement('div');
                    let rightWidth = imgSrc ? '240px' : '380px';
                    rightSide.style.cssText = 'flex:1;background:#ffffff;padding:24px 20px;display:flex;flex-direction:column;justify-content:center;';
                    rightSide.innerHTML = '<div style="font-size:0.78rem;color:#6366f1;font-weight:600;margin-bottom:8px;display:flex;align-items:center;gap:5px;"><i class="fas fa-book-open"></i> التعريف</div>' +
                        '<div style="font-size:1rem;font-weight:700;color:#1e293b;margin-bottom:8px;">' + escapeHtml(term) + '</div>' +
                        '<div style="font-size:0.88rem;color:#475569;line-height:1.75;overflow:hidden;">' + escapeHtml(definition) + '</div>';

                    clone.appendChild(leftSide);
                    clone.appendChild(rightSide);

                    // إضافة الصورة إن وجدت
                    if (imgSrc) {
                        const imgSide = document.createElement('div');
                        imgSide.style.cssText = 'width:140px;min-width:140px;overflow:hidden;position:relative;';
                        imgSide.innerHTML = '<img src="' + imgSrc + '" style="width:100%;height:100%;object-fit:cover;display:block;" crossorigin="anonymous">';
                        clone.appendChild(imgSide);
                    }

                    document.body.appendChild(clone);

                    if (typeof html2canvas === 'undefined') {
                        clone.remove();
                        alert('مكتبة التصوير غير متاحة');
                        return;
                    }

                    // تصوير سريع - scale 1.5 بدلاً من 2 لتسريع العملية
                    html2canvas(clone, {
                        scale: 1.5,
                        useCORS: true,
                        backgroundColor: null,
                        logging: false,
                        allowTaint: true,
                        width: 580,
                        height: 300
                    }).then(canvas => {
                        const link = document.createElement('a');
                        link.download = 'flashcard_' + num + '.png';
                        link.href = canvas.toDataURL('image/png', 0.92);
                        link.click();
                        clone.remove();
                    }).catch(err => {
                        console.error('Flash card capture error:', err);
                        clone.remove();
                        alert('فشل في تصوير البطاقة. جرب تحميل الصورة مباشرة.');
                    });
                }

                // Accordion toggle — open clicked section, close all others
                function toggleElementsSection(headerEl) {
                    const section = headerEl.closest('.elements-collapsible-section');
                    const body = headerEl.nextElementSibling;
                    const arrow = headerEl.querySelector('.elements-section-arrow');
                    const isCollapsed = body.classList.contains('collapsed');

                    // Close all sections
                    document.querySelectorAll('.elements-collapsible-section').forEach(sec => {
                        const b = sec.querySelector('.elements-section-body');
                        const a = sec.querySelector('.elements-section-arrow');
                        if (b) b.classList.add('collapsed');
                        if (a) a.style.transform = 'rotate(180deg)';
                    });

                    // If clicked section was collapsed, open it
                    if (isCollapsed && body) {
                        body.classList.remove('collapsed');
                        if (arrow) arrow.style.transform = 'rotate(0deg)';
                    }
                }

                // إظهار/إخفاء إعدادات بنك الأسئلة مع الـ checkbox
                function toggleQbSettings(checkbox) {
                    // No additional settings to toggle - QB auto-generates all types
                }

                // =============================================
                // الفئة العمرية للطلاب
                // =============================================
                // أصبحت قائمة منسدلة بسيطة (#studentAge) في أعلى النموذج بجانب زمن الحصة،
                // وقيمتها تُرسل مباشرة عبر name="student_age" — لا حاجة لأي JS هنا.

                // =============================================
                // Quick Copy Function
                // =============================================
                // quickCopySection - now in lesson_display.js

                // =============================================
                // Partial Regeneration Function
                // =============================================
                async function regenerateSection(sectionType) {
                    if (!currentLessonId) {
                        alert('يرجى توليد التحضير أولاً');
                        return;
                    }

                    // Find the regenerate button for this section
                    const allBtns = document.querySelectorAll('.btn-regenerate-section');
                    let btn = null;
                    allBtns.forEach(b => {
                        if (b.getAttribute('onclick') && b.getAttribute('onclick').includes(sectionType)) {
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
                        formData.append('section', sectionType);
                        // اختيار اللغة حسب الوضع
                        const langId = isStandaloneQBMode ? 'sqbLanguage' : (isStandaloneMode ? 'saLanguage' : 'language');
                        formData.append('language', document.getElementById(langId).value);
                        formData.append('duration', document.getElementById('duration').value);
                        formData.append('content', document.getElementById('content').value);
                        formData.append('elements', JSON.stringify(getSelectedElements()));
                        formData.append('phases', JSON.stringify(getSelectedPhases()));

                        // بنك الأسئلة يتولّد تلقائياً - لا حاجة لإرسال أعداد

                        // إضافة إعدادات الامتحان عند إعادة توليد الامتحان
                        if (sectionType === 'exam' || (sectionType === 'question_bank' && typeof examHtml === 'string' && examHtml.trim())) {
                            formData.append('exam_duration', getExamSettingValue('examDuration', 'saExamDuration'));
                            formData.append('exam_models', getExamSettingValue('examModels', 'saExamModels'));
                            formData.append('mc_count', getExamSettingValue('mcCount', 'saMcCount'));
                            formData.append('tf_count', getExamSettingValue('tfCount', 'saTfCount'));
                            formData.append('essay_count', getExamSettingValue('essayCount', 'saEssayCount'));
                            formData.append('model_type', getExamSettingValue('modelType', 'saModelType'));
                            formData.append('anti_cheat', getExamSettingChecked('antiCheatEnabled', 'saAntiCheatEnabled') ? '1' : '0');
                            formData.append('student_info', getExamSettingChecked('studentInfoEnabled', 'saStudentInfoEnabled') ? '1' : '0');
                            formData.append('exam_theme', document.querySelector(isStandaloneMode ? 'input[name="sa_exam_theme"]:checked' : 'input[name="exam_theme"]:checked')?.value || 'classic');
                        }

                        const response = await fetch('ajax/regenerate_section.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (result.success) {
                            // Update the specific section data
                            if (sectionType === 'lesson_plan') {
                                generatedData.lesson_plan = result.data;
                                displayLessonPlan();
                            } else if (sectionType === 'question_bank') {
                                generatedData.question_bank = result.data;
                                displayQuestionBank();
                                // تحذير إذا لم يتولد العدد المطلوب عند إعادة التوليد
                                if (result.qb_warnings && result.qb_warnings.length > 0) {
                                    LessonDialog.fire({
                                        icon: 'info',
                                        title: 'تنبيه بنك الأسئلة',
                                        html: '<div style="text-align:right;direction:rtl;font-size:0.9rem;"><p>لم يتمكن الذكاء الاصطناعي من توليد العدد الكامل المطلوب:</p><ul style="padding-right:20px;margin-top:8px;">' + result.qb_warnings.map(w => '<li>' + w + '</li>').join('') + '</ul><p style="margin-top:10px;color:#64748b;">يمكنك إعادة المحاولة بمحتوى أكثر تفصيلاً.</p></div>',
                                        confirmButtonText: 'حسناً'
                                    });
                                }
                            } else if (sectionType === 'visual_materials') {
                                generatedData.visual_materials = result.data;
                                displayVisualMaterials();
                            } else if (sectionType === 'class_activities') {
                                generatedData.class_activities = result.data;
                                displayClassActivities();
                            } else if (sectionType === 'mind_maps') {
                                generatedData.mind_maps = result.data;
                                displayMindMaps();
                            } else if (sectionType === 'lesson_summary') {
                                generatedData.lesson_summary = result.data;
                                displayLessonSummary();
                            } else if (sectionType === 'custom_content') {
                                generatedData.custom_content = result.data;
                                displayCustomContent();
                            } else if (sectionType === 'exam') {
                                examHtml = result.exam_html;
                                if (result.exam_models_count) examModelsCount = result.exam_models_count;
                                displayModelButtons();
                                displayAnswerKeyButtons();
                            }
                            saveGeneratedLessonToLocalStorage();
                        } else {
                            alert('خطأ في إعادة التوليد: ' + result.message);
                        }
                    } catch (error) {
                        alert('حدث خطأ: ' + error.message);
                    } finally {
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-sync-alt"></i> إعادة توليد';
                        }
                    }
                }
                // analyzeBloomsTaxonomy - now in lesson_display.js
                // toggleBloomDetails - now in lesson_display.js

                // =============================================
                // Difficulty Analysis
                // =============================================
                // analyzeDifficulty - now in lesson_display.js

                // =============================================
                // QR Code Generation (using QRCode.js from CDN)
                // =============================================
                function generateQRCode(text, containerId) {
                    const container = document.getElementById(containerId);
                    if (!container) return;
                    container.innerHTML = '';
