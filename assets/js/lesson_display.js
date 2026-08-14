/**
 * EduCore - Shared Lesson Display Functions
 * Used by both lesson_prep.php (generation results) and lesson_view.php (archive view)
 * 
 * Requirements:
 * - window.generatedData must be set before calling display functions
 * - window.isArchiveView = true hides regenerate buttons (archive mode)
 * - Font Awesome 6 must be loaded
 * - Sub-tab CSS classes must be defined in the page
 */

// =============================================
// Utility Functions
// =============================================

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    var div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

function decodeHtmlEntities(str) {
    var textarea = document.createElement('textarea');
    textarea.innerHTML = String(str == null ? '' : str);
    return textarea.value;
}

function escapeTextTree(value) {
    if (typeof value === 'string') return escapeHtml(value);
    if (Array.isArray(value)) return value.map(escapeTextTree);
    if (value && typeof value === 'object') {
        var copy = {};
        Object.keys(value).forEach(function (key) {
            copy[key] = escapeTextTree(value[key]);
        });
        return copy;
    }
    return value;
}

function safeHttpUrl(value) {
    if (!value) return '';
    try {
        var decoded = decodeHtmlEntities(value).trim();
        var parsed = new URL(decoded, window.location.origin);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return '';
        return parsed.href;
    } catch (error) {
        return '';
    }
}

function safeIconClass(value) {
    var icon = String(value || '');
    return /^fa-[a-z0-9-]+$/i.test(icon) ? icon : 'fa-file-alt';
}

function safeColor(value) {
    var color = String(value || '');
    return /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test(color) ? color : '#10b981';
}

function sanitizeGeneratedHtml(source) {
    if (!source) return '';

    var template = document.createElement('template');
    template.innerHTML = String(source);
    var allowedTags = new Set([
        'A', 'B', 'BLOCKQUOTE', 'BR', 'CODE', 'DIV', 'EM', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
        'HR', 'I', 'IMG', 'LI', 'OL', 'P', 'PRE', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP',
        'TABLE', 'TBODY', 'TD', 'TFOOT', 'TH', 'THEAD', 'TR', 'U', 'UL'
    ]);
    var allowedAttributes = new Set([
        'alt', 'class', 'colspan', 'dir', 'height', 'href', 'rel', 'rowspan', 'src', 'style',
        'target', 'title', 'width'
    ]);
    var blockedTags = new Set(['BASE', 'EMBED', 'FORM', 'IFRAME', 'LINK', 'META', 'OBJECT', 'SCRIPT', 'STYLE']);

    Array.from(template.content.querySelectorAll('*')).forEach(function (node) {
        if (blockedTags.has(node.tagName)) {
            node.remove();
            return;
        }
        if (!allowedTags.has(node.tagName)) {
            node.replaceWith.apply(node, Array.from(node.childNodes));
            return;
        }

        Array.from(node.attributes).forEach(function (attribute) {
            var name = attribute.name.toLowerCase();
            var value = attribute.value;
            if (!allowedAttributes.has(name) || name.indexOf('on') === 0) {
                node.removeAttribute(attribute.name);
                return;
            }
            if (name === 'style' && /(?:expression\s*\(|url\s*\(|@import|javascript:|behavior\s*:)/i.test(value)) {
                node.removeAttribute(attribute.name);
                return;
            }
            if (name === 'href' || name === 'src') {
                var safeUrl = safeHttpUrl(value);
                if (!safeUrl) {
                    node.removeAttribute(attribute.name);
                } else {
                    node.setAttribute(attribute.name, safeUrl);
                }
            }
        });

        if (node.tagName === 'A' && node.getAttribute('target') === '_blank') {
            node.setAttribute('rel', 'noopener noreferrer');
        }
    });

    return template.innerHTML;
}

// عقد صريح للصفحات التي تملك renderers إضافية خارج هذا الملف.
// يمنع الاعتماد على التحويل الضمني لتعريفات الدوال العليا إلى خصائص window.
window.safeIconClass = safeIconClass;
window.safeColor = safeColor;
window.sanitizeGeneratedHtml = sanitizeGeneratedHtml;

function copyVisualSearchQuery(button) {
    if (!button) return;
    var text = button.dataset.copyText || '';
    navigator.clipboard.writeText(text).then(function () {
        var original = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> تم النسخ';
        setTimeout(function () {
            button.innerHTML = original;
        }, 1500);
    }).catch(function () {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
    });
}

function switchSubTab(containerId, subTabId) {
    var container = document.getElementById(containerId);
    if (!container) return;
    container.querySelectorAll('.sub-tab-btn').forEach(function (b) { b.classList.remove('active'); });
    container.querySelectorAll('.sub-tab-content').forEach(function (c) { c.classList.remove('active'); });

    var activeBtn = container.querySelector('[data-subtab="' + subTabId + '"]');
    if (activeBtn) activeBtn.classList.add('active');
    var target = document.getElementById(subTabId);
    if (target) target.classList.add('active');
}

function quickCopySection(containerId) {
    var container = document.getElementById(containerId);
    if (!container) return;

    var tempDiv = document.createElement('div');
    tempDiv.innerHTML = container.innerHTML;
    var text = tempDiv.textContent || tempDiv.innerText || '';

    var btn = null;
    container.querySelectorAll('.btn-quick-copy').forEach(function (b) {
        if (b.getAttribute('onclick') && b.getAttribute('onclick').indexOf(containerId) !== -1) btn = b;
    });
    if (!btn) {
        document.querySelectorAll('.btn-quick-copy').forEach(function (b) {
            if (b.getAttribute('onclick') && b.getAttribute('onclick').indexOf(containerId) !== -1) btn = b;
        });
    }

    navigator.clipboard.writeText(text).then(function () {
        if (btn) {
            var originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> تم النسخ!';
            btn.classList.add('copied');
            setTimeout(function () {
                btn.innerHTML = originalHTML;
                btn.classList.remove('copied');
            }, 2000);
        }
    }).catch(function () {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        if (btn) {
            var originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> تم النسخ!';
            btn.classList.add('copied');
            setTimeout(function () {
                btn.innerHTML = originalHTML;
                btn.classList.remove('copied');
            }, 2000);
        }
    });
}

// Helper to build action buttons (copy + optional regenerate + edit)
function _buildSectionActions(containerId, sectionKey, extraButtons) {
    var html = '<div style="display:flex;gap:8px;">';
    html += '<button class="btn-quick-copy" onclick="quickCopySection(\'' + containerId + '\')" title="نسخ سريع"><i class="fas fa-copy"></i> نسخ</button>';
    if (!window.isArchiveView && sectionKey) {
        html += '<button class="btn-regenerate-section" onclick="regenerateSection(\'' + sectionKey + '\')" title="إعادة توليد"><i class="fas fa-sync-alt"></i> إعادة توليد</button>';
    }
    // زر التعديل المباشر — يظهر دائماً في صفحة التوليد، وكذلك في الأرشيف (للمعلم صاحب الدرس)
    if (sectionKey && window.currentLessonId) {
        html += '<button class="btn-inline-edit" onclick="toggleInlineEdit(\'' + containerId + '\', \'' + sectionKey + '\')" title="تعديل المحتوى" data-section="' + sectionKey + '">';
        html += '<i class="fas fa-edit"></i> تعديل</button>';
    }
    if (extraButtons) html += extraButtons;
    html += '</div>';
    return html;
}

// =============================================
// Inline Editing Functions
// =============================================
function toggleInlineEdit(containerId, sectionKey) {
    var container = document.getElementById(containerId);
    if (!container) return;

    var editBtn = container.querySelector('.btn-inline-edit[data-section="' + sectionKey + '"]');
    var isEditing = container.classList.contains('inline-editing');

    if (isEditing) {
        cancelInlineEdit(containerId, sectionKey);
        return;
    }

    // حفظ المحتوى الأصلي للتراجع
    if (!container._originalData) {
        container._originalData = JSON.parse(JSON.stringify(window.generatedData[sectionKey] || {}));
    }

    container.classList.add('inline-editing');
    if (editBtn) {
        editBtn.innerHTML = '<i class="fas fa-times"></i> إلغاء';
        editBtn.classList.add('editing-active');
    }

    // إضافة شريط أدوات التعديل
    var toolbar = document.createElement('div');
    toolbar.className = 'inline-edit-toolbar';
    toolbar.id = 'editToolbar_' + sectionKey;
    toolbar.innerHTML = '<div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(135deg,#dbeafe,#ede9fe);border-radius:12px;margin:10px 0;border:2px solid #93c5fd;">' +
        '<i class="fas fa-info-circle" style="color:#3b82f6;font-size:1.1rem;"></i>' +
        '<span style="color:#1e40af;font-weight:600;font-size:0.9rem;">وضع التعديل — انقر على أي نص لتحريره</span>' +
        '<div style="margin-right:auto;display:flex;gap:8px;">' +
        '<button onclick="saveInlineEdit(\'' + containerId + '\', \'' + sectionKey + '\')" class="btn-save-edit" style="background:linear-gradient(135deg,#22c55e,#16a34a);color:white;border:none;padding:6px 16px;border-radius:8px;cursor:pointer;font-family:Cairo,sans-serif;font-weight:600;font-size:0.85rem;"><i class="fas fa-save"></i> حفظ التعديلات</button>' +
        '<button onclick="cancelInlineEdit(\'' + containerId + '\', \'' + sectionKey + '\')" class="btn-cancel-edit" style="background:#ef4444;color:white;border:none;padding:6px 16px;border-radius:8px;cursor:pointer;font-family:Cairo,sans-serif;font-weight:600;font-size:0.85rem;"><i class="fas fa-undo"></i> تراجع</button>' +
        '</div></div>';

    var headerActions = container.querySelector('.section-header-actions');
    if (headerActions && headerActions.nextSibling) {
        headerActions.parentNode.insertBefore(toolbar, headerActions.nextSibling);
    } else {
        container.prepend(toolbar);
    }

    // جعل العناصر القابلة للتعديل
    _makeEditable(container, sectionKey);
}

function _makeEditable(container, sectionKey) {
    // جعل عناصر النص قابلة للتعديل
    var editableSelectors = '.plan-item-content, .question-text, .option-item, .option-correct, li, td, p, .fc-card-term, .fc-back-definition, .bloom-verbs, .es-title-text, .es-goal-text, .es-characters-text, .es-setting-text, .es-opening-text, .es-discovery-text, .es-connection-text, .es-summary-text, .es-scene-title, .es-scene-narrative, .cc-title-text, .cc-body-text, .qb-question-text, .qb-explanation, .qb-model-answer, .vm-item-title, .vm-item-description, .vm-fc-term, .vm-fc-definition, .vm-fc-suggested-image, .vm-yt-title, .vm-yt-description, .vm-yt-why-relevant, .ca-activity-title, .ca-activity-description, .ls-summary-title-en, .ls-summary-title-ar, .ls-point-title-en, .ls-point-title-ar, .ls-point-expl-en, .ls-point-expl-ar, .ls-term-en, .ls-term-ar, .ls-term-def-en, .ls-term-def-ar, .ls-formula, .ls-formula-desc-en, .ls-formula-desc-ar, .ls-tip-en, .ls-tip-ar';
    var elements = container.querySelectorAll(editableSelectors);

    elements.forEach(function (el) {
        // تجاهل الأزرار والعناصر التفاعلية
        if (el.closest('.inline-edit-toolbar') || el.closest('.section-header-actions') ||
            el.closest('.sub-tabs-container') || el.tagName === 'BUTTON' || el.tagName === 'A') return;

        // تجاهل العناصر التي تحتوي على حقول إدخال فعلية (input/select) — لكن لا نتجاهل
        // حاويات النص لمجرد احتوائها على روابط تحميل/مصدر بالداخل؛ contenteditable يدعم ذلك.
        if (el.querySelector('input') || el.querySelector('select')) return;

        // تجاهل العناصر التي تحتوي فقط على عناصر فرعية معقدة
        if (el.children.length > 3 && el.textContent.trim().length < 5) return;

        el.setAttribute('contenteditable', 'true');
        el.classList.add('editable-field');
        el.style.outline = 'none';
        el.addEventListener('focus', function () {
            this.style.background = '#fef3c7';
            this.style.borderRadius = '6px';
            this.style.boxShadow = '0 0 0 2px #f59e0b';
        });
        el.addEventListener('blur', function () {
            this.style.background = '';
            this.style.boxShadow = '';
        });
    });
}

function cancelInlineEdit(containerId, sectionKey) {
    var container = document.getElementById(containerId);
    if (!container) return;

    // استعادة البيانات الأصلية
    if (container._originalData) {
        window.generatedData[sectionKey] = container._originalData;
        container._originalData = null;
    }

    // إزالة شريط الأدوات
    var toolbar = document.getElementById('editToolbar_' + sectionKey);
    if (toolbar) toolbar.remove();

    // إزالة contenteditable
    container.querySelectorAll('[contenteditable]').forEach(function (el) {
        el.removeAttribute('contenteditable');
        el.classList.remove('editable-field');
        el.style.background = '';
        el.style.boxShadow = '';
    });

    container.classList.remove('inline-editing');

    var editBtn = container.querySelector('.btn-inline-edit[data-section="' + sectionKey + '"]');
    if (editBtn) {
        editBtn.innerHTML = '<i class="fas fa-edit"></i> تعديل';
        editBtn.classList.remove('editing-active');
    }

    // إعادة عرض القسم
    _refreshSection(sectionKey);
}

function saveInlineEdit(containerId, sectionKey) {
    var container = document.getElementById(containerId);
    if (!container) return;

    var lessonId = window.currentLessonId;
    if (!lessonId) {
        alert('يرجى حفظ الدرس أولاً');
        return;
    }

    // جمع البيانات المعدلة من DOM
    _collectEditedData(container, sectionKey);

    var saveBtn = container.querySelector('.btn-save-edit');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...';
    }

    // إرسال البيانات المعدلة للخادم
    // ملاحظة: رمز الأمان يُقرأ من وسم <meta name="csrf-token"> (نفس المصدر الذي يستخدمه
    // ai_lesson_csrf.js) لضمان توافقه مع requireCsrfPost() في update_section.php.
    var formData = new FormData();
    formData.append('lesson_id', lessonId);
    formData.append('section_type', sectionKey);
    formData.append('section_data', JSON.stringify(window.generatedData[sectionKey]));
    formData.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');

    var ajaxBase = window.isArchiveView ? 'ajax/update_section.php' : 'ajax/update_section.php';

    fetch(ajaxBase, {
        method: 'POST',
        body: formData
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                // إزالة وضع التعديل بنجاح
                container._originalData = null;
                var toolbar = document.getElementById('editToolbar_' + sectionKey);
                if (toolbar) toolbar.remove();

                container.querySelectorAll('[contenteditable]').forEach(function (el) {
                    el.removeAttribute('contenteditable');
                    el.classList.remove('editable-field');
                    el.style.background = '';
                    el.style.boxShadow = '';
                });

                container.classList.remove('inline-editing');

                var editBtn = container.querySelector('.btn-inline-edit[data-section="' + sectionKey + '"]');
                if (editBtn) {
                    editBtn.innerHTML = '<i class="fas fa-check"></i> تم الحفظ!';
                    editBtn.classList.remove('editing-active');
                    setTimeout(function () {
                        editBtn.innerHTML = '<i class="fas fa-edit"></i> تعديل';
                    }, 2000);
                }

                _refreshSection(sectionKey);
            } else {
                alert('خطأ في الحفظ: ' + (result.message || 'خطأ غير معروف'));
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> حفظ التعديلات';
                }
            }
        })
        .catch(function (err) {
            alert('خطأ في الاتصال: ' + err.message);
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> حفظ التعديلات';
            }
        });
}

