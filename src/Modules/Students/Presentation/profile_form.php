        <?php if ($page_action === 'add' || $page_action === 'edit'):
            // ========================= عرض نموذج الإضافة/التعديل بالتبويبات =========================
            $sp = $studentProfile ?: [];
            $isEditing = ($page_action === 'edit' && $editStudent);
            $studentProfilePendingMode = $studentProfilePendingMode ?? false;

            // استرجاع البيانات المُدخلة سابقًا في حال فشل التحقق عند الحفظ (لتفادي فقدان بيانات النموذج بعد إعادة التوجيه)
            $oldFormInput = null;
            if (!empty($_SESSION['student_form_old_input']) && is_array($_SESSION['student_form_old_input'])) {
                $oldCandidate = $_SESSION['student_form_old_input'];
                $oldEditId = !empty($oldCandidate['edit_user_id']) ? (int) $oldCandidate['edit_user_id'] : 0;
                $currentEditId = $isEditing ? (int) $editStudent->id : 0;
                if (($isEditing && $oldEditId > 0 && $oldEditId === $currentEditId) || (!$isEditing && $oldEditId === 0)) {
                    $oldFormInput = $oldCandidate;
                }
                unset($_SESSION['student_form_old_input']);
            }

            if ($oldFormInput) {
                $sp = array_merge($sp, $oldFormInput);
                if (!empty($oldFormInput['guardians']) && is_array($oldFormInput['guardians'])) {
                    $studentGuardians = $oldFormInput['guardians'];
                }
                // إعادة بناء الأرقام والبيانات الإضافية للطالب من المدخلات القديمة بنفس صيغة التخزين المعتادة
                $oldStudentExtraPhones = json_decode(build_student_extra_phones($oldFormInput) ?? 'null', true);
                if ($oldStudentExtraPhones !== null) {
                    $editExtraPhones = $oldStudentExtraPhones;
                }
                $oldStudentExtraData = json_decode(build_student_extra_data($oldFormInput) ?? 'null', true);
                if ($oldStudentExtraData !== null) {
                    $editExtraData = $oldStudentExtraData;
                }
                // إعادة بناء الأرقام والبيانات الإضافية لكل ولي أمر
                if (!empty($oldFormInput['guardians']) && is_array($oldFormInput['guardians'])) {
                    foreach ($oldFormInput['guardians'] as $gi => $gOld) {
                        $gOldPhones = json_decode(build_guardian_extra_phones($gOld) ?? 'null', true);
                        if ($gOldPhones !== null) {
                            $guardianExtraPhones[$gi] = $gOldPhones;
                        }
                        $gOldData = json_decode(build_guardian_extra_data($gOld) ?? 'null', true);
                        if ($gOldData !== null) {
                            $guardianExtraData[$gi] = $gOldData;
                        }
                    }
                }
                // إعادة تعبئة بيانات النقل الخارجي إن وُجدت
                if (isset($oldFormInput['transfer_destination']) || isset($oldFormInput['external_transfer_date'])) {
                    $studentExternalTransfer = [
                        'destination' => $oldFormInput['transfer_destination'] ?? ($studentExternalTransfer['destination'] ?? ''),
                        'transfer_date' => $oldFormInput['external_transfer_date'] ?? ($studentExternalTransfer['transfer_date'] ?? ''),
                        'reason' => $oldFormInput['external_transfer_reason'] ?? ($studentExternalTransfer['reason'] ?? ''),
                        'notes' => $oldFormInput['external_transfer_notes'] ?? ($studentExternalTransfer['notes'] ?? ''),
                    ];
                }
            }

            // إذا لم يكن هناك ملف تفصيلي للطالب، نقوم بتقسيم الاسم من جدول المستخدمين كقيم افتراضية
            if ($isEditing && empty($sp['first_name_ar']) && !empty($editStudent->name)) {
                $sp = array_merge($sp, User::splitDisplayName((string) $editStudent->name));
            }
            if (empty($sp['educational_guardianship']) && !empty($educationalGuardianship)) {
                $sp['educational_guardianship'] = $educationalGuardianship;
            }
            $formUserId = $isEditing ? $editStudent->id : '';
            $currentAnnualEnrollment = $studentCurrentEnrollment ?? [];
            $formClassId = $oldFormInput['class_id']
                ?? ($currentAnnualEnrollment['class_id']
                    ?? ($isEditing ? $editStudent->class_id : ($filter_class_id ?? '')));

            // تحديد الصف الحالي مسبقًا: من المدخلات القديمة (عند فشل تحقق سابق)، وإلا من الصف المحفوظ فعليًا للطالب،
            // وإلا (لسجلات قديمة قبل إضافة عمود grade_id) من فصل الطالب الحالي كحل بديل
            $formGradeId = $oldFormInput['grade_id']
                ?? ($oldFormInput['graduate_grade_id']
                    ?? ($currentAnnualEnrollment['grade_id'] ?? ($sp['grade_id'] ?? '')));
            if ($formGradeId === '' && $formClassId) {
                foreach ($classes as $cl) {
                    if ($cl['id'] == $formClassId) {
                        $formGradeId = $cl['grade_id'];
                        break;
                    }
                }
            }
            $formStageId = $oldFormInput['stage_id'] ?? ($currentAnnualEnrollment['stage_id'] ?? '');
            if ($formStageId === '' && $formGradeId !== '') {
                foreach ($scopeGrades as $scopeGrade) {
                    if ((int) $scopeGrade['id'] === (int) $formGradeId) {
                        $formStageId = $scopeGrade['stage_id'] ?? '';
                        break;
                    }
                }
            }
            $defaultEnrollmentStatus = $studentDataScope === 'transferred' ? 'transferred' : 'enrolled';
            $formEnrollmentStatus = $oldFormInput['enrollment_status']
                ?? ($currentAnnualEnrollment['enrollment_status'] ?? $defaultEnrollmentStatus);
            if ($formEnrollmentStatus === 'graduated') {
                $formEnrollmentStatus = 'enrolled';
            } elseif ($formEnrollmentStatus === 'withdrawn') {
                $formEnrollmentStatus = 'discontinued';
            }
            $defaultAcademicStatus = $studentDataScope === 'graduates' ? 'graduated' : 'new';
            $formAcademicStatus = $oldFormInput['academic_status']
                ?? ($currentAnnualEnrollment['academic_status'] ?? $defaultAcademicStatus);

            // Labels
            $genderLabels = ['male' => 'ذكر', 'female' => 'أنثى'];
            $religionLabels = ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى'];
            $relationshipLabels = [
                'father' => 'الأب',
                'mother' => 'الأم',
                'grandfather' => 'الجد',
                'grandmother' => 'الجدة',
                'uncle_paternal' => 'العم',
                'aunt_paternal' => 'العمة',
                'uncle_maternal' => 'الخال',
                'aunt_maternal' => 'الخالة',
                'brother' => 'الأخ',
                'sister' => 'الأخت',
                'legal_guardian' => 'وصي قانوني',
                'other' => 'أخرى'
            ];
            $siblingRelLabels = ['brother' => 'أخ', 'sister' => 'أخت', 'half_brother' => 'أخ غير شقيق', 'half_sister' => 'أخت غير شقيقة', 'step_brother' => 'أخ من زواج آخر', 'step_sister' => 'أخت من زواج آخر'];
            $allRelLabels = array_merge($siblingRelLabels, [
                'ابن عم' => 'ابن عم',
                'ابنة عم' => 'ابنة عم',
                'ابن عمة' => 'ابن عمة',
                'ابنة عمة' => 'ابنة عمة',
                'ابن خال' => 'ابن خال',
                'ابنة خال' => 'ابنة خال',
                'ابن خالة' => 'ابن خالة',
                'ابنة خالة' => 'ابنة خالة'
            ]);
            ?>
            <?php if ($studentDataScope === 'current'): ?>
                <div class="modal fade" id="studentProfileModal" tabindex="-1"
                    aria-labelledby="studentProfileModalTitle" aria-hidden="true"
                    data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
            <?php endif; ?>
            <form method="POST"
                action="<?php echo $studentsBasePage; ?>?action=<?php echo $page_action; ?><?php echo $isEditing ? '&id=' . $formUserId : ''; ?><?php echo $backQueryAmp; ?>"
                id="studentProfileForm" data-no-form-safety="true" enctype="multipart/form-data" novalidate
                class="<?php echo $studentDataScope === 'current' ? 'modal-content admin-modal admin-modal-premium ' . ($isEditing ? 'admin-modal-edit' : 'admin-modal-create') : ''; ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="student_scope" value="<?php echo htmlspecialchars($studentDataScope); ?>">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="edit_user_id" value="<?php echo $formUserId; ?>">
                    <input type="hidden" name="record_version"
                        value="<?php echo htmlspecialchars((string) ($sp['updated_at'] ?? '')); ?>">
                <?php endif; ?>
                <input type="hidden" name="active_tab" id="active_tab_input"
                    value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="back_spage" value="<?php echo htmlspecialchars($back_spage); ?>">
                <input type="hidden" name="back_stage_id" value="<?php echo htmlspecialchars($back_stage_id); ?>">
                <input type="hidden" name="back_grade_id" value="<?php echo htmlspecialchars($back_grade_id); ?>">
                <input type="hidden" name="back_class_id" value="<?php echo htmlspecialchars($back_class_id); ?>">
                <?php if ($studentProfilePendingMode): ?>
                    <input type="hidden" name="student_extra_phones_present" value="1">
                    <input type="hidden" name="student_extra_data_present" value="1">
                    <input type="hidden" name="student_guardians_present" value="1">
                    <input type="hidden" name="student_external_transfer_present" value="1">
                    <input type="hidden" name="student_extra_phones_touched" value="0">
                    <input type="hidden" name="student_extra_data_touched" value="0">
                    <input type="hidden" name="student_guardians_touched" value="0">
                    <input type="hidden" name="student_external_transfer_touched" value="0">
                <?php endif; ?>

                <div class="<?php echo $studentDataScope === 'current' ? 'd-flex flex-column h-100 overflow-hidden' : 'admin-work-panel mb-4'; ?>">
                    <div
                        class="<?php echo $studentDataScope === 'current' ? 'modal-header' : 'admin-work-panel-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3'; ?>">
                        <h5 class="<?php echo $studentDataScope === 'current' ? 'modal-title' : 'mb-0'; ?> d-flex align-items-center flex-wrap gap-1"
                            id="studentProfileModalTitle">
                            <?php if ($isEditing): ?>
                                <i class="fas fa-edit me-2"></i>تعديل بيانات الطالب:
                                <span class="admin-student-name-chip ms-2"
                                    id="student-name-header"><?php echo htmlspecialchars($editStudent->name); ?></span>
                                <button type="button" class="btn btn-sm btn-light ms-2 px-2 py-0 border-0"
                                    onclick="navigator.clipboard.writeText(document.getElementById('student-name-header').innerText); const icon = this.querySelector('i'); icon.className = 'fas fa-check text-success'; setTimeout(() => icon.className = 'far fa-copy', 2000);"
                                    data-bs-toggle="tooltip" title="نسخ الاسم">
                                    <i class="far fa-copy"></i>
                                </button>
                            <?php else: ?>
                                <i class="fas fa-user-plus me-2"></i>إضافة طالب جديد
                            <?php endif; ?>
                        </h5>
                        <?php if ($studentDataScope === 'current'): ?>
                            <button type="button" class="btn-close" data-student-modal-close aria-label="إغلاق"></button>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-2">
                                <button type="submit" name="save_student_profile"
                                    class="btn <?php echo $isEditing ? 'btn-primary' : 'btn-success'; ?>">
                                    <i class="fas <?php echo $studentProfilePendingMode ? 'fa-paper-plane' : 'fa-save'; ?> me-1"></i><?php echo $studentProfilePendingMode ? 'إرسال التعديلات للمراجعة' : ($isEditing ? 'حفظ جميع التغييرات' : 'إضافة الطالب'); ?>
                                </button>
                                <a href="<?php echo $studentsBasePage . $backQuery; ?>" class="btn btn-secondary"><i
                                        class="fas fa-times me-1"></i>إلغاء</a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="<?php echo $studentDataScope === 'current' ? 'modal-body d-flex flex-column overflow-hidden' : 'card-body'; ?>">
                        <?php if ($studentDataScope === 'current' && $error_message): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($studentProfilePendingMode): ?>
                            <div class="alert alert-info" role="alert">
                                <i class="fas fa-hourglass-half me-2"></i>
                                يمكنك تعديل بيانات الطالب كاملة، ولن تُطبق التغييرات إلا بعد موافقة الإدارة من صفحة العمليات المعلقة.
                            </div>
                        <?php endif; ?>

                        <!-- التبويبات -->
                        <ul class="nav nav-tabs nav-fill flex-nowrap overflow-auto mb-3" id="studentTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $activeTab === 'basic' ? 'active' : ''; ?>"
                                    id="tab-basic" data-bs-toggle="tab" data-bs-target="#pane-basic" type="button"
                                    role="tab">
                                    <i class="fas fa-user me-1"></i>البيانات الأساسية
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $activeTab === 'guardians' ? 'active' : ''; ?>"
                                    id="tab-guardians" data-bs-toggle="tab" data-bs-target="#pane-guardians" type="button"
                                    role="tab">
                                    <i class="fas fa-people-arrows me-1"></i>بيانات أولياء الأمور
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $activeTab === 'health' ? 'active' : ''; ?>"
                                    id="tab-health" data-bs-toggle="tab" data-bs-target="#pane-health" type="button"
                                    role="tab">
                                    <i class="fas fa-heartbeat me-1"></i>البيانات الصحية والنفسية
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $activeTab === 'siblings' ? 'active' : ''; ?>"
                                    id="tab-siblings" data-bs-toggle="tab" data-bs-target="#pane-siblings" type="button"
                                    role="tab">
                                    <i class="fas fa-user-friends me-1"></i>الإخوة والأشقاء وصلات القرابة
                                    <?php if (!empty($studentSiblings)): ?>
                                        <span class="badge bg-success ms-1"><?php echo count($studentSiblings); ?></span>
                                    <?php endif; ?>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $activeTab === 'academic_history' ? 'active' : ''; ?>"
                                    id="tab-academic-history" data-bs-toggle="tab" data-bs-target="#pane-academic-history"
                                    type="button" role="tab">
                                    <i class="fas fa-route me-1"></i>الحالة والمسار الدراسي
                                    <?php if (!empty($studentAcademicHistory)): ?>
                                        <span
                                            class="badge bg-info text-dark ms-1"><?php echo count($studentAcademicHistory); ?></span>
                                    <?php endif; ?>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $activeTab === 'attachments' ? 'active' : ''; ?>"
                                    id="tab-attachments" data-bs-toggle="tab" data-bs-target="#pane-attachments"
                                    type="button" role="tab">
                                    <i class="fas fa-paperclip me-1"></i>المرفقات
                                </button>
                            </li>
                        </ul>

                        <?php if ($studentDataScope === 'current'): ?>
                            <div class="student-profile-tab-scroll flex-grow-1 overflow-auto">
                        <?php endif; ?>
                        <div class="tab-content" id="studentTabContent">

                            <!-- ====== تبويب 1: البيانات الأساسية ====== -->
                            <div class="tab-pane fade <?php echo $activeTab === 'basic' ? 'show active' : ''; ?>"
                                id="pane-basic" role="tabpanel">

                                <!-- البيانات الشخصية -->
                                <h6 class="tab-section-title amber"><i class="fas fa-id-card me-2"></i>البيانات الشخصية
                                </h6>
                                <div class="mb-3">
                                    <label class="form-label text-dark small fw-bold mb-2">الاسم باللغة العربية <span
                                            class="text-danger">*</span></label>
                                    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xxl-5 g-3">
                                        <div class="col">
                                            <input type="text" class="form-control" name="first_name_ar"
                                                placeholder="الاسم الأول *"
                                                value="<?php echo htmlspecialchars($sp['first_name_ar'] ?? ''); ?>"
                                                required>
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control" name="second_name_ar"
                                                placeholder="اسم الأب"
                                                value="<?php echo htmlspecialchars($sp['second_name_ar'] ?? ''); ?>">
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control" name="third_name_ar"
                                                placeholder="اسم الجد"
                                                value="<?php echo htmlspecialchars($sp['third_name_ar'] ?? ''); ?>">
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control" name="fourth_name_ar"
                                                placeholder="الاسم الرابع"
                                                value="<?php echo htmlspecialchars($sp['fourth_name_ar'] ?? ''); ?>">
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control" name="family_name_ar"
                                                placeholder="اسم العائلة"
                                                value="<?php echo htmlspecialchars($sp['family_name_ar'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label text-dark small fw-bold mb-2">الاسم باللغة الإنجليزية</label>
                                    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xxl-5 g-3" dir="ltr">
                                        <div class="col">
                                            <input type="text" class="form-control" name="first_name_en"
                                                placeholder="First Name"
                                                value="<?php echo htmlspecialchars($sp['first_name_en'] ?? ''); ?>"
                                                dir="ltr">
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control" name="second_name_en"
                                                placeholder="Father Name"
                                                value="<?php echo htmlspecialchars($sp['second_name_en'] ?? ''); ?>"
                                                dir="ltr">
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control" name="third_name_en"
                                                placeholder="Grandfather"
                                                value="<?php echo htmlspecialchars($sp['third_name_en'] ?? ''); ?>"
                                                dir="ltr">
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control" name="fourth_name_en"
                                                placeholder="Fourth Name"
                                                value="<?php echo htmlspecialchars($sp['fourth_name_en'] ?? ''); ?>"
                                                dir="ltr">
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control" name="family_name_en"
                                                placeholder="Family Name"
                                                value="<?php echo htmlspecialchars($sp['family_name_en'] ?? ''); ?>"
                                                dir="ltr">
                                        </div>
                                    </div>
                                </div>

                                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xxl-5 g-3 mb-4">
                                    <div class="col">
                                        <label class="form-label">الديانة</label>
                                        <select class="form-select other-toggle" name="religion"
                                            data-other-target="student_religion_other">
                                            <option value="">-- اختر --</option>
                                            <?php foreach ($religionLabels as $k => $v): ?>
                                                <option value="<?php echo $k; ?>" <?php echo (($sp['religion'] ?? '') === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" class="form-control mt-2" id="student_religion_other"
                                            name="religion_other" placeholder="يرجى تحديد الديانة"
                                            style="display:<?php echo (($sp['religion'] ?? '') === 'other') ? 'block' : 'none'; ?>;">
                                    </div>
                                    <div class="col">
                                        <label class="form-label">النوع</label>
                                        <select class="form-select" name="gender">
                                            <option value="">-- اختر --</option>
                                            <?php foreach ($genderLabels as $k => $v): ?>
                                                <option value="<?php echo $k; ?>" <?php echo (($sp['gender'] ?? '') === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php
                                    $nationalityOptions = [
                                        'مصري',
                                        'سعودي',
                                        'إماراتي',
                                        'كويتي',
                                        'بحريني',
                                        'قطري',
                                        'عماني',
                                        'أردني',
                                        'فلسطيني',
                                        'سوري',
                                        'لبناني',
                                        'عراقي',
                                        'يمني',
                                        'سوداني',
                                        'ليبي',
                                        'تونسي',
                                        'جزائري',
                                        'مغربي',
                                        'موريتاني',
                                        'صومالي',
                                        'جيبوتي',
                                        'جزر القمر',
                                        'أمريكي',
                                        'بريطاني',
                                        'كندي',
                                        'أسترالي',
                                        'ألماني',
                                        'فرنسي',
                                        'إيطالي',
                                        'إسباني',
                                        'برتغالي',
                                        'هولندي',
                                        'بلجيكي',
                                        'سويدي',
                                        'نرويجي',
                                        'دنماركي',
                                        'فنلندي',
                                        'سويسري',
                                        'نمساوي',
                                        'يوناني',
                                        'تركي',
                                        'روسي',
                                        'أوكراني',
                                        'بولندي',
                                        'روماني',
                                        'بلغاري',
                                        'صربي',
                                        'كرواتي',
                                        'بوسني',
                                        'ألباني',
                                        'هندي',
                                        'باكستاني',
                                        'بنغلاديشي',
                                        'سريلانكي',
                                        'فلبيني',
                                        'إندونيسي',
                                        'ماليزي',
                                        'تايلاندي',
                                        'فيتنامي',
                                        'صيني',
                                        'ياباني',
                                        'كوري جنوبي',
                                        'إيراني',
                                        'أفغاني',
                                        'كازاخستاني',
                                        'أوزباكستاني',
                                        'نيجيري',
                                        'سنغالي',
                                        'غاني',
                                        'كاميروني',
                                        'إثيوبي',
                                        'كيني',
                                        'جنوب أفريقي',
                                        'برازيلي',
                                        'أرجنتيني',
                                        'كولومبي',
                                        'تشيلي',
                                        'بيروفي',
                                        'مكسيكي',
                                        'أخرى'
                                    ];
                                    $currentStudentNat = !empty($sp['nationality']) ? $sp['nationality'] : 'مصري';
                                    $studentNatIsCustom = !empty($currentStudentNat) && !in_array($currentStudentNat, $nationalityOptions, true);
                                    ?>
                                    <div class="col">
                                        <label class="form-label">الجنسية</label>
                                        <select class="form-select other-toggle" name="nationality"
                                            data-other-target="student_nationality_other">
                                            <?php foreach ($nationalityOptions as $nat): ?>
                                                <option value="<?php echo $nat; ?>" <?php echo ($currentStudentNat === $nat || ($nat === 'أخرى' && $studentNatIsCustom)) ? 'selected' : ''; ?>>
                                                    <?php echo $nat; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" class="form-control mt-2" id="student_nationality_other"
                                            name="nationality_other" placeholder="حدد الجنسية..."
                                            value="<?php echo htmlspecialchars($studentNatIsCustom ? $currentStudentNat : ''); ?>"
                                            style="display:<?php echo ($currentStudentNat === 'أخرى' || $studentNatIsCustom) ? 'block' : 'none'; ?>;">
                                    </div>
                                    <div class="col">
                                        <label class="form-label">الرقم القومي للمصريين</label>
                                        <input type="text" class="form-control national-id-input" name="national_id"
                                            value="<?php echo htmlspecialchars($sp['national_id'] ?? ''); ?>" maxlength="14"
                                            pattern="[0-9]{14}" dir="ltr" inputmode="numeric" placeholder="14 رقمًا">
                                    </div>
                                    <div class="col">
                                        <label class="form-label">رقم جواز السفر</label>
                                        <input type="text" class="form-control" name="passport_number"
                                            value="<?php echo htmlspecialchars($sp['passport_number'] ?? ''); ?>" dir="ltr"
                                            placeholder="رقم الجواز">
                                    </div>
                                </div>

                                <?php $currentStudentAge = User::calculateCurrentAge($sp['birth_date'] ?? null); ?>
                                <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-2 mb-4">
                                    <div class="col">
                                        <label class="form-label">تاريخ الميلاد</label>
                                        <input type="text" class="form-control form-control-sm flatpickr-date" name="birth_date" id="birth_date"
                                            value="<?php echo htmlspecialchars($sp['birth_date'] ?? ''); ?>" dir="ltr"
                                            placeholder="اختر التاريخ..." onchange="calculateAge()">
                                    </div>
                                    <div class="col">
                                        <label class="form-label">العمر الحالي</label>
                                        <input type="text" class="form-control form-control-sm" id="current_age_display" readonly
                                            aria-live="polite"
                                            value="<?php echo ($currentStudentAge && empty($currentStudentAge['is_future'])) ? $currentStudentAge['years'] . ' سنة ' . $currentStudentAge['months'] . ' شهر ' . $currentStudentAge['days'] . ' يوم' : ''; ?>"
                                            style="background:#f8f9fa;">
                                    </div>
                                    <div class="col">
                                        <label class="form-label">العمر في 1 أكتوبر من العام الحالي</label>
                                        <input type="text" class="form-control form-control-sm" id="age_display" readonly
                                            value="<?php echo (!empty($sp['age_years'])) ? $sp['age_years'] . ' سنة ' . ($sp['age_months'] ?? 0) . ' شهر ' . ($sp['age_days'] ?? 0) . ' يوم' : ''; ?>"
                                            style="background:#f8f9fa;">
                                    </div>
                                    <div class="col">
                                        <label class="form-label">محل الميلاد</label>
                                        <input type="text" class="form-control form-control-sm" name="birth_place"
                                            value="<?php echo htmlspecialchars($sp['birth_place'] ?? ''); ?>">
                                    </div>
                                    <div class="col">
                                        <label class="form-label">الوصاية التعليمية</label>
                                        <?php
                                        $currentEducationalGuardianship = (string) ($sp['educational_guardianship'] ?? '');
                                        $educationalGuardianshipIsCustom = $currentEducationalGuardianship !== '' && !array_key_exists($currentEducationalGuardianship, $relationshipLabels);
                                        ?>
                                        <select class="form-select form-select-sm" name="educational_guardianship">
                                            <option value="">-- اختر --</option>
                                            <?php foreach ($relationshipLabels as $k => $v): ?>
                                                <option value="<?php echo $k; ?>" <?php echo (($currentEducationalGuardianship === $k) || ($k === 'other' && $educationalGuardianshipIsCustom)) ? 'selected' : ''; ?>>
                                                    <?php echo $v; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" class="form-control form-control-sm mt-2" name="educational_guardianship_other"
                                            id="educational_guardianship_other" placeholder="اكتب صفة الوصاية"
                                            value="<?php echo htmlspecialchars($educationalGuardianshipIsCustom ? $currentEducationalGuardianship : ''); ?>"
                                            style="display:<?php echo $educationalGuardianshipIsCustom ? 'block' : 'none'; ?>;">
                                    </div>
                                </div>

                                <!-- أكواد الطالب -->
                                <h6 class="tab-section-title blue"><i class="fas fa-barcode me-2"></i>أكواد الطالب</h6>
                                <div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
                                    <div class="col">
                                        <label class="form-label">كود الطالب لدى المدرسة</label>
                                        <input type="text" class="form-control" name="student_code"
                                            value="<?php echo htmlspecialchars($sp['student_code'] ?? ''); ?>"
                                            placeholder="يتم توليده تلقائياً" dir="ltr">
                                        <small class="text-muted">اتركه فارغاً للتوليد التلقائي</small>
                                    </div>
                                    <div class="col">
                                        <label class="form-label">كود الطالب بوزارة التربية والتعليم</label>
                                        <input type="text" class="form-control" name="ministry_code"
                                            value="<?php echo htmlspecialchars($sp['ministry_code'] ?? ''); ?>"
                                            placeholder="كود الطالب بالوزارة" dir="ltr">
                                    </div>
                                </div>

                                <!-- العناوين وبيانات التواصل -->
                                <h6 class="tab-section-title purple"><i class="fas fa-map-marker-alt me-2"></i>العناوين
                                    وبيانات
                                    التواصل</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <label class="form-label">المدينة / المنطقة</label>
                                        <input type="text" class="form-control" name="city_area"
                                            value="<?php echo htmlspecialchars($sp['city_area'] ?? ''); ?>"
                                            placeholder="مثال: القاهرة، المنصوره...">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">العنوان التفصيلي</label>
                                        <input type="text" class="form-control" name="address_current"
                                            value="<?php echo htmlspecialchars($sp['address_current'] ?? ''); ?>"
                                            placeholder="الحي، الشارع، رقم المبنى...">
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <label class="form-label">رقم الطوارئ</label>
                                        <input type="text" class="form-control mobile-input" name="phone_emergency"
                                            value="<?php echo htmlspecialchars($sp['phone_emergency'] ?? ''); ?>" dir="ltr"
                                            maxlength="11" pattern="[0-9]{11}" inputmode="numeric" placeholder="11 رقمًا">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">رقم موبايل الطالب الأساسي</label>
                                        <input type="text" class="form-control mobile-input" name="phone_mobile"
                                            value="<?php echo htmlspecialchars($sp['phone_mobile'] ?? ''); ?>" dir="ltr"
                                            maxlength="11" pattern="[0-9]{11}" inputmode="numeric" placeholder="11 رقمًا">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">رقم الهاتف الأرضي الأساسي</label>
                                        <input type="text" class="form-control landline-input" name="phone_home"
                                            value="<?php echo htmlspecialchars($sp['phone_home'] ?? ''); ?>" dir="ltr"
                                            pattern="[0-9]*" inputmode="numeric" placeholder="أرقام فقط">
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-12">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <label class="form-label mb-0">أرقام هواتف إضافية مع ملاحظة لكل رقم</label>
                                            <button type="button" class="btn btn-success btn-sm" id="addStudentMobileBtn"><i
                                                    class="fas fa-plus me-1"></i>إضافة موبايل أو رقم
                                                هاتف إضافي</button>
                                        </div>
                                        <div id="studentMobilesContainer"></div>
                                    </div>
                                </div>

                                <h6 class="tab-section-title red"><i class="fas fa-plus-square me-2"></i>إضافة بيانات أخرى
                                </h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-12">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <label class="form-label mb-0">بيانات إضافية (مسمى البيانات + بيانها)</label>
                                            <button type="button" class="btn btn-success btn-sm"
                                                id="addAdditionalDataBtn"><i class="fas fa-plus me-1"></i>إضافة
                                                بيان</button>
                                        </div>
                                        <div id="additionalDataContainer"></div>
                                    </div>
                                </div>

                                <!-- ملاحظات عامة -->
                                <h6 class="tab-section-title purple"><i class="fas fa-sticky-note me-2"></i>ملاحظات عامة
                                </h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-12">
                                        <textarea class="form-control" name="notes" rows="3"
                                            placeholder="اكتب أي ملاحظات إضافية هنا..."><?php echo htmlspecialchars($sp['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>


                            </div>

                            <!-- ====== تبويب 2: أولياء الأمور ====== -->
                            <div class="tab-pane fade <?php echo $activeTab === 'guardians' ? 'show active' : ''; ?>"
                                id="pane-guardians" role="tabpanel">


                                <div id="guardiansContainer">
                                    <?php
                                    $guardiansSource = !empty($studentGuardians) ? $studentGuardians : [];
                                    $guardiansList = normalize_guardians_fixed_parents($guardiansSource, $sp);
                                    $derivedFatherName = build_father_name_from_student($sp);
                                    foreach ($guardiansList as $gi => $g):
                                        $roleKey = $gi === 0 ? 'father' : ($gi === 1 ? 'mother' : ($g['relationship'] ?? ''));
                                        $roleLabel = $relationshipLabels[$roleKey] ?? 'ولي الأمر';
                                        $guardianNameValue = $g['guardian_name'] ?? '';
                                        if ($gi === 0 && $derivedFatherName !== '') {
                                            $guardianNameValue = $derivedFatherName;
                                        }
                                        ?>
                                        <div class="guardian-entry border rounded p-4 mb-4 bg-transparent"
                                            data-index="<?php echo $gi; ?>">
                                            <div
                                                class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2 guardian-head">
                                                <h6 class="mb-0 text-primary fw-bold guardian-title"><i
                                                        class="fas fa-user-tie me-1"></i>بيانات
                                                    <?php echo htmlspecialchars($roleLabel); ?>
                                                </h6>
                                                <div class="d-flex align-items-center gap-2">
                                                    <button type="button" class="btn btn-sm btn-light guardian-collapse-btn"
                                                        title="طي/فتح"><i class="fas fa-chevron-up"></i></button>
                                                    <?php if ($gi > 1): ?>
                                                        <button type="button" class="btn btn-sm btn-danger remove-guardian"><i
                                                                class="fas fa-trash me-1"></i>حذف ولي الأمر</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- 1. البيانات الشخصية -->
                                            <div class="tab-section-title blue"><i class="fas fa-id-card me-1"></i>البيانات
                                                الشخصية</div>
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label">الاسم الرباعي <span
                                                            class="text-danger">*</span></label>
                                                    <input type="text" class="form-control"
                                                        name="guardians[<?php echo $gi; ?>][guardian_name]"
                                                        value="<?php echo htmlspecialchars($guardianNameValue); ?>" <?php echo ($gi === 0) ? 'readonly' : ''; ?>>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">صلة القرابة بالطالب <span
                                                            class="text-danger">*</span></label>
                                                    <?php if ($gi === 0): ?>
                                                        <select class="form-select guardian-relationship"
                                                            name="guardians[<?php echo $gi; ?>][relationship]" disabled>
                                                            <option value="father" selected>
                                                                <?php echo $relationshipLabels['father']; ?>
                                                            </option>
                                                        </select>
                                                        <input type="hidden" class="guardian-rel-hidden"
                                                            name="guardians[<?php echo $gi; ?>][relationship]" value="father">
                                                    <?php elseif ($gi === 1): ?>
                                                        <select class="form-select guardian-relationship"
                                                            name="guardians[<?php echo $gi; ?>][relationship]" disabled>
                                                            <option value="mother" selected>
                                                                <?php echo $relationshipLabels['mother']; ?>
                                                            </option>
                                                        </select>
                                                        <input type="hidden" class="guardian-rel-hidden"
                                                            name="guardians[<?php echo $gi; ?>][relationship]" value="mother">
                                                    <?php else: ?>
                                                        <?php
                                                        $standardGuardianRelationships = array_keys($relationshipLabels);
                                                        $currentGuardianRelationship = (string) ($g['relationship'] ?? '');
                                                        $currentGuardianRelationshipOther = (string) ($g['relationship_other'] ?? '');
                                                        // قيمة مخصّصة في الحالتين:
                                                        // 1) البيانات الجديدة: relationship = 'other' مع تفصيل في relationship_other
                                                        // 2) البيانات القديمة الفاسدة: قيمة غير معيارية مخزّنة في relationship
                                                        $guardianRelationshipIsCustom = ($currentGuardianRelationship !== '' && !in_array($currentGuardianRelationship, $standardGuardianRelationships, true))
                                                            || ($currentGuardianRelationship === 'other' && $currentGuardianRelationshipOther !== '');
                                                        // نص التفصيل المعروض: نفضّل relationship_other، وإلا قيمة relationship القديمة
                                                        $guardianRelationshipOtherValue = $currentGuardianRelationshipOther !== ''
                                                            ? $currentGuardianRelationshipOther
                                                            : ($guardianRelationshipIsCustom && $currentGuardianRelationship !== 'other' ? $currentGuardianRelationship : '');
                                                        ?>
                                                        <select class="form-select guardian-relationship"
                                                            name="guardians[<?php echo $gi; ?>][relationship]">
                                                            <?php foreach ($relationshipLabels as $rk => $rv): ?>
                                                                <option value="<?php echo $rk; ?>" <?php echo (($currentGuardianRelationship === $rk) || ($rk === 'other' && $guardianRelationshipIsCustom)) ? 'selected' : ''; ?>>
                                                                    <?php echo $rv; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="text" class="form-control mt-2 guardian-relationship-other"
                                                            name="guardians[<?php echo $gi; ?>][relationship_other]"
                                                            placeholder="اكتب صلة القرابة"
                                                            value="<?php echo htmlspecialchars($guardianRelationshipOtherValue); ?>"
                                                            style="display:<?php echo $guardianRelationshipIsCustom ? 'block' : 'none'; ?>;">
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">تاريخ الميلاد</label>
                                                    <input type="text" class="form-control flatpickr-date"
                                                        name="guardians[<?php echo $gi; ?>][birth_date]"
                                                        placeholder="اختر التاريخ..."
                                                        value="<?php echo htmlspecialchars($g['birth_date'] ?? ''); ?>"
                                                        dir="ltr">
                                                </div>
                                                <div class="col-md">
                                                    <label class="form-label">محل الميلاد</label>
                                                    <input type="text" class="form-control"
                                                        name="guardians[<?php echo $gi; ?>][birth_place]"
                                                        value="<?php echo htmlspecialchars($g['birth_place'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-2">
                                                    <label class="form-label">الديانة</label>
                                                    <?php
                                                    $gReligion = $g['religion'] ?? '';
                                                    $gReligionOpts = ['مسلم', 'مسيحي', 'أخرى'];
                                                    $gReligionIsCustom = $gReligion !== '' && !in_array($gReligion, $gReligionOpts, true);
                                                    ?>
                                                    <select class="form-select other-toggle"
                                                        name="guardians[<?php echo $gi; ?>][religion]"
                                                        data-other-target="guardian_religion_other_<?php echo $gi; ?>">
                                                        <option value="">-- اختر --</option>
                                                        <?php foreach ($gReligionOpts as $rOpt): ?>
                                                            <option value="<?php echo $rOpt; ?>" <?php echo ($gReligion === $rOpt || ($rOpt === 'أخرى' && $gReligionIsCustom)) ? 'selected' : ''; ?>>
                                                                <?php echo $rOpt; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="text" class="form-control mt-2"
                                                        id="guardian_religion_other_<?php echo $gi; ?>"
                                                        name="guardians[<?php echo $gi; ?>][religion_other]"
                                                        placeholder="يرجى تحديد الديانة"
                                                        value="<?php echo htmlspecialchars($gReligionIsCustom ? $gReligion : ''); ?>"
                                                        style="display:<?php echo ($gReligion === 'أخرى' || $gReligionIsCustom) ? 'block' : 'none'; ?>;">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">الجنسية</label>
                                                    <?php
                                                    $gNat = $g['nationality'] ?? '';
                                                    $gNatIsCustom = $gNat !== '' && !in_array($gNat, $nationalityOptions, true);
                                                    ?>
                                                    <select class="form-select other-toggle"
                                                        name="guardians[<?php echo $gi; ?>][nationality]"
                                                        data-other-target="guardian_nationality_other_<?php echo $gi; ?>">
                                                        <option value="">-- اختر --</option>
                                                        <?php foreach ($nationalityOptions as $nat): ?>
                                                            <option value="<?php echo $nat; ?>" <?php echo ($gNat === $nat || ($nat === 'أخرى' && $gNatIsCustom)) ? 'selected' : ''; ?>>
                                                                <?php echo $nat; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="text" class="form-control mt-2"
                                                        id="guardian_nationality_other_<?php echo $gi; ?>"
                                                        name="guardians[<?php echo $gi; ?>][nationality_other]"
                                                        placeholder="حدد الجنسية..."
                                                        value="<?php echo htmlspecialchars($gNatIsCustom ? $gNat : ''); ?>"
                                                        style="display:<?php echo ($gNat === 'أخرى' || $gNatIsCustom) ? 'block' : 'none'; ?>;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">الرقم القومي للمصريين</label>
                                                    <input type="text" class="form-control national-id-input"
                                                        name="guardians[<?php echo $gi; ?>][national_id]"
                                                        value="<?php echo htmlspecialchars($g['national_id'] ?? ''); ?>"
                                                        dir="ltr" maxlength="14" pattern="[0-9]{14}" inputmode="numeric"
                                                        placeholder="14 رقمًا">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">رقم جواز السفر</label>
                                                    <input type="text" class="form-control"
                                                        name="guardians[<?php echo $gi; ?>][passport_number]"
                                                        value="<?php echo htmlspecialchars($g['passport_number'] ?? ''); ?>"
                                                        dir="ltr">
                                                </div>
                                            </div>

                                            <!-- 2. العناوين وبيانات التواصل -->
                                            <div class="tab-section-title cyan"><i class="fas fa-phone-alt me-1"></i>العناوين
                                                وبيانات التواصل</div>
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label">رقم الموبايل الأساسي</label>
                                                    <input type="text" class="form-control mobile-input"
                                                        name="guardians[<?php echo $gi; ?>][phone_primary]"
                                                        value="<?php echo htmlspecialchars($g['phone_primary'] ?? ''); ?>"
                                                        dir="ltr" maxlength="11" pattern="[0-9]{11}" inputmode="numeric"
                                                        placeholder="11 رقمًا">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">رقم الهاتف الأرضي الأساسي</label>
                                                    <input type="text" class="form-control landline-input"
                                                        name="guardians[<?php echo $gi; ?>][phone_landline]"
                                                        value="<?php echo htmlspecialchars($g['phone_landline'] ?? ''); ?>"
                                                        dir="ltr" pattern="[0-9]*" inputmode="numeric" placeholder="أرقام فقط">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">البريد الإلكتروني</label>
                                                    <input type="email" class="form-control"
                                                        name="guardians[<?php echo $gi; ?>][email]"
                                                        value="<?php echo htmlspecialchars($g['email'] ?? ''); ?>" dir="ltr"
                                                        placeholder="example@mail.com">
                                                </div>
                                                <div class="col-md-8">
                                                    <label class="form-label">العنوان الحالي بالتفصيل</label>
                                                    <input type="text" class="form-control"
                                                        name="guardians[<?php echo $gi; ?>][address]"
                                                        value="<?php echo htmlspecialchars($g['address'] ?? ''); ?>"
                                                        placeholder="الشارع، الحي، المدينة...">
                                                </div>
                                                <div class="col-12">
                                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                                        <label class="form-label mb-0 small text-muted">أرقام هواتف إضافية مع
                                                            ملاحظة
                                                            لكل رقم</label>
                                                        <button type="button" class="btn btn-success btn-sm add-guardian-mobile"
                                                            data-gi="<?php echo $gi; ?>"><i class="fas fa-plus me-1"></i>إضافة
                                                            موبايل أو رقم هاتف إضافي</button>
                                                    </div>
                                                    <div class="guardian-extra-mobiles"></div>
                                                </div>
                                            </div>

                                            <!-- 3. المؤهل وبيانات العمل -->
                                            <div class="tab-section-title purple"><i class="fas fa-briefcase me-1"></i>المؤهل
                                                وبيانات العمل</div>
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-3">
                                                    <label class="form-label">المؤهل الدراسي</label>
                                                    <input type="text" class="form-control"
                                                        name="guardians[<?php echo $gi; ?>][qualification]"
                                                        value="<?php echo htmlspecialchars($g['qualification'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">الوظيفة / المسمى الوظيفي</label>
                                                    <input type="text" class="form-control"
                                                        name="guardians[<?php echo $gi; ?>][job_title]"
                                                        value="<?php echo htmlspecialchars($g['job_title'] ?? ''); ?>"
                                                        placeholder="مثال: معلم، مهندس...">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">جهة العمل / الشركة</label>
                                                    <input type="text" class="form-control"
                                                        name="guardians[<?php echo $gi; ?>][employer]"
                                                        value="<?php echo htmlspecialchars($g['employer'] ?? ''); ?>"
                                                        placeholder="مثال: وزارة التعليم، شركة X...">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">هاتف العمل</label>
                                                    <input type="text" class="form-control"
                                                        name="guardians[<?php echo $gi; ?>][work_phone]"
                                                        value="<?php echo htmlspecialchars($g['work_phone'] ?? ''); ?>"
                                                        dir="ltr" pattern="[0-9]*" inputmode="numeric" placeholder="أرقام فقط">
                                                </div>
                                            </div>

                                            <!-- 4. إضافة بيانات أخرى -->
                                            <div class="tab-section-title red"><i class="fas fa-plus-square me-1"></i>إضافة
                                                بيانات
                                                أخرى</div>
                                            <div class="row g-3">
                                                <div class="col-12">
                                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                                        <label class="form-label mb-0 small text-muted">بيانات إضافية لولي الأمر
                                                            (مسمى البيانات + بيانها)</label>
                                                        <button type="button" class="btn btn-success btn-sm add-guardian-extra"
                                                            data-gi="<?php echo $gi; ?>"><i class="fas fa-plus me-1"></i>إضافة
                                                            بيان</button>
                                                    </div>
                                                    <div class="guardian-extra-data-container"></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <button type="button" class="btn btn-success mb-3" id="addGuardianBtn">
                                    <i class="fas fa-plus me-1"></i>إضافة ولي أمر آخر
                                </button>


                            </div>

                            <!-- ====== تبويب الأشقاء ====== -->
                            <div class="tab-pane fade <?php echo $activeTab === 'siblings' ? 'show active' : ''; ?>"
                                id="pane-siblings" role="tabpanel">
                                <?php if ($isEditing): ?>

                                    <?php if (!$studentProfilePendingMode): ?>
                                    <!-- البحث وربط الأشقاء والأقارب (تلقائي ويدوي في صف واحد لتوفير السطور) -->
                                    <h6 class="tab-section-title green"><i class="fas fa-magic me-2"></i>البحث عن الأشقاء
                                        والأقارب وربطهم</h6>
                                    <div class="p-3 border rounded bg-white mb-4">
                                        <div class="row g-2 align-items-end">
                                            <!-- البحث اليدوي -->
                                            <div class="col-md-5">
                                                <label class="form-label small text-muted"><i
                                                        class="fas fa-search me-1"></i>اكتب اسم الطالب أو الكود</label>
                                                <input type="text" class="form-control" id="manualSiblingSearch"
                                                    placeholder="ابحث بالاسم أو كود الطالب..." autocomplete="off">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small text-muted">صلة القرابة</label>
                                                <select class="form-select" id="manualSiblingRelationship">
                                                    <?php foreach ($allRelLabels as $rk => $rv): ?>
                                                        <option value="<?php echo $rk; ?>"><?php echo $rv; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- الأزرار المشتركة بتنسيق متناسق -->
                                            <div class="col-md-4">
                                                <div class="d-flex gap-2">
                                                    <button type="button" class="btn btn-success flex-grow-1"
                                                        id="btnManualSiblingSearch">
                                                        <i class="fas fa-search me-1"></i>بحث يدوي
                                                    </button>
                                                    <button type="button" class="btn btn-primary flex-grow-1"
                                                        id="btnFindAllRelations" data-bs-toggle="tooltip"
                                                        data-bs-placement="top"
                                                        title="البحث التلقائي الذكي: يبحث تلقائياً عن أشقاء وأقارب بناءً على تطابق وتشابه أسماء الأب والأم في قاعدة البيانات.">
                                                        <i class="fas fa-robot me-1"></i>بحث تلقائي ذكي
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- نتائج البحث -->
                                        <div id="relationSuggestionsArea" class="mt-3" style="display:none;"></div>
                                        <div id="manualSiblingResults" class="mt-3" style="display:none;"></div>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-secondary">
                                        <i class="fas fa-eye me-2"></i>روابط الأشقاء والأقارب متاحة للعرض فقط ضمن صلاحية الأخصائي.
                                    </div>
                                    <?php endif; ?>

                                    <!-- الأشقاء المربوطين حالياً -->
                                    <h6 class="tab-section-title blue"><i class="fas fa-user-friends me-2"></i>الأشقاء المربوطين
                                        حالياً</h6>
                                    <div class="mb-4">
                                        <?php if (!empty($studentSiblings)): ?>
                                            <table class="table table-sm table-bordered">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>الاسم</th>
                                                        <th>الكود</th>
                                                        <th>الفصل</th>
                                                        <th>صلة القرابة</th>
                                                        <th>إجراء</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($studentSiblings as $sib): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($sib['sibling_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($sib['student_code'] ?? '-'); ?></td>
                                                            <td><?php echo htmlspecialchars($sib['class_name'] ?? '-'); ?></td>
                                                            <td><?php echo $siblingRelLabels[$sib['relationship']] ?? $sib['relationship']; ?>
                                                            </td>
                                                            <td>
                                                                <?php if (!$studentProfilePendingMode): ?>
                                                                <form method="POST" class="d-inline unlink-sibling-form">
                                                                    <?php echo csrfField(); ?>
                                                                    <input type="hidden" name="student_id"
                                                                        value="<?php echo $formUserId; ?>">
                                                                    <input type="hidden" name="sibling_id"
                                                                        value="<?php echo $sib['sibling_user_id']; ?>">
                                                                    <input type="hidden" name="unlink_tab" value="siblings">
                                                                    <button type="submit" name="unlink_sibling"
                                                                        class="btn btn-sm btn-danger" data-bs-toggle="tooltip"
                                                                        title="إلغاء الربط"><i class="fas fa-unlink"></i></button>
                                                                </form>
                                                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php else: ?>
                                            <div class="text-muted py-2"><i class="fas fa-info-circle me-2"></i>لا يوجد أشقاء
                                                مربوطين
                                                حالياً.</div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- الأقارب (صلات القرابة) -->
                                    <h6 class="tab-section-title purple mt-4"><i class="fas fa-sitemap me-2"></i>الأقارب (صلات
                                        القرابة)</h6>
                                    <div class="mb-4">
                                        <?php
                                        // Fetch kinship links for this student
                                        $stmtKin = $db->prepare("
                            SELECT sk.relative_id, sk.notes,
                                   kt.name as kinship_name,
                                   u.name as relative_name,
                                   sp2.student_code as relative_code,
                                   c.name as relative_class
                            FROM student_kinships sk
                            JOIN kinship_types kt ON sk.kinship_type_id = kt.id
                            JOIN users u ON sk.relative_id = u.id
                            LEFT JOIN student_profiles sp2 ON u.id = sp2.user_id
                            LEFT JOIN classes c ON u.class_id = c.id
                            WHERE sk.student_id = ?
                            ORDER BY u.name
                        ");
                                        $stmtKin->execute([$formUserId]);
                                        $studentKinships = $stmtKin->fetchAll(PDO::FETCH_ASSOC);
                                        ?>
                                        <?php if (!empty($studentKinships)): ?>
                                            <table class="table table-sm table-bordered">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>الاسم</th>
                                                        <th>الكود</th>
                                                        <th>الفصل</th>
                                                        <th>صلة القرابة</th>
                                                        <th>ملاحظات</th>
                                                        <th>إجراء</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($studentKinships as $kin): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($kin['relative_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($kin['relative_code'] ?? '-'); ?></td>
                                                            <td><?php echo htmlspecialchars($kin['relative_class'] ?? '-'); ?></td>
                                                            <td><span
                                                                    class="badge bg-primary"><?php echo htmlspecialchars($kin['kinship_name']); ?></span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($kin['notes'] ?? '-'); ?></td>
                                                            <td>
                                                                <?php if (!$studentProfilePendingMode): ?>
                                                                <form method="POST" class="d-inline unlink-kinship-form">
                                                                    <?php echo csrfField(); ?>
                                                                    <input type="hidden" name="student_id"
                                                                        value="<?php echo $formUserId; ?>">
                                                                    <input type="hidden" name="relative_id"
                                                                        value="<?php echo $kin['relative_id']; ?>">
                                                                    <input type="hidden" name="unlink_tab" value="siblings">
                                                                    <button type="submit" name="unlink_kinship"
                                                                        class="btn btn-sm btn-danger" data-bs-toggle="tooltip"
                                                                        title="إلغاء الربط"><i class="fas fa-unlink"></i></button>
                                                                </form>
                                                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php else: ?>
                                            <div class="text-muted py-2"><i class="fas fa-info-circle me-2"></i>لا يوجد أقارب
                                                مربوطين
                                                حالياً.</div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="fas fa-info-circle me-2"></i>يمكن ربط الأشقاء وصلات القرابة بعد حفظ الطالب أولاً.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- ====== تبويب 3: البيانات الصحية والنفسية ====== -->
                            <div class="tab-pane fade <?php echo $activeTab === 'health' ? 'show active' : ''; ?>"
                                id="pane-health" role="tabpanel">
                                <h6 class="tab-section-title red"><i class="fas fa-heartbeat me-2"></i>الحالة الصحية</h6>
                                <div class="row g-3 mb-4">
                                    <!-- الصف الأول -->
                                    <div class="col-md-2">
                                        <label class="form-label">فصيلة الدم</label>
                                        <select class="form-select" name="blood_type">
                                            <option value="">-- اختر --</option>
                                            <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bt): ?>
                                                <option value="<?php echo $bt; ?>" <?php echo (($sp['blood_type'] ?? '') === $bt) ? 'selected' : ''; ?>><?php echo $bt; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">رقم التأمين الطبي</label>
                                        <input type="text" class="form-control" name="insurance_number"
                                            value="<?php echo htmlspecialchars($sp['insurance_number'] ?? ''); ?>"
                                            dir="ltr">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">تاريخ بداية التأمين</label>
                                        <input type="text" class="form-control flatpickr-date" name="insurance_start_date"
                                            value="<?php echo htmlspecialchars($sp['insurance_start_date'] ?? ''); ?>"
                                            placeholder="اختر التاريخ..." dir="ltr">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">تاريخ نهاية التأمين</label>
                                        <input type="text" class="form-control flatpickr-date" name="insurance_end_date"
                                            value="<?php echo htmlspecialchars($sp['insurance_end_date'] ?? ''); ?>"
                                            placeholder="اختر التاريخ..." dir="ltr">
                                    </div>

                                    <!-- الصف الثاني -->
                                    <div class="col-md-6">
                                        <label class="form-label">الحالة الصحية العامة</label>
                                        <textarea class="form-control" name="health_status"
                                            rows="2"><?php echo htmlspecialchars($sp['health_status'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">الأمراض المزمنة</label>
                                        <textarea class="form-control" name="chronic_diseases"
                                            rows="2"><?php echo htmlspecialchars($sp['chronic_diseases'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- الصف الثالث -->
                                    <div class="col-md-6">
                                        <label class="form-label">الحساسية</label>
                                        <textarea class="form-control" name="allergies"
                                            rows="2"><?php echo htmlspecialchars($sp['allergies'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">الإعاقات (إن وجدت)</label>
                                        <textarea class="form-control" name="disabilities"
                                            rows="2"><?php echo htmlspecialchars($sp['disabilities'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- الصف الرابع -->
                                    <div class="col-md-6">
                                        <label class="form-label">العلاج / الأدوية</label>
                                        <textarea class="form-control" name="medications"
                                            rows="2"><?php echo htmlspecialchars($sp['medications'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">خطط علاجية متبعة</label>
                                        <textarea class="form-control" name="treatment_plan"
                                            rows="2"><?php echo htmlspecialchars($sp['treatment_plan'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- الصف الخامس -->
                                    <div class="col-md-6">
                                        <label class="form-label">تقارير طبية سابقة</label>
                                        <textarea class="form-control" name="previous_medical_reports"
                                            rows="2"><?php echo htmlspecialchars($sp['previous_medical_reports'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">ملاحظات طبية طارئة</label>
                                        <textarea class="form-control" name="emergency_medical_notes"
                                            rows="2"><?php echo htmlspecialchars($sp['emergency_medical_notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <h6 class="tab-section-title purple"><i class="fas fa-brain me-2"></i>الحالة النفسية
                                    والسلوكية
                                </h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-12">
                                        <label class="form-label">ملاحظات نفسية وسلوكية</label>
                                        <textarea class="form-control" name="psychological_notes"
                                            rows="3"><?php echo htmlspecialchars($sp['psychological_notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>


                            </div>

                            <div class="tab-pane fade <?php echo $activeTab === 'academic_history' ? 'show active' : ''; ?>"
                                id="pane-academic-history" role="tabpanel">
                                <h6 class="tab-section-title blue"><i class="fas fa-school me-2"></i>قيد العام الدراسي الحالي</h6>
                                <div class="alert alert-info py-2">
                                    <i class="fas fa-calendar-alt me-2"></i>
                                    يُحفظ هذا التسكين كسجل مستقل للعام
                                    <strong><?php echo htmlspecialchars((string)($currentAnnualEnrollment['academic_year'] ?? ($currentAcademicYear['name'] ?? 'الحالي'))); ?></strong>.
                                    تنشئ تهيئة العام السجل التالي تلقائيًا.
                                </div>
                                <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3 mb-4">
                                    <div class="col">
                                        <label class="form-label" for="student_stage_filter">المرحلة <span class="text-danger">*</span></label>
                                        <select class="form-select" name="stage_id" id="student_stage_filter" required>
                                            <option value="">-- اختر المرحلة --</option>
                                            <?php foreach ($stages as $stageId => $stageName): ?>
                                                <option value="<?php echo (int)$stageId; ?>" <?php echo (int)$formStageId === (int)$stageId ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string)$stageName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col">
                                        <label class="form-label" for="student_grade_filter">الصف <span class="text-danger">*</span></label>
                                        <select class="form-select" id="student_grade_filter"
                                            <?php echo $studentDataScope === 'graduates' ? 'name="graduate_grade_id"' : 'name="grade_id"'; ?> required>
                                            <option value="">-- اختر الصف --</option>
                                            <?php foreach ($scopeGrades as $scopeGrade): ?>
                                                <option value="<?php echo (int)$scopeGrade['id']; ?>"
                                                    data-stage="<?php echo (int)($scopeGrade['stage_id'] ?? 0); ?>"
                                                    <?php echo ($formGradeId !== '' && (int)$formGradeId === (int)$scopeGrade['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($scopeGrade['grade_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col">
                                        <label class="form-label" for="student_class_id">الفصل <small class="text-muted">(اختياري)</small></label>
                                        <select class="form-select" name="class_id" id="student_class_id">
                                            <option value="">-- بدون فصل --</option>
                                            <?php foreach ($classes as $ci): ?>
                                                <option value="<?php echo (int)$ci['id']; ?>"
                                                    data-grade="<?php echo (int)($ci['grade_id'] ?? 0); ?>"
                                                    <?php echo ((int)$formClassId === (int)$ci['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($ci['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col">
                                        <label class="form-label" for="enrollment_status">حالة القيد <span class="text-danger">*</span></label>
                                        <select class="form-select" name="enrollment_status" id="enrollment_status" required
                                            onchange="toggleExternalTransferFields()">
                                            <option value="enrolled" <?php echo $formEnrollmentStatus === 'enrolled' ? 'selected' : ''; ?>>مقيد</option>
                                            <option value="transferred" <?php echo $formEnrollmentStatus === 'transferred' ? 'selected' : ''; ?>>منقول خارج المدرسة</option>
                                            <option value="discontinued" <?php echo $formEnrollmentStatus === 'discontinued' ? 'selected' : ''; ?>>منقطع</option>
                                        </select>
                                    </div>
                                    <div class="col">
                                        <label class="form-label" for="academic_status">الحالة الدراسية <span class="text-danger">*</span></label>
                                        <select class="form-select" name="academic_status" id="academic_status" required>
                                            <option value="new" <?php echo $formAcademicStatus === 'new' ? 'selected' : ''; ?>>مستجد</option>
                                            <option value="promoted" <?php echo $formAcademicStatus === 'promoted' ? 'selected' : ''; ?>>ناجح ومنقول</option>
                                            <option value="retained" <?php echo $formAcademicStatus === 'retained' ? 'selected' : ''; ?>>راسب</option>
                                            <option value="graduated" <?php echo $formAcademicStatus === 'graduated' ? 'selected' : ''; ?>>خريج</option>
                                        </select>
                                    </div>
                                    <div class="col">
                                        <label class="form-label">تاريخ القيد بالمدرسة</label>
                                        <input type="text" class="form-control flatpickr-date" name="enrollment_date"
                                            value="<?php echo htmlspecialchars($sp['enrollment_date'] ?? ($isEditing ? '' : date('Y-m-d'))); ?>"
                                            placeholder="اختر التاريخ..." dir="ltr">
                                    </div>
                                    <div class="col">
                                        <label class="form-label">المدرسة القادم منها الطالب <small class="text-muted">(اختياري)</small></label>
                                        <input type="text" class="form-control" name="previous_school" id="previous_school"
                                            value="<?php echo htmlspecialchars($sp['previous_school'] ?? ''); ?>"
                                            placeholder="المدرسة السابقة">
                                    </div>
                                    <?php if ($isEditing): ?>
                                        <div class="col">
                                            <label class="form-label">سبب النقل بين الفصول</label>
                                            <input type="text" class="form-control" name="transfer_reason"
                                                placeholder="يُطلب عند تغيير الفصل">
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div id="external_transfer_fields" class="border rounded p-3 mb-4 bg-light" style="display:none;">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">الجهة المنقول إليها <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="transfer_destination"
                                                id="transfer_destination"
                                                value="<?php echo htmlspecialchars($studentExternalTransfer['destination'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">تاريخ النقل <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control flatpickr-date" name="external_transfer_date"
                                                id="external_transfer_date" placeholder="اختر التاريخ..."
                                                value="<?php echo htmlspecialchars($studentExternalTransfer['transfer_date'] ?? ($isEditing ? '' : date('Y-m-d'))); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">سبب النقل</label>
                                            <input type="text" class="form-control" name="external_transfer_reason"
                                                value="<?php echo htmlspecialchars($studentExternalTransfer['reason'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">ملاحظات النقل</label>
                                            <textarea class="form-control" name="external_transfer_notes"
                                                rows="2"><?php echo htmlspecialchars($studentExternalTransfer['notes'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($isEditing): ?>
                                    <h6 class="tab-section-title purple"><i class="fas fa-route me-2"></i>المسار الدراسي للطالب
                                    </h6>
                                    <p class="text-muted small">يعرض هذا السجل مسار الطالب السنوي حسب التسجيلات الدراسية لكل
                                        عام،
                                        بما يشمل القيد والترقية والرسوب والتخرج.</p>

                                    <?php if (!empty($studentExternalTransfer)): ?>
                                        <div class="alert alert-warning border-warning mb-4">
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <i class="fas fa-external-link-alt"></i>
                                                <strong>نقل إلى خارج المدرسة</strong>
                                            </div>
                                            <div class="row g-2 small">
                                                <div class="col-md-4"><strong>الجهة:</strong>
                                                    <?php echo htmlspecialchars($studentExternalTransfer['destination']); ?></div>
                                                <div class="col-md-3"><strong>تاريخ النقل:</strong>
                                                    <?php echo htmlspecialchars($studentExternalTransfer['transfer_date']); ?></div>
                                                <div class="col-md-3"><strong>بواسطة:</strong>
                                                    <?php echo htmlspecialchars($studentExternalTransfer['transferred_by_name'] ?? '-'); ?>
                                                </div>
                                                <?php if (!empty($studentExternalTransfer['reason'])): ?>
                                                    <div class="col-12"><strong>السبب:</strong>
                                                        <?php echo htmlspecialchars($studentExternalTransfer['reason']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($studentExternalTransfer['notes'])): ?>
                                                    <div class="col-12"><strong>ملاحظات:</strong>
                                                        <?php echo nl2br(htmlspecialchars($studentExternalTransfer['notes'])); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (empty($studentAcademicHistory)): ?>
                                        <div class="text-center py-5 border rounded bg-light">
                                            <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                            <p class="text-muted mb-0">لا توجد تسجيلات دراسية سنوية لهذا الطالب حتى الآن.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle border" id="studentAcademicHistoryTable">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>العام الدراسي</th>
                                                        <th>حالة القيد</th>
                                                        <th>الحالة الدراسية</th>
                                                        <th>المرحلة</th>
                                                        <th>الصف</th>
                                                        <th>الفصل</th>
                                                        <th>مصدر السجل</th>
                                                        <th>تاريخ التسجيل</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($studentAcademicHistory as $history):
                                                        $enrollmentLabels = ['enrolled' => 'مقيد', 'transferred' => 'منقول', 'discontinued' => 'منقطع'];
                                                        $enrollmentClasses = ['enrolled' => 'success', 'transferred' => 'warning', 'discontinued' => 'secondary'];
                                                        $academicLabels = ['new' => 'مستجد', 'promoted' => 'ناجح ومنقول', 'retained' => 'راسب', 'graduated' => 'خريج'];
                                                        $academicClasses = ['new' => 'info', 'promoted' => 'success', 'retained' => 'warning', 'graduated' => 'primary'];
                                                        $enrollmentType = $history['enrollment_status'] ?? 'enrolled';
                                                        $academicType = $history['academic_status'] ?? ($history['promotion_type'] ?? 'new');
                                                        ?>
                                                        <tr
                                                            class="<?php echo !empty($history['is_reversed']) ? 'table-secondary' : ''; ?>">
                                                            <td class="fw-semibold text-nowrap">
                                                                <?php echo htmlspecialchars($history['academic_year']); ?>
                                                            </td>
                                                            <td><span
                                                                    class="badge bg-<?php echo $enrollmentClasses[$enrollmentType] ?? 'secondary'; ?>"><?php echo $enrollmentLabels[$enrollmentType] ?? htmlspecialchars($enrollmentType); ?></span>
                                                            </td>
                                                            <td><span
                                                                    class="badge bg-<?php echo $academicClasses[$academicType] ?? 'secondary'; ?>"><?php echo $academicLabels[$academicType] ?? htmlspecialchars($academicType); ?></span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($history['stage_name'] ?? '-'); ?></td>
                                                            <td><?php echo htmlspecialchars($history['to_grade'] ?? '-'); ?></td>
                                                            <td><?php echo htmlspecialchars($history['to_class'] ?? '-'); ?></td>
                                                            <td><?php echo htmlspecialchars($history['promoted_by_name'] ?? '-'); ?>
                                                            </td>
                                                            <td class="text-nowrap">
                                                                <?php echo !empty($history['created_at']) ? date('Y/m/d H:i', strtotime($history['created_at'])) : '-'; ?>
                                                            </td>
                                                        </tr>
                                                        <?php if (!empty($history['notes'])): ?>
                                                            <tr
                                                                class="<?php echo !empty($history['is_reversed']) ? 'table-secondary' : ''; ?>">
                                                                <td colspan="8" class="small text-muted"><strong>ملاحظات:</strong>
                                                                    <?php echo nl2br(htmlspecialchars($history['notes'])); ?></td>
                                                            </tr>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                 </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="fas fa-info-circle me-2"></i>يظهر المسار الدراسي بعد حفظ الطالب وإنشاء أول تسجيل دراسي له.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- ====== تبويب 5: المرفقات ====== -->
                            <div class="tab-pane fade <?php echo $activeTab === 'attachments' ? 'show active' : ''; ?>"
                                id="pane-attachments" role="tabpanel">

                                <h6 class="tab-section-title amber"><i class="fas fa-paperclip me-2"></i>المرفقات الشخصية
                                </h6>

                                 <?php if ($isEditing): ?>
                                    <?php
                                    $studentProfilePhoto = null;
                                    foreach ($studentAttachments as $tmpAtt) {
                                        if (($tmpAtt['label'] ?? '') === 'الصورة الشخصية') {
                                            $studentProfilePhoto = $tmpAtt;
                                            break;
                                        }
                                    }
                                    ?>

                                    <?php if (!$studentProfilePendingMode): ?>
                                    <div class="alert alert-light border shadow-sm p-4">
                                        <!-- قسم رفع الصورة الشخصية والمرفقات الأخرى -->
                                        <div class="row g-2 align-items-end mb-4 pb-4 border-bottom">
                                            <div class="col-md-5">
                                                <label class="form-label small text-secondary"><i
                                                        class="fas fa-camera me-1"></i>الصورة الشخصية</label>
                                                <input type="file" class="form-control form-control-sm" id="student_profile_image_file"
                                                    accept="image/jpeg,image/png,image/webp">

                                            </div>
                                            <div class="col-md-3 d-flex align-items-center gap-2 mb-1" id="current_student_avatar_container" style="display: <?php echo !empty($studentProfilePhoto) ? 'flex' : 'none'; ?> !important;">
                                                <span class="small text-secondary">الصورة الحالية:</span>
                                                <a href="<?php echo !empty($studentProfilePhoto) ? htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', (int)$studentProfilePhoto['id'])) : '#'; ?>"
                                                    target="_blank" id="current_student_avatar_link">
                                                    <img src="<?php echo !empty($studentProfilePhoto) ? htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', (int)$studentProfilePhoto['id'])) : ''; ?>"
                                                        class="rounded border shadow-sm" alt="صورة الطالب"
                                                        style="height: 31px; width: 31px; object-fit: cover;" id="current_student_avatar_img">
                                                </a>
                                            </div>
                                            <div class="col-md-4 d-flex align-items-center ms-auto justify-content-md-end mb-1">
                                                <input type="file" id="student_attachment_file_input" multiple
                                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp"
                                                    style="display:none;">
                                                <button type="button" class="btn btn-primary shadow-sm"
                                                    id="student_upload_attachment_btn">
                                                    <i class="fas fa-cloud-upload-alt me-2"></i>رفع مرفقات إضافية
                                                </button>
                                            </div>
                                        </div>

                                        <!-- قسم رفع المرفقات الأخرى (رفع فوري) -->
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                                <span class="text-muted small"><i class="fas fa-info-circle me-1"></i>الأنواع
                                                    المسموحة: PDF, Word, Excel, صور (JPG, PNG, WebP) - حد أقصى 10MB. تُرفع جميع الملفات والصورة الشخصية فوراً عند اختيارها مع عرض نسبة التقدم.</span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-secondary">
                                        <i class="fas fa-eye me-2"></i>المرفقات متاحة للعرض والتنزيل فقط ضمن صلاحية الأخصائي.
                                    </div>
                                    <?php endif; ?>

                                    <!-- جدول المرفقات الحالية -->
                                    <div class="table-responsive" id="studentAttachmentsTableWrap" <?php echo empty($studentAttachments) ? 'style="display:none;"' : ''; ?>>
                                        <table class="table table-hover table-bordered align-middle"
                                            id="studentAttachmentsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 4%;">#</th>
                                                    <th style="width: 26%;"><i class="fas fa-tag me-1"></i>اسم المرفق</th>
                                                    <th style="width: 28%;"><i class="fas fa-file me-1"></i>الملف</th>
                                                    <th style="width: 13%;"><i class="fas fa-weight me-1"></i>الحجم</th>
                                                    <th style="width: 14%;"><i class="fas fa-calendar me-1"></i>التاريخ</th>
                                                    <th style="width: 15%; text-align: center; white-space: nowrap;">إجراءات</th>
                                                </tr>
                                            </thead>
                                            <tbody id="studentAttachmentsTableBody">
                                                <?php if (!empty($studentAttachments)): ?>
                                                    <?php foreach ($studentAttachments as $saidx => $satt): ?>
                                                        <tr data-attachment-id="<?php echo (int) $satt['id']; ?>">
                                                            <td class="att-index"><?php echo $saidx + 1; ?></td>
                                                            <td><strong class="att-label"><?php echo htmlspecialchars($satt['label']); ?></strong></td>
                                                            <td>
                                                <a href="<?php echo htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', (int)$satt['id'])); ?>"
                                                                    target="_blank" class="text-decoration-none">
                                                                    <i class="fas fa-<?php
                                                                    $sfext = strtolower(pathinfo($satt['file_name'], PATHINFO_EXTENSION));
                                                                    echo in_array($sfext, ['pdf']) ? 'file-pdf text-danger' :
                                                                        (in_array($sfext, ['doc', 'docx']) ? 'file-word text-primary' :
                                                                            (in_array($sfext, ['xls', 'xlsx']) ? 'file-excel text-success' :
                                                                                (in_array($sfext, ['jpg', 'jpeg', 'png', 'webp']) ? 'file-image text-info' : 'file text-secondary')));
                                                                    ?> me-1"></i>
                                                                    <?php echo htmlspecialchars($satt['original_name']); ?>
                                                                </a>
                                                            </td>
                                                            <td><?php echo round($satt['file_size'] / 1024, 1); ?> KB</td>
                                                            <td><?php echo date('Y/m/d', strtotime($satt['uploaded_at'])); ?></td>
                                                            <td class="actions-column text-center" style="white-space: nowrap;">
                                                                <div class="d-inline-flex align-items-center justify-content-center gap-1">
                                                                    <?php if (!$studentProfilePendingMode): ?>
                                                                    <?php if (($satt['label'] ?? '') !== 'الصورة الشخصية'): ?>
                                                                    <button type="button" class="btn btn-action-pills btn-edit att-rename-btn"
                                                                        data-bs-toggle="tooltip" title="تعديل الاسم"
                                                                        data-attachment-id="<?php echo (int) $satt['id']; ?>"
                                                                        data-attachment-label="<?php echo htmlspecialchars($satt['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                                                        <i class="fas fa-edit"></i>
                                                                    </button>
                                                                    <?php endif; ?>
                                                                    <button type="button" class="btn btn-action-pills btn-delete att-delete-btn"
                                                                        data-bs-toggle="tooltip" title="حذف"
                                                                        data-attachment-id="<?php echo (int) $satt['id']; ?>"
                                                                        data-attachment-label="<?php echo htmlspecialchars($satt['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                                                        <i class="fas fa-trash"></i>
                                                                    </button>
                                                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div id="studentAttachmentsEmpty" <?php echo !empty($studentAttachments) ? 'style="display:none;"' : ''; ?>>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>لا توجد مرفقات حالياً. استخدم زر «رفع مرفق»
                                            لإضافة مرفقات.
                                        </div>
                                    </div>

                                <?php else: ?>
                                    <!-- وضع الإضافة: رسالة -->
                                    <div class="alert alert-warning">
                                        <i class="fas fa-info-circle me-2"></i>يمكنك إضافة المرفقات بعد حفظ بيانات الطالب أولاً.
                                    </div>
                                <?php endif; ?>

                            </div>

                        </div><!-- /tab-content -->
                        <?php if ($studentDataScope === 'current'): ?>
                            </div><!-- /student-profile-tab-scroll -->
                        <?php endif; ?>
                    </div>

                    <div class="<?php echo $studentDataScope === 'current' ? 'modal-footer' : 'd-flex justify-content-end gap-2 mt-3'; ?>">
                        <a href="<?php echo $studentsBasePage . $backQuery; ?>" class="btn btn-secondary" data-modal-cancel><i
                                class="fas fa-times me-1"></i>إلغاء</a>
                        <button type="submit" name="save_student_profile"
                            class="btn <?php echo $isEditing ? 'btn-primary' : 'btn-success'; ?>">
                            <i class="fas <?php echo $studentProfilePendingMode ? 'fa-paper-plane' : 'fa-save'; ?> me-1"></i><?php echo $studentProfilePendingMode ? 'إرسال التعديلات للمراجعة' : ($isEditing ? 'حفظ جميع التغييرات' : 'إضافة الطالب'); ?>
                        </button>
                    </div>
                </div>
            </form>
            <?php if ($studentDataScope === 'current'): ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- نماذج مخفية لرفع/حذف المرفقات (خارج النموذج الرئيسي) -->
            <?php if ($isEditing): ?>
                <form id="uploadStudentAttachmentForm" method="POST"
                    action="<?php echo $studentsBasePage; ?>?action=edit&id=<?php echo $editStudent->id; ?><?php echo $backQueryAmp; ?>"
                    enctype="multipart/form-data" style="display:none;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="upload_student_attachment">
                    <input type="hidden" name="id" value="<?php echo $editStudent->id; ?>">
                    <input type="hidden" name="attachment_label" id="hidden_student_attachment_label">
                    <input type="file" name="attachment_file" id="hidden_student_attachment_file">
                </form>
                <form id="deleteStudentAttachmentForm" method="POST"
                    action="<?php echo $studentsBasePage; ?>?action=edit&id=<?php echo $editStudent->id; ?><?php echo $backQueryAmp; ?>"
                    style="display:none;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete_student_attachment">
                    <input type="hidden" name="id" value="<?php echo $editStudent->id; ?>">
                    <input type="hidden" name="attachment_id" id="hidden_delete_student_attachment_id">
                </form>
                <?php require dirname(__DIR__, 4) . '/includes/profile_attachment_label_modal.php'; ?>
            <?php endif; ?>

            <div class="modal fade" id="studentInlineDeleteConfirmModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="text-center mb-3">
                                    <i class="fas fa-trash-alt text-danger admin-modal-icon-md"></i>
                                </div>
                                <p class="text-center mb-0" id="studentInlineDeleteConfirmMessage">هل أنت متأكد من الحذف؟
                                </p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i>إلغاء
                                </button>
                                <button type="button" class="btn btn-danger" id="studentInlineDeleteConfirmBtn">
                                    <i class="fas fa-check me-1"></i>تأكيد
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            <div class="modal fade" id="studentUnsavedChangesModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-exclamation-circle me-2"></i>تنبيه قبل الخروج</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-triangle-exclamation text-warning admin-modal-icon-md"></i>
                            </div>
                            <p class="text-center mb-0">لديك بيانات غير محفوظة. إذا غادرت الآن ستفقد التغييرات.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">البقاء في
                                الصفحة</button>
                            <button type="button" class="btn btn-danger" id="studentUnsavedLeaveBtn">مغادرة بدون
                                حفظ</button>
                        </div>
                    </div>
                </div>
            </div>
<?php endif; ?>
<!-- cache_bust_20260717 -->
