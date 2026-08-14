                            </div>
                            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-graduation-cap" style="color: #0ea5e9;"></i> الصف الدراسي
                                </label>
                                <select class="form-select" id="saGradeLevel" name="sa_grade_level"
                                    style="padding: 10px 14px; font-size: 1rem; border: 2px solid #cbd5e1; border-radius: 8px;">
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
                            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-language" style="color: #0ea5e9;"></i> لغة الامتحان
                                </label>
                                <select class="form-select" id="saLanguage"
                                    style="padding: 10px 14px; font-size: 1rem; border: 2px solid #cbd5e1; border-radius: 8px;">
                                    <option value="ar" selected>العربية</option>
                                    <option value="en">English</option>
                                    <option value="fr">Français</option>
                                    <option value="de">Deutsch</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                <i class="fas fa-file-alt" style="color: #0ea5e9;"></i> المحتوى التعليمي للامتحان <span
                                    style="color: #ef4444;">*</span>
                            </label>
                            <textarea class="form-control" id="examContent" name="exam_content" rows="5" placeholder="اكتب أو الصق المحتوى التعليمي الذي تريد توليد الامتحان بناءً عليه...
أو قم برفع ملف PDF أو صورة من الأسفل"
                                style="padding: 12px 14px; font-size: 1rem; border: 2px solid #cbd5e1; border-radius: 8px; min-height: 120px; resize: vertical;"></textarea>
                        </div>

                        <!-- رفع ملفات للامتحان المستقل -->
                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-file-pdf" style="color: #ef4444;"></i> رفع ملف PDF
                                </label>
                                <div style="border: 2px dashed #cbd5e1; border-radius: 10px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.2s; background: white;"
                                    id="examPdfUploadArea" onclick="document.getElementById('examPdfInput').click()"
                                    onmouseover="this.style.borderColor='#3b82f6'; this.style.background='#eff6ff';"
                                    onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='white';">
                                    <input type="file" id="examPdfInput" accept=".pdf" style="display: none;"
                                        onchange="handleExamPdfSelect(this)">
                                    <i class="fas fa-cloud-upload-alt"
                                        style="font-size: 1.5rem; color: #94a3b8; margin-bottom: 5px; display: block;"></i>
                                    <p style="margin: 0; color: #64748b; font-size: 0.85rem;">اسحب أو <strong>انقر
                                            للاختيار</strong></p>
                                    <p style="margin: 3px 0 0 0; color: #94a3b8; font-size: 0.75rem;">PDF فقط - الحد الأقصى
                                        10MB</p>
                                </div>
                                <div id="examPdfPreview"
                                    style="display: none; margin-top: 8px; background: #f0fdf4; padding: 8px 12px; border-radius: 8px; border: 1px solid #86efac;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="color: #166534; font-size: 0.85rem; flex: 1;"><i
                                                class="fas fa-file-pdf" style="color: #ef4444;"></i> <span
                                                id="examPdfFileName"></span></span>
                                        <button type="button" onclick="removeExamFile('pdf')"
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
                                    id="examImageUploadArea" onclick="document.getElementById('examImageInput').click()"
                                    onmouseover="this.style.borderColor='#8b5cf6'; this.style.background='#f5f3ff';"
                                    onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='white';">
                                    <input type="file" id="examImageInput" accept="image/*" style="display: none;"
                                        onchange="handleExamImageSelect(this)">
                                    <i class="fas fa-cloud-upload-alt"
                                        style="font-size: 1.5rem; color: #94a3b8; margin-bottom: 5px; display: block;"></i>
                                    <p style="margin: 0; color: #64748b; font-size: 0.85rem;">اسحب أو <strong>انقر
                                            للاختيار</strong></p>
                                    <p style="margin: 3px 0 0 0; color: #94a3b8; font-size: 0.75rem;">JPG, PNG - الحد الأقصى
                                        5MB</p>
                                </div>
                                <div id="examImagePreview"
                                    style="display: none; margin-top: 8px; background: #f5f3ff; padding: 8px 12px; border-radius: 8px; border: 1px solid #c4b5fd;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="color: #5b21b6; font-size: 0.85rem; flex: 1;"><i class="fas fa-image"
                                                style="color: #8b5cf6;"></i> <span id="examImageFileName"></span></span>
                                        <button type="button" onclick="removeExamFile('image')"
                                            style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 1rem; flex-shrink: 0;"><i
                                                class="fas fa-times-circle"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <!-- Modern Integrated Settings Layout -->
                    <div class="modern-settings-container mb-4">

                        <!-- Section 1: Main Exam Parameters -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark fs-7" for="saExamDuration">
                                    <i class="fas fa-stopwatch text-primary me-1"></i> مدة الامتحان
                                </label>
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control text-center fw-bold" id="saExamDuration" name="sa_exam_duration" value="0" min="1" disabled style="opacity: 0.6; border-radius: 0 8px 8px 0 !important;">
                                    <span class="input-group-text bg-light border-start-0" style="border-radius: 8px 0 0 8px !important;">
                                        <label class="d-flex align-items-center gap-1 mb-0 cursor-pointer user-select-none">
                                            <input type="checkbox" id="saUnlimitedTime" checked onchange="toggleUnlimitedTime('saExamDuration', 'saUnlimitedTime')" class="form-check-input mt-0 me-1">
                                            <span class="fw-bold text-primary fs-8">∞ مفتوح</span>
                                        </label>
                                    </span>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark fs-7" for="saExamModels">
                                    <i class="fas fa-copy text-purple me-1" style="color: #8b5cf6;"></i> عدد النماذج
                                </label>
                                <select class="form-select form-select-sm fw-semibold" id="saExamModels" name="sa_exam_models" style="border-radius: 8px;">
                                    <option value="1">نموذج واحد (A)</option>
                                    <option value="2">نموذجان (A, B)</option>
                                    <option value="3" selected>3 نماذج (A, B, C)</option>
                                    <option value="4">4 نماذج (A, B, C, D)</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark fs-7" for="saModelType">
                                    <i class="fas fa-shuffle text-indigo me-1" style="color: #6366f1;"></i> طريقة توزيع النماذج
                                </label>
                                <select class="form-select form-select-sm fw-semibold" id="saModelType" name="sa_model_type" style="border-radius: 8px;">
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
                                        <input type="number" class="form-control form-control-sm qcount-input" id="saMcCount" name="sa_mc_count" value="10" min="0" max="999">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="qcount-stepper tf">
                                        <div class="qcount-info">
                                            <i class="fas fa-check-double text-success"></i>
                                            <span>صح وخطأ</span>
                                        </div>
                                        <input type="number" class="form-control form-control-sm qcount-input" id="saTfCount" name="sa_tf_count" value="10" min="0" max="999">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="qcount-stepper essay">
                                        <div class="qcount-info">
                                            <i class="fas fa-pen-fancy text-purple" style="color: #8b5cf6;"></i>
                                            <span>أسئلة مقالية</span>
                                        </div>
                                        <input type="number" class="form-control form-control-sm qcount-input" id="saEssayCount" name="sa_essay_count" value="0" min="0" max="999">
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
                                                <input type="checkbox" id="saStudentInfoEnabled" name="sa_student_info" value="1" checked>
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
                                                <input type="checkbox" id="saAntiCheatEnabled" name="sa_anti_cheat" value="1">
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
                                                <input type="checkbox" id="saAnswerKeyEnabled" name="sa_answer_key" value="1" checked>
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
                                    <input type="radio" name="sa_exam_theme" value="classic" checked>
                                    <div class="theme-palette-card">
                                        <span class="swatch classic-swatch"></span>
                                        <span class="theme-name">كلاسيكي</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="sa_exam_theme" value="ocean">
                                    <div class="theme-palette-card">
                                        <span class="swatch ocean-swatch"></span>
                                        <span class="theme-name">المحيط</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="sa_exam_theme" value="nature">
                                    <div class="theme-palette-card">
                                        <span class="swatch nature-swatch"></span>
                                        <span class="theme-name">طبيعي</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="sa_exam_theme" value="sunset">
                                    <div class="theme-palette-card">
                                        <span class="swatch sunset-swatch"></span>
                                        <span class="theme-name">الغروب</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="sa_exam_theme" value="rose">
                                    <div class="theme-palette-card">
                                        <span class="swatch rose-swatch"></span>
                                        <span class="theme-name">وردي</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="sa_exam_theme" value="dark">
                                    <div class="theme-palette-card">
                                        <span class="swatch dark-swatch"></span>
                                        <span class="theme-name">داكن</span>
                                    </div>
                                </label>
                                <label class="theme-palette-item">
                                    <input type="radio" name="sa_exam_theme" value="royal">
                                    <div class="theme-palette-card">
                                        <span class="swatch royal-swatch"></span>
                                        <span class="theme-name">ملكي</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                    </div>

                        <!-- زر توليد الامتحان المستقل -->
                        <button type="button" id="generateExamOnlyBtn" onclick="submitStandaloneExam()"
                            style="width: 100%; padding: 12px; background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: 'Cairo', sans-serif; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;">
                            <i class="fas fa-bolt"></i> توليد الامتحان فقط
                        </button>
                    </div>
                </div>
            </div><!-- /tabExam -->

            <!-- ===== Tab 3: توليد بنك أسئلة مستقل ===== -->
            <div class="main-tab-panel" id="tabQbank">
                <div class="content-card" id="standaloneQBSection">
                    <!-- عنوان القسم -->
                    <div
                        style="background: linear-gradient(135deg, #f5f3ff, #ede9fe); padding: 12px 18px; border-radius: 12px; margin-bottom: 14px; border: 1px solid #c4b5fd; display: flex; align-items: center; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0;">
                            <i class="fas fa-database" style="color: #8b5cf6; font-size: 1.2rem; flex-shrink: 0;"></i>
                            <h4 style="color: #5b21b6; margin: 0; font-size: 1rem; font-weight: 700;">توليد بنك أسئلة مستقل
                            </h4>
                            <span style="color: #6d28d9; font-size: 0.78rem; opacity: 0.8;">— بدون الحاجة لتوليد تحضير درس
                                أو امتحان</span>
                        </div>
                    </div>

                    <!-- محتوى بنك الأسئلة المستقل -->
                    <div id="standaloneQBContent">
                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 12px; margin-top: 4px;">
                            <div class="form-group" style="flex: 2; min-width: 220px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-heading" style="color: #8b5cf6;"></i> عنوان بنك الأسئلة <span
                                        style="color: #ef4444;">*</span>
                                </label>
                                <input type="text" class="form-control" id="qbStandaloneTitle" name="qb_title"
                                    placeholder="أدخل عنوان بنك الأسئلة"
                                    style="padding: 10px 14px; font-size: 1rem; border: 2px solid #cbd5e1; border-radius: 8px;">
                            </div>
                            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-graduation-cap" style="color: #8b5cf6;"></i> الصف الدراسي
                                </label>
                                <select class="form-select" id="sqbGradeLevel" name="sqb_grade_level"
                                    style="padding: 10px 14px; font-size: 1rem; border: 2px solid #cbd5e1; border-radius: 8px;">
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
                            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-language" style="color: #8b5cf6;"></i> لغة بنك الأسئلة
                                </label>
                                <select class="form-select" id="sqbLanguage"
                                    style="padding: 10px 14px; font-size: 1rem; border: 2px solid #cbd5e1; border-radius: 8px;">
                                    <option value="ar" selected>العربية</option>
                                    <option value="en">English</option>
                                    <option value="fr">Français</option>
                                    <option value="de">Deutsch</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                <i class="fas fa-file-alt" style="color: #6366f1;"></i> المحتوى التعليمي <span
                                    style="color: #ef4444;">*</span>
                            </label>
                            <textarea class="form-control" id="qbStandaloneContent" rows="5" placeholder="اكتب أو الصق المحتوى التعليمي الذي تريد توليد بنك الأسئلة بناءً عليه...