function _collectEditedData(container, sectionKey) {
    // جمع التعديلات من DOM وتحديث generatedData
    // هذا تبسيط — يحفظ النص المعدل مباشرة
    var data = window.generatedData[sectionKey];
    if (!data) return;

    if (sectionKey === 'lesson_plan') {
        // جمع الأهداف المعدلة
        var objSections = { cognitive: [], affective: [], psychomotor: [] };
        container.querySelectorAll('.plan-item-content ul').forEach(function (ul) {
            var prevStrong = ul.previousElementSibling;
            var key = null;
            if (prevStrong) {
                var txt = prevStrong.textContent;
                if (txt.indexOf('المعرفية') !== -1) key = 'cognitive';
                else if (txt.indexOf('الوجدانية') !== -1) key = 'affective';
                else if (txt.indexOf('المهارية') !== -1) key = 'psychomotor';
            }
            if (key && data.objectives) {
                var items = [];
                ul.querySelectorAll('li').forEach(function (li) {
                    var text = li.textContent.trim();
                    if (text) items.push(text);
                });
                if (items.length > 0) data.objectives[key] = items;
            }
        });

        // جمع المراحل المعدلة
        if (data.lesson_phases) {
            var rows = container.querySelectorAll('.lesson-table tbody tr');
            rows.forEach(function (row, i) {
                if (data.lesson_phases[i]) {
                    var cells = row.querySelectorAll('td');
                    if (cells[0]) data.lesson_phases[i].phase = cells[0].textContent.trim();
                    if (cells[2]) data.lesson_phases[i].description = cells[2].textContent.trim();
                    if (cells[3]) data.lesson_phases[i].teacher_role = cells[3].textContent.trim();
                    if (cells[4]) data.lesson_phases[i].student_role = cells[4].textContent.trim();
                }
            });
        }
    } else if (sectionKey === 'educational_stories') {
        // المخطط الحالي: {title, learning_goal, characters, setting, opening,
        // discovery_moment, lesson_connection, summary, scenes:[{title, narrative,
        // questions[], expected_answers[], ...}], ...}
        // (المخطط القديم {stories:[...]} مهجور — displayEducationalStories يعرض رسالة إعادة توليد له.)
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch (e) {}
        }
        if (!data || typeof data !== 'object') return;

        // قراءة حقل نصي عبر كلاسه الدلالي؛ innerText يحترم <br> كأسطر جديدة.
        function readField(sel) {
            var el = container.querySelector(sel);
            if (!el) return null;
            return (el.innerText != null ? el.innerText : el.textContent).trim();
        }
        var val;
        if ((val = readField('.es-title-text')) !== null) data.title = val;
        if ((val = readField('.es-goal-text')) !== null) data.learning_goal = val;
        if ((val = readField('.es-characters-text')) !== null) data.characters = val;
        if ((val = readField('.es-setting-text')) !== null) data.setting = val;
        if ((val = readField('.es-opening-text')) !== null) data.opening = val;
        if ((val = readField('.es-discovery-text')) !== null) data.discovery_moment = val;
        if ((val = readField('.es-connection-text')) !== null) data.lesson_connection = val;
        if ((val = readField('.es-summary-text')) !== null) data.summary = val;

        // المشاهد: مصفوفة واحدة بترتيب العرض — الموضع = الفهرس.
        // داخل كل بطاقة مشهد لا يوجد سوى <ul> واحدة للأسئلة وواحدة للإجابات (renderList فقط).
        if (Array.isArray(data.scenes)) {
            container.querySelectorAll('.es-scene-card').forEach(function (card, idx) {
                if (!data.scenes[idx]) return;
                var scene = data.scenes[idx];
                var titleEl = card.querySelector('.es-scene-title');
                var narrEl = card.querySelector('.es-scene-narrative');
                if (titleEl) scene.title = (titleEl.innerText != null ? titleEl.innerText : titleEl.textContent).trim();
                if (narrEl) scene.narrative = (narrEl.innerText != null ? narrEl.innerText : narrEl.textContent).trim();

                var uls = card.querySelectorAll('ul');
                if (uls.length >= 1) {
                    scene.questions = [];
                    uls[0].querySelectorAll('li').forEach(function (li) {
                        var t = (li.innerText != null ? li.innerText : li.textContent).trim();
                        if (t) scene.questions.push(t);
                    });
                }
                if (uls.length >= 2) {
                    scene.expected_answers = [];
                    uls[1].querySelectorAll('li').forEach(function (li) {
                        var t = (li.innerText != null ? li.innerText : li.textContent).trim();
                        if (t) scene.expected_answers.push(t);
                    });
                }
            });
        }
    } else if (sectionKey === 'custom_content') {
        // جمع تعديلات المحتوى المخصص (العنوان + النص) من DOM إلى generatedData.custom_content
        if (Array.isArray(data)) {
            container.querySelectorAll('.visual-item[data-cc-index]').forEach(function (card) {
                var idx = parseInt(card.getAttribute('data-cc-index'), 10);
                if (isNaN(idx) || !data[idx]) return;
                var titleEl = card.querySelector('.cc-title-text');
                var bodyEl = card.querySelector('.cc-body-text');
                if (titleEl) data[idx].title = titleEl.textContent.trim();
                if (bodyEl) data[idx].content_html = bodyEl.innerHTML;
            });
        }
    } else if (sectionKey === 'question_bank') {
        // question_bank = {multiple_choice:[], true_false:[], graduated:[], short_answer:[],
        //                  fill_blank:[], ordering:[], matching:[]}
        // التجميع يقتصر على #qb-all (فهو يحوي كل الأسئلة موسومة بـ data-qb-type+data-qb-index).
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            var allPane = container.querySelector('#qb-all');
            if (!allPane) allPane = container; // احتياط
            allPane.querySelectorAll('.question-item[data-qb-type][data-qb-index]').forEach(function (el) {
                var typeKey = el.getAttribute('data-qb-type');
                var idx = parseInt(el.getAttribute('data-qb-index'), 10);
                if (!typeKey || isNaN(idx) || !data[typeKey] || !data[typeKey][idx]) return;
                var q = data[typeKey][idx];
                var qTextEl = el.querySelector('.qb-question-text');
                if (qTextEl) q.question = qTextEl.textContent.trim();

                // خيارات MCQ: كل option موسوم بـ data-qb-option-index.
                if (typeKey === 'multiple_choice') {
                    el.querySelectorAll('.option-item[data-qb-option-index]').forEach(function (opt) {
                        var oi = parseInt(opt.getAttribute('data-qb-option-index'), 10);
                        if (!isNaN(oi)) {
                            // إزالة أيقونة "الإجابة الصحيحة" من النص المجمع.
                            var raw = (opt.innerText != null ? opt.innerText : opt.textContent).trim();
                            if (q.options) q.options[oi] = raw;
                        }
                    });
                }
                // شرح TF / إجابة مقالية / إجابة قصيرة: حقول نصية موسومة بـ كلاسات دلالية.
                var expl = el.querySelector('.qb-explanation');
                if (expl) q.explanation = expl.textContent.trim();
                var ma = el.querySelector('.qb-model-answer');
                if (ma) q.model_answer = ma.textContent.trim();
                // الترتيب: قائمة <ol> عناصرها <li> (يتم جمعها موضعياً).
                var ordList = el.querySelector('.qb-ordering-list, ol');
                if (typeKey === 'ordering' && ordList && q.items) {
                    var liTexts = [];
                    ordList.querySelectorAll('li').forEach(function (li) {
                        var t = (li.innerText != null ? li.innerText : li.textContent).trim();
                        if (t) liTexts.push(t);
                    });
                    if (liTexts.length === q.items.length) q.items = liTexts;
                }
            });
        }
    } else if (sectionKey === 'visual_materials') {
        // visual_materials = {flash_cards:[], educational_images:[], sequential_images:[],
        //                      youtube_videos:[], lesson_images:[]}
        // التجميع يقتصر على #vm-all لتفادي التكرار.
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            var vmPane = container.querySelector('#vm-all') || container;
            function readText(el) {
                if (!el) return null;
                return (el.innerText != null ? el.innerText : el.textContent).trim();
            }
            vmPane.querySelectorAll('[data-vm-type][data-vm-index]').forEach(function (el) {
                var typeKey = el.getAttribute('data-vm-type');
                var idx = parseInt(el.getAttribute('data-vm-index'), 10);
                if (!typeKey || isNaN(idx) || !data[typeKey] || !data[typeKey][idx]) return;
                var item = data[typeKey][idx];

                if (typeKey === 'flash_cards') {
                    var termEl = el.querySelector('.vm-fc-term');
                    var defEl = el.querySelector('.vm-fc-definition');
                    var suggEl = el.querySelector('.vm-fc-suggested-image');
                    if (termEl) { item.term = termEl.textContent.trim(); }
                    if (defEl) { item.definition = defEl.textContent.trim(); }
                    if (suggEl) { item.suggested_image = suggEl.textContent.trim(); }
                } else if (typeKey === 'educational_images' || typeKey === 'sequential_images') {
                    var titleEl = el.querySelector('.vm-item-title');
                    var descEl = el.querySelector('.vm-item-description');
                    if (titleEl) item.title = titleEl.textContent.trim();
                    if (descEl) item.description = descEl.textContent.trim();
                    // قائمة العناصر (educational_images فقط) أو الخطوات (sequential_images).
                    var elemsUl = el.querySelector('.vm-item-elements');
                    if (elemsUl && Array.isArray(item.elements)) {
                        var arr = [];
                        elemsUl.querySelectorAll('li').forEach(function (li) {
                            var t = li.textContent.trim();
                            if (t) arr.push(t);
                        });
                        if (arr.length) item.elements = arr;
                    }
                    var stepsWrap = el.querySelector('.vm-item-steps');
                    if (stepsWrap && Array.isArray(item.steps)) {
                        var sarr = [];
                        stepsWrap.querySelectorAll('.vm-step-description').forEach(function (p) {
                            var t = p.textContent.trim();
                            if (t) sarr.push({ step_number: '', description: t });
                        });
                        if (sarr.length) item.steps = sarr;
                    }
                } else if (typeKey === 'youtube_videos') {
                    var ytTitleEl = el.querySelector('.vm-yt-title');
                    var ytDescEl = el.querySelector('.vm-yt-description');
                    var ytWhyEl = el.querySelector('.vm-yt-why-relevant');
                    if (ytTitleEl) item.title = ytTitleEl.textContent.trim();
                    if (ytDescEl) item.description = ytDescEl.textContent.trim();
                    if (ytWhyEl) item.why_relevant = ytWhyEl.textContent.trim();
                }
            });
        }
    } else if (sectionKey === 'class_activities') {
        // class_activities = {digital_activities:[], collaborative_activities:[],
        //                      creative_activities:[], quick_activities:[], assessment_activities:[]}
        // التجميع يقتصر على #ca-all.
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            var caPane = container.querySelector('#ca-all') || container;
            caPane.querySelectorAll('.visual-item[data-ca-type][data-ca-index]').forEach(function (el) {
                var typeKey = el.getAttribute('data-ca-type');
                var idx = parseInt(el.getAttribute('data-ca-index'), 10);
                if (!typeKey || isNaN(idx) || !data[typeKey] || !data[typeKey][idx]) return;
                var a = data[typeKey][idx];
                var titleEl = el.querySelector('.ca-activity-title');
                var descEl = el.querySelector('.ca-activity-description');
                if (titleEl) a.title = titleEl.textContent.trim();
                if (descEl) a.description = descEl.textContent.trim();
                var stepsOl = el.querySelector('.ca-activity-steps');
                if (stepsOl && Array.isArray(a.steps)) {
                    var sarr = [];
                    stepsOl.querySelectorAll('li').forEach(function (li) {
                        var t = (li.innerText != null ? li.innerText : li.textContent).trim();
                        if (t) sarr.push(t);
                    });
                    if (sarr.length) a.steps = sarr;
                }
            });
        }
    } else if (sectionKey === 'lesson_summary') {
        // lesson_summary = {summary_title_en, summary_title_ar, introduction, conclusion,
        //                   key_points:[], key_terms:[], important_formulas:[], study_tips:[]}
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            var tEn = container.querySelector('.ls-summary-title-en');
            var tAr = container.querySelector('.ls-summary-title-ar');
            if (tEn) data.summary_title_en = tEn.textContent.trim();
            if (tAr) data.summary_title_ar = tAr.textContent.trim();

            function readSection(sel) {
                var el = container.querySelector(sel);
                if (!el) return null;
                return (el.innerText != null ? el.innerText : el.textContent).trim();
            }
            var intro = readSection('.ls-introduction');
            if (intro !== null) data.introduction = intro;
            var concl = readSection('.ls-conclusion');
            if (concl !== null) data.conclusion = concl;

            container.querySelectorAll('[data-ls-list]').forEach(function (listEl) {
                var listKey = listEl.getAttribute('data-ls-list');
                if (!listKey || !Array.isArray(data[listKey])) return;
                listEl.querySelectorAll('[data-ls-item-index]').forEach(function (itemEl) {
                    var i = parseInt(itemEl.getAttribute('data-ls-item-index'), 10);
                    if (isNaN(i) || !data[listKey][i]) return;
                    var item = data[listKey][i];

                    function read(cls) {
                        var e = itemEl.querySelector('.' + cls);
                        return e ? e.textContent.trim() : null;
                    }
                    if (listKey === 'key_points') {
                        var en = read('ls-point-title-en');
                        var ar = read('ls-point-title-ar');
                        var explEn = read('ls-point-expl-en');
                        var explAr = read('ls-point-expl-ar');
                        if (en !== null) item.title_en = en;
                        if (ar !== null) item.title_ar = ar;
                        if (explEn !== null) item.explanation_en = explEn;
                        if (explAr !== null) item.explanation_ar = explAr;
                    } else if (listKey === 'key_terms') {
                        var t = read('ls-term-en');
                        var tar = read('ls-term-ar');
                        var d = read('ls-term-def-en');
                        var dar = read('ls-term-def-ar');
                        if (t !== null) item.term_en = t;
                        if (tar !== null) item.term_ar = tar;
                        if (d !== null) item.definition_en = d;
                        if (dar !== null) item.definition_ar = dar;
                    } else if (listKey === 'important_formulas') {
                        var f = read('ls-formula');
                        var dEn = read('ls-formula-desc-en');
                        var dAr = read('ls-formula-desc-ar');
                        if (f !== null) item.formula = f;
                        if (dEn !== null) item.description_en = dEn;
                        if (dAr !== null) item.description_ar = dAr;
                    } else if (listKey === 'study_tips') {
                        var tt = read('ls-tip-en');
                        var tta = read('ls-tip-ar');
                        if (tt !== null) item.tip_en = tt;
                        if (tta !== null) item.tip_ar = tta;
                    }
                });
            });
        }
    }

    // حفظ أي تعديلات نصية عامة في البيانات
    window.generatedData[sectionKey] = data;
}

function _refreshSection(sectionKey) {
    switch (sectionKey) {
        case 'lesson_plan': displayLessonPlan(); break;
        case 'visual_materials': displayVisualMaterials(); break;
        case 'question_bank': displayQuestionBank(); break;
        case 'class_activities': displayClassActivities(); break;
        case 'educational_stories': displayEducationalStories(); break;
        case 'lesson_summary': displayLessonSummary(); break;
        case 'custom_content': if (typeof displayCustomContent === 'function') displayCustomContent(); break;
        case 'mind_maps': if (typeof displayMindMaps === 'function') displayMindMaps(); break;
    }
}

// =============================================
// Bloom's Taxonomy Analysis
// =============================================
function analyzeBloomsTaxonomy() {
    if (!window.generatedData || !window.generatedData.lesson_plan) return '';
    var plan = window.generatedData.lesson_plan;

    var bloomLevels = {
        'remember': { ar: 'التذكر', color: '#ef4444', verbs: 'يذكر · يعدد · يسمي · يحدد · يتعرف', keywords: ['يذكر', 'يعدد', 'يسمي', 'يحدد', 'يتعرف', 'remember', 'recall', 'list', 'define', 'identify'] },
        'understand': { ar: 'الفهم', color: '#f97316', verbs: 'يفسر · يشرح · يوضح · يلخص · يقارن', keywords: ['يفسر', 'يشرح', 'يوضح', 'يلخص', 'يقارن', 'understand', 'explain', 'describe', 'summarize', 'compare'] },
        'apply': { ar: 'التطبيق', color: '#eab308', verbs: 'يطبق · يستخدم · ينفذ · يحل · يوظف', keywords: ['يطبق', 'يستخدم', 'ينفذ', 'يحل', 'يوظف', 'apply', 'use', 'execute', 'solve', 'implement'] },
        'analyze': { ar: 'التحليل', color: '#22c55e', verbs: 'يحلل · يميز · يفرق · يصنف · ينظم', keywords: ['يحلل', 'يميز', 'يقارن', 'يفرق', 'يصنف', 'analyze', 'differentiate', 'organize', 'classify'] },
        'evaluate': { ar: 'التقويم', color: '#3b82f6', verbs: 'يقيّم · يحكم · يبرر · ينتقد · يدافع', keywords: ['يقيّم', 'يحكم', 'يبرر', 'ينتقد', 'يدافع', 'evaluate', 'judge', 'justify', 'critique'] },
        'create': { ar: 'الإبداع', color: '#8b5cf6', verbs: 'يصمم · يبتكر · يقترح · يؤلف · ينتج', keywords: ['يصمم', 'يبتكر', 'يقترح', 'يؤلف', 'ينتج', 'create', 'design', 'construct', 'produce', 'compose'] }
    };

    var allObjectives = [];
    if (plan.objectives) {
        if (plan.objectives.cognitive) allObjectives = allObjectives.concat(plan.objectives.cognitive);
        if (plan.objectives.affective) allObjectives = allObjectives.concat(plan.objectives.affective);
        if (plan.objectives.psychomotor) allObjectives = allObjectives.concat(plan.objectives.psychomotor);
    }

    var objectivesText = allObjectives.join(' ').toLowerCase();
    var counts = {};
    var matchedObjectives = {};
    var total = 0;

    var levelKeys = Object.keys(bloomLevels);
    for (var li = 0; li < levelKeys.length; li++) {
        var level = levelKeys[li];
        var info = bloomLevels[level];
        var count = 0;
        matchedObjectives[level] = [];
        info.keywords.forEach(function (kw) {
            var regex = new RegExp(kw, 'gi');
            allObjectives.forEach(function (obj) {
                if (regex.test(obj)) {
                    if (matchedObjectives[level].indexOf(obj) === -1) {
                        matchedObjectives[level].push(obj);
                    }
                }
                regex.lastIndex = 0;
            });
            var matches = objectivesText.match(regex);
            if (matches) count += matches.length;
        });
        counts[level] = count;
        total += count;
    }

    if (total === 0 && allObjectives.length > 0) {
        counts['understand'] = Math.ceil(allObjectives.length * 0.4);
        counts['apply'] = Math.ceil(allObjectives.length * 0.3);
        counts['remember'] = Math.ceil(allObjectives.length * 0.3);
        var uCount = Math.ceil(allObjectives.length * 0.4);
        var aCount = Math.ceil(allObjectives.length * 0.3);
        matchedObjectives['understand'] = allObjectives.slice(0, uCount);
        matchedObjectives['apply'] = allObjectives.slice(uCount, uCount + aCount);
        matchedObjectives['remember'] = allObjectives.slice(uCount + aCount);
        total = allObjectives.length;
    }

    var html = '<div class="bloom-pyramid">';
    var levels = ['create', 'evaluate', 'analyze', 'apply', 'understand', 'remember'];
    var widths = [45, 55, 65, 75, 85, 95];

    levels.forEach(function (level, idx) {
        var count = counts[level] || 0;
        var pct = total > 0 ? Math.round((count / total) * 100) : 0;
        var info = bloomLevels[level];
        var objectives = matchedObjectives[level] || [];
        var objDataAttr = objectives.length > 0 ? ' data-objectives="' + objectives.map(function (o) { return escapeHtml(o); }).join('|||') + '"' : '';
        html += '<div class="bloom-level' + (count > 0 ? ' bloom-clickable' : '') + '" style="background: ' + info.color + '; width: ' + widths[idx] + '%; opacity: ' + (count > 0 ? '1' : '0.4') + ';" data-level="' + level + '" data-level-name="' + info.ar + '" data-color="' + info.color + '"' + objDataAttr + ' onclick="toggleBloomDetails(this)">';
        html += '<div>' + info.ar + '</div>';
        html += '<div class="bloom-verbs">' + info.verbs + '</div>';
        if (count > 0) html += '<span class="bloom-count">' + count + ' هدف <span class="bloom-pct">(' + pct + '%)</span></span>';
        html += '</div>';
        if (count > 0 && objectives.length > 0) {
            html += '<div class="bloom-details" id="bloom-detail-' + level + '" style="display:none; width: ' + widths[idx] + '%;">';
            html += '<div class="bloom-details-header" style="border-right: 4px solid ' + info.color + ';">';
            html += '<i class="fas fa-bullseye" style="color: ' + info.color + ';"></i> أهداف مستوى <strong>' + info.ar + '</strong>:';
            html += '</div>';
            html += '<ul class="bloom-details-list">';
            objectives.forEach(function (obj) {
                var highlighted = escapeHtml(obj);
                info.keywords.forEach(function (kw) {
                    var re = new RegExp('(' + kw + ')', 'gi');
                    highlighted = highlighted.replace(re, '<mark style="background: ' + info.color + '22; color: ' + info.color + '; font-weight: 700; padding: 1px 4px; border-radius: 3px;">$1</mark>');
                });
                html += '<li>' + highlighted + '</li>';
            });
            html += '</ul></div>';
        }
    });
    html += '</div>';

    return html;
}

function toggleBloomDetails(el) {
    var level = el.getAttribute('data-level');
    var detailPanel = document.getElementById('bloom-detail-' + level);
    if (!detailPanel) return;

    var isVisible = detailPanel.style.display !== 'none';

    document.querySelectorAll('.bloom-details').forEach(function (d) { d.style.display = 'none'; });
    document.querySelectorAll('.bloom-level').forEach(function (l) { l.classList.remove('bloom-active'); });

    if (!isVisible) {
        detailPanel.style.display = 'block';
        el.classList.add('bloom-active');
    }
}

