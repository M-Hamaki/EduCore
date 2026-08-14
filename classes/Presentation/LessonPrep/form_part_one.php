
        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loadingOverlay">
            <div class="loading-content">
                <div class="loading-ai-container">
                    <div class="loading-ai-ring"></div>
                    <div class="loading-ai-ring-inner"></div>
                    <div class="loading-ai-circuit"></div>
                    <div class="loading-ai-nodes">
                        <div class="ai-node"></div>
                        <div class="ai-node"></div>
                        <div class="ai-node"></div>
                        <div class="ai-node"></div>
                        <div class="ai-node"></div>
                        <div class="ai-node"></div>
                        <div class="ai-node"></div>
                        <div class="ai-node"></div>
                    </div>
                    <div class="loading-ai-stream">
                        <div class="ai-particle"></div>
                        <div class="ai-particle"></div>
                        <div class="ai-particle"></div>
                        <div class="ai-particle"></div>
                        <div class="ai-particle"></div>
                        <div class="ai-particle"></div>
                    </div>
                    <div class="loading-ai-brain">
                        <i class="fas fa-brain"></i>
                    </div>
                    <span class="loading-ai-badge">AI</span>
                </div>
                <p class="loading-text">جاري التوليد بالذكاء الاصطناعي...</p>
                <p class="loading-subtext" id="loadingSubtext">قد يستغرق هذا بضع ثوانٍ</p>
                <div class="loading-progress">
                    <div class="loading-progress-bar"></div>
                </div>
                <p class="loading-tips" id="loadingTips"></p>
                <button type="button" class="btn-cancel-overlay" id="cancelOverlayBtn" onclick="cancelLessonGeneration()"
                    style="margin-top: 20px; background: #ef4444; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-family: 'Cairo', sans-serif; font-weight: 600; transition: background 0.3s ease; display: inline-flex; align-items: center; justify-content: center; gap: 8px;"
                    onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                    <i class="fas fa-times-circle"></i> إلغاء التوليد
                </button>
            </div>
        </div>

        <div class="main-container">
            <!-- Unified Page Heading -->
            <div class="admin-page-heading mb-4">
                <h1 class="h2"><i class="fas fa-wand-magic-sparkles me-2 text-primary"></i>أداة تحضير الدروس بالذكاء الاصطناعي</h1>
                <div class="admin-top-actions no-print">
                    <a href="lesson_archive.php" class="btn btn-header-premium btn-secondary shadow-sm">
                        <i class="fas fa-archive me-1"></i>أرشيف الدروس
                    </a>
                    <a href="<?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'external_teacher') ? '../external/index.php' : 'portal.php'; ?>" class="btn btn-header-premium btn-import-soft">
                        <i class="fas fa-arrow-right me-1"></i>العودة للبوابة
                    </a>
                </div>
            </div>

            <!-- ===== Tab Navigation ===== -->
            <div class="main-page-tabs">
                <button type="button" class="main-tab-btn active" data-tab="lesson" onclick="switchMainTab('lesson')">
                    <i class="fas fa-chalkboard-teacher tab-icon"></i>
                    <span style="display: flex; flex-direction: column; gap: 5px; line-height: 1.3;">
                        <span>تحضير درس كامل</span>
                        <small style="font-size: 0.72rem; font-weight: 500; opacity: 0.88; line-height: 1.2;">بنك الأسئلة وامتحان إلكتروني وعرض تقديمي</small>
                    </span>
                </button>
                <button type="button" class="main-tab-btn" data-tab="exam" onclick="switchMainTab('exam')">
                    <i class="fas fa-file-medical tab-icon"></i>
                    <span>امتحان إلكتروني فقط</span>
                </button>
                <button type="button" class="main-tab-btn" data-tab="qbank" onclick="switchMainTab('qbank')">
                    <i class="fas fa-database tab-icon"></i>
                    <span>بنك أسئلة فقط</span>
                </button>
                <button type="button" class="main-tab-btn" data-tab="ppt" onclick="switchMainTab('ppt')">
                    <i class="fas fa-file-powerpoint tab-icon"></i>
                    <span>عرض تقديمي PowerPoint فقط</span>
                </button>
            </div>

            <!-- ===== Tab 1: تحضير الدرس ===== -->
            <div class="main-tab-panel active" id="tabLesson">

                <!-- Input Form -->
                <div class="content-card" id="inputSection">
                    <h2 class="card-title">
                        <i class="fas fa-edit"></i> إدخال محتوى الدرس
                        <span id="autosaveIndicator"
                            style="font-size:0.7rem;color:#22c55e;opacity:0;transition:opacity 0.3s;margin-right:10px;"><i
                                class="fas fa-check-circle"></i> تم الحفظ التلقائي</span>
                    </h2>

                    <form id="lessonForm">
                        <div
                            style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); padding: 20px; border-radius: 15px; margin-bottom: 25px; border: 2px solid #e2e8f0;">
                            <h4 style="color: #475569; margin: 0 0 15px 0; font-size: 1.1rem;">
                                <i class="fas fa-sliders-h"></i> إعدادات الدرس الأساسية
                            </h4>
                            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 15px;">
                                <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                                    <label class="form-label">
                                        <i class="fas fa-language" style="color: #3b82f6;"></i> لغة التحضير
                                    </label>
                                    <select class="form-select" id="language" name="language" required>
                                        <option value="ar" selected>العربية</option>
                                        <option value="en">English</option>
                                        <option value="fr">Français</option>
                                        <option value="de">Deutsch</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                                    <label class="form-label">
                                        <i class="fas fa-clock" style="color: #f59e0b;"></i> زمن الحصة (بالدقائق)
                                    </label>
                                    <input type="number" class="form-control" id="duration" name="duration" value="45"
                                        min="1" required>
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                                    <label class="form-label">
                                        <i class="fas fa-graduation-cap" style="color: #0ea5e9;"></i> الصف الدراسي
                                    </label>
                                    <select class="form-select" id="gradeLevel" name="grade_level">
                                        <option value="">— غير محدد —</option>
                                        <?php if (!empty($allGrades)): ?>
                                            <?php foreach ($allGrades as $grade): ?>
                                                <option value="<?php echo htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                                    <label class="form-label d-flex align-items-center gap-1">
                                        <i class="fas fa-child-reaching" style="color: #ec4899;"></i>
                                        <span>الفئة العمرية للطلاب</span>
                                        <i class="fas fa-info-circle text-muted ms-1" style="cursor: pointer; font-size: 0.85rem;" data-bs-toggle="tooltip" data-bs-placement="top" title="خاص بالقصة التعليمية: لتحديد الفئة العمرية المناسبة وتوليد قصة تربوية تلائم الطلاب"></i>
                                    </label>
                                    <select class="form-select" id="studentAge" name="student_age">
                                        <option value="">— غير محدَّدة —</option>
                                        <option value="6" selected>الأولية (6 - 8 سنوات)</option>
                                        <option value="9">الابتدائية (9 - 11 سنة)</option>
                                        <option value="12">الإعدادية (12 - 14 سنة)</option>
                                        <option value="15">الثانوية (15 - 17 سنة)</option>
                                        <option value="18">الجامعية (18+ سنة)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">
                                    <i class="fas fa-heading" style="color: #8b5cf6;"></i> عنوان الدرس <span
                                        style="color: #ef4444;">*</span>
                                </label>
                                <input type="text" class="form-control" id="title" name="title"
                                    placeholder="أدخل عنوان الدرس" required>
                            </div>
                        </div>

                        <!-- إعدادات عناصر التحضير المراد توليدها -->
                        <div style="background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid #e2e8f0;"
                            id="elementsSettingsSection">
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <label class="form-label" style="margin-bottom: 0;">
                                    <i class="fas fa-puzzle-piece" style="color: #10b981;"></i> تحديد عناصر التحضير
                                </label>
                                <div style="display: flex; gap: 8px;">
                                    <button type="button" id="btnSelectAll" onclick="selectAllElements()"
                                        class="btn-element-toggle"
                                        style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 5px 14px; border-radius: 8px; font-size: 0.85rem; cursor: pointer; font-family: 'Cairo', sans-serif; transition: opacity 0.2s;">
                                        <i class="fas fa-check-double"></i> تحديد الكل
                                    </button>
                                    <button type="button" id="btnDeselectAll" onclick="deselectAllElements()"
                                        class="btn-element-toggle"
                                        style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; padding: 5px 14px; border-radius: 8px; font-size: 0.85rem; cursor: pointer; font-family: 'Cairo', sans-serif; transition: opacity 0.2s;">
                                        <i class="fas fa-times"></i> إلغاء الكل
                                    </button>
                                </div>
                            </div>
                            <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 15px;">
                                <i class="fas fa-info-circle"></i> اختر العناصر التي تريد تضمينها. كلما قلّت العناصر كان
                                التوليد أسرع.
                            </p>

                            <!-- العناصر الأساسية (مفتوحة افتراضياً) -->
                            <div class="elements-collapsible-section" style="margin-bottom: 10px;">
                                <div class="elements-section-header" onclick="toggleElementsSection(this)"
                                    style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 10px 15px; background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 10px; color: white;">
                                    <h5 style="color: white; font-size: 1rem; margin: 0; font-weight: 600;">
                                        <i class="fas fa-star"></i> العناصر الأساسية
                                    </h5>
                                    <i class="fas fa-chevron-up elements-section-arrow"
                                        style="color: white; font-size: 0.8rem; transition: transform 0.3s;"></i>
                                </div>
                                <div class="elements-section-body" style="padding: 10px 0 0 0;">
                                    <div
                                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 6px;">
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="objectives" checked
                                                class="element-checkbox">
                                            <i class="fas fa-bullseye" style="color: #10b981;"></i>
                                            <span>الأهداف التعليمية</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="strategies" checked
                                                class="element-checkbox">
                                            <i class="fas fa-lightbulb" style="color: #f59e0b;"></i>
                                            <span>الاستراتيجيات التعليمية</span>
                                        </label>
                                        <!-- مراحل الدرس (ضمن العناصر الأساسية) -->
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="phases[]" value="warmup" checked
                                                class="element-checkbox phase-checkbox">
                                            <i class="fas fa-sun" style="color: #f59e0b;"></i>
                                            <span>التمهيد</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="phases[]" value="review" checked
                                                class="element-checkbox phase-checkbox">
                                            <i class="fas fa-undo" style="color: #6366f1;"></i>
                                            <span>المراجعة الجزئية</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="phases[]" value="intro" checked
                                                class="element-checkbox phase-checkbox">
                                            <i class="fas fa-play-circle" style="color: #10b981;"></i>
                                            <span>مقدمة الدرس</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="phases[]" value="explanation" checked
                                                class="element-checkbox phase-checkbox">
                                            <i class="fas fa-chalkboard-teacher" style="color: #3b82f6;"></i>
                                            <span>شرح الدرس</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="phases[]" value="assessment" checked
                                                class="element-checkbox phase-checkbox">
                                            <i class="fas fa-clipboard-check" style="color: #ef4444;"></i>
                                            <span>التقويم</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="phases[]" value="keypoints" checked
                                                class="element-checkbox phase-checkbox">
                                            <i class="fas fa-key" style="color: #8b5cf6;"></i>
                                            <span>أهم النقاط</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="phases[]" value="homework" checked
                                                class="element-checkbox phase-checkbox">
                                            <i class="fas fa-home" style="color: #06b6d4;"></i>
                                            <span>الواجب المنزلي</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="resources" checked
                                                class="element-checkbox">
                                            <i class="fas fa-toolbox" style="color: #06b6d4;"></i>
                                            <span>الموارد والوسائل</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- العناصر الإضافية المتقدمة (مغلقة افتراضياً) -->
                            <div class="elements-collapsible-section" style="margin-bottom: 10px;">
                                <div class="elements-section-header" onclick="toggleElementsSection(this)"
                                    style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 10px 15px; background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-radius: 10px; color: white;">
                                    <h5 style="color: white; font-size: 1rem; margin: 0; font-weight: 600;">
                                        <i class="fas fa-plus-circle"></i> عناصر إضافية متقدمة
                                    </h5>
                                    <i class="fas fa-chevron-up elements-section-arrow"
                                        style="color: white; font-size: 0.8rem; transition: transform 0.3s; transform: rotate(180deg);"></i>
                                </div>
                                <div class="elements-section-body collapsed" style="padding: 10px 0 0 0;">
                                    <div
                                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 6px;">
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="learning_styles" checked
                                                class="element-checkbox">
                                            <i class="fas fa-brain" style="color: #8b5cf6;"></i>
                                            <span>أنماط التعلم</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="target_competencies" checked
                                                class="element-checkbox">
                                            <i class="fas fa-award" style="color: #ec4899;"></i>
                                            <span>الكفايات المستهدفة</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="motivational_intro" checked
                                                class="element-checkbox">
                                            <i class="fas fa-rocket" style="color: #f97316;"></i>
                                            <span>المقدمة التحفيزية</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="differentiation" checked
                                                class="element-checkbox">
                                            <i class="fas fa-layer-group" style="color: #14b8a6;"></i>
                                            <span>مراعاة الفروق الفردية</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="enrichment" checked
                                                class="element-checkbox">
                                            <i class="fas fa-gem" style="color: #6366f1;"></i>
                                            <span>الإثراء والتوسع</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="new_vocabulary" checked
                                                class="element-checkbox">
                                            <i class="fas fa-spell-check" style="color: #0ea5e9;"></i>
                                            <span>المفردات الجديدة</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="formative_assessment" checked
                                                class="element-checkbox">
                                            <i class="fas fa-clipboard-check" style="color: #22c55e;"></i>
                                            <span>التقويم التكويني</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="closure_summary" checked
                                                class="element-checkbox">
                                            <i class="fas fa-flag-checkered" style="color: #ef4444;"></i>
                                            <span>الغلق والتلخيص</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="real_life_connections" checked
                                                class="element-checkbox">
                                            <i class="fas fa-globe-americas" style="color: #059669;"></i>
                                            <span>الربط بالحياة الواقعية</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="self_reflection" checked
                                                class="element-checkbox">
                                            <i class="fas fa-brain" style="color: #a855f7;"></i>
                                            <span>التأمل الذاتي للمعلم</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="elements[]" value="post_notes" checked
                                                class="element-checkbox">
                                            <i class="fas fa-sticky-note" style="color: #eab308;"></i>
                                            <span>ملاحظات ما بعد التنفيذ</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- الأقسام المستقلة (مغلقة افتراضياً) -->
                            <div class="elements-collapsible-section" style="margin-bottom: 10px;">
                                <div class="elements-section-header" onclick="toggleElementsSection(this)"
                                    style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 10px 15px; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 10px; color: white;">
                                    <h5 style="color: white; font-size: 1rem; margin: 0; font-weight: 600;">
                                        <i class="fas fa-cubes"></i> الأقسام المستقلة
                                    </h5>
                                    <i class="fas fa-chevron-up elements-section-arrow"
                                        style="color: white; font-size: 0.8rem; transition: transform 0.3s; transform: rotate(180deg);"></i>
                                </div>
                                <div class="elements-section-body collapsed" style="padding: 10px 0 0 0;">
                                    <div
                                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 6px;">
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="sections[]" value="visual_materials" checked
                                                class="element-checkbox">
                                            <i class="fas fa-images" style="color: #6366f1;"></i>
                                            <span>المواد البصرية</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="sections[]" value="class_activities" checked
                                                class="element-checkbox">
                                            <i class="fas fa-puzzle-piece" style="color: #10b981;"></i>
                                            <span>الأنشطة الصفية</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="sections[]" value="mind_maps" checked
                                                class="element-checkbox">
                                            <i class="fas fa-project-diagram" style="color: #f59e0b;"></i>
                                            <span>الخرائط الذهنية</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="sections[]" value="lesson_summary" checked
                                                class="element-checkbox">
                                            <i class="fas fa-file-lines" style="color: #8b5cf6;"></i>
                                            <span>ملخص الدرس</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="sections[]" value="question_bank" checked
                                                class="element-checkbox" onchange="toggleQbSettings(this)">
                                            <i class="fas fa-question-circle" style="color: #3b82f6;"></i>
                                            <span>بنك الأسئلة</span>
                                        </label>
                                        <label class="element-checkbox-label">
                                            <input type="checkbox" name="sections[]" value="educational_stories" checked
                                                class="element-checkbox">
                                            <i class="fas fa-book-open" style="color: #ec4899;"></i>
                                            <span>القصة التربوية</span>
                                        </label>
                                    </div>

                                </div>
                            </div>

                            <!-- عرض PowerPoint للدرس (مغلق افتراضياً كقائمة تحت الأقسام المستقلة) -->
                            <div class="elements-collapsible-section" style="margin-bottom: 10px;">
                                <div class="elements-section-header" onclick="toggleElementsSection(this)"
                                    style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 10px 15px; background: linear-gradient(135deg, #6366f1, #4f46e5); border-radius: 10px; color: white;">
                                    <h5 style="color: white; font-size: 1rem; margin: 0; font-weight: 600;">
                                        <i class="fas fa-file-powerpoint"></i> عرض PowerPoint للدرس
                                    </h5>
                                    <i class="fas fa-chevron-up elements-section-arrow"
                                        style="color: white; font-size: 0.8rem; transition: transform 0.3s; transform: rotate(180deg);"></i>
                                </div>
                                <div class="elements-section-body collapsed" style="padding: 10px 0 0 0;">
                                    <div
                                        style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #cbd5e1; margin-top: 5px;">
                                        <div
                                            style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px dashed #cbd5e1;">
                                            <div>
                                                <span style="font-weight: 600; font-size: 0.95rem; color: #1e293b;"><i
                                                        class="fas fa-circle-check"
                                                        style="color: #6366f1; margin-left: 5px;"></i>توليد عرض PowerPoint
                                                    للدرس</span>
                                                <small
                                                    style="color: #64748b; font-size: 0.8rem; display: block; margin-top: 2px;">عرض
                                                    بصري قابل للتعديل، يُنشأ محليًا دون خدمة مدفوعة إضافية.</small>
                                            </div>
                                            <label class="exam-toggle">
                                                <input type="checkbox" id="generatePowerPoint" checked
                                                    onchange="document.getElementById('powerpointSettings').style.opacity = this.checked ? '1' : '0.5'; document.getElementById('powerpointSettings').style.pointerEvents = this.checked ? 'auto' : 'none';">
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                        <div id="powerpointSettings"
                                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; padding-top: 5px;">
                                            <div>
                                                <label class="form-label"
                                                    style="font-size: 0.85rem; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">قالب
                                                    العرض</label>
                                                <select id="powerPointTheme" class="form-select"
                                                    style="font-size: 0.9rem; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #fff;">
                                                    <option value="modern">🎓 تعليمي حديث</option>
                                                    <option value="colorful">🌈 أطفال ملون</option>
                                                    <option value="formal">📋 رسمي مبسط</option>
                                                    <option value="gradient">🎨 تدرجات أنيقة</option>
                                                    <option value="nature">🌿 طبيعة وبيئة</option>
                                                    <option value="tech">💻 تقني عصري</option>
                                                    <option value="creative">✨ إبداعي فني</option>
                                                    <option value="minimal">◻️ بسيط أنيق</option>
                                                    <option value="islamic">🕌 إسلامي تراثي</option>
                                                    <option value="scientific">🔬 علمي أكاديمي</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label"
                                                    style="font-size: 0.85rem; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">الحد
                                                    الأقصى للشرائح</label>
                                                <input id="powerPointSlides" type="number" class="form-control" value="12"
                                                    min="6" max="18"
                                                    style="font-size: 0.9rem; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #fff;">
                                            </div>

                                            <?php if (!empty($canvaTemplates)): ?>
                                            <div>
                                                <label class="form-label"
                                                    style="font-size: 0.85rem; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">
                                                    <i class="fas fa-palette" style="color: #8b3dff;"></i> قالب Canva</label>
                                                <select id="powerPointCanvaTemplate" class="form-select"
                                                    onchange="syncPptTemplateChoice('main', 'canva')"
                                                    style="font-size: 0.9rem; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #fff;">
                                                    <option value="">— بدون قالب Canva —</option>
                                                    <?php foreach ($canvaTemplates as $tpl): ?>
                                                    <option value="<?= (int)$tpl['id'] ?>" <?= $tpl['is_active'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($tpl['name'] ?? $tpl['design_id'], ENT_QUOTES, 'UTF-8') ?><?= (($tpl['template_type'] ?? 'design') === 'brand_template') ? ' - Canva Autofill' : '' ?><?= $tpl['is_active'] ? ' ⭐' : '' ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <?php endif; ?>

                                            <?php if (!empty($internalPptTemplates)): ?>
                                            <div>
                                                <label class="form-label"
                                                    style="font-size: 0.85rem; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">
                                                    <i class="fas fa-file-powerpoint" style="color: #dc2626;"></i> قالب من مكتبة EduCore</label>
                                                <select id="powerPointInternalTemplate" class="form-select"
                                                    onchange="syncPptTemplateChoice('main', 'internal')"
                                                    style="font-size: 0.9rem; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #fff;">
                                                    <option value="">— اختيار تلقائي —</option>
                                                    <?php foreach ($internalPptTemplates as $tpl): ?>
                                                    <option value="<?= (int)$tpl['id'] ?>">
                                                        <?= htmlspecialchars($tpl['name'], ENT_QUOTES, 'UTF-8') ?>
                                                        <?= !empty($tpl['subject']) ? ' - ' . htmlspecialchars($tpl['subject'], ENT_QUOTES, 'UTF-8') : '' ?>
                                                        <?= !empty($tpl['stage']) ? ' - ' . htmlspecialchars($tpl['stage'], ENT_QUOTES, 'UTF-8') : '' ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small style="color: #dc2626; font-size: 0.75rem; display: block; margin-top: 3px;">
                                                    اختيار قالب من المكتبة يلغي اختيار Canva تلقائيًا.
                                                </small>
                                            </div>
                                            <?php endif; ?>

                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- محتوى إضافي مخصص -->
                            <div class="elements-collapsible-section" style="margin-bottom: 10px; margin-top: 15px;">
                                <div class="elements-section-header" onclick="toggleElementsSection(this)"
                                    style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 10px 15px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 10px; color: white;">
                                    <h5 style="color: white; font-size: 1rem; margin: 0; font-weight: 600;">
                                        <i class="fas fa-magic"></i> محتوى إضافي مخصص
                                    </h5>
                                    <i class="fas fa-chevron-up elements-section-arrow"
                                        style="color: white; font-size: 0.8rem; transition: transform 0.3s; transform: rotate(180deg);"></i>
                                </div>
                                <div class="elements-section-body collapsed" style="padding: 10px 0 0 0;">
                                    <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 10px;">
                                        <i class="fas fa-info-circle" style="color: #10b981;"></i>
                                        اكتب عناصر إضافية تريد من الذكاء الاصطناعي توليدها (مثل: ورقة عمل، خطة علاجية، نشاط
                                        إثرائي...). اضغط Enter بعد كل عنصر.
                                    </p>
                                    <div style="position: relative;">
                                        <div id="customTagsContainer"
                                            style="display: flex; flex-wrap: wrap; gap: 8px; padding: 10px; min-height: 48px; border: 2px solid #d1d5db; border-radius: 10px; background: white; cursor: text;"
                                            onclick="document.getElementById('customTagInput').focus()">
                                            <input type="text" id="customTagInput"
                                                placeholder="اكتب عنصرًا ثم اضغط Enter..."
                                                style="border: none; outline: none; flex: 1; min-width: 180px; font-family: 'Cairo', sans-serif; font-size: 0.95rem; background: transparent;"
                                                onkeydown="handleCustomTagKey(event)">
                                        </div>
                                    </div>
                                    <div id="customTagsList" style="margin-top: 8px;"></div>
                                    <div style="margin-top: 8px; display: flex; gap: 6px; flex-wrap: wrap;">
                                        <small style="color: #94a3b8; font-size: 0.8rem;">أمثلة سريعة:</small>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;"
                                            onclick="addCustomTag('ورقة عمل')">ورقة عمل</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;"
                                            onclick="addCustomTag('خطة علاجية')">خطة علاجية</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;"
                                            onclick="addCustomTag('نشاط إثرائي')">نشاط إثرائي</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;"
                                            onclick="addCustomTag('تقييم ذاتي للطالب')">تقييم ذاتي</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;"
                                            onclick="addCustomTag('ربط بين المواد')">ربط بين المواد</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #fef3c7; color: #d97706; border: 1px solid #fcd34d;"
                                            onclick="addCustomTag('واجب منزلي')">واجب منزلي</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #fef3c7; color: #d97706; border: 1px solid #fcd34d;"
                                            onclick="addCustomTag('تمارين إضافية')">تمارين إضافية</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #ede9fe; color: #7c3aed; border: 1px solid #c4b5fd;"
                                            onclick="addCustomTag('أسئلة تفكير عليا')">تفكير عليا</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #ede9fe; color: #7c3aed; border: 1px solid #c4b5fd;"
                                            onclick="addCustomTag('نشاط تعاوني')">نشاط تعاوني</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #e0f2fe; color: #0284c7; border: 1px solid #7dd3fc;"
                                            onclick="addCustomTag('خريطة ذهنية')">خريطة ذهنية</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #e0f2fe; color: #0284c7; border: 1px solid #7dd3fc;"
                                            onclick="addCustomTag('مشروع بحثي')">مشروع بحثي</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #fce7f3; color: #db2777; border: 1px solid #f9a8d4;"
                                            onclick="addCustomTag('تحليل نص')">تحليل نص</button>
                                        <button type="button" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 10px; border-radius: 20px; background: #fce7f3; color: #db2777; border: 1px solid #f9a8d4;"
                                            onclick="addCustomTag('مفردات ومصطلحات')">مفردات ومصطلحات</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                <i class="fas fa-file-alt" style="color: #0ea5e9;"></i> المحتوى التعليمي
                            </label>
                            <textarea class="form-control" id="content" name="content" rows="5" placeholder="اكتب أو الصق المحتوى التعليمي هنا...
أو قم برفع ملف PDF أو صورة من الأسفل"
                                style="padding: 12px 14px; font-size: 1rem; border: 2px solid #cbd5e1; border-radius: 8px; min-height: 120px; resize: vertical;"></textarea>
                        </div>

                        <!-- File Upload -->
                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-file-pdf" style="color: #ef4444;"></i> رفع ملف PDF
                                </label>
                                <div style="border: 2px dashed #cbd5e1; border-radius: 10px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.2s; background: white;"
                                    id="pdfUploadArea" onclick="document.getElementById('pdfInput').click()"
                                    onmouseover="this.style.borderColor='#3b82f6'; this.style.background='#eff6ff';"
                                    onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='white';">
                                    <input type="file" id="pdfInput" accept=".pdf" style="display: none;"
                                        onchange="handlePdfSelect(this)">
                                    <i class="fas fa-cloud-upload-alt"
                                        style="font-size: 1.5rem; color: #94a3b8; margin-bottom: 5px; display: block;"></i>
                                    <p style="margin: 0; color: #64748b; font-size: 0.85rem;">اسحب أو <strong>انقر
                                            للاختيار</strong></p>
                                    <p style="margin: 3px 0 0 0; color: #94a3b8; font-size: 0.75rem;">PDF فقط - الحد الأقصى
                                        10MB</p>
                                </div>
                                <div id="pdfPreview"
                                    style="display: none; margin-top: 8px; background: #f0fdf4; padding: 8px 12px; border-radius: 8px; border: 1px solid #86efac;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="color: #166534; font-size: 0.85rem; flex: 1;"><i
                                                class="fas fa-file-pdf" style="color: #ef4444;"></i> <span
                                                id="pdfFileName"></span></span>
                                        <button type="button" onclick="removeFile('pdf')"
                                            style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 1rem; flex-shrink: 0;"><i
                                                class="fas fa-times-circle"></i></button>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-image" style="color: #8b5cf6;"></i> رفع صورة
                                </label>
                                <div style="border: 2px dashed #cbd5e1; border-radius: 10px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.2s; background: white;"
                                    id="imageUploadArea" onclick="document.getElementById('imageInput').click()"
                                    onmouseover="this.style.borderColor='#8b5cf6'; this.style.background='#f5f3ff';"
                                    onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='white';">
                                    <input type="file" id="imageInput" accept="image/*" style="display: none;"
                                        onchange="handleImageSelect(this)">
                                    <i class="fas fa-cloud-upload-alt"
                                        style="font-size: 1.5rem; color: #94a3b8; margin-bottom: 5px; display: block;"></i>
                                    <p style="margin: 0; color: #64748b; font-size: 0.85rem;">اسحب أو <strong>انقر
                                            للاختيار</strong></p>
                                    <p style="margin: 3px 0 0 0; color: #94a3b8; font-size: 0.75rem;">JPG, PNG - الحد الأقصى
                                        5MB</p>
                                </div>
                                <div id="imagePreview"
                                    style="display: none; margin-top: 8px; background: #f5f3ff; padding: 8px 12px; border-radius: 8px; border: 1px solid #c4b5fd;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="color: #5b21b6; font-size: 0.85rem; flex: 1;"><i class="fas fa-image"
                                                style="color: #8b5cf6;"></i> <span id="imageFileName"></span></span>
                                        <button type="button" onclick="removeFile('image')"
                                            style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 1rem; flex-shrink: 0;"><i
                                                class="fas fa-times-circle"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- قسم إعدادات الامتحان الإلكتروني - منفصل -->
                <div class="content-card" id="examSettingsSection">
                    <h2 class="card-title">
                        <i class="fas fa-file-alt" style="color: #0ea5e9;"></i> إعدادات الامتحان الإلكتروني
                    </h2>

                    <style>
                        .exam-toggle {
                            position: relative;
                            display: inline-block;
                            width: 50px;
                            height: 26px;
                        }

                        .exam-toggle input {
                            opacity: 0;
                            width: 0;
                            height: 0;
                        }

                        .exam-toggle .slider {
                            position: absolute;
                            cursor: pointer;
                            top: 0;
                            left: 0;
                            right: 0;
                            bottom: 0;
                            background: #cbd5e1;
                            transition: .3s;
                            border-radius: 26px;
                        }

                        .exam-toggle .slider:before {
                            position: absolute;
                            content: "";
                            height: 20px;
                            width: 20px;
                            left: 3px;
                            bottom: 3px;
                            background: white;
                            transition: .3s;
                            border-radius: 50%;
                            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
                        }

                        .exam-toggle input:checked+.slider {
                            background: #10b981;
                        }

                        .exam-toggle input:checked+.slider:before {
                            transform: translateX(24px);
                        }

                        /* Theme selector styles */
                        .exam-theme-option input:checked+.exam-theme-card {
                            border-color: #3b82f6 !important;
                            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
                            transform: translateY(-2px);
                        }

                        .exam-theme-card:hover {
                            border-color: #94a3b8 !important;
                            transform: translateY(-1px);
                        }

                        body.dark-mode .exam-theme-card {
                            border-color: #3d3d5c !important;
                        }

                        body.dark-mode .exam-theme-card span {
                            color: #94a3b8 !important;
                        }

                        body.dark-mode .exam-theme-option input:checked+.exam-theme-card {
                            border-color: #818cf8 !important;
                            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2);
                        }
                    </style>

                    <!-- Modern Integrated Settings Layout -->
                    <div class="modern-settings-container">

                        <!-- Section 1: Main Exam Parameters -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark fs-7" for="examDuration">
                                    <i class="fas fa-stopwatch text-primary me-1"></i> مدة الامتحان
                                </label>
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control text-center fw-bold" id="examDuration" name="exam_duration" value="0" min="1" disabled style="opacity: 0.6; border-radius: 0 8px 8px 0 !important;">
                                    <span class="input-group-text bg-light border-start-0" style="border-radius: 8px 0 0 8px !important;">
                                        <label class="d-flex align-items-center gap-1 mb-0 cursor-pointer user-select-none">
                                            <input type="checkbox" id="unlimitedTime" checked onchange="toggleUnlimitedTime('examDuration', 'unlimitedTime')" class="form-check-input mt-0 me-1">
                                            <span class="fw-bold text-primary fs-8">∞ مفتوح</span>
                                        </label>
                                    </span>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark fs-7" for="examModels">
                                    <i class="fas fa-copy text-purple me-1" style="color: #8b5cf6;"></i> عدد النماذج
                                </label>
                                <select class="form-select form-select-sm fw-semibold" id="examModels" name="exam_models" style="border-radius: 8px;">
                                    <option value="1">نموذج واحد (A)</option>
                                    <option value="2">نموذجان (A, B)</option>
                                    <option value="3" selected>3 نماذج (A, B, C)</option>
                                    <option value="4">4 نماذج (A, B, C, D)</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark fs-7" for="modelType">
                                    <i class="fas fa-shuffle text-indigo me-1" style="color: #6366f1;"></i> طريقة توزيع النماذج
                                </label>
                                <select class="form-select form-select-sm fw-semibold" id="modelType" name="model_type" style="border-radius: 8px;">
                                    <option value="shuffle" selected>نفس الأسئلة مع تغيير الترتيب</option>
                                    <option value="different">أسئلة مختلفة لكل نموذج</option>
                                </select>
                            </div>
                        </div>

                        <!-- Section 2: Question Counts Bar -->
                        <div class="settings-subcard mb-4">
                            <div class="subcard-title">
                                <i class="fas fa-list-check text-primary me-1"></i> أعداد الأسئلة المطلوبة
                                <small class="text-muted ms-auto font-normal fs-8">ضع 0 لإلغاء أي نوع أسئلة</small>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="qcount-stepper mc">
                                        <div class="qcount-info">
                                            <i class="fas fa-check-circle text-primary"></i>
                                            <span>اختيار من متعدد</span>
                                        </div>
                                        <input type="number" class="form-control form-control-sm qcount-input" id="mcCount" name="mc_count" value="10" min="0" max="999">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="qcount-stepper tf">
                                        <div class="qcount-info">
                                            <i class="fas fa-check-double text-success"></i>
                                            <span>صح وخطأ</span>
                                        </div>
                                        <input type="number" class="form-control form-control-sm qcount-input" id="tfCount" name="tf_count" value="10" min="0" max="999">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="qcount-stepper essay">
                                        <div class="qcount-info">
                                            <i class="fas fa-pen-fancy text-purple" style="color: #8b5cf6;"></i>
                                            <span>أسئلة مقالية</span>
                                        </div>
                                        <input type="number" class="form-control form-control-sm qcount-input" id="essayCount" name="essay_count" value="0" min="0" max="999">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 3: Feature Switches (3-Column Compact Grid) -->
                        <div class="settings-subcard mb-4">
                            <div class="subcard-title mb-3">
                                <i class="fas fa-sliders-h text-primary me-1"></i> الميزات وخيارات الأمان
                            </div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="feature-card">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <span class="feature-name"><i class="fas fa-user-edit text-success me-1"></i> طلب بيانات الطالب</span>
                                            <label class="exam-toggle mb-0 ms-2">
                                                <input type="checkbox" id="studentInfoEnabled" name="student_info" value="1" checked>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                        <small class="feature-hint">اشتراط كتابة اسم الطالب والفصل</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="feature-card">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <span class="feature-name"><i class="fas fa-shield-alt text-warning me-1"></i> نظام منع الغش</span>
                                            <label class="exam-toggle mb-0 ms-2">
                                                <input type="checkbox" id="antiCheatEnabled" name="anti_cheat" value="1">
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                        <small class="feature-hint">رصد الخروج وتنقلات النوافذ</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="feature-card">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <span class="feature-name"><i class="fas fa-key text-info me-1"></i> نموذج إجابة مستقل</span>
                                            <label class="exam-toggle mb-0 ms-2">
                                                <input type="checkbox" id="answerKeyEnabled" name="answer_key" value="1" checked>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                        <small class="feature-hint">إنشاء ملف خاص بمفتاح الإجابة</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 4: Themes Palette Badges -->
                        <div class="settings-subcard">
                            <div class="subcard-title">
                                <i class="fas fa-palette text-primary me-1"></i> مظهر وثيم الامتحان
                            </div>
                            <div class="theme-palette-grid">
                                <label class="theme-palette-item">
                                    <input type="radio" name="exam_theme" value="classic" checked>
                                    <div class="theme-palette-card">
                                        <span class="swatch classic-swatch"></span>
                                        <span class="theme-name">كلاسيكي</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="exam_theme" value="ocean">
                                    <div class="theme-palette-card">
                                        <span class="swatch ocean-swatch"></span>
                                        <span class="theme-name">المحيط</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="exam_theme" value="nature">
                                    <div class="theme-palette-card">
                                        <span class="swatch nature-swatch"></span>
                                        <span class="theme-name">طبيعي</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="exam_theme" value="sunset">
                                    <div class="theme-palette-card">
                                        <span class="swatch sunset-swatch"></span>
                                        <span class="theme-name">الغروب</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="exam_theme" value="rose">
                                    <div class="theme-palette-card">
                                        <span class="swatch rose-swatch"></span>
                                        <span class="theme-name">وردي</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="exam_theme" value="dark">
                                    <div class="theme-palette-card">
                                        <span class="swatch dark-swatch"></span>
                                        <span class="theme-name">داكن</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="exam_theme" value="royal">
                                    <div class="theme-palette-card">
                                        <span class="swatch royal-swatch"></span>
                                        <span class="theme-name">ملكي</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- زر التوليد - بعد جميع الإعدادات -->
                <div class="content-card" id="generateSection"
                    style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); text-align: center; padding: 20px 15px; border: none; position: relative; overflow: hidden;">
                    <div class="generate-section" style="margin: 0;">
                        <button type="button" class="btn-generate" id="generateBtn" onclick="submitLessonForm()">
                            <span class="spinner"></span>
                            <span class="btn-text">
                                <i class="fas fa-magic"></i> توليد التحضير والاختبار
                            </span>
                        </button>
                        <p style="color: rgba(255,255,255,0.7); margin-top: 8px; margin-bottom: 0; font-size: 0.85rem;"><br>
                            <i class="fas fa-info-circle"></i> تأكد من إدخال جميع البيانات المطلوبة
                        </p>
                    </div>
                </div>

            </div><!-- /tabLesson -->

            <!-- ===== Tab 2: توليد امتحان إلكتروني مستقل ===== -->
            <div class="main-tab-panel" id="tabExam">
                <div class="content-card" id="standaloneExamSection">
                    <!-- عنوان القسم -->
                    <div
                        style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); padding: 12px 18px; border-radius: 12px; margin-bottom: 14px; border: 1px solid #bae6fd; display: flex; align-items: center; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0;">
                            <i class="fas fa-file-medical" style="color: #0ea5e9; font-size: 1.2rem; flex-shrink: 0;"></i>
                            <h4 style="color: #0369a1; margin: 0; font-size: 1rem; font-weight: 700;">توليد امتحان إلكتروني
                                مستقل</h4>
                            <span style="color: #0369a1; font-size: 0.78rem; opacity: 0.8;">— بدون الحاجة لتوليد تحضير درس
                                كامل</span>
                        </div>
                    </div>

                    <!-- محتوى الامتحان المستقل -->
                    <div id="standaloneExamContent">
                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 12px; margin-top: 4px;">
                            <div class="form-group" style="flex: 2; min-width: 220px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-heading" style="color: #3b82f6;"></i> عنوان الامتحان <span
                                        style="color: #ef4444;">*</span>
                                </label>
                                <input type="text" class="form-control" id="examTitle" name="exam_title"
                                    placeholder="أدخل عنوان الامتحان"
                                    style="padding: 10px 14px; font-size: 1rem; border: 2px solid #cbd5e1; border-radius: 8px;">