أو قم برفع ملف PDF أو صورة من الأسفل"
                                style="padding: 12px 14px; font-size: 1rem; border: 2px solid #cbd5e1; border-radius: 8px; min-height: 120px; resize: vertical;"></textarea>
                        </div>

                        <!-- رفع ملفات لبنك الأسئلة المستقل -->
                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-file-pdf" style="color: #ef4444;"></i> رفع ملف PDF
                                </label>
                                <div style="border: 2px dashed #cbd5e1; border-radius: 10px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.2s; background: white;"
                                    id="qbPdfUploadArea" onclick="document.getElementById('qbPdfInput').click()"
                                    onmouseover="this.style.borderColor='#8b5cf6'; this.style.background='#f5f3ff';"
                                    onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='white';">
                                    <input type="file" id="qbPdfInput" accept=".pdf" style="display: none;"
                                        onchange="handleQBPdfSelect(this)">
                                    <i class="fas fa-cloud-upload-alt"
                                        style="font-size: 1.5rem; color: #94a3b8; margin-bottom: 5px; display: block;"></i>
                                    <p style="margin: 0; color: #64748b; font-size: 0.85rem;">اسحب أو <strong>انقر
                                            للاختيار</strong></p>
                                    <p style="margin: 3px 0 0 0; color: #94a3b8; font-size: 0.75rem;">PDF فقط - الحد الأقصى
                                        10MB</p>
                                </div>
                                <div id="qbPdfPreview"
                                    style="display: none; margin-top: 8px; background: #f0fdf4; padding: 8px 12px; border-radius: 8px; border: 1px solid #86efac;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="color: #166534; font-size: 0.85rem; flex: 1;"><i
                                                class="fas fa-file-pdf" style="color: #ef4444;"></i> <span
                                                id="qbPdfFileName"></span></span>
                                        <button type="button" onclick="removeQBFile('pdf')"
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
                                    id="qbImageUploadArea" onclick="document.getElementById('qbImageInput').click()"
                                    onmouseover="this.style.borderColor='#8b5cf6'; this.style.background='#f5f3ff';"
                                    onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='white';">
                                    <input type="file" id="qbImageInput" accept="image/*" style="display: none;"
                                        onchange="handleQBImageSelect(this)">
                                    <i class="fas fa-cloud-upload-alt"
                                        style="font-size: 1.5rem; color: #94a3b8; margin-bottom: 5px; display: block;"></i>
                                    <p style="margin: 0; color: #64748b; font-size: 0.85rem;">اسحب أو <strong>انقر
                                            للاختيار</strong></p>
                                    <p style="margin: 3px 0 0 0; color: #94a3b8; font-size: 0.75rem;">JPG, PNG - الحد الأقصى
                                        5MB</p>
                                </div>
                                <div id="qbImagePreview"
                                    style="display: none; margin-top: 8px; background: #f5f3ff; padding: 8px 12px; border-radius: 8px; border: 1px solid #c4b5fd;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="color: #5b21b6; font-size: 0.85rem; flex: 1;"><i class="fas fa-image"
                                                style="color: #8b5cf6;"></i> <span id="qbImageFileName"></span></span>
                                        <button type="button" onclick="removeQBFile('image')"
                                            style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 1rem; flex-shrink: 0;"><i
                                                class="fas fa-times-circle"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- زر توليد بنك الأسئلة المستقل -->
                        <button type="button" id="generateQBOnlyBtn" onclick="submitStandaloneQB()"
                            style="width: 100%; padding: 12px; background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: 'Cairo', sans-serif; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;">
                            <i class="fas fa-database"></i> توليد بنك الأسئلة فقط
                        </button>
                    </div>
                </div>
            </div><!-- /tabQbank -->

            <!-- ===== Tab 4: توليد عرض تقديمي مستقل ===== -->
            <div class="main-tab-panel" id="tabPpt">
                <div class="content-card" id="standalonePptSection">
                    <!-- عنوان القسم -->
                    <div
                        style="background: linear-gradient(135deg, #eef2ff, #e0e7ff); padding: 12px 18px; border-radius: 12px; margin-bottom: 14px; border: 1px solid #c7d2fe; display: flex; align-items: center; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0;">
                            <i class="fas fa-file-powerpoint"
                                style="color: #6366f1; font-size: 1.2rem; flex-shrink: 0;"></i>
                            <h4 style="color: #4338ca; margin: 0; font-size: 1rem; font-weight: 700;">توليد عرض تقديمي
                                PowerPoint مستقل</h4>
                            <span style="color: #4338ca; font-size: 0.78rem; opacity: 0.8;">— بدون الحاجة لتوليد تحضير درس
                                كامل</span>
                        </div>
                    </div>

                    <!-- نموذج العرض التقديمي المستقل -->
                    <div id="standalonePptContent">
                        <!-- عنوان الموضوع ولغة العرض بجانبه -->
                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 12px; margin-top: 4px;">
                            <div class="form-group" style="flex: 2; min-width: 220px; margin-bottom: 0;">
                                <label class="form-label"
                                    style="font-weight: 700; font-size: 0.95rem; color: #1e3a5f; display: block; margin-bottom: 6px;">
                                    <i class="fas fa-heading" style="color: #6366f1; margin-left: 5px;"></i>
                                    عنوان العرض التقديمي <span style="color: #ef4444;">*</span>
                                </label>
                                <input type="text" id="pptTitle" placeholder="مثال: الخلية النباتية وتركيبها"
                                    style="width: 100%; padding: 10px 14px; border: 2px solid #cbd5e1; border-radius: 10px; font-size: 0.95rem; font-family: 'Cairo', sans-serif; transition: all 0.2s;"
                                    onfocus="this.style.borderColor='#6366f1'; this.style.boxShadow='0 0 0 3px rgba(99,102,241,0.15)'"
                                    onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'">
                            </div>
                            <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                <label class="form-label"
                                    style="font-weight: 700; font-size: 0.95rem; color: #1e3a5f; display: block; margin-bottom: 6px;">
                                    <i class="fas fa-graduation-cap" style="color: #6366f1; margin-left: 4px;"></i> الصف الدراسي
                                </label>
                                <select id="pptGradeLevelStandalone" class="form-select"
                                    style="width: 100%; padding: 10px 14px; border: 2px solid #cbd5e1; border-radius: 10px; font-size: 0.95rem; font-family: 'Cairo', sans-serif; background-color: #fff;">
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
                            <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                <label class="form-label"
                                    style="font-weight: 700; font-size: 0.95rem; color: #1e3a5f; display: block; margin-bottom: 6px;">
                                    <i class="fas fa-language" style="color: #10b981; margin-left: 4px;"></i> لغة العرض
                                </label>
                                <select id="pptLanguageStandalone" class="form-select"
                                    style="width: 100%; padding: 10px 14px; border: 2px solid #cbd5e1; border-radius: 10px; font-size: 0.95rem; font-family: 'Cairo', sans-serif; background-color: #fff;">
                                    <option value="ar" selected>العربية</option>
                                    <option value="en">English</option>
                                    <option value="fr">Français</option>
                                    <option value="de">Deutsch</option>
                                </select>
                            </div>
                        </div>

                        <!-- المحتوى التعليمي -->
                        <div style="margin-bottom: 14px;">
                            <label class="form-label"
                                style="font-weight: 700; font-size: 0.95rem; color: #1e3a5f; display: block; margin-bottom: 6px;">
                                <i class="fas fa-align-right" style="color: #6366f1; margin-left: 5px;"></i>
                                المحتوى التعليمي <span style="color: #ef4444;">*</span>
                            </label>
                            <textarea id="pptContent" rows="8"
                                placeholder="اكتب أو الصق المحتوى الذي تريد تحويله إلى عرض تقديمي..."
                                style="width: 100%; padding: 12px 14px; border: 2px solid #cbd5e1; border-radius: 10px; font-size: 0.95rem; font-family: 'Cairo', sans-serif; resize: vertical; transition: all 0.2s; line-height: 1.8;"
                                onfocus="this.style.borderColor='#6366f1'; this.style.boxShadow='0 0 0 3px rgba(99,102,241,0.15)'"
                                onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'"></textarea>
                            <small style="color: #64748b; font-size: 0.8rem; display: block; margin-top: 4px;">
                                <i class="fas fa-info-circle" style="color: #6366f1;"></i>
                                يمكنك كتابة نص الدرس أو الاستعانة برفع ملفات PDF وصور من الأسفل
                            </small>
                        </div>

                        <!-- رفع ملفات للعرض التقديمي المستقل -->
                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.9rem; font-weight: 600; color: #1e3a5f;">
                                    <i class="fas fa-file-pdf" style="color: #ef4444;"></i> رفع ملف PDF
                                </label>
                                <div style="border: 2px dashed #cbd5e1; border-radius: 10px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.2s; background: white;"
                                    id="pptPdfUploadArea" onclick="document.getElementById('pptPdfInput').click()"
                                    onmouseover="this.style.borderColor='#6366f1'; this.style.background='#f5f3ff';"
                                    onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='white';">
                                    <input type="file" id="pptPdfInput" accept=".pdf" style="display: none;"
                                        onchange="handlePptPdfSelect(this)">
                                    <i class="fas fa-cloud-upload-alt"
                                        style="font-size: 1.5rem; color: #94a3b8; margin-bottom: 5px; display: block;"></i>
                                    <p style="margin: 0; color: #64748b; font-size: 0.85rem;">اسحب أو <strong>انقر
                                            للاختيار</strong></p>
                                    <p style="margin: 3px 0 0 0; color: #94a3b8; font-size: 0.75rem;">PDF فقط - الحد الأقصى
                                        10MB</p>
                                </div>
                                <div id="pptPdfPreview"
                                    style="display: none; margin-top: 8px; background: #f0fdf4; padding: 8px 12px; border-radius: 8px; border: 1px solid #86efac;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="color: #166534; font-size: 0.85rem; flex: 1;"><i
                                                class="fas fa-file-pdf" style="color: #ef4444;"></i> <span
                                                id="pptPdfFileName"></span></span>
                                        <button type="button" onclick="removePptFile('pdf')"
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
                                    id="pptImageUploadArea" onclick="document.getElementById('pptImageInput').click()"
                                    onmouseover="this.style.borderColor='#6366f1'; this.style.background='#f5f3ff';"
                                    onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='white';">
                                    <input type="file" id="pptImageInput" accept="image/*" style="display: none;"
                                        onchange="handlePptImageSelect(this)">
                                    <i class="fas fa-cloud-upload-alt"
                                        style="font-size: 1.5rem; color: #94a3b8; margin-bottom: 5px; display: block;"></i>
                                    <p style="margin: 0; color: #64748b; font-size: 0.85rem;">اسحب أو <strong>انقر
                                            للاختيار</strong></p>
                                    <p style="margin: 3px 0 0 0; color: #94a3b8; font-size: 0.75rem;">JPG, PNG - الحد الأقصى
                                        5MB</p>
                                </div>
                                <div id="pptImagePreview"
                                    style="display: none; margin-top: 8px; background: #f5f3ff; padding: 8px 12px; border-radius: 8px; border: 1px solid #c4b5fd;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="color: #5b21b6; font-size: 0.85rem; flex: 1;"><i class="fas fa-image"
                                                style="color: #8b5cf6;"></i> <span id="pptImageFileName"></span></span>
                                        <button type="button" onclick="removePptFile('image')"
                                            style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 1rem; flex-shrink: 0;"><i
                                                class="fas fa-times-circle"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- إعدادات العرض -->
                        <div
                            style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); padding: 18px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 14px;">
                            <h4
                                style="color: #475569; margin: 0 0 14px 0; font-size: 0.95rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-sliders-h" style="color: #6366f1;"></i> إعدادات العرض التقديمي
                            </h4>
                            <div
                                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                                <!-- قالب العرض -->
                                <div>
                                    <label class="form-label"
                                        style="font-size: 0.85rem; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">
                                        <i class="fas fa-palette" style="color: #8b5cf6; margin-left: 4px;"></i> قالب العرض
                                    </label>
                                    <select id="pptThemeStandalone" class="form-select"
                                        style="font-size: 0.9rem; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #fff; width: 100%;">
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

                                <!-- عدد الشرائح -->
                                <div>
                                    <label class="form-label"
                                        style="font-size: 0.85rem; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">
                                        <i class="fas fa-layer-group" style="color: #0ea5e9; margin-left: 4px;"></i> عدد
                                        الشرائح
                                    </label>
                                    <input id="pptSlidesStandalone" type="number" class="form-control" value="12" min="4"
                                        max="30"
                                        style="font-size: 0.9rem; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #fff; width: 100%;">
                                </div>

                                <?php if (!empty($canvaTemplates)): ?>
                                <!-- قالب Canva -->
                                <div>
                                    <label class="form-label"
                                        style="font-size: 0.85rem; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">
                                        <i class="fas fa-palette" style="color: #8b3dff; margin-left: 4px;"></i> قالب Canva
                                    </label>
                                    <select id="pptCanvaTemplateStandalone"
                                        onchange="syncPptTemplateChoice('standalone', 'canva')"
                                        style="font-size: 0.9rem; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #fff; width: 100%;">
                                        <option value="">— بدون قالب Canva —</option>
                                        <?php foreach ($canvaTemplates as $tpl): ?>
                                        <option value="<?= (int)$tpl['id'] ?>" <?= $tpl['is_active'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tpl['name'] ?? $tpl['design_id'], ENT_QUOTES, 'UTF-8') ?>
                                            <?= (($tpl['template_type'] ?? 'design') === 'brand_template') ? ' - Canva Autofill' : '' ?>
                                            <?= $tpl['is_active'] ? ' ⭐' : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small style="color: #8b3dff; font-size: 0.75rem; display: block; margin-top: 3px;">
                                        <i class="fas fa-info-circle"></i> سيُستخدم غلاف وختام هذا القالب في الباوربوينت
                                    </small>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($internalPptTemplates)): ?>
                                <!-- قالب من مكتبة EduCore -->
                                <div>
                                    <label class="form-label"
                                        style="font-size: 0.85rem; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">
                                        <i class="fas fa-file-powerpoint" style="color: #dc2626; margin-left: 4px;"></i> قالب من مكتبة EduCore
                                    </label>
                                    <select id="pptInternalTemplateStandalone"
                                        onchange="syncPptTemplateChoice('standalone', 'internal')"
                                        style="font-size: 0.9rem; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #fff; width: 100%;">
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
                                        <i class="fas fa-info-circle"></i> عند تركه تلقائيًا سيختار النظام أفضل قالب حسب الدرس. اختيار قالب من المكتبة يلغي Canva.
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>


                    </div>

                    <!-- زر التوليد -->
                    <button type="button" id="generatePptBtn" onclick="generateStandalonePPT()"
                        style="width: 100%; padding: 14px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: 'Cairo', sans-serif; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;"
                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(99,102,241,0.4)'"
                        onmouseout="this.style.transform=''; this.style.boxShadow=''">
                        <i class="fas fa-file-powerpoint"></i> توليد العرض التقديمي فقط
                    </button>
                </div>
            </div><!-- /tabPpt -->

            <!-- Results Section -->
            <div class="results-section" id="resultsSection">
                <div class="content-card">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                        <h2 class="card-title" style="margin-bottom: 0;">
                            <i class="fas fa-check-circle"></i> نتائج التوليد
                        </h2>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn-quick-copy" onclick="exportFullLessonPdf()" title="تصدير الدرس كاملاً PDF">
                                <i class="fas fa-file-pdf"></i> تصدير كامل PDF
                            </button>
                            <button class="btn-quick-copy" onclick="shareLesson()" title="مشاركة">
                                <i class="fas fa-share-alt"></i> مشاركة
                            </button>
                            <button class="btn-quick-copy" onclick="copyShareLink()" title="نسخ الرابط">
                                <i class="fas fa-link"></i> نسخ الرابط
                            </button>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="tabs-container">
                        <button class="tab-btn active" data-tab="lessonPlan">
                            <i class="fas fa-clipboard-list"></i> تحضير الدرس
                        </button>
                        <button class="tab-btn" data-tab="visualMaterials">
                            <i class="fas fa-images"></i> المواد البصرية
                        </button>
                        <button class="tab-btn" data-tab="mindMaps">
                            <i class="fas fa-project-diagram"></i> الخرائط الذهنية
                        </button>
                        <button class="tab-btn" data-tab="questionBank">
                            <i class="fas fa-question-circle"></i> بنك الأسئلة
                        </button>
                        <button class="tab-btn" data-tab="classActivities">
                            <i class="fas fa-puzzle-piece"></i> أنشطة صفية
                        </button>
                        <button class="tab-btn" data-tab="educationalStories">
                            <i class="fas fa-book-open"></i> القصة التربوية
                        </button>
                        <button class="tab-btn" data-tab="customContent" id="customContentTab" style="display:none;">
                            <i class="fas fa-magic"></i> محتوى مخصص
                        </button>
                        <button class="tab-btn" data-tab="powerPointPreview" id="powerPointTab" style="display:none;">
                            <i class="fas fa-file-powerpoint"></i> العرض التقديمي
                        </button>
                        <button class="tab-btn" data-tab="examPreview">
                            <i class="fas fa-file-alt"></i> الامتحان الإلكتروني
                        </button>
                        <button class="tab-btn" data-tab="lessonSummary">
                            <i class="fas fa-file-lines"></i> ملخص الدرس
                        </button>
                        <button class="tab-btn" data-tab="exportLesson">
                            <i class="fas fa-file-export"></i> مشاركة وتصدير الدرس
                        </button>
                    </div>

                    <!-- Tab Contents -->
                    <div class="tab-content active" id="lessonPlan">
                        <div id="lessonPlanContent"></div>
                    </div>

                    <div class="tab-content" id="visualMaterials">
                        <div id="visualMaterialsContent"></div>
                    </div>

                    <div class="tab-content" id="mindMaps">
                        <div id="mindMapsContent"></div>
                    </div>

                    <div class="tab-content" id="questionBank">
                        <div id="questionBankContent"></div>
                    </div>

                    <div class="tab-content" id="classActivities">
                        <div id="classActivitiesContent"></div>
                    </div>

                    <div class="tab-content" id="lessonSummary">
                        <div id="lessonSummaryContent"></div>
                    </div>

                    <div class="tab-content" id="educationalStories">
                        <div id="educationalStoriesContent"></div>
                    </div>

                    <div class="tab-content" id="customContent">
                        <div id="customContentArea"></div>
                    </div>

                    <div class="tab-content" id="examPreview">
                        <div id="examPreviewContent">
                            <div class="section-header-actions">
                                <h3 class="section-title" style="margin-bottom:0"><i class="fas fa-file-alt"></i> الامتحان
                                    الإلكتروني</h3>
                                <div style="display:flex;gap:8px;">
                                    <button class="btn-quick-copy" onclick="quickCopySection('examPreviewContent')"
                                        title="نسخ سريع"><i class="fas fa-copy"></i> نسخ</button>
                                    <button class="btn-regenerate-section" onclick="regenerateSection('exam')"
                                        title="إعادة توليد"><i class="fas fa-sync-alt"></i> إعادة توليد</button>
                                </div>
                            </div>
                            <div style="text-align: center; padding: 40px;">
                                <i class="fas fa-file-code"
                                    style="font-size: 4rem; color: #8b5cf6; margin-bottom: 20px;"></i>
                                <h3 style="color: #1e293b; margin-bottom: 15px;">الامتحان الإلكتروني جاهز</h3>
                                <p style="color: #64748b; margin-bottom: 25px;">ملف HTML مستقل يعمل بدون إنترنت ويحتوي على
                                    جميع مميزات منع الغش</p>

                                <!-- قسم النماذج المتاحة -->
                                <div id="examModelsSection"
                                    style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); padding: 25px; border-radius: 15px; margin-bottom: 25px; border: 2px solid #e2e8f0;">
                                    <h4 style="color: #475569; margin-bottom: 20px; font-size: 1.1rem;">
                                        <i class="fas fa-copy" style="color: #8b5cf6;"></i> النماذج المتاحة للتحميل
                                    </h4>
                                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">
                                        كل نموذج يحتوي على نفس الأسئلة ولكن بترتيب مختلف لمنع الغش
                                    </p>
                                    <div id="modelButtonsContainer"
                                        style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                                        <!-- سيتم ملء الأزرار ديناميكياً -->
                                    </div>
                                </div>

                                <!-- أزرار تحميل نموذج الإجابة -->
                                <div id="answerKeySection"
                                    style="display: none; background: linear-gradient(135deg, #fefce8, #fef9c3); padding: 25px; border-radius: 15px; margin-bottom: 25px; border: 2px solid #eab308;">
                                    <h4 style="color: #854d0e; margin-bottom: 15px; font-size: 1.1rem;">
                                        <i class="fas fa-key" style="color: #eab308;"></i> نماذج الإجابة
                                    </h4>
                                    <p style="color: #92400e; font-size: 0.9rem; margin-bottom: 15px;">تحميل نموذج إجابة
                                        يحتوي على جميع الأسئلة والإجابات الصحيحة</p>
                                    <div id="answerKeyButtonsContainer"
                                        style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                                    </div>
                                </div>


                                <!-- قسم رابط الامتحان الأونلاين -->
                                <div id="onlineExamLink"
                                    style="display: none; background: linear-gradient(135deg, #f0fdf4, #dcfce7); padding: 25px; border-radius: 15px; border: 2px solid #22c55e; margin-top: 20px;">
                                    <h4 style="color: #15803d; margin-bottom: 15px;">
                                        <i class="fas fa-check-circle"></i> تم نشر الامتحان بنجاح!
                                    </h4>
                                    <p style="color: #166534; margin-bottom: 15px;">شارك هذا الرابط مع طلابك:</p>
                                    <div
                                        style="display: flex; gap: 10px; align-items: center; justify-content: center; flex-wrap: wrap;">
                                        <input type="text" id="examLinkInput" readonly
                                            style="flex: 1; min-width: 250px; padding: 12px 15px; border: 2px solid #22c55e; border-radius: 10px; font-size: 0.95rem; background: white; direction: ltr; text-align: center;">
                                        <button onclick="copyExamLink(event)" class="btn-export"
                                            style="background: linear-gradient(135deg, #3b82f6, #2563eb); padding: 12px 20px;">
                                            <i class="fas fa-copy"></i> نسخ
                                        </button>
                                        <a id="viewResultsLink" href="#" target="_blank" class="btn-export"
                                            style="background: linear-gradient(135deg, #f59e0b, #d97706); padding: 12px 20px; text-decoration: none;">
                                            <i class="fas fa-chart-bar"></i> النتائج
                                        </a>
                                    </div>
                                    <p style="color: #166534; font-size: 0.85rem; margin-top: 15px;">
                                        <i class="fas fa-info-circle"></i> يمكن للطلاب فتح الرابط وإدخال بياناتهم لبدء
                                        الامتحان
                                    </p>
                                    <!-- QR Code Container -->
                                    <div id="examQRCodeContainer" style="margin-top: 20px; text-align: center;">
                                        <h5 style="color: #15803d; margin-bottom: 10px;"><i class="fas fa-qrcode"></i> رمز
                                            QR للامتحان</h5>
                                        <div id="examQRCode"
                                            style="display: inline-block; background: white; padding: 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                                        </div>
                                        <p style="color: #166534; font-size: 0.8rem; margin-top: 8px;"><i
                                                class="fas fa-mobile-alt"></i> يمكن للطلاب مسح الرمز بالجوال لفتح الامتحان
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Export Lesson Tab -->
                    <div class="tab-content" id="powerPointPreview">
                        <div id="powerPointPreviewContent" style="text-align: center; padding: 30px;">
                            <i class="fas fa-file-powerpoint"
                                style="font-size: 4rem; color: #6366f1; margin-bottom: 20px;"></i>
                            <h3 style="color: #1e293b; margin-bottom: 10px;">العرض التقديمي PowerPoint</h3>
                            <p style="color: #64748b; margin-bottom: 20px;">سيظهر العرض التقديمي هنا بعد توليد الدرس مع
                                تفعيل خيار PowerPoint</p>
                        </div>
                    </div>

                    <div class="tab-content" id="exportLesson">
                        <div style="padding: 10px 0;">
                            <?php
                            $lessonShareLessonId = 0;
                            require __DIR__ . '/share_panel.php';
                            ?>

                            <!-- اختيار العناصر والتصدير المباشر -->
                            <div class="settings-subcard text-start mb-3" style="background: #ffffff !important; border: 1px solid #cbd5e1 !important; border-radius: 12px !important; padding: 18px 20px !important;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                    <h4 style="color: #1e293b; margin: 0; font-size: 0.95rem; font-weight: 700;">
                                        <i class="fas fa-check-double text-primary me-1"></i> اختيار عناصر التصدير
                                    </h4>
                                    <button type="button" class="btn btn-outline-primary btn-sm px-3 fw-semibold shadow-sm" onclick="toggleAllExportElements()" id="exportToggleAllBtn" style="font-size: 0.8rem; border-radius: 6px;">
                                        <i class="fas fa-times-circle me-1"></i> إلغاء تحديد الكل
                                    </button>
                                </div>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; margin-bottom: 16px;">
                                    <label class="export-element-checkbox-label" id="exportEl_lessonPlan">
                                        <input type="checkbox" name="export_elements[]" value="lessonPlanContent" checked class="export-element-checkbox">
                                        <i class="fas fa-clipboard-list" style="color: #10b981;"></i>
                                        <span>تحضير الدرس</span>
                                    </label>
                                    <label class="export-element-checkbox-label" id="exportEl_questionBank">
                                        <input type="checkbox" name="export_elements[]" value="questionBankContent" checked class="export-element-checkbox">
                                        <i class="fas fa-question-circle" style="color: #f59e0b;"></i>
                                        <span>بنك الأسئلة</span>
                                    </label>
                                    <label class="export-element-checkbox-label" id="exportEl_visualMaterials">
                                        <input type="checkbox" name="export_elements[]" value="visualMaterialsContent" checked class="export-element-checkbox">
                                        <i class="fas fa-images" style="color: #8b5cf6;"></i>
                                        <span>المواد البصرية</span>
                                    </label>
                                    <label class="export-element-checkbox-label" id="exportEl_classActivities">
                                        <input type="checkbox" name="export_elements[]" value="classActivitiesContent" checked class="export-element-checkbox">
                                        <i class="fas fa-puzzle-piece" style="color: #ef4444;"></i>
                                        <span>الأنشطة الصفية</span>
                                    </label>
                                    <label class="export-element-checkbox-label" id="exportEl_mindMaps">
                                        <input type="checkbox" name="export_elements[]" value="mindMapsContent" checked class="export-element-checkbox">
                                        <i class="fas fa-project-diagram" style="color: #06b6d4;"></i>
                                        <span>الخرائط الذهنية</span>
                                    </label>
                                    <label class="export-element-checkbox-label" id="exportEl_lessonSummary">
                                        <input type="checkbox" name="export_elements[]" value="lessonSummaryContent" checked class="export-element-checkbox">
                                        <i class="fas fa-file-lines" style="color: #8b5cf6;"></i>
                                        <span>ملخص الدرس</span>
                                    </label>
                                    <label class="export-element-checkbox-label" id="exportEl_educationalStories">
                                        <input type="checkbox" name="export_elements[]" value="educationalStoriesContent" checked class="export-element-checkbox">
                                        <i class="fas fa-book-open" style="color: #ec4899;"></i>
                                        <span>القصة التربوية</span>
                                    </label>
                                    <label class="export-element-checkbox-label" id="exportEl_customContent">
                                        <input type="checkbox" name="export_elements[]" value="customContentArea" checked class="export-element-checkbox">
                                        <i class="fas fa-magic" style="color: #10b981;"></i>
                                        <span>محتوى مخصص</span>
                                    </label>
                                    <label class="export-element-checkbox-label" id="exportEl_exam">
                                        <input type="checkbox" name="export_elements[]" value="exam" checked class="export-element-checkbox">
                                        <i class="fas fa-file-alt" style="color: #8b5cf6;"></i>
                                        <span>الامتحان</span>
                                    </label>
                                </div>

                                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                                    <button type="button" class="btn-export btn-export-html" onclick="exportSelectedToHtml()">
                                        <i class="fas fa-code me-1"></i> تصدير HTML
                                    </button>
                                    <button type="button" class="btn-export btn-export-pdf" onclick="exportSelectedToPdf()">
                                        <i class="fas fa-file-pdf me-1"></i> تصدير PDF
                                    </button>
                                    <button type="button" class="btn-export btn-export-word" onclick="exportSelectedToWord()">
                                        <i class="fas fa-file-word me-1"></i> تصدير Word
                                    </button>
                                    <button type="button" class="btn-export btn-export-print" onclick="exportSelectedToPrint()">
                                        <i class="fas fa-print me-1"></i> طباعة المحدد
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Export Section (Bottom bar) -->
                    <div class="export-section" id="dynamicExportSection">
                        <button class="btn-export btn-export-html" id="exportHtmlBtn" onclick="exportAllToHtml()">
                            <i class="fas fa-code"></i> تصدير HTML
                        </button>
                        <button class="btn-export btn-export-pdf" id="exportPdfBtn" onclick="exportAllToPdf()">
                            <i class="fas fa-file-pdf"></i> تصدير PDF
                        </button>
                        <button class="btn-export btn-export-word" id="exportWordBtn" onclick="exportAllToWord()">
                            <i class="fas fa-file-word"></i> تصدير Word
                        </button>
                        <button class="btn-export btn-export-exam" id="downloadExamBtn2" onclick="downloadAllModels()">
                            <i class="fas fa-download"></i> تحميل الامتحان
                        </button>
                    </div>
                </div>
            </div>