// =============================================
// Difficulty Analysis
// =============================================
function analyzeDifficulty() {
    if (!window.generatedData || !window.generatedData.question_bank) return '';
    var qb = window.generatedData.question_bank;

    var easy = 0, medium = 0, hard = 0, total = 0;

    var allQuestions = [].concat(
        qb.multiple_choice || [],
        qb.true_false || [],
        qb.graduated || [],
        qb.short_answer || [],
        qb.fill_blank || [],
        qb.ordering || [],
        qb.matching || []
    );

    allQuestions.forEach(function (q) {
        total++;
        var diff = (q.difficulty || 'medium').toLowerCase();
        if (diff === 'easy') easy++;
        else if (diff === 'hard') hard++;
        else medium++;
    });

    if (total === 0) return '';

    var easyPct = Math.round((easy / total) * 100);
    var mediumPct = Math.round((medium / total) * 100);
    var hardPct = Math.round((hard / total) * 100);

    var html = '<div style="margin-top: 15px;">';

    html += '<div class="difficulty-bar-container">';
    html += '<span class="difficulty-bar-label" style="color: #22c55e;">سهل</span>';
    html += '<div class="difficulty-bar"><div class="difficulty-bar-fill" style="width: ' + easyPct + '%; background: linear-gradient(135deg, #22c55e, #16a34a);">' + easy + ' (' + easyPct + '%)</div></div>';
    html += '</div>';

    html += '<div class="difficulty-bar-container">';
    html += '<span class="difficulty-bar-label" style="color: #f59e0b;">متوسط</span>';
    html += '<div class="difficulty-bar"><div class="difficulty-bar-fill" style="width: ' + mediumPct + '%; background: linear-gradient(135deg, #f59e0b, #d97706);">' + medium + ' (' + mediumPct + '%)</div></div>';
    html += '</div>';

    html += '<div class="difficulty-bar-container">';
    html += '<span class="difficulty-bar-label" style="color: #ef4444;">صعب</span>';
    html += '<div class="difficulty-bar"><div class="difficulty-bar-fill" style="width: ' + hardPct + '%; background: linear-gradient(135deg, #ef4444, #dc2626);">' + hard + ' (' + hardPct + '%)</div></div>';
    html += '</div>';

    html += '<p style="text-align: center; color: #64748b; font-size: 0.85rem; margin-top: 8px;">إجمالي الأسئلة: ' + total + '</p>';
    html += '</div>';

    return html;
}

// =============================================
// Display Lesson Summary
// =============================================
function displayLessonSummary() {
    var container = document.getElementById('lessonSummaryContent');
    if (!container) return;
    var tabBtn = document.querySelector('[data-tab="lessonSummary"]') || document.querySelector('[data-tab="lesson-summary"]');

    if (!window.generatedData || !window.generatedData.lesson_summary) {
        var errorDetail = '';
        if (window._lastGenerationErrors && window._lastGenerationErrors.length > 0) {
            var errs = window._lastGenerationErrors.filter(function (e) { return e.indexOf('ملخص الدرس') !== -1 || e.indexOf('lesson_summary') !== -1; });
            if (errs.length > 0) {
                errorDetail = '<br><small style="color:#b45309;">' + errs.join('<br>') + '</small>';
            }
        }
        container.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;">' +
            '<i class="fas fa-file-lines" style="font-size:3rem;margin-bottom:15px;display:block;"></i>' +
            '<p>لم يتم توليد ملخص الدرس</p>' + errorDetail +
            (!window.isArchiveView ? '<button class="btn-regenerate-section" onclick="regenerateSection(\'lesson_summary\')" style="margin-top:10px;"><i class="fas fa-sync-alt"></i> إعادة توليد ملخص الدرس</button>' : '') + '</div>';
        if (tabBtn) tabBtn.style.display = 'none';
        return;
    }

    if (tabBtn) tabBtn.style.display = '';
    var summary = window.generatedData.lesson_summary;

    var html = '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-file-lines"></i> ملخص الدرس</h3>';
    html += _buildSectionActions('lessonSummaryContent', 'lesson_summary');
    html += '</div>';

    html += '<div class="lesson-summary-container" style="max-width:900px;margin:0 auto;padding:20px;">';

    var titleEn = summary.summary_title_en || 'Lesson Summary';
    var titleAr = summary.summary_title_ar || 'ملخص الدرس';
    // ls-summary-title-en/ar: كلاسات دلالية لربط العناوين بحقلي generatedData.lesson_summary.
    html += '<div style="text-align:center;margin-bottom:30px;padding:25px;background:linear-gradient(135deg,#667eea,#764ba2);border-radius:16px;color:white;">' +
        '<h2 class="ls-summary-title-en" style="margin:0 0 5px 0;font-size:1.6rem;font-weight:700;">' + escapeHtml(titleEn) + '</h2>' +
        '<h3 class="ls-summary-title-ar" style="margin:0;font-size:1.3rem;font-weight:600;opacity:0.9;">' + escapeHtml(titleAr) + '</h3>' +
        '</div>';

    if (summary.introduction) {
        html += _buildSummarySection('fas fa-book-open', '#3b82f6', 'Introduction', 'مقدمة', summary.introduction, 'ls-introduction');
    }

    if (summary.key_points && summary.key_points.length > 0) {
        // data-ls-list="key_points" يربط الحاوية بالقائمة في generatedData.lesson_summary.key_points.
        var pointsHtml = '<div data-ls-list="key_points" style="display:flex;flex-direction:column;gap:10px;">';
        summary.key_points.forEach(function (point, i) {
            var gradients = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316'];
            var color = gradients[i % gradients.length];
            var pTitleEn = typeof point === 'string' ? point : (point.title_en || point.en || '');
            var pTitleAr = typeof point === 'string' ? '' : (point.title_ar || point.ar || '');
            var pExplEn = typeof point === 'string' ? '' : (point.explanation_en || '');
            var pExplAr = typeof point === 'string' ? '' : (point.explanation_ar || '');
            var pEmoji = (typeof point === 'object' && point.emoji) ? point.emoji + ' ' : '';
            // data-ls-item-index يربط البطاقة بفهرسها في key_points.
            pointsHtml += '<div data-ls-item-index="' + i + '" style="display:flex;gap:12px;align-items:flex-start;padding:12px 15px;background:linear-gradient(135deg,' + color + '08,' + color + '15);border-radius:10px;border-right:4px solid ' + color + ';">' +
                '<span style="min-width:28px;height:28px;border-radius:50%;background:' + color + ';color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;flex-shrink:0;">' + (i + 1) + '</span>' +
                '<div>' +
                '<div class="ls-point-title-en" style="font-weight:600;color:#1e293b;margin-bottom:3px;">' + pEmoji + escapeHtml(pTitleEn) + '</div>' +
                (pTitleAr ? '<div class="ls-point-title-ar" style="color:#475569;font-size:0.9rem;margin-bottom:4px;">' + escapeHtml(pTitleAr) + '</div>' : '') +
                (pExplEn ? '<div class="ls-point-expl-en" style="color:#334155;font-size:0.88rem;line-height:1.5;margin-top:4px;">' + escapeHtml(pExplEn) + '</div>' : '') +
                (pExplAr ? '<div class="ls-point-expl-ar" style="color:#64748b;font-size:0.85rem;line-height:1.5;margin-top:2px;">' + escapeHtml(pExplAr) + '</div>' : '') +
                '</div></div>';
        });
        pointsHtml += '</div>';
        html += _buildSummaryBlock('fas fa-star', '#f59e0b', 'Key Points', 'النقاط الرئيسية', pointsHtml);
    }

    if (summary.key_terms && summary.key_terms.length > 0) {
        var termsHtml = '<div data-ls-list="key_terms" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">';
        summary.key_terms.forEach(function (term, i) {
            termsHtml += '<div data-ls-item-index="' + i + '" style="padding:14px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:10px;border:1px solid #86efac;">' +
                '<div class="ls-term-en" style="font-weight:700;color:#166534;font-size:1rem;margin-bottom:4px;">' + escapeHtml(term.term_en || term.term || '') + '</div>' +
                (term.term_ar ? '<div class="ls-term-ar" style="font-weight:600;color:#15803d;font-size:0.9rem;margin-bottom:6px;">' + escapeHtml(term.term_ar) + '</div>' : '') +
                '<div class="ls-term-def-en" style="color:#374151;font-size:0.88rem;line-height:1.5;">' + escapeHtml(term.definition_en || term.definition || '') + '</div>' +
                (term.definition_ar ? '<div class="ls-term-def-ar" style="color:#4b5563;font-size:0.85rem;margin-top:3px;">' + escapeHtml(term.definition_ar) + '</div>' : '') +
                '</div>';
        });
        termsHtml += '</div>';
        html += _buildSummaryBlock('fas fa-book', '#10b981', 'Key Terms', 'المصطلحات الأساسية', termsHtml);
    }

    if (summary.important_formulas && summary.important_formulas.length > 0) {
        var formulasHtml = '<div data-ls-list="important_formulas" style="display:flex;flex-direction:column;gap:10px;">';
        summary.important_formulas.forEach(function (f, i) {
            formulasHtml += '<div data-ls-item-index="' + i + '" style="padding:14px;background:linear-gradient(135deg,#fefce8,#fef9c3);border-radius:10px;border:1px solid #fde047;">' +
                '<div class="ls-formula" style="font-weight:700;color:#854d0e;font-size:1.05rem;font-family:monospace;margin-bottom:6px;">' + escapeHtml(f.formula || f) + '</div>' +
                '<div class="ls-formula-desc-en" style="color:#713f12;font-size:0.88rem;">' + escapeHtml(f.description_en || f.description || '') + '</div>' +
                (f.description_ar ? '<div class="ls-formula-desc-ar" style="color:#92400e;font-size:0.85rem;margin-top:2px;">' + escapeHtml(f.description_ar) + '</div>' : '') +
                '</div>';
        });
        formulasHtml += '</div>';
        html += _buildSummaryBlock('fas fa-calculator', '#eab308', 'Important Formulas', 'الصيغ المهمة', formulasHtml);
    }

    if (summary.conclusion) {
        html += _buildSummarySection('fas fa-flag-checkered', '#8b5cf6', 'Conclusion', 'الخاتمة', summary.conclusion, 'ls-conclusion');
    }

    if (summary.study_tips && summary.study_tips.length > 0) {
        var tipsHtml = '<div data-ls-list="study_tips" style="display:flex;flex-direction:column;gap:8px;">';
        summary.study_tips.forEach(function (tip, i) {
            var tipEn = typeof tip === 'string' ? tip : (tip.tip_en || tip.en || '');
            var tipAr = typeof tip === 'string' ? '' : (tip.tip_ar || tip.ar || '');
            tipsHtml += '<div data-ls-item-index="' + i + '" style="display:flex;gap:10px;align-items:flex-start;padding:10px 14px;">' +
                '<i class="fas fa-lightbulb" style="color:#f59e0b;margin-top:3px;flex-shrink:0;"></i>' +
                '<div>' +
                '<div class="ls-tip-en" style="font-weight:600;color:#1e293b;">' + escapeHtml(tipEn) + '</div>' +
                (tipAr ? '<div class="ls-tip-ar" style="color:#64748b;font-size:0.9rem;margin-top:2px;">' + escapeHtml(tipAr) + '</div>' : '') +
                '</div></div>';
        });
        tipsHtml += '</div>';
        html += _buildSummaryBlock('fas fa-graduation-cap', '#ec4899', 'Study Tips', 'نصائح للدراسة', tipsHtml);
    }

    html += '</div>';
    container.innerHTML = html;
}

function _buildSummarySection(icon, color, titleEn, titleAr, data, fieldClass) {
    // fieldClass يُطبَّق على حاوية المحتوى ليربطها بحقل generatedData.lesson_summary
    // (مثل ls-introduction / ls-conclusion) ليتمكن الجامع من قراءتها.
    var cls = fieldClass ? ' class="' + fieldClass + '"' : '';
    return '<div style="margin-bottom:20px;padding:20px;background:white;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,0.04);">' +
        '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">' +
        '<i class="' + icon + '" style="color:' + color + ';font-size:1.2rem;"></i>' +
        '<span style="font-weight:700;color:#1e293b;font-size:1.05rem;">' + titleEn + '</span>' +
        '<span style="color:#64748b;font-size:0.95rem;">/ ' + titleAr + '</span>' +
        '</div>' +
        '<div' + cls + ' style="line-height:1.7;">' +
        '<div style="color:#334155;margin-bottom:6px;">' + escapeHtml(data.en || data) + '</div>' +
        (data.ar ? '<div style="color:#64748b;font-size:0.93rem;">' + escapeHtml(data.ar) + '</div>' : '') +
        '</div></div>';
}

function _buildSummaryBlock(icon, color, titleEn, titleAr, innerHtml) {
    return '<div style="margin-bottom:20px;padding:20px;background:white;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,0.04);">' +
        '<div style="display:flex;align-items:center;gap:10px;margin-bottom:15px;">' +
        '<i class="' + icon + '" style="color:' + color + ';font-size:1.2rem;"></i>' +
        '<span style="font-weight:700;color:#1e293b;font-size:1.05rem;">' + titleEn + '</span>' +
        '<span style="color:#64748b;font-size:0.95rem;">/ ' + titleAr + '</span>' +
        '</div>' +
        innerHtml +
        '</div>';
}

