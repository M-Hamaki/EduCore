        <?php
        // ===========================
// عرض الملف الشخصي المفصل للطالب
// ===========================
        if ($page_action === 'view' && $viewStudent !== null):
            $vp = $viewProfile ?: [];
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

            $hasHealthData = !empty($vp['blood_type']) ||
                !empty($vp['insurance_number']) ||
                !empty($vp['insurance_start_date']) ||
                !empty($vp['insurance_end_date']) ||
                !empty($vp['health_status']) ||
                !empty($vp['chronic_diseases']) ||
                !empty($vp['allergies']) ||
                !empty($vp['disabilities']) ||
                !empty($vp['medications']) ||
                !empty($vp['treatment_plan']) ||
                !empty($vp['previous_medical_reports']) ||
                !empty($vp['emergency_medical_notes']) ||
                !empty($vp['psychological_notes']);

            // بناء الاسم الكامل
            $fullNameAr = trim(($vp['first_name_ar'] ?? '') . ' ' . ($vp['second_name_ar'] ?? '') . ' ' . ($vp['third_name_ar'] ?? '') . ' ' . ($vp['fourth_name_ar'] ?? '') . ' ' . ($vp['family_name_ar'] ?? ''));
            $fullNameAr = preg_replace('/\s+/', ' ', $fullNameAr);
            if (empty(trim($fullNameAr)))
                $fullNameAr = $viewStudent->name;

            $fullNameEn = trim(($vp['first_name_en'] ?? '') . ' ' . ($vp['second_name_en'] ?? '') . ' ' . ($vp['third_name_en'] ?? '') . ' ' . ($vp['fourth_name_en'] ?? '') . ' ' . ($vp['family_name_en'] ?? ''));
            $fullNameEn = preg_replace('/\s+/', ' ', $fullNameEn);

            // البحث عن الصورة الشخصية في المرفقات
            $profileImgFile = '';
            $profileImgId = 0;
            foreach ($viewAttachments as $vatt) {
                if (($vatt['label'] ?? '') === 'الصورة الشخصية') {
                    $profileImgFile = $vatt['file_name'];
                    $profileImgId = (int)$vatt['id'];
                    break;
                }
            }
            ?>
            <div class="admin-work-panel mb-0">
                <div class="row g-4">
                    <div class="col-lg-3 col-md-4 d-flex flex-column text-center mb-3 mb-md-0">
                        <div class="card border-0 shadow-sm p-4 bg-white rounded-3">
                            <div class="text-muted small fw-bold mb-3 pb-2 border-bottom">
                                <i class="fas fa-id-card me-1"></i>الملف الشخصي للطالب
                            </div>
                            <div class="position-relative d-inline-block mx-auto mb-3">
                                <?php if ($profileImgFile): ?>
                                <img src="<?php echo htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', $profileImgId)); ?>"
                                        class="rounded-circle shadow-sm admin-avatar-xl"
                                        style="object-fit:cover; border: 4px solid var(--bs-primary-bg-subtle);"
                                        alt="صورة الطالب">
                                <?php else: ?>
                                    <div
                                        class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center shadow-sm admin-avatar-xl">
                                        <i class="fas fa-user-graduate fa-4x text-primary"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <h5 class="fw-bold mb-2 text-dark"><?php echo htmlspecialchars($fullNameAr); ?></h5>
                            <?php if (!empty($vp['student_code'])): ?>
                                <div class="mb-2">
                                    <span class="badge bg-secondary-subtle text-secondary px-3 py-2 fs-6 rounded-pill"
                                        dir="ltr">
                                        <i class="fas fa-barcode me-1"></i><?php echo htmlspecialchars($vp['student_code']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex flex-wrap justify-content-center gap-1 mt-2">
                                <span class="badge bg-primary px-3 py-2 rounded-pill">طالب</span>
                                <?php
                                $viewEnrollmentStatus = (string)($viewCurrentEnrollment['enrollment_status'] ?? 'enrolled');
                                if ($viewEnrollmentStatus === 'withdrawn') $viewEnrollmentStatus = 'discontinued';
                                if ($viewEnrollmentStatus === 'graduated') $viewEnrollmentStatus = 'enrolled';
                                $viewAcademicStatus = (string)($viewCurrentEnrollment['academic_status']
                                    ?? ($viewStudent->status === 'graduated' ? 'graduated' : 'new'));
                                $viewEnrollmentLabels = ['enrolled' => 'مقيد', 'transferred' => 'منقول', 'discontinued' => 'منقطع'];
                                $viewEnrollmentClasses = ['enrolled' => 'success', 'transferred' => 'warning text-dark', 'discontinued' => 'secondary'];
                                $viewAcademicLabels = ['new' => 'مستجد', 'promoted' => 'ناجح ومنقول', 'retained' => 'راسب', 'graduated' => 'خريج'];
                                $viewAcademicClasses = ['new' => 'info', 'promoted' => 'success', 'retained' => 'warning text-dark', 'graduated' => 'primary'];
                                ?>
                                <span class="badge bg-<?php echo $viewEnrollmentClasses[$viewEnrollmentStatus] ?? 'secondary'; ?> px-3 py-2 rounded-pill">
                                    <?php echo htmlspecialchars($viewEnrollmentLabels[$viewEnrollmentStatus] ?? $viewEnrollmentStatus); ?>
                                </span>
                                <span class="badge bg-<?php echo $viewAcademicClasses[$viewAcademicStatus] ?? 'secondary'; ?> px-3 py-2 rounded-pill">
                                    <?php echo htmlspecialchars($viewAcademicLabels[$viewAcademicStatus] ?? $viewAcademicStatus); ?>
                                </span>
                            </div>

                            <div class="mt-4 pt-3 border-top w-100 text-center">
                                <?php if (!empty($viewClassName)): ?>
                                    <div class="text-muted small mb-1 fw-bold">الفصل الحالي</div>
                                    <div class="fw-bold text-primary fs-6 mb-3"><i
                                            class="fas fa-school me-1"></i><?php echo htmlspecialchars($viewClassName); ?></div>
                                    <div class="border-top pt-3"></div>
                                <?php endif; ?>
                                <a href="<?php echo $studentsBasePage; ?>?action=edit&id=<?php echo $viewUserId; ?><?php echo $backQueryAmp; ?>"
                                    class="btn btn-primary btn-sm w-100 py-2 mb-2"><i class="fas fa-edit me-1"></i>تعديل
                                    البيانات</a>
                                <a href="<?php echo $studentsBasePage . $backQuery; ?>"
                                    class="btn btn-secondary btn-sm w-100 py-2"><i class="fas fa-arrow-right me-1"></i>رجوع
                                    للقائمة</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-9 col-md-8 d-flex flex-column mb-0">
                        <div class="card border-0 shadow-sm p-4 bg-white rounded-3">
                            <!-- البيانات الأساسية -->
                            <h6 class="tab-section-title blue">
                                <i class="fas fa-user me-2"></i>البيانات الأساسية
                            </h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-barcode text-primary me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">كود الطالب:</span>
                                        <strong class="text-dark"
                                            dir="ltr"><?php echo htmlspecialchars($vp['student_code'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-school text-primary me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">الفصل:</span>
                                        <strong
                                            class="text-dark"><?php echo htmlspecialchars($viewClassName ?: '-'); ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-calendar-alt text-primary me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">تاريخ القيد:</span>
                                        <strong class="text-dark"><?php echo $vp['enrollment_date'] ?? '-'; ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-university text-primary me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">المدرسة القادم منها:</span>
                                        <strong
                                            class="text-dark"><?php echo htmlspecialchars($vp['previous_school'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <?php if (!empty(trim($fullNameEn))): ?>
                                    <div class="col-md-8">
                                        <div class="d-flex align-items-center py-2 border-bottom border-light">
                                            <i class="fas fa-language text-primary me-2 admin-icon-fixed"></i>
                                            <span class="text-secondary me-2">الاسم بالإنجليزية:</span>
                                            <strong class="text-dark"
                                                dir="ltr"><?php echo htmlspecialchars($fullNameEn); ?></strong>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-id-card text-primary me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">الرقم القومي للمصريين:</span>
                                        <strong
                                            class="text-dark"><?php echo htmlspecialchars($vp['national_id'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-birthday-cake text-primary me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">تاريخ الميلاد:</span>
                                        <strong class="text-dark"><?php echo $vp['birth_date'] ?? '-'; ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-map-marker-alt text-primary me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">محل الميلاد:</span>
                                        <strong
                                            class="text-dark"><?php echo htmlspecialchars($vp['birth_place'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-venus-mars text-primary me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">النوع:</span>
                                        <strong
                                            class="text-dark"><?php echo $genderLabels[$vp['gender'] ?? ''] ?? '-'; ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-book text-primary me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">الديانة:</span>
                                        <strong
                                            class="text-dark"><?php echo $religionLabels[$vp['religion'] ?? ''] ?? htmlspecialchars($vp['religion'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <?php if (!empty($vp['age_years']) || !empty($vp['age_months'])): ?>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center py-2 border-bottom border-light">
                                            <i class="fas fa-hourglass-half text-primary me-2 admin-icon-fixed"></i>
                                            <span class="text-secondary me-2">العمر:</span>
                                            <strong
                                                class="text-dark"><?php echo ($vp['age_years'] ?? 0) . ' سنة و ' . ($vp['age_months'] ?? 0) . ' شهر'; ?></strong>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- العنوان والتواصل -->
                            <h6 class="tab-section-title green mt-4">
                                <i class="fas fa-phone-alt me-2"></i>العنوان والتواصل
                            </h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-city text-success me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">المدينة / المنطقة:</span>
                                        <strong
                                            class="text-dark"><?php echo htmlspecialchars($vp['city_area'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-8">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-map-marked-alt text-success me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">العنوان التفصيلي:</span>
                                        <strong
                                            class="text-dark"><?php echo htmlspecialchars($vp['address_current'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-mobile-alt text-success me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">موبايل الطالب الأساسي:</span>
                                        <strong class="text-dark"
                                            dir="ltr"><?php echo htmlspecialchars($vp['phone_mobile'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-phone text-success me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">تليفون المنزل:</span>
                                        <strong class="text-dark"
                                            dir="ltr"><?php echo htmlspecialchars($vp['phone_home'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-flex align-items-center py-2 border-bottom border-light">
                                        <i class="fas fa-phone-slash text-success me-2 admin-icon-fixed"></i>
                                        <span class="text-secondary me-2">تليفون الطوارئ:</span>
                                        <strong class="text-dark"
                                            dir="ltr"><?php echo htmlspecialchars($vp['phone_emergency'] ?? '-'); ?></strong>
                                    </div>
                                </div>

                                <?php if (!empty($vp['notes'])): ?>
                                    <div class="col-12">
                                        <div class="d-flex align-items-start py-2 border-bottom border-light">
                                            <i class="fas fa-comment-alt text-success me-2 mt-1 admin-icon-fixed"></i>
                                            <span class="text-secondary me-2">ملاحظات:</span>
                                            <span class="text-dark"><?php echo nl2br(htmlspecialchars($vp['notes'])); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- أولياء الأمور -->
                            <?php if (!empty($viewGuardians)): ?>
                                <h6 class="tab-section-title cyan mt-4">
                                    <i class="fas fa-user-friends me-2"></i>أولياء الأمور
                                </h6>
                                <?php foreach ($viewGuardians as $gi => $g): ?>
                                    <div class="border rounded-3 p-3 mb-3 bg-white shadow-sm">
                                        <div
                                            class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-light">
                                            <h6 class="mb-0 text-primary fw-bold">
                                                <i
                                                    class="fas fa-user-tie me-2 text-secondary"></i><?php echo htmlspecialchars($g['guardian_name'] ?? ''); ?>
                                                <?php if (!empty($g['is_primary'])): ?>
                                                    <span class="badge bg-success-subtle text-success ms-2 px-2 py-1 fs-7">أساسي</span>
                                                <?php endif; ?>
                                            </h6>
                                            <span
                                                class="badge bg-secondary-subtle text-secondary px-3 py-1.5 fs-7"><?php echo $relationshipLabels[$g['relationship'] ?? ''] ?? ($g['relationship'] ?? '-'); ?></span>
                                        </div>

                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                    <i class="fas fa-id-card text-info me-2 admin-icon-fixed"></i>
                                                    <span class="text-secondary me-2">الرقم القومي للمصريين:</span>
                                                    <strong
                                                        class="text-dark"><?php echo htmlspecialchars($g['national_id'] ?? '-'); ?></strong>
                                                </div>
                                            </div>

                                            <div class="col-md-4">
                                                <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                    <i class="fas fa-phone text-info me-2 admin-icon-fixed"></i>
                                                    <span class="text-secondary me-2">رقم الموبايل الأساسي:</span>
                                                    <strong class="text-dark"
                                                        dir="ltr"><?php echo htmlspecialchars($g['phone_primary'] ?? '-'); ?></strong>
                                                </div>
                                            </div>

                                            <?php if (!empty($g['email'])): ?>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                        <i class="fas fa-envelope text-info me-2 admin-icon-fixed"></i>
                                                        <span class="text-secondary me-2">البريد الإلكتروني:</span>
                                                        <strong class="text-dark"><?php echo htmlspecialchars($g['email']); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($g['job_title'])): ?>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                        <i class="fas fa-briefcase text-info me-2 admin-icon-fixed"></i>
                                                        <span class="text-secondary me-2">الوظيفة:</span>
                                                        <strong
                                                            class="text-dark"><?php echo htmlspecialchars($g['job_title']); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($g['employer'])): ?>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                        <i class="fas fa-building text-info me-2 admin-icon-fixed"></i>
                                                        <span class="text-secondary me-2">جهة العمل:</span>
                                                        <strong
                                                            class="text-dark"><?php echo htmlspecialchars($g['employer']); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($g['address'])): ?>
                                                <div class="col-md-8">
                                                    <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                        <i class="fas fa-map-marker-alt text-info me-2 admin-icon-fixed"></i>
                                                        <span class="text-secondary me-2">العنوان:</span>
                                                        <strong
                                                            class="text-dark"><?php echo htmlspecialchars($g['address']); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($g['qualification'])): ?>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                        <i class="fas fa-graduation-cap text-info me-2 admin-icon-fixed"></i>
                                                        <span class="text-secondary me-2">المؤهل الدراسي:</span>
                                                        <strong
                                                            class="text-dark"><?php echo htmlspecialchars($g['qualification']); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($g['birth_place'])): ?>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                        <i class="fas fa-map-marker-alt text-info me-2 admin-icon-fixed"></i>
                                                        <span class="text-secondary me-2">محل الميلاد:</span>
                                                        <strong
                                                            class="text-dark"><?php echo htmlspecialchars($g['birth_place']); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($g['passport_number'])): ?>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                        <i class="fas fa-passport text-info me-2 admin-icon-fixed"></i>
                                                        <span class="text-secondary me-2">رقم جواز السفر:</span>
                                                        <strong class="text-dark"
                                                            dir="ltr"><?php echo htmlspecialchars($g['passport_number']); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($g['nationality'])): ?>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                        <i class="fas fa-globe text-info me-2 admin-icon-fixed"></i>
                                                        <span class="text-secondary me-2">الجنسية:</span>
                                                        <strong
                                                            class="text-dark"><?php echo htmlspecialchars($g['nationality']); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($g['religion'])): ?>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center py-1 border-bottom border-light">
                                                        <i class="fas fa-mosque text-info me-2 admin-icon-fixed"></i>
                                                        <span class="text-secondary me-2">الديانة:</span>
                                                        <strong
                                                            class="text-dark"><?php echo htmlspecialchars($g['religion']); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- الحالة والمسار الدراسي السنوي -->
                            <?php if (!empty($viewAcademicHistory)): ?>
                                <h6 class="tab-section-title blue mt-4">
                                    <i class="fas fa-route me-2"></i>الحالة والمسار الدراسي
                                </h6>
                                <div class="card border border-light-subtle shadow-sm rounded-3 mb-4 overflow-hidden">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="ps-3 text-secondary">العام الدراسي</th>
                                                    <th class="text-secondary">حالة القيد</th>
                                                    <th class="text-secondary">الحالة الدراسية</th>
                                                    <th class="text-secondary">المرحلة</th>
                                                    <th class="text-secondary">الصف</th>
                                                    <th class="pe-3 text-secondary">الفصل</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($viewAcademicHistory as $historyEntry): ?>
                                                    <?php
                                                    $historyEnrollmentStatus = (string)($historyEntry['enrollment_status'] ?? 'enrolled');
                                                    $historyAcademicStatus = (string)($historyEntry['academic_status'] ?? ($historyEntry['promotion_type'] ?? 'new'));
                                                    ?>
                                                    <tr>
                                                        <td class="ps-3 fw-bold text-dark">
                                                            <?php echo htmlspecialchars((string)($historyEntry['academic_year'] ?? '-')); ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $viewEnrollmentClasses[$historyEnrollmentStatus] ?? 'secondary'; ?>">
                                                                <?php echo htmlspecialchars($viewEnrollmentLabels[$historyEnrollmentStatus] ?? $historyEnrollmentStatus); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $viewAcademicClasses[$historyAcademicStatus] ?? 'secondary'; ?>">
                                                                <?php echo htmlspecialchars($viewAcademicLabels[$historyAcademicStatus] ?? $historyAcademicStatus); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars((string)($historyEntry['stage_name'] ?? '-')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($historyEntry['to_grade'] ?? '-')); ?></td>
                                                        <td class="pe-3"><?php echo htmlspecialchars((string)($historyEntry['to_class'] ?? '-')); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- الأشقاء في المدرسة -->
                            <?php if (!empty($viewSiblings)): ?>
                                <h6 class="tab-section-title amber mt-4">
                                    <i class="fas fa-user-friends me-2"></i>الأشقاء في المدرسة
                                </h6>
                                <div class="card border border-light-subtle shadow-sm rounded-3 mb-4 overflow-hidden">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="ps-3 text-secondary">الاسم</th>
                                                    <th class="text-secondary">الكود</th>
                                                    <th class="text-secondary">الفصل</th>
                                                    <th class="pe-3 text-secondary">صلة القرابة</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($viewSiblings as $sib): ?>
                                                    <tr>
                                                        <td class="ps-3 fw-bold text-dark">
                                                            <?php echo htmlspecialchars($sib['sibling_name']); ?>
                                                        </td>
                                                        <td dir="ltr"><?php echo htmlspecialchars($sib['student_code'] ?? '-'); ?>
                                                        </td>
                                                        <td><span
                                                                class="badge bg-primary-subtle text-primary"><?php echo htmlspecialchars($sib['class_name'] ?? '-'); ?></span>
                                                        </td>
                                                        <td class="pe-3"><span
                                                                class="badge bg-secondary-subtle text-secondary"><?php echo $siblingRelLabels[$sib['relationship']] ?? $sib['relationship']; ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- الأقارب (صلات القرابة) -->
                            <?php if (!empty($viewKinships)): ?>
                                <h6 class="tab-section-title indigo mt-4">
                                    <i class="fas fa-sitemap me-2"></i>الأقارب في المدرسة
                                </h6>
                                <div class="card border border-light-subtle shadow-sm rounded-3 mb-4 overflow-hidden">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="ps-3 text-secondary">الاسم</th>
                                                    <th class="text-secondary">الكود</th>
                                                    <th class="text-secondary">الفصل</th>
                                                    <th class="pe-3 text-secondary">صلة القرابة</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($viewKinships as $kin): ?>
                                                    <tr>
                                                        <td class="ps-3 fw-bold text-dark">
                                                            <?php echo htmlspecialchars($kin['relative_name']); ?>
                                                        </td>
                                                        <td dir="ltr"><?php echo htmlspecialchars($kin['relative_code'] ?? '-'); ?>
                                                        </td>
                                                        <td><span
                                                                class="badge bg-primary-subtle text-primary"><?php echo htmlspecialchars($kin['relative_class'] ?? '-'); ?></span>
                                                        </td>
                                                        <td class="pe-3"><span
                                                                class="badge bg-info-subtle text-info"><?php echo htmlspecialchars($kin['kinship_name']); ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- البيانات الصحية والنفسية -->
                            <?php if ($hasHealthData): ?>
                                <h6 class="tab-section-title red mt-4">
                                    <i class="fas fa-heartbeat me-2"></i>البيانات الصحية والنفسية
                                </h6>
                                <div class="row g-3 mb-4">
                                    <?php if (!empty($vp['blood_type'])): ?>
                                        <div class="col-md-4">
                                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                                <i class="fas fa-tint text-danger me-2 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">فصيلة الدم:</span>
                                                <strong
                                                    class="text-danger fs-5"><?php echo htmlspecialchars($vp['blood_type']); ?></strong>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['insurance_number'])): ?>
                                        <div class="col-md-4">
                                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                                <i class="fas fa-file-medical text-danger me-2 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">رقم التأمين الصحي:</span>
                                                <strong
                                                    class="text-dark"><?php echo htmlspecialchars($vp['insurance_number']); ?></strong>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['insurance_start_date']) || !empty($vp['insurance_end_date'])): ?>
                                        <div class="col-md-4">
                                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                                <i class="fas fa-calendar-alt text-danger me-2 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">فترة التأمين:</span>
                                                <strong class="text-dark fs-7">
                                                    <?php echo !empty($vp['insurance_start_date']) ? 'من: ' . htmlspecialchars($vp['insurance_start_date']) : ''; ?>
                                                    <?php echo !empty($vp['insurance_end_date']) ? ' إلى: ' . htmlspecialchars($vp['insurance_end_date']) : ''; ?>
                                                </strong>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['health_status'])): ?>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                                <i class="fas fa-notes-medical text-danger me-2 mt-1 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">الحالة الصحية العامة:</span>
                                                <span
                                                    class="text-dark"><?php echo nl2br(htmlspecialchars($vp['health_status'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['chronic_diseases'])): ?>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                                <i class="fas fa-heartbeat text-danger me-2 mt-1 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">الأمراض المزمنة:</span>
                                                <span
                                                    class="text-danger fw-bold"><?php echo nl2br(htmlspecialchars($vp['chronic_diseases'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['allergies'])): ?>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                                <i class="fas fa-hand-holding-medical text-danger me-2 mt-1 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">الحساسية:</span>
                                                <span
                                                    class="text-dark"><?php echo nl2br(htmlspecialchars($vp['allergies'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['disabilities'])): ?>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                                <i class="fas fa-wheelchair text-danger me-2 mt-1 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">الإعاقات:</span>
                                                <span
                                                    class="text-dark"><?php echo nl2br(htmlspecialchars($vp['disabilities'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['medications'])): ?>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                                <i class="fas fa-pills text-danger me-2 mt-1 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">الأدوية الدائمة:</span>
                                                <span
                                                    class="text-dark"><?php echo nl2br(htmlspecialchars($vp['medications'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['treatment_plan'])): ?>
                                        <div class="col-12">
                                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                                <i class="fas fa-briefcase-medical text-danger me-2 mt-1 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">خطة العلاج:</span>
                                                <span
                                                    class="text-dark"><?php echo nl2br(htmlspecialchars($vp['treatment_plan'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['previous_medical_reports'])): ?>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                                <i class="fas fa-file-alt text-danger me-2 mt-1 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">تقارير طبية سابقة:</span>
                                                <span
                                                    class="text-dark"><?php echo nl2br(htmlspecialchars($vp['previous_medical_reports'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['emergency_medical_notes'])): ?>
                                        <div class="col-12">
                                            <div class="d-flex align-items-start py-2 border-bottom border-danger">
                                                <i class="fas fa-exclamation-triangle text-danger me-2 mt-1 admin-icon-fixed"></i>
                                                <span class="text-danger fw-bold me-2">ملاحظات طبية طارئة (حرج):</span>
                                                <span
                                                    class="text-danger fw-bold"><?php echo nl2br(htmlspecialchars($vp['emergency_medical_notes'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vp['psychological_notes'])): ?>
                                        <div class="col-12">
                                            <div class="d-flex align-items-start py-2 border-bottom border-warning">
                                                <i class="fas fa-brain text-warning me-2 mt-1 admin-icon-fixed"></i>
                                                <span class="text-secondary me-2">ملاحظات نفسية وسلوكية:</span>
                                                <span
                                                    class="text-dark"><?php echo nl2br(htmlspecialchars($vp['psychological_notes'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>



                            <!-- المرفقات -->
                            <?php if (!empty($viewAttachments)): ?>
                                <h6 class="fw-bold text-warning mb-3 d-flex align-items-center border-top pt-4">
                                    <span
                                        class="bg-warning-subtle text-warning rounded-circle p-2 d-flex align-items-center justify-content-center me-2 admin-square-icon-md">
                                        <i class="fas fa-paperclip fs-6"></i>
                                    </span>
                                    المرفقات
                                </h6>
                                <div class="card border border-light-subtle shadow-sm rounded-3 mb-4 overflow-hidden">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="ps-3 text-secondary admin-col-50px">#</th>
                                                    <th class="text-secondary">اسم المرفق</th>
                                                    <th class="text-secondary">الملف</th>
                                                    <th class="text-secondary">الحجم</th>
                                                    <th class="pe-3 text-secondary">التاريخ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($viewAttachments as $aidx => $vatt): ?>
                                                    <tr>
                                                        <td class="ps-3 text-muted"><?php echo $aidx + 1; ?></td>
                                                        <td><strong
                                                                class="text-dark"><?php echo htmlspecialchars($vatt['label']); ?></strong>
                                                        </td>
                                                        <td>
                                            <a href="<?php echo htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', (int)$vatt['id'])); ?>"
                                                                target="_blank"
                                                                class="btn btn-sm btn-link text-decoration-none p-0 d-inline-flex align-items-center">
                                                                <i class="fas fa-<?php
                                                                $vfext = strtolower(pathinfo($vatt['file_name'], PATHINFO_EXTENSION));
                                                                echo in_array($vfext, ['pdf']) ? 'file-pdf text-danger fs-5' :
                                                                    (in_array($vfext, ['doc', 'docx']) ? 'file-word text-primary fs-5' :
                                                                        (in_array($vfext, ['xls', 'xlsx']) ? 'file-excel text-success fs-5' :
                                                                            (in_array($vfext, ['jpg', 'jpeg', 'png', 'webp']) ? 'file-image text-info fs-5' : 'file text-secondary fs-5')));
                                                                ?> me-2"></i>
                                                                <span
                                                                    class="text-truncate admin-file-name"><?php echo htmlspecialchars($vatt['original_name']); ?></span>
                                                            </a>
                                                        </td>
                                                        <td><span
                                                                class="badge bg-light text-dark border"><?php echo round($vatt['file_size'] / 1024, 1); ?>
                                                                KB</span></td>
                                                        <td class="pe-3 text-muted">
                                                            <?php echo date('Y/m/d', strtotime($vatt['uploaded_at'])); ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>

                <!-- تم نقل زر تعديل البيانات إلى العمود الجانبي تحت الفصل الحالي -->
            </div>
        <?php endif; ?>