// =============================================
// Display Lesson Plan
// =============================================
function displayLessonPlan() {
    var container = document.getElementById('lessonPlanContent');
    if (!container) return;
    var plan = escapeTextTree(window.generatedData.lesson_plan);

    if (!plan) {
        var errorDetail = '';
        if (window._lastGenerationErrors && window._lastGenerationErrors.length > 0) {
            var errs = window._lastGenerationErrors.filter(function (e) { return e.indexOf('تحضير الدرس') !== -1 || e.indexOf('lesson_plan') !== -1; });
            if (errs.length > 0) {
                errorDetail = '<br><small style="color:#b45309;">' + errs.map(escapeHtml).join('<br>') + '</small>';
            }
        }
        container.innerHTML = '<div class="alert alert-error">' +
            '<i class="fas fa-exclamation-circle"></i> لم يتم توليد تحضير الدرس' + errorDetail +
            (!window.isArchiveView ? '<br><button class="btn-regenerate-section" onclick="regenerateSection(\'lesson_plan\')" style="margin-top:10px;"><i class="fas fa-sync-alt"></i> إعادة توليد تحضير الدرس</button>' : '') + '</div>';
        return;
    }

    function buildObjectivesHtml() {
        var h = '';
        if (plan.objectives) {
            h += '<div style="margin-bottom: 25px;">';
            h += '<h4 style="color: #1e293b; margin-bottom: 15px;"><i class="fas fa-bullseye" style="color: #10b981;"></i> أهداف الدرس</h4>';
            if (plan.objectives.cognitive && plan.objectives.cognitive.length > 0) {
                h += '<div style="margin-bottom: 15px;"><strong>الأهداف المعرفية:</strong><ul>';
                plan.objectives.cognitive.forEach(function (obj) { h += '<li>' + obj + '</li>'; });
                h += '</ul></div>';
            }
            if (plan.objectives.affective && plan.objectives.affective.length > 0) {
                h += '<div style="margin-bottom: 15px;"><strong>الأهداف الوجدانية:</strong><ul>';
                plan.objectives.affective.forEach(function (obj) { h += '<li>' + obj + '</li>'; });
                h += '</ul></div>';
            }
            if (plan.objectives.psychomotor && plan.objectives.psychomotor.length > 0) {
                h += '<div style="margin-bottom: 15px;"><strong>الأهداف المهارية:</strong><ul>';
                plan.objectives.psychomotor.forEach(function (obj) { h += '<li>' + obj + '</li>'; });
                h += '</ul></div>';
            }
            h += '</div>';
        }
        if (plan.target_competencies && Array.isArray(plan.target_competencies) && plan.target_competencies.length > 0) {
            h += '<div class="plan-item">';
            h += '<div class="plan-item-title"><i class="fas fa-award" style="color: #f59e0b;"></i> الكفايات المستهدفة</div>';
            h += '<div class="plan-item-content"><div style="display: flex; flex-wrap: wrap; gap: 10px;">';
            plan.target_competencies.forEach(function (comp) {
                h += '<span style="background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e; padding: 8px 15px; border-radius: 10px; font-size: 0.9rem;">';
                h += '<i class="fas fa-star" style="margin-left: 5px; color: #f59e0b;"></i>' + comp + '</span>';
            });
            h += '</div></div></div>';
        }
        if (plan.objectives) {
            h += '<div class="plan-item">';
            h += '<div class="plan-item-title"><i class="fas fa-layer-group" style="color: #6366f1;"></i> تحليل هرم بلوم للأهداف</div>';
            h += '<div class="plan-item-content">' + analyzeBloomsTaxonomy() + '</div></div>';
        }
        return h;
    }

    function buildPhasesHtml() {
        var h = '';
        if (plan.motivational_intro) {
            h += '<div class="plan-item">';
            h += '<div class="plan-item-title"><i class="fas fa-rocket" style="color: #ec4899;"></i> المقدمة التحفيزية</div>';
            h += '<div class="plan-item-content" style="background: linear-gradient(135deg, #fdf2f8, #fce7f3); padding: 15px; border-radius: 12px;">';
            if (typeof plan.motivational_intro === 'object') {
                if (plan.motivational_intro.hook) h += '<p><strong>🎣 الجذب:</strong> ' + plan.motivational_intro.hook + '</p>';
                if (plan.motivational_intro.question) h += '<p><strong>❓ سؤال تحفيزي:</strong> ' + plan.motivational_intro.question + '</p>';
                if (plan.motivational_intro.story) h += '<p><strong>📖 قصة/موقف:</strong> ' + plan.motivational_intro.story + '</p>';
                if (plan.motivational_intro.content) h += plan.motivational_intro.content.replace(/\n/g, '<br>');
            } else {
                h += String(plan.motivational_intro).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        if (plan.strategies) {
            h += '<div style="margin-bottom: 25px;">';
            h += '<h4 style="color: #1e293b; margin-bottom: 15px;"><i class="fas fa-lightbulb" style="color: #f59e0b;"></i> الاستراتيجيات التعليمية</h4>';
            if (plan.strategies.teaching_strategies) {
                h += '<ul>';
                plan.strategies.teaching_strategies.forEach(function (s) { h += '<li>' + s + '</li>'; });
                h += '</ul>';
            }
            h += '</div>';
        }
        if (plan.lesson_phases && plan.lesson_phases.length > 0) {
            h += '<h4 style="color: #1e293b; margin-bottom: 15px;"><i class="fas fa-tasks" style="color: #3b82f6;"></i> مراحل الدرس</h4>';
            h += '<div class="lesson-table-wrapper"><table class="lesson-table">';
            h += '<thead><tr><th>المرحلة</th><th>الزمن</th><th>الوصف</th><th>دور المعلم</th><th>دور المتعلم</th></tr></thead>';
            h += '<tbody>';
            plan.lesson_phases.forEach(function (phase) {
                h += '<tr>';
                h += '<td><strong>' + (phase.phase || phase.name || '-') + '</strong></td>';
                h += '<td>' + (phase.duration_minutes || phase.duration || '-') + ' دقيقة</td>';
                h += '<td>';
                if (phase.description) {
                    h += phase.description.replace(/\n/g, '<br>');
                } else if (phase.content_points && Array.isArray(phase.content_points)) {
                    h += '<ul style="padding-right: 15px; margin: 0;">';
                    phase.content_points.forEach(function (p) { h += '<li>' + p + '</li>'; });
                    h += '</ul>';
                } else if (phase.activities && Array.isArray(phase.activities)) {
                    h += '<ul style="padding-right: 15px; margin: 0;">';
                    phase.activities.forEach(function (a) { h += '<li>' + a + '</li>'; });
                    h += '</ul>';
                } else if (phase.key_points && Array.isArray(phase.key_points)) {
                    h += '<ul style="padding-right: 15px; margin: 0;">';
                    phase.key_points.forEach(function (p) { h += '<li>' + p + '</li>'; });
                    h += '</ul>';
                } else if (phase.homework) {
                    h += phase.homework;
                } else {
                    h += '-';
                }
                h += '</td>';
                h += '<td>' + (phase.teacher_role || '-') + '</td>';
                h += '<td>' + (phase.student_role || '-') + '</td>';
                h += '</tr>';
            });
            h += '</tbody></table></div>';
        }
        if (plan.total_duration) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-clock" style="color: #ef4444;"></i> المدة الكلية</div>';
            h += '<div class="plan-item-content"><span style="background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; padding: 8px 15px; border-radius: 10px; font-size: 1rem; font-weight: 600;">' + plan.total_duration + ' دقيقة</span></div></div>';
        }
        if (plan.introduction) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-play-circle" style="color: #10b981;"></i> المقدمة / التهيئة</div>';
            if (plan.introduction.duration) {
                h += '<span style="background: #dbeafe; color: #1e40af; padding: 3px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 600;"><i class="fas fa-clock"></i> ' + plan.introduction.duration + '</span>';
            }
            h += '<div class="plan-item-content">';
            if (typeof plan.introduction === 'object') {
                h += (plan.introduction.content || JSON.stringify(plan.introduction)).replace(/\n/g, '<br>');
            } else {
                h += String(plan.introduction).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        var mainContent = plan.presentation || plan.main_content;
        if (mainContent) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-chalkboard-teacher" style="color: #3b82f6;"></i> العرض / المحتوى الرئيسي</div>';
            h += '<div class="plan-item-content">';
            if (typeof mainContent === 'object') {
                if (mainContent.content) {
                    h += String(mainContent.content).replace(/\n/g, '<br>');
                } else if (mainContent.steps && Array.isArray(mainContent.steps)) {
                    h += '<ol style="padding-right: 20px;">';
                    mainContent.steps.forEach(function (step) {
                        var stepText = typeof step === 'object' ? (step.description || JSON.stringify(step)) : step;
                        h += '<li style="margin-bottom: 8px;">' + stepText + '</li>';
                    });
                    h += '</ol>';
                } else {
                    h += JSON.stringify(mainContent);
                }
            } else {
                h += String(mainContent).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        if (plan.activities && Array.isArray(plan.activities) && plan.activities.length > 0) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-tasks" style="color: #f59e0b;"></i> الأنشطة</div>';
            h += '<div class="plan-item-content"><ul style="padding-right: 20px;">';
            plan.activities.forEach(function (act) {
                var actText = typeof act === 'object' ? (act.description || act.title || JSON.stringify(act)) : act;
                h += '<li style="margin-bottom: 8px;">' + actText + '</li>';
            });
            h += '</ul></div></div>';
        }
        return h;
    }

    function buildAssessmentHtml() {
        var h = '';
        var evalData = plan.evaluation || plan.assessment;
        if (evalData) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-clipboard-check" style="color: #8b5cf6;"></i> التقويم</div>';
            h += '<div class="plan-item-content">';
            if (typeof evalData === 'object') {
                h += (evalData.content || JSON.stringify(evalData)).replace(/\n/g, '<br>');
            } else {
                h += String(evalData).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        if (plan.formative_assessment) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-clipboard-list" style="color: #f97316;"></i> التقويم التكويني (أثناء الدرس)</div>';
            h += '<div class="plan-item-content">';
            if (Array.isArray(plan.formative_assessment)) {
                h += '<ul>';
                plan.formative_assessment.forEach(function (item) {
                    if (typeof item === 'object') {
                        var itemHtml = '<li>';
                        if (item.method || item.type) itemHtml += '<strong>' + (item.method || item.type) + '</strong>';
                        if (item.timing) itemHtml += ' <span style="color: #f97316;">(' + item.timing + ')</span>';
                        if (item.tool) itemHtml += ' - أداة: ' + item.tool;
                        if (item.description || item.content) itemHtml += '<br>' + (item.description || item.content);
                        if (item.success_criteria) itemHtml += '<br><small style="color: #059669;"><i class="fas fa-check-circle"></i> معيار النجاح: ' + item.success_criteria + '</small>';
                        itemHtml += '</li>';
                        h += itemHtml;
                    } else {
                        h += '<li>' + item + '</li>';
                    }
                });
                h += '</ul>';
            } else if (typeof plan.formative_assessment === 'object') {
                h += (plan.formative_assessment.content || JSON.stringify(plan.formative_assessment)).replace(/\n/g, '<br>');
            } else {
                h += String(plan.formative_assessment).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        if (plan.differentiation) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-layer-group" style="color: #14b8a6;"></i> مراعاة الفروق الفردية</div>';
            h += '<div class="plan-item-content">';
            if (typeof plan.differentiation === 'object' && !Array.isArray(plan.differentiation)) {
                var diffIcons = { advanced: 'fa-arrow-up', intermediate: 'fa-arrows-alt-h', beginner: 'fa-arrow-down', struggling: 'fa-hand-holding-heart', gifted: 'fa-gem', strategies: 'fa-cogs' };
                var diffNames = { advanced: 'المتفوقون', intermediate: 'المتوسطون', beginner: 'المبتدئون', struggling: 'ذوو الصعوبات', gifted: 'الموهوبون', strategies: 'الاستراتيجيات' };
                var diffColors = { advanced: '#059669', intermediate: '#2563eb', beginner: '#d97706', struggling: '#dc2626', gifted: '#7c3aed', strategies: '#0891b2' };
                Object.keys(plan.differentiation).forEach(function (level) {
                    h += '<div style="margin-bottom: 10px; padding: 10px; background: #f0fdfa; border-radius: 10px; border-right: 3px solid ' + (diffColors[level] || '#14b8a6') + ';">';
                    h += '<strong><i class="fas ' + (diffIcons[level] || 'fa-user') + '" style="color: ' + (diffColors[level] || '#14b8a6') + '; margin-left: 5px;"></i>' + (diffNames[level] || escapeHtml(level)) + ':</strong> ';
                    if (Array.isArray(plan.differentiation[level])) {
                        h += '<ul style="margin: 5px 0 0 0;">';
                        plan.differentiation[level].forEach(function (item) { h += '<li>' + item + '</li>'; });
                        h += '</ul>';
                    } else if (typeof plan.differentiation[level] === 'string') {
                        h += plan.differentiation[level];
                    } else {
                        h += JSON.stringify(plan.differentiation[level]);
                    }
                    h += '</div>';
                });
            } else if (Array.isArray(plan.differentiation)) {
                h += '<ul>';
                plan.differentiation.forEach(function (d) { h += '<li>' + (typeof d === 'object' ? JSON.stringify(d) : d) + '</li>'; });
                h += '</ul>';
            } else {
                h += String(plan.differentiation).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        if (plan.enrichment) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-puzzle-piece" style="color: #6366f1;"></i> أنشطة إثرائية وعلاجية</div>';
            h += '<div class="plan-item-content">';
            if (typeof plan.enrichment === 'object' && !Array.isArray(plan.enrichment)) {
                if (plan.enrichment.enrichment_activities || plan.enrichment.extension_activities) {
                    var extActs = plan.enrichment.enrichment_activities || plan.enrichment.extension_activities;
                    h += '<div style="margin-bottom: 12px;"><strong style="color: #059669;">🌟 أنشطة إثرائية:</strong>';
                    if (Array.isArray(extActs)) { h += '<ul>'; extActs.forEach(function (a) { h += '<li>' + a + '</li>'; }); h += '</ul>'; }
                    else { h += ' ' + extActs; }
                    h += '</div>';
                }
                if (plan.enrichment.remedial_activities) {
                    h += '<div style="margin-bottom: 12px;"><strong style="color: #dc2626;">🔧 أنشطة علاجية:</strong>';
                    if (Array.isArray(plan.enrichment.remedial_activities)) { h += '<ul>'; plan.enrichment.remedial_activities.forEach(function (a) { h += '<li>' + a + '</li>'; }); h += '</ul>'; }
                    else { h += ' ' + plan.enrichment.remedial_activities; }
                    h += '</div>';
                }
                if (plan.enrichment.additional_resources) {
                    h += '<div style="margin-bottom: 12px;"><strong style="color: #2563eb;">📚 مصادر إضافية:</strong>';
                    if (Array.isArray(plan.enrichment.additional_resources)) { h += '<ul>'; plan.enrichment.additional_resources.forEach(function (a) { h += '<li>' + a + '</li>'; }); h += '</ul>'; }
                    else { h += ' ' + plan.enrichment.additional_resources; }
                    h += '</div>';
                }
                if (plan.enrichment.challenge_questions) {
                    h += '<div><strong style="color: #7c3aed;">🏆 أسئلة تحدي:</strong>';
                    if (Array.isArray(plan.enrichment.challenge_questions)) { h += '<ul>'; plan.enrichment.challenge_questions.forEach(function (a) { h += '<li>' + a + '</li>'; }); h += '</ul>'; }
                    else { h += ' ' + plan.enrichment.challenge_questions; }
                    h += '</div>';
                }
            } else if (Array.isArray(plan.enrichment)) {
                h += '<ul>';
                plan.enrichment.forEach(function (e) { h += '<li>' + (typeof e === 'object' ? JSON.stringify(e) : e) + '</li>'; });
                h += '</ul>';
            } else {
                h += String(plan.enrichment).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        return h;
    }

    function buildResourcesHtml() {
        var h = '';
        if (plan.resources_needed && Array.isArray(plan.resources_needed) && plan.resources_needed.length > 0) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-toolbox" style="color: #06b6d4;"></i> الموارد والوسائل المطلوبة</div>';
            h += '<div class="plan-item-content"><div style="display: flex; flex-wrap: wrap; gap: 10px;">';
            plan.resources_needed.forEach(function (resource) {
                h += '<span style="background: linear-gradient(135deg, #ecfeff, #cffafe); color: #0e7490; padding: 8px 15px; border-radius: 10px; font-size: 0.9rem;"><i class="fas fa-check-circle" style="margin-left: 5px;"></i>' + resource + '</span>';
            });
            h += '</div></div></div>';
        }
        if (plan.new_vocabulary && Array.isArray(plan.new_vocabulary) && plan.new_vocabulary.length > 0) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-spell-check" style="color: #0ea5e9;"></i> المفردات والمصطلحات الجديدة</div>';
            h += '<div class="plan-item-content"><div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px;">';
            plan.new_vocabulary.forEach(function (vocab) {
                if (typeof vocab === 'object') {
                    h += '<div style="background: linear-gradient(135deg, #e0f2fe, #bae6fd); padding: 12px; border-radius: 10px; border-right: 3px solid #0ea5e9;">';
                    h += '<strong style="color: #0c4a6e;">' + (vocab.term || vocab.word || '') + '</strong>';
                    if (vocab.definition || vocab.meaning) h += '<br><span style="color: #075985; font-size: 0.9rem;">' + (vocab.definition || vocab.meaning) + '</span>';
                    if (vocab.example) h += '<br><small style="color: #0369a1;"><i class="fas fa-quote-right"></i> ' + vocab.example + '</small>';
                    h += '</div>';
                } else {
                    h += '<div style="background: linear-gradient(135deg, #e0f2fe, #bae6fd); padding: 10px; border-radius: 10px; text-align: center; color: #0c4a6e; font-weight: 600;">' + vocab + '</div>';
                }
            });
            h += '</div></div></div>';
        }
        if (plan.learning_styles) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-users-cog" style="color: #8b5cf6;"></i> أنماط التعلم</div>';
            h += '<div class="plan-item-content">';
            if (typeof plan.learning_styles === 'object' && !Array.isArray(plan.learning_styles)) {
                var styleIcons = { visual: 'fa-eye', auditory: 'fa-headphones', kinesthetic: 'fa-hands', reading: 'fa-book-reader', reading_writing: 'fa-book-reader' };
                var styleNames = { visual: 'بصري', auditory: 'سمعي', kinesthetic: 'حركي', reading: 'قرائي', reading_writing: 'قرائي/كتابي' };
                Object.keys(plan.learning_styles).forEach(function (style) {
                    h += '<div style="margin-bottom: 12px; padding: 10px; background: #f5f3ff; border-radius: 10px; border-right: 3px solid #8b5cf6;">';
                    h += '<strong><i class="fas ' + (styleIcons[style] || 'fa-star') + '" style="color: #8b5cf6; margin-left: 5px;"></i>' + (styleNames[style] || style) + ':</strong> ';
                    h += typeof plan.learning_styles[style] === 'string' ? plan.learning_styles[style] : JSON.stringify(plan.learning_styles[style]);
                    h += '</div>';
                });
            } else if (Array.isArray(plan.learning_styles)) {
                h += '<ul>';
                plan.learning_styles.forEach(function (s) { h += '<li>' + (typeof s === 'object' ? JSON.stringify(s) : s) + '</li>'; });
                h += '</ul>';
            } else {
                h += String(plan.learning_styles).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        if (plan.real_life_connections && Array.isArray(plan.real_life_connections) && plan.real_life_connections.length > 0) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-globe" style="color: #059669;"></i> الربط بالحياة الواقعية</div>';
            h += '<div class="plan-item-content"><ul>';
            plan.real_life_connections.forEach(function (conn) {
                if (typeof conn === 'object') {
                    h += '<li style="margin-bottom: 10px; padding: 8px; background: #f0fdf4; border-radius: 8px;">';
                    if (conn.context) h += '<strong><i class="fas fa-link" style="color: #059669; margin-left: 5px;"></i>' + conn.context + '</strong><br>';
                    if (conn.application) h += '<span style="color: #065f46;">' + conn.application + '</span><br>';
                    if (conn.example) h += '<small style="color: #047857;"><i class="fas fa-lightbulb"></i> مثال: ' + conn.example + '</small>';
                    h += '</li>';
                } else {
                    h += '<li style="margin-bottom: 8px;"><i class="fas fa-link" style="color: #059669; margin-left: 5px;"></i>' + conn + '</li>';
                }
            });
            h += '</ul></div></div>';
        }
        return h;
    }

    function buildClosureHtml() {
        var h = '';
        if (plan.closure_summary) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-flag-checkered" style="color: #6d28d9;"></i> الغلق والتلخيص</div>';
            h += '<div class="plan-item-content" style="background: linear-gradient(135deg, #f5f3ff, #ede9fe); padding: 15px; border-radius: 12px;">';
            if (typeof plan.closure_summary === 'object') {
                if (plan.closure_summary.closing_activity) h += '<p><strong>🎬 النشاط الختامي:</strong> ' + plan.closure_summary.closing_activity + '</p>';
                if (plan.closure_summary.summary) h += '<p><strong>📝 ملخص:</strong> ' + plan.closure_summary.summary + '</p>';
                if (plan.closure_summary.key_takeaways) {
                    h += '<p><strong>🎯 النقاط الرئيسية:</strong></p><ul>';
                    if (Array.isArray(plan.closure_summary.key_takeaways)) {
                        plan.closure_summary.key_takeaways.forEach(function (t) { h += '<li>' + t + '</li>'; });
                    }
                    h += '</ul>';
                }
                if (plan.closure_summary.exit_ticket) h += '<p><strong>🎫 بطاقة الخروج:</strong> ' + plan.closure_summary.exit_ticket + '</p>';
                if (plan.closure_summary.content) h += plan.closure_summary.content.replace(/\n/g, '<br>');
            } else {
                h += String(plan.closure_summary).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        var hwData = plan.homework || plan.assignment;
        if (hwData) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-home" style="color: #06b6d4;"></i> الواجب المنزلي</div>';
            h += '<div class="plan-item-content">';
            if (typeof hwData === 'object') { h += (hwData.content || JSON.stringify(hwData)).replace(/\n/g, '<br>'); }
            else { h += String(hwData).replace(/\n/g, '<br>'); }
            h += '</div></div>';
        }
        if (plan.self_reflection) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-brain" style="color: #be185d;"></i> التأمل الذاتي للمعلم</div>';
            h += '<div class="plan-item-content" style="background: linear-gradient(135deg, #fdf2f8, #fce7f3); padding: 15px; border-radius: 12px; font-style: italic;">';
            if (Array.isArray(plan.self_reflection)) {
                h += '<ul>';
                plan.self_reflection.forEach(function (q) { h += '<li>' + q + '</li>'; });
                h += '</ul>';
            } else if (typeof plan.self_reflection === 'object') {
                if (plan.self_reflection.questions && Array.isArray(plan.self_reflection.questions)) {
                    h += '<p><strong>❓ أسئلة تأملية:</strong></p><ul>';
                    plan.self_reflection.questions.forEach(function (q) { h += '<li>' + q + '</li>'; });
                    h += '</ul>';
                }
                if (plan.self_reflection.improvement_areas && Array.isArray(plan.self_reflection.improvement_areas)) {
                    h += '<p><strong>📈 مجالات التحسين:</strong></p><ul>';
                    plan.self_reflection.improvement_areas.forEach(function (a) { h += '<li>' + a + '</li>'; });
                    h += '</ul>';
                }
                if (plan.self_reflection.what_worked) h += '<p><strong>✅ ما نجح في الدرس:</strong> ' + plan.self_reflection.what_worked + '</p>';
                if (!plan.self_reflection.questions && !plan.self_reflection.improvement_areas && !plan.self_reflection.what_worked) {
                    h += (plan.self_reflection.content || JSON.stringify(plan.self_reflection)).replace(/\n/g, '<br>');
                }
            } else {
                h += String(plan.self_reflection).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        if (plan.post_notes) {
            h += '<div class="plan-item"><div class="plan-item-title"><i class="fas fa-sticky-note" style="color: #ca8a04;"></i> ملاحظات ما بعد التنفيذ</div>';
            h += '<div class="plan-item-content" style="background: linear-gradient(135deg, #fefce8, #fef9c3); padding: 15px; border-radius: 12px; border: 1px dashed #ca8a04;">';
            if (typeof plan.post_notes === 'object') {
                if (plan.post_notes.template) h += '<p style="color: #854d0e; margin-bottom: 10px;">' + plan.post_notes.template + '</p>';
                if (plan.post_notes.prompts && Array.isArray(plan.post_notes.prompts)) {
                    h += '<ul style="list-style: none; padding: 0;">';
                    plan.post_notes.prompts.forEach(function (p) { h += '<li style="margin-bottom: 8px; padding: 8px 12px; background: rgba(255,255,255,0.6); border-radius: 8px; border-right: 3px solid #ca8a04;">📝 ' + p + '</li>'; });
                    h += '</ul>';
                }
                if (!plan.post_notes.template && !plan.post_notes.prompts) {
                    h += (plan.post_notes.content || JSON.stringify(plan.post_notes)).replace(/\n/g, '<br>');
                }
            } else {
                h += String(plan.post_notes).replace(/\n/g, '<br>');
            }
            h += '</div></div>';
        }
        return h;
    }

    function buildExtraKeysHtml() {
        var h = '';
        var knownKeys = ['lesson_title', 'objectives', 'introduction', 'presentation', 'main_content', 'activities', 'evaluation', 'assessment', 'homework', 'assignment', 'duration', 'time_allocation', 'strategies', 'lesson_phases', 'resources_needed', 'total_duration', 'learning_styles', 'target_competencies', 'motivational_intro', 'differentiation', 'enrichment', 'new_vocabulary', 'formative_assessment', 'closure_summary', 'real_life_connections', 'self_reflection', 'post_notes'];
        Object.keys(plan).forEach(function (key) {
            if (knownKeys.indexOf(key) !== -1 || !plan[key]) return;
            h += '<div class="plan-item"><div class="plan-item-title">' + escapeHtml(key) + '</div>';
            h += '<div class="plan-item-content">';
            if (typeof plan[key] === 'object') { h += JSON.stringify(plan[key], null, 2).replace(/\n/g, '<br>'); }
            else { h += String(plan[key]).replace(/\n/g, '<br>'); }
            h += '</div></div>';
        });
        return h;
    }

    var objectivesContent = buildObjectivesHtml();
    var phasesContent = buildPhasesHtml();
    var assessmentContent = buildAssessmentHtml();
    var resourcesContent = buildResourcesHtml();
    var closureContent = buildClosureHtml();
    var extraContent = buildExtraKeysHtml();

    var tabCount = 0;
    if (objectivesContent) tabCount++;
    if (phasesContent) tabCount++;
    if (assessmentContent) tabCount++;
    if (resourcesContent) tabCount++;
    if (closureContent) tabCount++;

    var html = '<div class="section-header-actions">';
    html += '<h3 style="color: #10b981; margin-bottom: 0;">' + (plan.lesson_title || 'تحضير الدرس') + '</h3>';
    html += _buildSectionActions('lessonPlanContent', 'lesson_plan');
    html += '</div>';

    html += '<div class="sub-tabs-container" id="lpSubTabs">';
    html += '<button class="sub-tab-btn active" data-subtab="lp-all" onclick="switchSubTab(\'lessonPlanContent\', \'lp-all\')"><i class="fas fa-th-list"></i> الكل <span class="sub-tab-badge">' + tabCount + '</span></button>';
    if (objectivesContent) html += '<button class="sub-tab-btn" data-subtab="lp-objectives" onclick="switchSubTab(\'lessonPlanContent\', \'lp-objectives\')"><i class="fas fa-bullseye"></i> الأهداف</button>';
    if (phasesContent) html += '<button class="sub-tab-btn" data-subtab="lp-phases" onclick="switchSubTab(\'lessonPlanContent\', \'lp-phases\')"><i class="fas fa-tasks"></i> سير الدرس</button>';
    if (assessmentContent) html += '<button class="sub-tab-btn" data-subtab="lp-assessment" onclick="switchSubTab(\'lessonPlanContent\', \'lp-assessment\')"><i class="fas fa-clipboard-check"></i> التقويم والتمايز</button>';
    if (resourcesContent) html += '<button class="sub-tab-btn" data-subtab="lp-resources" onclick="switchSubTab(\'lessonPlanContent\', \'lp-resources\')"><i class="fas fa-toolbox"></i> الموارد والمفردات</button>';
    if (closureContent) html += '<button class="sub-tab-btn" data-subtab="lp-closure" onclick="switchSubTab(\'lessonPlanContent\', \'lp-closure\')"><i class="fas fa-flag-checkered"></i> الغلق والتأمل</button>';
    html += '</div>';

    html += '<div class="sub-tab-content active" id="lp-all">' + objectivesContent + phasesContent + assessmentContent + resourcesContent + closureContent + extraContent + '</div>';
    if (objectivesContent) html += '<div class="sub-tab-content" id="lp-objectives">' + objectivesContent + '</div>';
    if (phasesContent) html += '<div class="sub-tab-content" id="lp-phases">' + phasesContent + '</div>';
    if (assessmentContent) html += '<div class="sub-tab-content" id="lp-assessment">' + assessmentContent + '</div>';
    if (resourcesContent) html += '<div class="sub-tab-content" id="lp-resources">' + resourcesContent + '</div>';
    if (closureContent) html += '<div class="sub-tab-content" id="lp-closure">' + closureContent + '</div>';

    container.innerHTML = html;
}

// =============================================
// Display Visual Materials
// =============================================
function displayVisualMaterials() {
    var container = document.getElementById('visualMaterialsContent');
    if (!container) return;
    var visual = escapeTextTree(window.generatedData.visual_materials);

    if (!visual) {
        var errorDetail = '';
        if (window._lastGenerationErrors && window._lastGenerationErrors.length > 0) {
            var errs = window._lastGenerationErrors.filter(function (e) { return e.indexOf('المواد البصرية') !== -1 || e.indexOf('visual') !== -1; });
            if (errs.length > 0) errorDetail = '<br><small style="color:#b45309;">' + errs.map(escapeHtml).join('<br>') + '</small>';
        }
        container.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> لم يتم توليد المواد البصرية' + errorDetail +
            (!window.isArchiveView ? '<br><button class="btn-regenerate-section" onclick="regenerateSection(\'visual_materials\')" style="margin-top:10px;"><i class="fas fa-sync-alt"></i> إعادة توليد المواد البصرية</button>' : '') + '</div>';
        return;
    }

    var flashCards = visual.flash_cards || [];
    var eduImages = visual.educational_images || [];
    var seqImages = visual.sequential_images || [];
    var youtubeVideos = visual.youtube_videos || [];

    function buildFlashCardsHtml(cards) {
        if (!cards || cards.length === 0) return '';
        var h = '<div style="margin-bottom: 30px;">';
        h += '<h4 style="color: #6366f1; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;"><i class="fas fa-clone"></i> البطاقات التعليمية (Flash Cards)</h4>';
        h += '<p style="color: #94a3b8; font-size: 0.85rem; margin-bottom: 18px;"><i class="fas fa-hand-pointer" style="margin-left: 4px;"></i> انقر على البطاقة لقلبها وعرض التعريف</p>';
        h += '<div class="fc-grid">';
        cards.forEach(function (card, idx) {
            var gradients = [
                'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
                'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
                'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
                'linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%)',
                'linear-gradient(135deg, #fccb90 0%, #d57eeb 100%)',
                'linear-gradient(135deg, #e0c3fc 0%, #8ec5fc 100%)'
            ];
            var gradient = gradients[idx % gradients.length];
            var cardNum = idx + 1;

            h += '<div class="fc-card-wrapper" data-vm-type="flash_cards" data-vm-index="' + idx + '" onclick="this.classList.toggle(\'fc-flipped\')">';
            h += '<div class="fc-card-inner">';
            h += '<div class="fc-card-front" style="background: ' + gradient + ';">';
            h += '<div class="fc-card-number">' + cardNum + '</div>';
            h += '<div class="fc-card-icon"><i class="fas fa-lightbulb"></i></div>';
            h += '<div class="fc-card-term">' + (card.term || '') + '</div>';
            h += '<div class="fc-card-hint"><i class="fas fa-sync-alt"></i> انقر للقلب</div>';
            h += '</div>';
            h += '<div class="fc-card-back">';
            h += '<div class="fc-back-header"><span class="fc-back-label"><i class="fas fa-book-open"></i> التعريف</span><span class="fc-back-num"><a href="#" onclick="event.stopPropagation(); downloadFlashCard(this); return false;" title="تحميل البطاقة كصورة" style="color:#64748b;margin-left:6px;font-size:1rem;"><i class="fas fa-download"></i></a> #' + cardNum + '</span></div>';
            // vm-fc-term: المصدر القابل للتعديل للحد — fc-card-term في الوجه الأمامي مرآة عرض فقط.
            // event.stopPropagation على البطاقة يمنع القلب عند الكتابة داخل contenteditable.
            h += '<div class="fc-back-term vm-fc-term" onclick="event.stopPropagation()">' + (card.term || '') + '</div>';
            h += '<div class="fc-back-definition vm-fc-definition" onclick="event.stopPropagation()">' + (card.definition || '') + '</div>';

            if (card.web_image && card.web_image.url) {
                var cImageUrl = safeHttpUrl(card.web_image.url);
                var cLargeUrl = safeHttpUrl(card.web_image.large_url || card.web_image.url);
                var cPageUrl = safeHttpUrl(card.web_image.page_url || '');
                var cSource = card.web_image.source || 'pixabay';
                if (cImageUrl) {
                    h += '<div class="fc-back-image">';
                    h += '<img src="' + escapeHtml(cImageUrl) + '" alt="' + (card.term || '') + '" loading="lazy" onerror="this.parentElement.style.display=\'none\'">';
                h += '<div class="fc-img-overlay">';
                h += '<small><i class="fas fa-camera"></i> ' + (card.web_image.user || _ucfirst(cSource)) + '</small>';
                h += '<div class="fc-img-actions">';
                    if (cPageUrl) h += '<a href="' + escapeHtml(cPageUrl) + '" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()" title="المصدر"><i class="fas fa-external-link-alt"></i></a>';
                    if (cLargeUrl) h += '<a href="' + escapeHtml(cLargeUrl) + '" download target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()" title="تحميل الصورة"><i class="fas fa-image"></i></a>';
                h += '</div></div></div>';
                }
            } else if (card.suggested_image) {
                h += '<div class="fc-suggested-img vm-fc-suggested-image" onclick="event.stopPropagation()"><i class="fas fa-image"></i> ' + card.suggested_image + '</div>';
            }

            h += '</div></div></div>';
        });
        h += '</div></div>';
        return h;
    }

    function buildEduImagesHtml(images) {
        if (!images || images.length === 0) return '';
        var h = '<div style="margin-bottom: 30px;">';
        h += '<h4 style="color: #10b981; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;"><i class="fas fa-image"></i> الصور التعليمية التوضيحية</h4>';
        images.forEach(function (img, i) {
            // data-vm-type + data-vm-index يربطان البطاقة بحقلها في generatedData.visual_materials.
            h += '<div class="visual-item" data-vm-type="educational_images" data-vm-index="' + i + '" style="border-right: 4px solid #10b981; text-align: right;">';
            h += '<h4 class="vm-item-title" style="color: #059669;"><i class="fas fa-image" style="margin-left: 8px;"></i>' + (img.title || '') + '</h4>';
            h += '<p class="vm-item-description" style="margin-bottom: 15px;">' + (img.description || '') + '</p>';
            if (img.web_images && img.web_images.length > 0) {
                h += '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin-bottom: 15px;">';
                img.web_images.forEach(function (wi) {
                    var eImageUrl = safeHttpUrl(wi.url);
                    var eLargeUrl = safeHttpUrl(wi.large_url || wi.url);
                    var ePageUrl = safeHttpUrl(wi.page_url || '');
                    if (!eImageUrl) return;
                    h += '<div style="border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
                    h += '<img src="' + escapeHtml(eImageUrl) + '" alt="' + (img.title || '') + '" style="width: 100%; height: 150px; object-fit: cover;" loading="lazy">';
                    h += '<div style="padding: 6px 8px; font-size: 0.75rem; color: #6b7280; background: #f9fafb; display: flex; justify-content: space-between; align-items: center;">';
                    h += '<span><i class="fas fa-camera" style="margin-left: 4px;"></i> ' + (wi.user || 'Pixabay') + '</span>';
                    h += '<div style="display: flex; gap: 8px;">';
                    if (ePageUrl) h += '<a href="' + escapeHtml(ePageUrl) + '" target="_blank" rel="noopener noreferrer" style="color: #3b82f6; text-decoration: none; display: flex; align-items: center; gap: 3px;" title="المصدر"><i class="fas fa-external-link-alt"></i> المصدر</a>';
                    if (eLargeUrl) h += '<a href="' + escapeHtml(eLargeUrl) + '" download target="_blank" rel="noopener noreferrer" style="color: #10b981; text-decoration: none; display: flex; align-items: center; gap: 3px;" title="تحميل"><i class="fas fa-download"></i> تحميل</a>';
                    h += '</div></div></div>';
                });
                h += '</div>';
            }
            if (img.elements && img.elements.length > 0) {
                h += '<div style="background: #dcfce7; padding: 12px; border-radius: 8px; margin-bottom: 10px;">';
                h += '<strong style="color: #166534;"><i class="fas fa-list"></i> العناصر المطلوبة:</strong>';
                h += '<ul class="vm-item-elements" style="margin: 8px 0 0 0; padding-right: 20px; color: #166534;">';
                img.elements.forEach(function (el) { h += '<li>' + el + '</li>'; });
                h += '</ul></div>';
            }
            if (img.colors_suggested) {
                h += '<small style="color: #6b7280;"><i class="fas fa-palette"></i> الألوان المقترحة: ' + img.colors_suggested + '</small>';
            }
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    function buildSeqImagesHtml(sequences) {
        if (!sequences || sequences.length === 0) return '';
        var h = '<div style="margin-bottom: 30px;">';
        h += '<h4 style="color: #f59e0b; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;"><i class="fas fa-sort-numeric-down"></i> الصور التسلسلية</h4>';
        sequences.forEach(function (seq, i) {
            // data-vm-type + data-vm-index يربطان البطاقة بحقلها في generatedData.visual_materials.
            h += '<div class="visual-item" data-vm-type="sequential_images" data-vm-index="' + i + '" style="border-right: 4px solid #f59e0b; text-align: right;">';
            h += '<h4 class="vm-item-title" style="color: #d97706;"><i class="fas fa-stream" style="margin-left: 8px;"></i>' + (seq.title || '') + '</h4>';
            if (seq.web_images && seq.web_images.length > 0) {
                h += '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin: 10px 0 15px;">';
                seq.web_images.forEach(function (wi) {
                    var sImageUrl = safeHttpUrl(wi.url);
                    var sLargeUrl = safeHttpUrl(wi.large_url || wi.url);
                    var sPageUrl = safeHttpUrl(wi.page_url || '');
                    if (!sImageUrl) return;
                    h += '<div style="border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
                    h += '<img src="' + escapeHtml(sImageUrl) + '" alt="' + (seq.title || '') + '" style="width: 100%; height: 150px; object-fit: cover;" loading="lazy">';
                    h += '<div style="padding: 6px 8px; font-size: 0.75rem; color: #6b7280; background: #f9fafb; display: flex; justify-content: space-between; align-items: center;">';
                    h += '<span><i class="fas fa-camera" style="margin-left: 4px;"></i> ' + (wi.user || 'Pixabay') + '</span>';
                    h += '<div style="display: flex; gap: 8px;">';
                    if (sPageUrl) h += '<a href="' + escapeHtml(sPageUrl) + '" target="_blank" rel="noopener noreferrer" style="color: #d97706; text-decoration: none; display: flex; align-items: center; gap: 3px;" title="المصدر"><i class="fas fa-external-link-alt"></i> المصدر</a>';
                    if (sLargeUrl) h += '<a href="' + escapeHtml(sLargeUrl) + '" download target="_blank" rel="noopener noreferrer" style="color: #059669; text-decoration: none; display: flex; align-items: center; gap: 3px;" title="تحميل"><i class="fas fa-download"></i> تحميل</a>';
                    h += '</div></div></div>';
                });
                h += '</div>';
            }
            if (seq.steps && seq.steps.length > 0) {
                h += '<div class="vm-item-steps" style="margin-top: 15px;">';
                seq.steps.forEach(function (step) {
                    h += '<div style="display: flex; align-items: flex-start; gap: 15px; margin-bottom: 15px; background: #fffbeb; padding: 15px; border-radius: 10px;">';
                    h += '<div style="background: #f59e0b; color: white; width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; flex-shrink: 0;">' + (step.step_number || '') + '</div>';
                    h += '<div style="flex: 1;"><p class="vm-step-description" style="margin: 0 0 5px 0; color: #92400e; font-weight: 600;">' + (step.description || '') + '</p>';
                    if (step.visual_elements) h += '<small style="color: #a16207;"><i class="fas fa-eye"></i> العناصر البصرية: ' + step.visual_elements + '</small>';
                    h += '</div></div>';
                });
                h += '</div>';
            }
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    function buildLessonImagesHtml(images) {
        // تمت إزالة قسم "صور الإنترنت" (lesson_images) بناءً على طلب المستخدم —
        // أداة البحث عن صور من الإنترنت لم تعد متوفرة في تبويب المواد البصرية.
        return '';
    }

    function extractYouTubeVideoId(url) {
        if (!url) return null;
        var match = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/);
        return match ? match[1] : null;
    }

    // ===== معاينة فيديوهات يوتيوب المدمجة (Embedded Preview) =====
    // تشغيل معاينة inline: يستبدل الصورة المصغّرة بمشغّل iframe مدمّج
    // يستخدم سمة data-uniqid فريدة لكل بطاقة لتجنب تعارض الـ IDs المكرّرة بين
    // تبويب "الكل" وتبويب "فيديوهات يوتيوب" (نفس buildYouTubeVideosHtml يُستدعى مرتين).
    window._ytPreviewCache = window._ytPreviewCache || {};

    window.playYouTubePreview = function(uniqId, videoId) {
        if (!videoId) return;
        var container = document.getElementById('vm-yt-preview-' + uniqId);
        if (!container) return;
        // حفظ HTML الأصلي (الصورة المصغّرة) لاستعادته عند الإغلاق
        window._ytPreviewCache[uniqId] = container.innerHTML;
        container.innerHTML =
            '<div style="position: relative; padding-top: 56.25%; aspect-ratio: 16 / 9; background: #000;">' +
            '<iframe style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;" ' +
            'src="https://www.youtube.com/embed/' + videoId + '?autoplay=1&rel=0&modestbranding=1" ' +
            'title="معاينة الفيديو" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>' +
            '<button type="button" onclick="closeYouTubePreview(\'' + uniqId + '\')" ' +
            'style="position: absolute; top: 8px; left: 8px; background: rgba(0,0,0,0.75); color: white; border: none; border-radius: 6px; padding: 6px 12px; cursor: pointer; font-size: 0.8rem; z-index: 2;">' +
            '<i class="fas fa-times"></i> إغلاق المعاينة</button>' +
            '</div>';
    };

    window.closeYouTubePreview = function(uniqId) {
        var container = document.getElementById('vm-yt-preview-' + uniqId);
        if (!container) return;
        if (window._ytPreviewCache[uniqId]) {
            container.innerHTML = window._ytPreviewCache[uniqId];
            delete window._ytPreviewCache[uniqId];
        }
    };

    function buildYouTubeVideosHtml(videos, scopePrefix) {
        // scopePrefix: بادئة فريدة لكل تبويب ('all' أو 'yt') لتفادي تعارض الـ IDs
        // لأن buildYouTubeVideosHtml يُستدعى مرتين (في vm-all و vm-youtube).
        scopePrefix = scopePrefix || 'all';
        if (!videos || videos.length === 0) return '';
        var h = '<div style="margin-bottom: 30px;">';
        h += '<h4 style="color: #ef4444; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;"><i class="fab fa-youtube"></i> فيديوهات يوتيوب مقترحة</h4>';
        h += '<p style="color: #94a3b8; font-size: 0.85rem; margin-bottom: 18px;"><i class="fas fa-info-circle" style="margin-left: 4px;"></i> فيديوهات تعليمية مقترحة من الذكاء الاصطناعي ذات صلة بموضوع الدرس</p>';

        // تنبيه عندما لا توجد روابط فيديو حقيقية (مفتاح YouTube API غير مُكوّن)
        var hasRealVideoLinks = videos.some(function (v) {
            return extractYouTubeVideoId(v.video_url || '') !== null;
        });
        if (!hasRealVideoLinks) {
            h += '<div style="margin-bottom: 16px; padding: 12px 16px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; color: #92400e; font-size: 0.85rem; line-height: 1.6;">';
            h += '<i class="fas fa-info-circle" style="margin-left: 6px; color: #d97706;"></i> ';
            h += '<strong>ملاحظة حول الفيديوهات:</strong> يتم حالياً عرض روابط البحث في يوتيوب. إذا قمت بإضافة مفتاح <code>YOUTUBE_API_KEY</code> حديثاً في ملف <code>.env</code>، يرجى الضغط على زر <strong>"إعادة توليد المواد البصرية"</strong> لتحديث الفيديوهات وجلب الفيديوهات المباشرة. (تأكد أيضاً من تفعيل <em>YouTube Data API v3</em> وصحة المفتاح).';
            h += '</div>';
        }

        h += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; align-items: stretch;">';
        videos.forEach(function (video, vIdx) {
            var uniqId = scopePrefix + '-' + vIdx;
            var searchUrl = 'https://www.youtube.com/results?search_query=' + encodeURIComponent(video.search_query || video.title || '');
            var videoId = extractYouTubeVideoId(video.video_url || '');
            var directUrl = videoId ? 'https://www.youtube.com/watch?v=' + videoId : null;
            var thumbnailUrl = safeHttpUrl(video.thumbnail || (videoId ? 'https://img.youtube.com/vi/' + videoId + '/hqdefault.jpg' : ''));
            var channelTitle = video.channel_title || '';

            h += '<div data-vm-type="youtube_videos" data-vm-index="' + vIdx + '" style="background: white; border-radius: 14px; border: 2px solid #fecaca; overflow: hidden; box-shadow: 0 3px 12px rgba(239,68,68,0.08); transition: all 0.3s ease; display: flex; flex-direction: column;" onmouseover="this.style.borderColor=\'#ef4444\';this.style.boxShadow=\'0 6px 20px rgba(239,68,68,0.15)\'" onmouseout="this.style.borderColor=\'#fecaca\';this.style.boxShadow=\'0 3px 12px rgba(239,68,68,0.08)\'">';

            // حاوية المعاينة: تحوي الصورة المصغّرة وتُستبدل بمشغّل iframe مدمّج عند الضغط على "معاينة"
            h += '<div id="vm-yt-preview-' + uniqId + '" style="position: relative;">';
            if (thumbnailUrl) {
                if (videoId) {
                    h += '<a href="javascript:void(0)" onclick="playYouTubePreview(\'' + uniqId + '\', \'' + videoId + '\')" style="display: block; position: relative; cursor: pointer; text-decoration: none;" title="تشغيل المعاينة المدمجة">';
                } else {
                    h += '<a href="' + searchUrl + '" target="_blank" style="display: block; position: relative; cursor: pointer; text-decoration: none;" title="بحث على يوتيوب">';
                }
                // aspect-ratio: 16/9 يثبّت نسبة الأبعاد لكل البطاقات، وpadding-top يدعم المتصفحات القديمة.
                h += '<div style="position: relative; padding-top: 56.25%; aspect-ratio: 16 / 9; background: #000; overflow: hidden;">';
                h += '<img src="' + escapeHtml(thumbnailUrl) + '" alt="' + (video.title || '') + '" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;" onerror="this.parentElement.innerHTML=\'<div style=\\\'position:absolute;top:0;left:0;width:100%;height:100%;background:linear-gradient(135deg,#1a1a2e,#16213e);display:flex;align-items:center;justify-content:center;flex-direction:column;\\\'><div style=\\\'width:55px;height:55px;background:rgba(255,0,0,0.85);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 15px rgba(255,0,0,0.3);\\\'><i class=\\\'fas fa-play\\\' style=\\\'color:white;font-size:1.2rem;margin-right:-2px;\\\'></i></div><span style=\\\'color:rgba(255,255,255,0.6);font-size:0.8rem;margin-top:10px;\\\'>YouTube</span></div>\'">';
                h += '<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 60px; height: 42px; background: rgba(255,0,0,0.85); border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.3);">';
                h += '<i class="fas fa-play" style="color: white; font-size: 1.1rem; margin-right: -2px;"></i></div></div></a>';
            } else {
                if (videoId) {
                    h += '<a href="javascript:void(0)" onclick="playYouTubePreview(\'' + uniqId + '\', \'' + videoId + '\')" style="display: block; text-decoration: none; cursor: pointer;" title="تشغيل المعاينة المدمجة">';
                } else {
                    h += '<a href="' + searchUrl + '" target="_blank" style="display: block; text-decoration: none; cursor: pointer;" title="بحث على يوتيوب">';
                }
                // نفس نسبة 16:9 المستخدمة في كتلة الصورة المصغّرة — يضمن تساوي ارتفاع كل البطاقات.
                h += '<div style="background: linear-gradient(135deg, #1a1a2e, #16213e); padding: 20px; text-align: center; position: relative; aspect-ratio: 16 / 9; display: flex; align-items: center; justify-content: center; flex-direction: column;">';
                h += '<div style="width: 55px; height: 55px; background: rgba(255,0,0,0.85); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; box-shadow: 0 4px 15px rgba(255,0,0,0.3);">';
                h += '<i class="fas fa-play" style="color: white; font-size: 1.2rem; margin-right: -2px;"></i></div>';
                h += '<span style="color: rgba(255,255,255,0.6); font-size: 0.8rem;"><i class="fab fa-youtube" style="margin-left: 4px;"></i> YouTube</span></div></a>';
            }
            h += '</div>';

            // الجسم: flex-grow يملأ الفراغ المتبقي، فتتساوى أرتفاع البطاقات داخل كل صف
            h += '<div style="padding: 16px; flex: 1; display: flex; flex-direction: column;">';
            h += '<h5 class="vm-yt-title" style="color: #1e293b; font-size: 1rem; font-weight: 700; margin-bottom: 8px; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 3em;">' + (video.youtube_title || video.title || '') + '</h5>';
            if (channelTitle) {
                h += '<div style="display: flex; align-items: center; gap: 6px; margin-bottom: 8px;"><i class="fas fa-user-circle" style="color: #ef4444; font-size: 0.9rem;"></i><span style="color: #64748b; font-size: 0.85rem; font-weight: 600;">' + channelTitle + '</span></div>';
            }
            if (video.description) h += '<p class="vm-yt-description" style="color: #64748b; font-size: 0.88rem; line-height: 1.6; margin-bottom: 10px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">' + video.description + '</p>';
            if (video.why_relevant) {
                h += '<div style="background: #fef2f2; padding: 8px 12px; border-radius: 8px; margin-bottom: 12px;">';
                h += '<small class="vm-yt-why-relevant" style="color: #991b1b; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;"><i class="fas fa-check-circle" style="margin-left: 4px;"></i> <strong>لماذا هذا الفيديو:</strong> ' + video.why_relevant + '</small></div>';
            }

            // شريط الأزرار يلتصق بقاع البطاقة عبر margin-top:auto
            h += '<div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: auto;">';
            if (directUrl) {
                h += '<button type="button" onclick="playYouTubePreview(\'' + uniqId + '\', \'' + videoId + '\')" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; background: white; color: #dc2626; padding: 10px 16px; border-radius: 10px; border: 2px solid #fecaca; cursor: pointer; font-weight: 600; font-size: 0.85rem; transition: all 0.3s ease;" onmouseover="this.style.borderColor=\'#ef4444\';this.style.color=\'#ef4444\'" onmouseout="this.style.borderColor=\'#fecaca\';this.style.color=\'#dc2626\'" title="معاينة الفيديو داخل الصفحة"><i class="fas fa-play-circle"></i> معاينة</button>';
                h += '<a href="' + directUrl + '" target="_blank" style="flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: all 0.3s ease; min-width: 140px;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 4px 15px rgba(239,68,68,0.4)\'" onmouseout="this.style.transform=\'none\';this.style.boxShadow=\'none\'">';
                h += '<i class="fab fa-youtube"></i> مشاهدة الفيديو</a>';
                h += '<a href="' + searchUrl + '" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; background: #f1f5f9; color: #475569; padding: 10px 14px; border-radius: 10px; text-decoration: none; border: 1px solid #e2e8f0; font-weight: 500; font-size: 0.85rem;" title="بحث عن فيديوهات مشابهة"><i class="fas fa-search"></i> فيديوهات مشابهة</a>';
            } else {
                h += '<a href="' + searchUrl + '" target="_blank" style="flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 0.9rem; min-width: 140px;"><i class="fab fa-youtube"></i> شاهد على يوتيوب</a>';
            }
            h += '<button data-copy-text="' + (video.search_query || '') + '" onclick="copyVisualSearchQuery(this)" style="display: inline-flex; align-items: center; gap: 6px; background: #f1f5f9; color: #475569; padding: 10px 14px; border-radius: 10px; border: 1px solid #e2e8f0; cursor: pointer; font-weight: 500; font-size: 0.85rem;">';
            h += '<i class="fas fa-copy"></i> نسخ البحث</button></div>';

            if (video.search_query) {
                h += '<div style="margin-top: 8px; padding: 6px 10px; background: #f8fafc; border-radius: 6px; font-size: 0.78rem; color: #94a3b8; direction: ltr; text-align: left;">';
                h += '<i class="fas fa-search" style="margin-right: 4px;"></i> ' + video.search_query + '</div>';
            }

            h += '</div></div>';
        });
        h += '</div></div>';
        return h;
    }

    var totalCount = flashCards.length + eduImages.length + seqImages.length;
    var totalWithYoutube = totalCount + youtubeVideos.length;

    var html = '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-images"></i> المواد البصرية</h3>';
    html += _buildSectionActions('visualMaterialsContent', 'visual_materials');
    html += '</div>';

    if (totalWithYoutube === 0) {
        container.innerHTML = html + '<div class="alert alert-warning"><i class="fas fa-info-circle"></i> لا توجد مواد بصرية</div>';
        return;
    }

    html += '<div class="sub-tabs-container" id="vmSubTabs">';
    html += '<button class="sub-tab-btn active" data-subtab="vm-all" onclick="switchSubTab(\'visualMaterialsContent\', \'vm-all\')"><i class="fas fa-list"></i> الكل <span class="sub-tab-badge">' + totalWithYoutube + '</span></button>';
    if (flashCards.length > 0) html += '<button class="sub-tab-btn" data-subtab="vm-flash" onclick="switchSubTab(\'visualMaterialsContent\', \'vm-flash\')"><i class="fas fa-clone"></i> بطاقات تعليمية <span class="sub-tab-badge">' + flashCards.length + '</span></button>';
    if (eduImages.length > 0) html += '<button class="sub-tab-btn" data-subtab="vm-edu" onclick="switchSubTab(\'visualMaterialsContent\', \'vm-edu\')"><i class="fas fa-image"></i> صور تعليمية <span class="sub-tab-badge">' + eduImages.length + '</span></button>';
    if (seqImages.length > 0) html += '<button class="sub-tab-btn" data-subtab="vm-seq" onclick="switchSubTab(\'visualMaterialsContent\', \'vm-seq\')"><i class="fas fa-sort-numeric-down"></i> صور تسلسلية <span class="sub-tab-badge">' + seqImages.length + '</span></button>';
    if (youtubeVideos.length > 0) html += '<button class="sub-tab-btn" data-subtab="vm-youtube" onclick="switchSubTab(\'visualMaterialsContent\', \'vm-youtube\')"><i class="fab fa-youtube" style="color:#ef4444"></i> فيديوهات يوتيوب <span class="sub-tab-badge" style="background:#ef4444">' + youtubeVideos.length + '</span></button>';
    html += '</div>';

    html += '<div class="sub-tab-content active" id="vm-all">' + buildFlashCardsHtml(flashCards) + buildEduImagesHtml(eduImages) + buildSeqImagesHtml(seqImages) + buildYouTubeVideosHtml(youtubeVideos, 'all') + '</div>';
    if (flashCards.length > 0) html += '<div class="sub-tab-content" id="vm-flash">' + buildFlashCardsHtml(flashCards) + '</div>';
    if (eduImages.length > 0) html += '<div class="sub-tab-content" id="vm-edu">' + buildEduImagesHtml(eduImages) + '</div>';
    if (seqImages.length > 0) html += '<div class="sub-tab-content" id="vm-seq">' + buildSeqImagesHtml(seqImages) + '</div>';
    if (youtubeVideos.length > 0) html += '<div class="sub-tab-content" id="vm-youtube">' + buildYouTubeVideosHtml(youtubeVideos, 'yt') + '</div>';

    container.innerHTML = html;
}

function _ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

// =============================================
// Display Question Bank
// =============================================
function displayQuestionBank() {
    var container = document.getElementById('questionBankContent');
    if (!container) return;
    var questions = escapeTextTree(window.generatedData.question_bank);

    if (!questions) {
        var emptyHtml = '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-question-circle"></i> بنك الأسئلة</h3>';
        emptyHtml += _buildSectionActions('questionBankContent', 'question_bank');
        emptyHtml += '</div>';
        emptyHtml += '<div class="alert alert-error" style="margin-top: 15px;"><i class="fas fa-exclamation-circle"></i> لم يتم توليد بنك الأسئلة. يمكنك إعادة التوليد بالضغط على الزر أعلاه.</div>';
        container.innerHTML = emptyHtml;
        return;
    }

    var headerHtml = '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-question-circle"></i> بنك الأسئلة</h3>';
    headerHtml += _buildSectionActions('questionBankContent', 'question_bank');
    headerHtml += '</div>';

    var mcQuestions = questions.multiple_choice || [];
    var tfQuestions = questions.true_false || [];
    var essayQuestions = questions.graduated || [];
    var shortAnswerQuestions = questions.short_answer || [];
    var fillBlankQuestions = questions.fill_blank || [];
    var orderingQuestions = questions.ordering || [];
    var matchingQuestions = questions.matching || [];

    var totalCount = mcQuestions.length + tfQuestions.length + essayQuestions.length + shortAnswerQuestions.length + fillBlankQuestions.length + orderingQuestions.length + matchingQuestions.length;

    function buildQuestionsHtml(qList, typeLabel, typeClass, startNum, typeKey) {
        var html = '';
        var qNum = startNum;
        // typeKey يربط كل سؤال بمفتاحه في generatedData.question_bank[typeKey][index].
        // يُمرَّر فقط لتبويب #qb-all لتفادي التجميع المكرر من التبويبات الفرعية.
        qList.forEach(function (q, qIdx) {
            var dataAttrs = (typeKey != null)
                ? ' data-qb-type="' + typeKey + '" data-qb-index="' + qIdx + '"'
                : '';
            html += '<div class="question-item"' + dataAttrs + '>';
            html += '<span class="question-number">' + qNum++ + '</span>';
            html += '<span class="question-type-badge ' + typeClass + '">' + typeLabel + '</span>';
            if (q.difficulty) {
                var diffLabel = { easy: 'سهل', medium: 'متوسط', hard: 'صعب' }[q.difficulty] || q.difficulty;
                html += '<span class="question-type-badge" style="background: #f3e8ff; color: #7c3aed; margin-right: 5px;">' + diffLabel + '</span>';
            }
            // qb-question-text: كلاس دلالي للجامع (يمايزه عن .question-type-badge).
            html += '<div class="question-text qb-question-text">' + (q.question || q.statement || '') + '</div>';

            // اختيار من متعدد
            if (typeClass === 'type-mcq' && q.options && q.options.length > 0) {
                q.options.forEach(function (opt, idx) {
                    var isCorrect = idx === q.correct_answer;
                    // data-qb-option-index يربط الخيار بفهرسه في q.options للجامع.
                    html += '<div class="option-item ' + (isCorrect ? 'option-correct' : '') + '" data-qb-option-index="' + idx + '">';
                    if (isCorrect) html += '<i class="fas fa-check-circle" style="color: #22c55e;"></i> ';
                    html += opt + '</div>';
                });
            }

            // صح وخطأ
            if (typeClass === 'type-tf' && q.correct_answer !== undefined) {
                html += '<div class="option-item option-correct qb-tf-answer"><i class="fas fa-check-circle" style="color: #22c55e;"></i> الإجابة: ' + (q.correct_answer ? 'صح' : 'خطأ') + '</div>';
                if (q.explanation) {
                    html += '<div class="option-item qb-explanation" style="background: #fef3c7; color: #92400e;"><i class="fas fa-lightbulb"></i> ' + q.explanation + '</div>';
                }
            }

            // مقالي / متدرج
            if (typeClass === 'type-graduated') {
                if (q.model_answer) {
                    html += '<div class="option-item option-correct qb-model-answer"><i class="fas fa-check-circle" style="color: #22c55e;"></i> الإجابة النموذجية: ' + q.model_answer + '</div>';
                }
                if (q.cognitive_level) {
                    html += '<div class="option-item" style="background: #e0f2fe; color: #0369a1;"><i class="fas fa-brain"></i> المستوى المعرفي: ' + q.cognitive_level + '</div>';
                }
            }

            // إجابة قصيرة
            if (typeClass === 'type-short-answer') {
                if (q.model_answer) {
                    html += '<div class="option-item option-correct"><i class="fas fa-check-circle" style="color: #22c55e;"></i> الإجابة النموذجية: ' + q.model_answer + '</div>';
                }
            }

            // ملء الفراغ
            if (typeClass === 'type-fill-blank') {
                if (q.answer) {
                    html += '<div class="option-item option-correct"><i class="fas fa-check-circle" style="color: #22c55e;"></i> الإجابة: ' + q.answer + '</div>';
                }
            }

            // ترتيب
            if (typeClass === 'type-ordering' && q.items && q.items.length > 0) {
                html += '<div style="margin-top: 8px;">';
                html += '<div style="background: #fef3c7; padding: 10px; border-radius: 8px; color: #92400e; margin-bottom: 8px;"><i class="fas fa-random"></i> <strong>العناصر (مخلوطة):</strong></div>';
                q.items.forEach(function (item, idx) {
                    html += '<div class="option-item" style="background: #f8fafc;"><span style="background: #e2e8f0; padding: 2px 8px; border-radius: 50%; margin-left: 8px; font-weight: 700;">' + (idx + 1) + '</span> ' + item + '</div>';
                });
                if (q.correct_order && q.correct_order.length > 0) {
                    html += '<div class="option-item option-correct"><i class="fas fa-check-circle" style="color: #22c55e;"></i> الترتيب الصحيح: ' + q.correct_order.join(' → ') + '</div>';
                }
                html += '</div>';
            }

            // توصيل / مطابقة
            if (typeClass === 'type-matching' && q.pairs && q.pairs.length > 0) {
                html += '<div style="margin-top: 8px;">';
                html += '<table style="width: 100%; border-collapse: collapse; border-radius: 8px; overflow: hidden;">';
                html += '<thead><tr><th style="background: #dbeafe; color: #1e40af; padding: 8px 12px; text-align: right; border: 1px solid #93c5fd;">المصطلح</th><th style="background: #dcfce7; color: #166534; padding: 8px 12px; text-align: right; border: 1px solid #86efac;">التعريف / المقابل</th></tr></thead>';
                html += '<tbody>';
                q.pairs.forEach(function (pair) {
                    html += '<tr><td style="padding: 8px 12px; border: 1px solid #e2e8f0; font-weight: 600;">' + (pair.term || '') + '</td>';
                    html += '<td style="padding: 8px 12px; border: 1px solid #e2e8f0;">' + (pair.definition || '') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }

            html += '</div>';
        });
        return html;
    }

    var html = headerHtml;

    // بناء التبويبات الفرعية
    html += '<div class="sub-tabs-container" id="qbSubTabs">';
    html += '<button class="sub-tab-btn active" data-subtab="qb-all" onclick="switchSubTab(\'questionBankContent\', \'qb-all\')"><i class="fas fa-list"></i> الكل <span class="sub-tab-badge">' + totalCount + '</span></button>';
    if (mcQuestions.length > 0) html += '<button class="sub-tab-btn" data-subtab="qb-mc" onclick="switchSubTab(\'questionBankContent\', \'qb-mc\')"><i class="fas fa-check-double"></i> اختيار من متعدد <span class="sub-tab-badge">' + mcQuestions.length + '</span></button>';
    if (tfQuestions.length > 0) html += '<button class="sub-tab-btn" data-subtab="qb-tf" onclick="switchSubTab(\'questionBankContent\', \'qb-tf\')"><i class="fas fa-check"></i> صح وخطأ <span class="sub-tab-badge">' + tfQuestions.length + '</span></button>';
    if (essayQuestions.length > 0) html += '<button class="sub-tab-btn" data-subtab="qb-essay" onclick="switchSubTab(\'questionBankContent\', \'qb-essay\')"><i class="fas fa-pen-fancy"></i> مقالي <span class="sub-tab-badge">' + essayQuestions.length + '</span></button>';
    if (shortAnswerQuestions.length > 0) html += '<button class="sub-tab-btn" data-subtab="qb-sa" onclick="switchSubTab(\'questionBankContent\', \'qb-sa\')"><i class="fas fa-comment-dots"></i> إجابة قصيرة <span class="sub-tab-badge">' + shortAnswerQuestions.length + '</span></button>';
    if (fillBlankQuestions.length > 0) html += '<button class="sub-tab-btn" data-subtab="qb-fb" onclick="switchSubTab(\'questionBankContent\', \'qb-fb\')"><i class="fas fa-text-width"></i> ملء فراغ <span class="sub-tab-badge">' + fillBlankQuestions.length + '</span></button>';
    if (orderingQuestions.length > 0) html += '<button class="sub-tab-btn" data-subtab="qb-ord" onclick="switchSubTab(\'questionBankContent\', \'qb-ord\')"><i class="fas fa-sort-numeric-down"></i> ترتيب <span class="sub-tab-badge">' + orderingQuestions.length + '</span></button>';
    if (matchingQuestions.length > 0) html += '<button class="sub-tab-btn" data-subtab="qb-match" onclick="switchSubTab(\'questionBankContent\', \'qb-match\')"><i class="fas fa-arrows-alt-h"></i> توصيل <span class="sub-tab-badge">' + matchingQuestions.length + '</span></button>';
    html += '</div>';

    // بناء محتوى كل تبويب
    var runNum = 1;
    var allHtml = '';
    // typeKey يُمرَّر فقط لبناء #qb-all لتفادي التجميع المكرر من التبويبات الفرعية.
    allHtml += buildQuestionsHtml(mcQuestions, 'اختيار من متعدد', 'type-mcq', runNum, 'multiple_choice'); runNum += mcQuestions.length;
    allHtml += buildQuestionsHtml(tfQuestions, 'صح وخطأ', 'type-tf', runNum, 'true_false'); runNum += tfQuestions.length;
    allHtml += buildQuestionsHtml(essayQuestions, 'مقالي / متدرج', 'type-graduated', runNum, 'graduated'); runNum += essayQuestions.length;
    allHtml += buildQuestionsHtml(shortAnswerQuestions, 'إجابة قصيرة', 'type-short-answer', runNum, 'short_answer'); runNum += shortAnswerQuestions.length;
    allHtml += buildQuestionsHtml(fillBlankQuestions, 'ملء فراغ', 'type-fill-blank', runNum, 'fill_blank'); runNum += fillBlankQuestions.length;
    allHtml += buildQuestionsHtml(orderingQuestions, 'ترتيب', 'type-ordering', runNum, 'ordering'); runNum += orderingQuestions.length;
    allHtml += buildQuestionsHtml(matchingQuestions, 'توصيل', 'type-matching', runNum, 'matching');

    html += '<div class="sub-tab-content active" id="qb-all">' + (allHtml || '<div class="alert alert-warning"><i class="fas fa-info-circle"></i> لا توجد أسئلة</div>') + '</div>';
    if (mcQuestions.length > 0) html += '<div class="sub-tab-content" id="qb-mc">' + buildQuestionsHtml(mcQuestions, 'اختيار من متعدد', 'type-mcq', 1) + '</div>';
    if (tfQuestions.length > 0) html += '<div class="sub-tab-content" id="qb-tf">' + buildQuestionsHtml(tfQuestions, 'صح وخطأ', 'type-tf', 1) + '</div>';
    if (essayQuestions.length > 0) html += '<div class="sub-tab-content" id="qb-essay">' + buildQuestionsHtml(essayQuestions, 'مقالي / متدرج', 'type-graduated', 1) + '</div>';
    if (shortAnswerQuestions.length > 0) html += '<div class="sub-tab-content" id="qb-sa">' + buildQuestionsHtml(shortAnswerQuestions, 'إجابة قصيرة', 'type-short-answer', 1) + '</div>';
    if (fillBlankQuestions.length > 0) html += '<div class="sub-tab-content" id="qb-fb">' + buildQuestionsHtml(fillBlankQuestions, 'ملء فراغ', 'type-fill-blank', 1) + '</div>';
    if (orderingQuestions.length > 0) html += '<div class="sub-tab-content" id="qb-ord">' + buildQuestionsHtml(orderingQuestions, 'ترتيب', 'type-ordering', 1) + '</div>';
    if (matchingQuestions.length > 0) html += '<div class="sub-tab-content" id="qb-match">' + buildQuestionsHtml(matchingQuestions, 'توصيل', 'type-matching', 1) + '</div>';

    var diffHtml = analyzeDifficulty();
    if (diffHtml) {
        html += '<div class="plan-item" style="margin-top: 25px;">';
        html += '<div class="plan-item-title"><i class="fas fa-chart-bar" style="color: #f59e0b;"></i> تحليل مستوى الصعوبة</div>';
        html += '<div class="plan-item-content">' + diffHtml + '</div></div>';
    }

    container.innerHTML = html;
}

// =============================================
// Display Class Activities
// =============================================
function displayClassActivities() {
    var container = document.getElementById('classActivitiesContent');
    if (!container) return;
    var activities = escapeTextTree(window.generatedData.class_activities);

    if (!activities) {
        var errorDetail = '';
        if (window._lastGenerationErrors && window._lastGenerationErrors.length > 0) {
            var errs = window._lastGenerationErrors.filter(function (e) { return e.indexOf('الأنشطة الصفية') !== -1 || e.indexOf('class_activities') !== -1; });
            if (errs.length > 0) errorDetail = '<br><small style="color:#b45309;">' + errs.map(escapeHtml).join('<br>') + '</small>';
        }
        container.innerHTML = '<div class="alert alert-warning"><i class="fas fa-info-circle"></i> لم يتم توليد الأنشطة الصفية' + errorDetail +
            (!window.isArchiveView ? '<br><button class="btn-regenerate-section" onclick="regenerateSection(\'class_activities\')" style="margin-top:10px;"><i class="fas fa-sync-alt"></i> إعادة توليد الأنشطة الصفية</button>' : '') + '</div>';
        return;
    }

    var digital = activities.digital_activities || [];
    var collaborative = activities.collaborative_activities || [];
    var creative = activities.creative_activities || [];
    var quick = activities.quick_activities || [];
    var assessment = activities.assessment_activities || [];

    function buildActivityHtml(activity, borderColor, titleColor, bgColor, typeKey, index) {
        // data-ca-type + data-ca-index يربطان البطاقة بنوعها وفهرسها في generatedData.class_activities
        // ليتمكن _collectEditedData من جمع التعديلات. typeKey اختياري: التبويبات الفرعية
        // المنفصلة (ca-digital ...) لا تمرّره فلا تُجمَع (التجميع يقتصر على #ca-all لتفادي التكرار).
        var dataAttrs = (typeKey != null && index != null)
            ? ' data-ca-type="' + typeKey + '" data-ca-index="' + index + '"'
            : '';
        var h = '<div class="visual-item"' + dataAttrs + ' style="border-right: 4px solid ' + borderColor + '; margin-bottom: 15px;">';
        h += '<div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">';
        h += '<h4 class="ca-activity-title" style="color: ' + titleColor + '; margin: 0;">' + (activity.title || '') + '</h4>';
        if (activity.duration_minutes) {
            h += '<span style="background: ' + bgColor + '; color: ' + titleColor + '; padding: 4px 12px; border-radius: 15px; font-size: 0.85rem;"><i class="fas fa-clock"></i> ' + activity.duration_minutes + ' دقيقة</span>';
        } else if (activity.duration) {
            h += '<span style="background: ' + bgColor + '; color: ' + titleColor + '; padding: 4px 12px; border-radius: 15px; font-size: 0.85rem;"><i class="fas fa-clock"></i> ' + activity.duration + '</span>';
        }
        h += '</div>';
        if (activity.type) h += '<span style="background: ' + borderColor + '; color: white; padding: 3px 10px; border-radius: 10px; font-size: 0.8rem; margin-bottom: 10px; display: inline-block;">' + activity.type + '</span>';
        if (activity.group_size) h += '<span style="background: ' + bgColor + '; color: ' + titleColor + '; padding: 4px 12px; border-radius: 15px; font-size: 0.85rem; margin-bottom: 10px; display: inline-block;"><i class="fas fa-users"></i> ' + activity.group_size + '</span>';
        h += '<p class="ca-activity-description" style="margin: 10px 0;">' + (activity.description || '') + '</p>';
        if (activity.tool || activity.digital_tool_suggestion) h += '<div style="background: #f0fdf4; padding: 10px; border-radius: 8px; color: #166534;"><i class="fas fa-tools"></i> <strong>الأداة المقترحة:</strong> ' + (activity.tool || activity.digital_tool_suggestion) + '</div>';
        var steps = activity.steps || activity.instructions;
        if (steps && steps.length > 0) {
            h += '<div style="background: #eff6ff; padding: 12px; border-radius: 8px; margin: 10px 0;"><strong style="color: #1e40af;"><i class="fas fa-list-ol"></i> خطوات التنفيذ:</strong>';
            h += '<ol class="ca-activity-steps" style="margin: 8px 0 0 0; padding-right: 25px; color: #1e40af;">';
            steps.forEach(function (s) { h += '<li style="margin-bottom: 5px;">' + s + '</li>'; });
            h += '</ol></div>';
        }
        if (activity.materials && activity.materials.length > 0) h += '<div style="background: #f0fdf4; padding: 10px; border-radius: 8px; color: #166534; margin-top: 10px;"><i class="fas fa-box"></i> <strong>المواد:</strong> ' + activity.materials.join('، ') + '</div>';
        if (activity.props_needed && activity.props_needed.length > 0) h += '<div style="background: #f3e8ff; padding: 10px; border-radius: 8px; color: #7c3aed; margin: 10px 0;"><i class="fas fa-box"></i> <strong>الأدوات المطلوبة:</strong> ' + activity.props_needed.join(', ') + '</div>';
        if (activity.learning_outcome) h += '<div style="margin-top: 10px; color: #6b7280; font-size: 0.9rem;"><i class="fas fa-graduation-cap"></i> <strong>المخرج التعليمي:</strong> ' + activity.learning_outcome + '</div>';
        if (activity.differentiation) h += '<div style="background: #fef3c7; padding: 10px; border-radius: 8px; color: #92400e; margin-top: 10px;"><i class="fas fa-layer-group"></i> <strong>التمايز:</strong> ' + activity.differentiation + '</div>';
        if (activity.rubric) h += '<div style="background: #fee2e2; padding: 10px; border-radius: 8px; color: #991b1b;"><i class="fas fa-ruler"></i> <strong>معايير التقييم:</strong> ' + activity.rubric + '</div>';
        if (activity.when_to_use) h += '<span style="background: #f59e0b; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; margin-top: 8px; display: inline-block;">' + activity.when_to_use + '</span>';
        if (activity.learning_styles_addressed && activity.learning_styles_addressed.length > 0) h += '<div style="margin-top: 10px; color: #6b7280; font-size: 0.9rem;"><i class="fas fa-brain"></i> <strong>أنماط التعلم:</strong> ' + activity.learning_styles_addressed.join(', ') + '</div>';
        if (activity.skills) h += '<div style="margin-top: 10px; color: #6b7280; font-size: 0.9rem;"><i class="fas fa-star"></i> <strong>المهارات:</strong> ' + activity.skills + '</div>';
        if (activity.output) h += '<div style="margin-top: 10px; padding: 8px 12px; background: rgba(139,92,246,0.1); border-radius: 6px; font-size: 13px;"><i class="fas fa-gift"></i> <strong>المخرج:</strong> ' + activity.output + '</div>';
        if (activity.purpose) h += '<div style="margin-top: 10px; padding: 8px 12px; background: rgba(239,68,68,0.1); border-radius: 6px; font-size: 13px;"><i class="fas fa-bullseye"></i> <strong>الهدف:</strong> ' + activity.purpose + '</div>';
        h += '</div>';
        return h;
    }

    function buildTypeSection(list, icon, title, borderColor, titleColor, bgColor, typeKey) {
        if (!list || list.length === 0) return '';
        var h = '<div style="margin-bottom: 30px;">';
        h += '<h4 style="color: ' + titleColor + '; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;"><i class="fas fa-' + icon + '"></i> ' + title + '</h4>';
        // typeKey يُمرَّر فقط لتبويب #ca-all لربط كل بطاقة بنوعها+فهرسها داخل generatedData.
        // التبويبات الفرعية المنفصلة (ca-digital...) لا تُمرّر typeKey فلا تُجمَع (تفادي تكرار).
        list.forEach(function (a, i) { h += buildActivityHtml(a, borderColor, titleColor, bgColor, typeKey, typeKey != null ? i : null); });
        h += '</div>';
        return h;
    }

    var totalCount = digital.length + collaborative.length + creative.length + quick.length + assessment.length;

    var html = '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-puzzle-piece"></i> أفكار الأنشطة الصفية</h3>';
    html += _buildSectionActions('classActivitiesContent', 'class_activities');
    html += '</div>';
    html += '<p style="color: #64748b; margin-bottom: 20px; font-size: 0.95rem;"><i class="fas fa-info-circle"></i> أنشطة تفاعلية مستوحاة من أدوات مثل Padlet, Kahoot, Mentimeter لجعل الحصة أكثر تفاعلاً</p>';

    if (totalCount === 0) {
        container.innerHTML = html + '<div class="alert alert-warning"><i class="fas fa-info-circle"></i> لا توجد أنشطة صفية</div>';
        return;
    }

    html += '<div class="sub-tabs-container" id="caSubTabs">';
    html += '<button class="sub-tab-btn active" data-subtab="ca-all" onclick="switchSubTab(\'classActivitiesContent\', \'ca-all\')"><i class="fas fa-list"></i> الكل <span class="sub-tab-badge">' + totalCount + '</span></button>';
    if (digital.length > 0) html += '<button class="sub-tab-btn" data-subtab="ca-digital" onclick="switchSubTab(\'classActivitiesContent\', \'ca-digital\')"><i class="fas fa-laptop"></i> رقمية <span class="sub-tab-badge">' + digital.length + '</span></button>';
    if (collaborative.length > 0) html += '<button class="sub-tab-btn" data-subtab="ca-collab" onclick="switchSubTab(\'classActivitiesContent\', \'ca-collab\')"><i class="fas fa-users"></i> تعاونية <span class="sub-tab-badge">' + collaborative.length + '</span></button>';
    if (creative.length > 0) html += '<button class="sub-tab-btn" data-subtab="ca-creative" onclick="switchSubTab(\'classActivitiesContent\', \'ca-creative\')"><i class="fas fa-lightbulb"></i> إبداعية <span class="sub-tab-badge">' + creative.length + '</span></button>';
    if (quick.length > 0) html += '<button class="sub-tab-btn" data-subtab="ca-quick" onclick="switchSubTab(\'classActivitiesContent\', \'ca-quick\')"><i class="fas fa-bolt"></i> سريعة <span class="sub-tab-badge">' + quick.length + '</span></button>';
    if (assessment.length > 0) html += '<button class="sub-tab-btn" data-subtab="ca-assess" onclick="switchSubTab(\'classActivitiesContent\', \'ca-assess\')"><i class="fas fa-clipboard-check"></i> تقييم <span class="sub-tab-badge">' + assessment.length + '</span></button>';
    html += '</div>';

    var allHtml = buildTypeSection(digital, 'laptop', 'أنشطة رقمية تفاعلية', '#3b82f6', '#1e40af', '#dbeafe', 'digital_activities');
    allHtml += buildTypeSection(collaborative, 'users', 'أنشطة تعاونية جماعية', '#10b981', '#059669', '#dcfce7', 'collaborative_activities');
    allHtml += buildTypeSection(creative, 'lightbulb', 'أنشطة إبداعية وحركية', '#8b5cf6', '#7c3aed', '#f3e8ff', 'creative_activities');
    allHtml += buildTypeSection(quick, 'bolt', 'أنشطة سريعة', '#f59e0b', '#d97706', '#fef3c7', 'quick_activities');
    allHtml += buildTypeSection(assessment, 'clipboard-check', 'أنشطة التقييم', '#ef4444', '#dc2626', '#fee2e2', 'assessment_activities');
    html += '<div class="sub-tab-content active" id="ca-all">' + allHtml + '</div>';

    if (digital.length > 0) html += '<div class="sub-tab-content" id="ca-digital">' + buildTypeSection(digital, 'laptop', 'أنشطة رقمية تفاعلية', '#3b82f6', '#1e40af', '#dbeafe') + '</div>';
    if (collaborative.length > 0) html += '<div class="sub-tab-content" id="ca-collab">' + buildTypeSection(collaborative, 'users', 'أنشطة تعاونية جماعية', '#10b981', '#059669', '#dcfce7') + '</div>';
    if (creative.length > 0) html += '<div class="sub-tab-content" id="ca-creative">' + buildTypeSection(creative, 'lightbulb', 'أنشطة إبداعية وحركية', '#8b5cf6', '#7c3aed', '#f3e8ff') + '</div>';
    if (quick.length > 0) html += '<div class="sub-tab-content" id="ca-quick">' + buildTypeSection(quick, 'bolt', 'أنشطة سريعة', '#f59e0b', '#d97706', '#fef3c7') + '</div>';
    if (assessment.length > 0) html += '<div class="sub-tab-content" id="ca-assess">' + buildTypeSection(assessment, 'clipboard-check', 'أنشطة التقييم', '#ef4444', '#dc2626', '#fee2e2') + '</div>';

    container.innerHTML = html;
}

// =============================================
// Display Custom Content (archive fallback — lesson_prep.php has its own full version)
// =============================================
window.displayCustomContent = window.displayCustomContent || function displayCustomContent() {
    var container = document.getElementById('customContentArea');
    if (!container) return;

    if (!window.generatedData || !window.generatedData.custom_content ||
        !Array.isArray(window.generatedData.custom_content) ||
        window.generatedData.custom_content.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;">' +
            '<i class="fas fa-magic" style="font-size:3rem;margin-bottom:15px;display:block;"></i>' +
            '<p>لم يتم توليد محتوى مخصص</p></div>';
        return;
    }

    var items = window.generatedData.custom_content;
    var html = '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-magic"></i> محتوى إضافي مخصص</h3>';
    // استخدام _buildSectionActions لإضافة زر التعديل (وزر النسخ) بشكل موحّد مع باقي الأقسام
    // في صفحة الأرشيف. الجامع يعتمد على data-cc-index + .cc-title-text + .cc-body-text.
    html += _buildSectionActions('customContentArea', 'custom_content');
    html += '</div>';

    items.forEach(function (item, i) {
        var icon = safeIconClass(item.icon);
        var color = safeColor(item.color);
        // بطاقة بنفس class باقي التبويبات (.visual-item) لتوحيد الخط/الخلفية/dark mode،
        // مع accent لوني لكل عنصر بدل البطاقة الخضراء الثابتة (مطابقة لنسخة lesson_prep).
        // data-cc-index يربط البطاقة بفهرسها في generatedData.custom_content للجامع.
        html += '<div class="visual-item" data-cc-index="' + i + '" style="border-right: 4px solid ' + color + '; margin-bottom: 15px; text-align: right;">';
        // ترويسة بسيطة: عنوان + أيقونة ملوّنة (بدل شريط gradient ثقيل).
        // العنوان ملفوف بـ .cc-title-text ليقرأه الجامع؛ الأيقونة خارجه كي لا تُجمع.
        html += '<h4 style="margin: 0 0 12px 0; font-size: 1.1rem; font-weight: 700; color: ' + color + '; display: flex; align-items: center; gap: 8px;">';
        html += '<i class="fas ' + icon + '"></i>';
        html += '<span class="cc-title-text">' + escapeHtml(item.title || 'عنصر مخصص') + '</span>';
        html += '</h4>';
        // المحتوى يَرِث الخط من .visual-item/.tab-content (بدل تثبيت Cairo).
        // .cc-body-text: الجامع يقرأ innerHTML للحفاظ على التنسيق الداخلي للمحتوى.
        html += '<div class="cc-body-text" style="line-height: 1.9; font-size: 1rem;">';
        html += sanitizeGeneratedHtml(item.content_html) || '<p style="color: #94a3b8;">لم يتم توليد محتوى لهذا العنصر</p>';
        html += '</div></div>';
    });

    container.innerHTML = html;
};

// =============================================
// Display Educational Stories
// =============================================
function displayEducationalStories() {
    var container = document.getElementById('educationalStoriesContent');
    if (!container) return;
    var tabBtn = document.querySelector('[data-tab="educationalStories"]') || document.querySelector('[data-tab="educational-stories"]');

    if (!window.generatedData || !window.generatedData.educational_stories) {
        var errorDetail = '';
        if (window._lastGenerationErrors && window._lastGenerationErrors.length > 0) {
            var errs = window._lastGenerationErrors.filter(function (e) { return e.indexOf('القصص التعليمية') !== -1 || e.indexOf('القصة التربوية') !== -1 || e.indexOf('educational_stories') !== -1; });
            if (errs.length > 0) {
                errorDetail = '<br><small style="color:#b45309;">' + errs.map(escapeHtml).join('<br>') + '</small>';
            }
        }
        container.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;">' +
            '<i class="fas fa-book-open" style="font-size:3rem;margin-bottom:15px;display:block;"></i>' +
            '<p>لم يتم توليد القصة التربوية</p>' + errorDetail +
            (!window.isArchiveView ? '<button class="btn-regenerate-section" onclick="regenerateSection(\'educational_stories\')" style="margin-top:10px;"><i class="fas fa-sync-alt"></i> إعادة توليد القصة التربوية</button>' : '') + '</div>';
        if (tabBtn) tabBtn.style.display = 'none';
        return;
    }

    if (tabBtn) tabBtn.style.display = '';
    var data = window.generatedData.educational_stories;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
        } catch (e) {
            console.error("Failed to parse educational_stories", e);
        }
    }

    // كشف البنية القديمة ({stories:[...]}) وعرض رسالة إعادة توليد بدل محتوى غير متوافق.
    if (!data || typeof data !== 'object' || (!data.scenes && data.stories)) {
        container.innerHTML =
            '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-book-open"></i> القصة التربوية</h3>' +
            _buildSectionActions('educationalStoriesContent', 'educational_stories') + '</div>' +
            '<div class="alert alert-warning"><i class="fas fa-info-circle"></i> صيغة القصة المخزَّنة غير متوافقة مع العرض الجديد. أعد توليد القسم للحصول على القصة التربوية المنظَّمة.' +
            (!window.isArchiveView ? ' <button class="btn-regenerate-section" onclick="regenerateSection(\'educational_stories\')" style="margin-top:5px;"><i class="fas fa-sync-alt"></i> إعادة توليد</button>' : '') +
            '</div>';
        return;
    }

    var story = data;
    var scenes = Array.isArray(story.scenes) ? story.scenes : [];
    var evaluation = story.evaluation || {};

    // أدوات مساعدة لتحويل النص إلى HTML مع الحفاظ على الأسطر، وتحويل المصفوفة إلى قائمة.
    function nl2br(s) { return escapeHtml(s == null ? '' : String(s)).replace(/\n/g, '<br>'); }
    function renderText(text, cls, extraStyle) {
        if (text == null || String(text).trim() === '') return '';
        return '<div class="' + (cls || '') + '" style="' + (extraStyle || '') + '">' + nl2br(text) + '</div>';
    }
    function renderList(arr) {
        if (!Array.isArray(arr) || arr.length === 0) return '<span style="color:#94a3b8;">—</span>';
        return '<ul style="margin:0; padding-inline-start: 18px;">' + arr.map(function (x) { return '<li>' + nl2br(x) + '</li>'; }).join('') + '</ul>';
    }

    // ====== الترويسة العامة ======
    var html = '<div class="section-header-actions"><h3 class="section-title" style="margin-bottom:0"><i class="fas fa-book-open"></i> القصة التربوية</h3>';
    html += _buildSectionActions('educationalStoriesContent', 'educational_stories');
    html += '</div>';

    if (story.assumptions && String(story.assumptions).trim() !== '') {
        html += '<div class="alert alert-info" style="font-size:0.85rem;"><i class="fas fa-flag me-1"></i> <strong>افتراضات تربوية:</strong> ' + nl2br(story.assumptions) + '</div>';
    }

    // ====== التبويبات الفرعية الأربعة ======
    html += '<div class="sub-tabs-container" id="esSubTabs">';
    html += '<button class="sub-tab-btn active" data-subtab="es-overview" onclick="switchSubTab(\'educationalStoriesContent\', \'es-overview\')"><i class="fas fa-eye"></i> نظرة عامة</button>';
    html += '<button class="sub-tab-btn" data-subtab="es-scenes" onclick="switchSubTab(\'educationalStoriesContent\', \'es-scenes\')"><i class="fas fa-film"></i> المشاهد <span class="sub-tab-badge">' + scenes.length + '</span></button>';
    html += '<button class="sub-tab-btn" data-subtab="es-science" onclick="switchSubTab(\'educationalStoriesContent\', \'es-science\')"><i class="fas fa-flask"></i> الشرح العلمي</button>';
    html += '<button class="sub-tab-btn" data-subtab="es-eval" onclick="switchSubTab(\'educationalStoriesContent\', \'es-eval\')"><i class="fas fa-clipboard-check"></i> التقويم والتحدي</button>';
    html += '</div>';

    // ------------------------------------------------------------------
    // Sub-tab 1: نظرة عامة (العنوان، الهدف، الشخصيات، المكان/الزمان، الافتتاح، الخلاصة)
    // ------------------------------------------------------------------
    var ov = '';
    ov += '<div style="background: linear-gradient(135deg, #fdf2f8, #fce7f3); border: 1px solid #fbcfe8; border-radius: 12px; padding: 20px; margin-bottom: 16px;">';
    ov += '<h4 style="color: #be185d; margin: 0 0 8px 0; font-size: 1.4rem; font-weight: 700;"><i class="fas fa-book-reader" style="margin-left:8px; color:#db2777;"></i><span class="es-title-text">' + escapeHtml(story.title || '') + '</span></h4>';
    if (story.learning_goal) {
        ov += '<div style="background: rgba(255,255,255,0.6); border-inline-start: 4px solid #ec4899; padding: 8px 12px; border-radius: 6px; margin-top: 8px;">';
        ov += '<strong style="color:#9d174d;"><i class="fas fa-bullseye" style="margin-left:5px;"></i> الهدف التعليمي: </strong>';
        ov += '<span class="es-goal-text" style="color:#4d0b28;">' + nl2br(story.learning_goal) + '</span>';
        ov += '</div>';
    }
    ov += '</div>';

    function overviewBlock(icon, label, text, fieldClass) {
        if (text == null || String(text).trim() === '') return '';
        return '<div style="background:#fff; border:1px solid #fbcfe8; border-radius:10px; padding:14px 16px; margin-bottom:12px;">' +
            '<strong style="color:#9d174d; display:block; margin-bottom:6px;"><i class="fas fa-' + icon + '" style="margin-left:6px; color:#db2777;"></i>' + label + '</strong>' +
            '<div class="' + (fieldClass || '') + '" style="color:#334155; line-height:1.7; font-size:0.97rem;">' + nl2br(text) + '</div>' +
            '</div>';
    }

    ov += overviewBlock('users', 'الشخصيات', story.characters, 'es-characters-text');
    ov += overviewBlock('location-dot', 'مكان وزمان القصة', story.setting, 'es-setting-text');
    ov += overviewBlock('flag-checkered', 'الموقف الافتتاحي', story.opening, 'es-opening-text');
    ov += overviewBlock('lightbulb', 'لحظة اكتشاف المفهوم', story.discovery_moment, 'es-discovery-text');
    ov += overviewBlock('link', 'الربط بين القصة والدرس', story.lesson_connection, 'es-connection-text');

    if (story.summary) {
        ov += '<div style="background: linear-gradient(135deg, #fdf4ff, #fae8ff); border: 1px dashed #d946ef; border-radius: 10px; padding: 14px 16px;">';
        ov += '<strong style="color:#86198f; display:block; margin-bottom:6px;"><i class="fas fa-quote-right" style="margin-left:6px;"></i> الخلاصة</strong>';
        ov += '<div class="es-summary-text" style="color:#4a044e; line-height:1.7; font-size:1rem;">' + nl2br(story.summary) + '</div>';
        ov += '</div>';
    }

    html += '<div class="sub-tab-content active" id="es-overview">' + ov + '</div>';

    // ------------------------------------------------------------------
    // Sub-tab 2: المشاهد (قابلة للطي)
    // ------------------------------------------------------------------
    var sc = '';
    if (scenes.length === 0) {
        sc += '<div class="alert alert-warning"><i class="fas fa-info-circle"></i> لا توجد مشاهد في القصة.</div>';
    } else {
        scenes.forEach(function (scene, idx) {
            var num = escapeHtml(String(scene.number != null ? scene.number : (idx + 1)));
            var questions = Array.isArray(scene.questions) ? scene.questions : [];
            var answers = Array.isArray(scene.expected_answers) ? scene.expected_answers : [];
            var panelId = 'es-scene-' + idx;
            sc += '<div class="visual-item es-scene-card" style="border-inline-start: 4px solid #ec4899; background:#fff; border:1px solid #fbcfe8; border-radius:12px; margin-bottom:16px; overflow:hidden;">';
            // ترويسة قابلة للطي
            sc += '<div onclick="esToggleScene(\'' + panelId + '\')" style="display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:12px 16px; background: linear-gradient(135deg, #fdf2f8, #fce7f3);">';
            sc += '<strong style="color:#be185d; font-size:1.05rem;"><i class="fas fa-circle" style="font-size:0.6rem; margin-left:8px; color:#db2777;"></i>المشهد ' + num + ': <span class="es-scene-title">' + escapeHtml(scene.title || '') + '</span></strong>';
            sc += '<i class="fas fa-chevron-down es-scene-arrow" id="' + panelId + '-arrow" style="color:#9d174d; transition:transform .3s;"></i>';
            sc += '</div>';
            // جسم المشهد
            sc += '<div id="' + panelId + '" style="padding:14px 16px;">';
            sc += renderText(scene.narrative, 'es-scene-narrative', 'color:#334155; line-height:1.85; font-size:1.02rem; margin-bottom:12px;');
            if (scene.concept) {
                sc += '<div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:10px 12px; margin-bottom:12px;">';
                sc += '<strong style="color:#b45309;"><i class="fas fa-lightbulb" style="margin-left:6px;"></i>المفهوم التعليمي: </strong>';
                sc += '<span style="color:#78350f;">' + nl2br(scene.concept) + '</span>';
                sc += '</div>';
            }
            if (questions.length > 0) {
                sc += '<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 12px; margin-bottom:10px;">';
                sc += '<strong style="color:#1d4ed8; display:block; margin-bottom:6px;"><i class="fas fa-comments" style="margin-left:6px;"></i>أسئلة للطلاب</strong>';
                sc += renderList(questions);
                sc += '</div>';
            }
            if (answers.length > 0) {
                sc += '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 12px; margin-bottom:10px;">';
                sc += '<strong style="color:#15803d; display:block; margin-bottom:6px;"><i class="fas fa-check-double" style="margin-left:6px;"></i>إجابات متوقعة</strong>';
                sc += renderList(answers);
                sc += '</div>';
            }
            if (scene.teacher_guidance) {
                sc += '<div style="background:#f5f3ff; border:1px solid #ddd6fe; border-radius:8px; padding:10px 12px; margin-bottom:10px;">';
                sc += '<strong style="color:#6d28d9; display:block; margin-bottom:6px;"><i class="fas fa-chalkboard-teacher" style="margin-left:6px;"></i>توجيه المعلم</strong>';
                sc += renderText(scene.teacher_guidance, '', 'color:#4c1d95; line-height:1.7;');
                sc += '</div>';
            }
            if (scene.transition) {
                sc += '<div style="border-top:1px dashed #fbcfe8; padding-top:8px; margin-top:8px; color:#9d174d; font-style:italic;">';
                sc += '<i class="fas fa-arrow-left" style="margin-left:6px;"></i>' + nl2br(scene.transition);
                sc += '</div>';
            }
            sc += '</div></div>';
        });
    }
    html += '<div class="sub-tab-content" id="es-scenes">' + sc + '</div>';

    // ------------------------------------------------------------------
    // Sub-tab 3: الشرح العلمي + الربط
    // ------------------------------------------------------------------
    var sci = '';
    sci += overviewBlock('flask', 'الشرح العلمي بعد القصة', story.scientific_explanation, 'es-science-text');
    if (!story.scientific_explanation) {
        sci = '<div class="alert alert-warning"><i class="fas fa-info-circle"></i> لا يوجد شرح علمي.</div>';
    }
    html += '<div class="sub-tab-content" id="es-science">' + sci + '</div>';

    // ------------------------------------------------------------------
    // Sub-tab 4: النشاط التطبيقي + التحدي + التقويم
    // ------------------------------------------------------------------
    var ev = '';
    if (story.practical_activity) {
        ev += overviewBlock('puzzle-piece', 'النشاط التطبيقي', story.practical_activity, 'es-activity-text');
    }
    if (story.final_challenge) {
        ev += '<div style="background: linear-gradient(135deg, #fef3c7, #fde68a); border:1px solid #fcd34d; border-radius:10px; padding:14px 16px; margin-bottom:16px;">';
        ev += '<strong style="color:#92400e; display:block; margin-bottom:6px;"><i class="fas fa-trophy" style="margin-left:6px;"></i>تحدي نهاية القصة</strong>';
        ev += '<div class="es-challenge-text" style="color:#78350f; line-height:1.7;">' + nl2br(story.final_challenge) + '</div>';
        ev += '</div>';
    }

    function evalCard(level, icon, color, label, question) {
        if (!question || String(question).trim() === '') return '';
        return '<div style="border-inline-start:4px solid ' + color + '; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin-bottom:10px;">' +
            '<strong style="color:' + color + '; display:block; margin-bottom:4px;"><i class="fas fa-' + icon + '" style="margin-left:6px;"></i>' + label + '</strong>' +
            '<div style="color:#1f2937; line-height:1.6;">' + nl2br(question) + '</div>' +
            '</div>';
    }
    ev += '<h5 style="color:#475569; margin:16px 0 10px;"><i class="fas fa-clipboard-question" style="margin-left:6px; color:#6366f1;"></i>أسئلة التقويم</h5>';
    ev += evalCard('recall', 'brain', '#3b82f6', 'تذكّر', evaluation.recall);
    ev += evalCard('understanding', 'lightbulb', '#10b981', 'فهم', evaluation.understanding);
    ev += evalCard('application', 'wrench', '#f59e0b', 'تطبيق', evaluation.application);
    ev += evalCard('analysis', 'magnifying-glass', '#8b5cf6', 'تفكير وتحليل', evaluation.analysis);
    if (!evaluation.recall && !evaluation.understanding && !evaluation.application && !evaluation.analysis) {
        ev += '<div class="alert alert-warning"><i class="fas fa-info-circle"></i> لا توجد أسئلة تقويم.</div>';
    }
    html += '<div class="sub-tab-content" id="es-eval">' + ev + '</div>';

    container.innerHTML = html;
}

// طيّ/توسيع بطاقة المشهد في القصة التربوية
function esToggleScene(panelId) {
    var panel = document.getElementById(panelId);
    var arrow = document.getElementById(panelId + '-arrow');
    if (!panel) return;
    var hidden = panel.style.display === 'none';
    panel.style.display = hidden ? '' : 'none';
    if (arrow) arrow.style.transform = hidden ? 'rotate(180deg)' : 'rotate(0deg)';
}

// =============================================
// Initialize all display functions
// =============================================
function initLessonDisplay() {
    if (!window.generatedData) return;

    // تعيين currentLessonId من المتغير العام أو من lesson_id في البيانات
    if (typeof currentLessonId !== 'undefined' && currentLessonId) {
        window.currentLessonId = currentLessonId;
    }

    if (window.generatedData.lesson_plan !== undefined) displayLessonPlan();
    if (window.generatedData.visual_materials !== undefined) displayVisualMaterials();
    if (window.generatedData.question_bank !== undefined) displayQuestionBank();
    if (window.generatedData.class_activities !== undefined) displayClassActivities();
    if (window.generatedData.educational_stories !== undefined) displayEducationalStories();
    if (window.generatedData.lesson_summary !== undefined) displayLessonSummary();
    if (window.generatedData.custom_content !== undefined) displayCustomContent();
}
