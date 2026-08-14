<?php
// ===========================
// نموذج الإضافة/التعديل بتبويبات
// ===========================
if ($action === 'add' || $action === 'edit'):
    $sp = $staffProfile ?: []; // بيانات الملف الشخصي

    // كشف القيم المخصصة لحقول "أخرى"
    $standardReligions = ['', 'muslim', 'christian', 'other'];
    $religionIsCustom = !empty($sp['religion']) && !in_array($sp['religion'], $standardReligions);
    $religionOtherText = $religionIsCustom ? $sp['religion'] : '';

    $jobTitleIsCustom = !empty($sp['job_title']) && !in_array($sp['job_title'], $jobTitles);
    $jobTitleOtherText = $jobTitleIsCustom ? $sp['job_title'] : '';

    $fullNameArParts = split_staff_name_parts($sp['full_name_ar'] ?? '');
    $fullNameEnParts = split_staff_name_parts($sp['full_name_en'] ?? '');

    $maritalStatusOptions = array_merge($maritalLabels, ['other' => 'أخرى']);
    $currentMaritalStatus = (string)($sp['marital_status'] ?? '');
    $maritalStatusIsCustom = $currentMaritalStatus !== '' && !isset($maritalLabels[$currentMaritalStatus]);
    $maritalStatusOtherText = $maritalStatusIsCustom ? $currentMaritalStatus : '';

    // تحويل القسم من نص إلى مصفوفة (دعم الاختيار المتعدد)
    $selectedDepartments = [];
    $departmentOtherValues = [];
    if (!empty($sp['department'])) {
        foreach (array_map('trim', explode(',', $sp['department'])) as $deptValue) {
            if ($deptValue === '') {
                continue;
            }
            if (in_array($deptValue, $departments, true)) {
                $selectedDepartments[] = $deptValue;
            } else {
                $departmentOtherValues[] = $deptValue;
            }
        }
    }
    $departmentHasOther = !empty($departmentOtherValues);
    $departmentOtherText = implode('، ', $departmentOtherValues);
    if ($departmentHasOther) {
        $selectedDepartments[] = 'أخرى';
    }
    $currentContractType = (string)($sp['contract_type'] ?? '');
    $contractTypeIsCustom = $currentContractType !== '' && !isset($contractLabels[$currentContractType]);
    $contractTypeOtherText = $contractTypeIsCustom ? $currentContractType : '';
    $currentWorkStatus = (string)($sp['current_work_status'] ?? 'on_duty');
    $currentWorkStatusLabel = $currentWorkStatus === 'off_duty' ? 'ليس على رأس العمل' : 'على رأس العمل';
    $currentWorkStatusBadge = $currentWorkStatus === 'off_duty' ? 'danger' : 'success';

?>

<div class="modal fade" id="staffProfileModal" tabindex="-1" aria-labelledby="staffProfileModalTitle"
    aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
<form id="staffForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data"
    novalidate class="modal-content admin-modal admin-modal-premium <?php echo $action === 'add' ? 'admin-modal-create' : 'admin-modal-edit'; ?>">
    <?php echo csrfField(); ?>
    <?php if ($action === 'edit'): ?>
        <input type="hidden" name="id" value="<?php echo $user->id; ?>">
        <input type="hidden" name="record_version" value="<?php echo htmlspecialchars((string)($sp['updated_at'] ?? '')); ?>">
    <?php endif; ?>
    <input type="hidden" name="active_tab" id="active_tab_input" value="<?php echo htmlspecialchars($activeTab); ?>">

    <div class="d-flex flex-column h-100 overflow-hidden">
        <div class="modal-header d-flex flex-column align-items-stretch gap-2 pb-2">
            <div class="d-flex align-items-center justify-content-between w-100">
                <h5 class="modal-title d-flex align-items-center flex-wrap gap-1" id="staffProfileModalTitle">
                    <?php if ($action === 'edit'): ?>
                        <i class="fas fa-edit me-2"></i>تعديل بيانات الموظف:
                        <span class="badge bg-light text-dark ms-2" id="staff-name-header"><?php echo htmlspecialchars($user->name); ?></span>
                        <button type="button" class="btn btn-sm btn-light ms-2 px-2 py-0 border-0"
                                onclick="navigator.clipboard.writeText(document.getElementById('staff-name-header').innerText); const icon = this.querySelector('i'); icon.className = 'fas fa-check text-success'; setTimeout(() => icon.className = 'fas fa-copy', 2000);"
                                data-bs-toggle="tooltip" title="نسخ الاسم">
                            <i class="fas fa-copy"></i>
                        </button>
                    <?php else: ?>
                        <i class="fas fa-plus-circle me-2"></i>إضافة موظف جديد
                    <?php endif; ?>
                </h5>
                <button type="button" class="btn-close" data-staff-modal-close aria-label="إغلاق"></button>
            </div>
            <?php if ($action === 'edit'): ?>
                <!-- شريط الحالة الحالية الموحد والمثبت في الهيدر -->
                <div class="staff-header-status-bar w-100">
                    <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                        <div class="status-item d-flex align-items-center gap-2">
                            <span class="status-item-label"><i class="fas fa-user-clock me-1 text-secondary"></i>الحالة الحالية:</span>
                            <span class="staff-status-badge <?php echo $currentWorkStatus === 'off_duty' ? 'inactive' : 'active'; ?>">
                                <?php echo $currentWorkStatusLabel; ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span class="status-item-label">تاريخ السريان:</span>
                            <span class="status-item-value ms-1"><?php echo htmlspecialchars($sp['current_status_effective_date'] ?? '-'); ?></span>
                        </div>
                        <div class="status-item">
                            <span class="status-item-label">نوع التعاقد:</span>
                            <span class="status-item-value ms-1"><?php echo htmlspecialchars($contractLabels[$sp['contract_type'] ?? ''] ?? ($sp['contract_type'] ?? '-')); ?></span>
                        </div>
                        <div class="status-item">
                            <span class="status-item-label">المسمى الوظيفي:</span>
                            <span class="status-item-value ms-1"><?php echo htmlspecialchars($sp['job_title'] ?? '-'); ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-body d-flex flex-column overflow-hidden">

            <!-- التبويبات -->
            <ul class="nav nav-tabs nav-fill flex-nowrap overflow-auto mb-3" id="staffTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab === 'basic' ? 'active' : ''; ?>" id="tab-basic" data-bs-toggle="tab" data-bs-target="#pane-basic" type="button" role="tab">
                        <i class="fas fa-user me-1"></i>البيانات الأساسية
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab === 'employment' ? 'active' : ''; ?>" id="tab-employment" data-bs-toggle="tab" data-bs-target="#pane-employment" type="button" role="tab">
                        <i class="fas fa-briefcase me-1"></i>البيانات الوظيفية
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab === 'qualifications' ? 'active' : ''; ?>" id="tab-qualifications" data-bs-toggle="tab" data-bs-target="#pane-qualifications" type="button" role="tab">
                        <i class="fas fa-graduation-cap me-1"></i>المؤهلات والخبرات
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab === 'health' ? 'active' : ''; ?>" id="tab-health" data-bs-toggle="tab" data-bs-target="#pane-health" type="button" role="tab">
                        <i class="fas fa-heartbeat me-1"></i>البيانات الصحية والنفسية
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab === 'attachments' ? 'active' : ''; ?>" id="tab-attachments" data-bs-toggle="tab" data-bs-target="#pane-attachments" type="button" role="tab">
                        <i class="fas fa-paperclip me-1"></i>المرفقات
                    </button>
                </li>
            </ul>

            <div class="staff-profile-tab-scroll flex-grow-1 overflow-auto">
            <div class="tab-content" id="staffTabContent">

                <!-- ====== تبويب: البيانات الشخصية ====== -->
                <div class="tab-pane fade <?php echo $activeTab === 'basic' ? 'show active' : ''; ?>" id="pane-basic" role="tabpanel">
                    <!-- حقل الاسم المخفي - يتم ملؤه تلقائياً من الاسم باللغة العربية -->
                    <input type="hidden" id="name" name="name" value="<?php echo $action === 'edit' ? htmlspecialchars($user->name) : ''; ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <h6 class="tab-section-title amber"><i class="fas fa-id-card me-2"></i>البيانات الشخصية</h6>
                            <input type="hidden" id="full_name_ar_input" name="full_name_ar" value="<?php echo htmlspecialchars($sp['full_name_ar'] ?? ''); ?>">
                            <input type="hidden" id="full_name_en_input" name="full_name_en" value="<?php echo htmlspecialchars($sp['full_name_en'] ?? ''); ?>">
                            <input type="hidden" id="name_parts_ar_touched" name="name_parts_ar_touched" value="0">
                            <input type="hidden" id="name_parts_en_touched" name="name_parts_en_touched" value="0">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label text-dark small fw-bold mb-2">الاسم باللغة العربية <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <?php $arabicNameLabels = ['الاسم الأول', 'اسم الأب', 'اسم الجد', 'الاسم الرابع', 'اسم العائلة']; ?>
                                <?php foreach ($arabicNameLabels as $index => $label): ?>
                                <div class="col-md-6 col-xl">
                                    <input type="text" class="form-control staff-name-part-ar" name="full_name_ar_parts[]" value="<?php echo htmlspecialchars($fullNameArParts[$index] ?? ''); ?>" placeholder="<?php echo $label; ?>" <?php echo $index === 0 ? 'required' : ''; ?>>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label text-dark small fw-bold mb-2">الاسم باللغة الإنجليزية</label>
                            <div class="row g-2" dir="ltr">
                                <?php $englishNameLabels = ['First Name', 'Father Name', 'Grandfather Name', 'Fourth Name', 'Family Name']; ?>
                                <?php foreach ($englishNameLabels as $index => $label): ?>
                                <div class="col-md-6 col-xl">
                                    <input type="text" class="form-control staff-name-part-en" name="full_name_en_parts[]" value="<?php echo htmlspecialchars($fullNameEnParts[$index] ?? ''); ?>" placeholder="<?php echo $label; ?>" dir="ltr">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <!-- الصف الثالث: الديانة، النوع، الجنسية، الرقم القومي، رقم جواز السفر -->
                        <div class="col-md col-lg-2">
                            <label class="form-label">الديانة</label>
                            <select class="form-select other-toggle" name="religion" data-other-target="religion_other_input">
                                <option value="">-- اختر --</option>
                                <option value="muslim" <?php echo ($sp['religion'] ?? '') === 'muslim' ? 'selected' : ''; ?>>مسلم</option>
                                <option value="christian" <?php echo ($sp['religion'] ?? '') === 'christian' ? 'selected' : ''; ?>>مسيحي</option>
                                <option value="other" <?php echo (($sp['religion'] ?? '') === 'other' || $religionIsCustom) ? 'selected' : ''; ?>>أخرى</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="religion_other_input" name="religion_other" placeholder="حدد الديانة..." value="<?php echo htmlspecialchars($religionOtherText); ?>" style="display:<?php echo (($sp['religion'] ?? '') === 'other' || $religionIsCustom) ? 'block' : 'none'; ?>">
                        </div>
                        <div class="col-md col-lg-2">
                            <label class="form-label">النوع</label>
                            <select class="form-select" name="gender" id="staff_gender_select">
                                <option value="">-- اختر --</option>
                                <option value="male" <?php echo ($sp['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>ذكر</option>
                                <option value="female" <?php echo ($sp['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>أنثى</option>
                            </select>
                        </div>
                        <div class="col-md col-lg-2">
                            <label class="form-label">الجنسية</label>
                            <select class="form-select other-toggle" name="nationality" data-other-target="nationality_other_input">
                                <option value="">-- اختر --</option>
                                <?php
                                $nationalityOptions = ['مصري', 'سعودي', 'إماراتي', 'كويتي', 'بحريني', 'قطري', 'عماني', 'عراقي', 'سوري', 'لبناني', 'أردني', 'فلسطيني', 'يمني', 'ليبي', 'تونسي', 'جزائري', 'مغربي', 'سوداني', 'أخرى'];
                                $currentNat = $sp['nationality'] ?? '';
                                $nationalityIsCustom = !empty($currentNat) && !in_array($currentNat, $nationalityOptions, true);
                                foreach ($nationalityOptions as $nat):
                                ?>
                                    <option value="<?php echo $nat; ?>" <?php echo ($currentNat === $nat || ($nat === 'أخرى' && $nationalityIsCustom)) ? 'selected' : ''; ?>><?php echo $nat; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" class="form-control mt-2" id="nationality_other_input" name="nationality_other" placeholder="حدد الجنسية..." value="<?php echo htmlspecialchars($nationalityIsCustom ? $currentNat : ''); ?>" style="display:<?php echo ($currentNat === 'أخرى' || $nationalityIsCustom) ? 'block' : 'none'; ?>;">
                        </div>
                        <div class="col-md col-lg-3">
                            <label class="form-label">الرقم القومي للمصريين</label>
                            <input type="text" class="form-control national-id-input" name="national_id" value="<?php echo htmlspecialchars($sp['national_id'] ?? ''); ?>" maxlength="14" pattern="[0-9]{14}" dir="ltr" inputmode="numeric" placeholder="14 رقمًا">
                        </div>
                        <div class="col-md col-lg-3">
                            <label class="form-label">رقم جواز السفر</label>
                            <input type="text" class="form-control" name="passport_number" value="<?php echo htmlspecialchars($sp['passport_number'] ?? ''); ?>" placeholder="رقم جواز السفر">
                        </div>

                        <!-- الصف الرابع: تاريخ الميلاد، العمر الحالي، محل الميلاد، كود الموظف لدى المدرسة، كود الموظف بوزارة التربية والتعليم -->
                        <div class="col-md col-lg-2">
                            <label class="form-label">تاريخ الميلاد</label>
                            <input type="text" class="form-control flatpickr-date" name="birth_date" id="staff_birth_date" value="<?php echo $sp['birth_date'] ?? ''; ?>" placeholder="اختر التاريخ..." onchange="calculateStaffAge()">
                        </div>
                        <div class="col-md col-lg-2">
                            <label class="form-label">العمر الحالي للموظف</label>
                            <input type="text" class="form-control" id="staff_age_display" readonly value="" placeholder="يتم حسابه تلقائياً" dir="rtl">
                        </div>
                        <div class="col-md col-lg-2">
                            <label class="form-label">محل الميلاد</label>
                            <input type="text" class="form-control" name="birth_place" value="<?php echo htmlspecialchars($sp['birth_place'] ?? ''); ?>" placeholder="محل الميلاد">
                        </div>
                        <div class="col-md col-lg-3">
                            <label class="form-label">كود الموظف لدى المدرسة</label>
                            <input type="text" class="form-control" name="employee_code" value="<?php echo htmlspecialchars($sp['employee_code'] ?? ''); ?>" placeholder="يُنشأ تلقائياً عند الحفظ" dir="ltr" readonly>
                            <div class="form-text">كود داخلي مستقل يُنشئه النظام تلقائياً بصيغة E ثم السنة والرقم التسلسلي.</div>
                        </div>
                        <div class="col-md col-lg-3">
                            <label class="form-label">كود الموظف بوزارة التربية والتعليم</label>
                            <input type="text" class="form-control" name="ministry_code" value="<?php echo htmlspecialchars($sp['ministry_code'] ?? ''); ?>" placeholder="كود الموظف بالوزارة" dir="ltr">
                        </div>

                        <!-- الصف الخامس: رقم البصمة والموقف من التجنيد للذكور والموقف من الخدمة العامة للإناث -->
                        <div class="col-md-4">
                            <label class="form-label">رقم البصمة</label>
                            <input type="text" class="form-control" name="biometric_id" value="<?php echo htmlspecialchars($sp['biometric_id'] ?? ''); ?>" placeholder="رقم الموظف على جهاز البصمة" dir="ltr" inputmode="numeric">
                        </div>
                        <div class="col-md-4" id="military_status_col">
                            <label class="form-label">الموقف من التجنيد للذكور</label>
                            <select class="form-select" name="military_status" id="military_status_select">
                                <option value="">-- اختر --</option>
                                <option value="أدى الخدمة" <?php echo ($sp['military_status'] ?? '') === 'أدى الخدمة' ? 'selected' : ''; ?>>أدى الخدمة</option>
                                <option value="معافى نهائي" <?php echo ($sp['military_status'] ?? '') === 'معافى نهائي' ? 'selected' : ''; ?>>معافى نهائي</option>
                                <option value="معافى مؤقت" <?php echo ($sp['military_status'] ?? '') === 'معافى مؤقت' ? 'selected' : ''; ?>>معافى مؤقت</option>
                                <option value="مؤجل" <?php echo ($sp['military_status'] ?? '') === 'مؤجل' ? 'selected' : ''; ?>>مؤجل</option>
                                <option value="لم يصبه الدور" <?php echo ($sp['military_status'] ?? '') === 'لم يصبه الدور' ? 'selected' : ''; ?>>لم يصبه الدور</option>
                                <option value="تحت الطلب" <?php echo ($sp['military_status'] ?? '') === 'تحت الطلب' ? 'selected' : ''; ?>>تحت الطلب</option>
                                <option value="غير مطلوب" <?php echo ($sp['military_status'] ?? '') === 'غير مطلوب' ? 'selected' : ''; ?>>غير مطلوب</option>
                                <option value="غير منطبق" <?php echo ($sp['military_status'] ?? '') === 'غير منطبق' ? 'selected' : ''; ?>>غير منطبق</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="public_service_status_col">
                            <label class="form-label">الموقف من أداء الخدمة العامة للإناث</label>
                            <select class="form-select" name="public_service_status" id="public_service_status_select">
                                <option value="">-- اختر --</option>
                                <option value="أدت الخدمة" <?php echo ($sp['public_service_status'] ?? '') === 'أدت الخدمة' ? 'selected' : ''; ?>>أدت الخدمة</option>
                                <option value="إعفاء" <?php echo ($sp['public_service_status'] ?? '') === 'إعفاء' ? 'selected' : ''; ?>>إعفاء</option>
                                <option value="تأجيل" <?php echo ($sp['public_service_status'] ?? '') === 'تأجيل' ? 'selected' : ''; ?>>تأجيل</option>
                                <option value="غير مطلوبة" <?php echo ($sp['public_service_status'] ?? '') === 'غير مطلوبة' ? 'selected' : ''; ?>>غير مطلوبة</option>
                                <option value="غير منطبق" <?php echo ($sp['public_service_status'] ?? '') === 'غير منطبق' ? 'selected' : ''; ?>>غير منطبق</option>
                            </select>
                        </div>
                        <!-- قسم البيانات الاجتماعية -->
                        <div class="col-12 mt-2">
                            <h6 class="tab-section-title blue"><i class="fas fa-users me-2"></i>البيانات الاجتماعية</h6>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">الحالة الاجتماعية</label>
                            <select class="form-select other-toggle" name="marital_status" id="marital_status_select" data-other-target="marital_status_other_input">
                                <option value="">-- اختر --</option>
                                <?php foreach ($maritalStatusOptions as $k => $v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo (($currentMaritalStatus === $k) || ($k === 'other' && $maritalStatusIsCustom)) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" class="form-control mt-2" id="marital_status_other_input" name="marital_status_other" placeholder="حدد الحالة الاجتماعية..." value="<?php echo htmlspecialchars($maritalStatusOtherText); ?>" style="display:<?php echo (($currentMaritalStatus === 'other') || $maritalStatusIsCustom) ? 'block' : 'none'; ?>;">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">عدد الأبناء للمتزوجين</label>
                            <input type="number" class="form-control" name="number_of_children" min="0" max="20" value="<?php echo (int)($sp['number_of_children'] ?? 0); ?>">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">ملاحظات اجتماعية</label>
                            <input type="text" class="form-control" name="notes" value="<?php echo htmlspecialchars($sp['notes'] ?? ''); ?>" placeholder="اكتب أي ملاحظات اجتماعية هنا...">
                        </div>

                        <!-- قسم العناوين وبيانات التواصل -->
                        <div class="col-12">
                            <h6 class="tab-section-title purple"><i class="fas fa-map-marker-alt me-2"></i>العناوين وبيانات التواصل</h6>
                        </div>
                        <!-- الصف الأول: المدينة والمنطقة ثم العنوان التفصيلي -->
                        <div class="col-md-4">
                            <label class="form-label">المدينة / المنطقة</label>
                            <input type="text" class="form-control" name="city_area" value="<?php echo htmlspecialchars($sp['city_area'] ?? ''); ?>" placeholder="المدينة أو المنطقة">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">العنوان التفصيلي</label>
                            <input type="text" class="form-control" name="address_detail" value="<?php echo htmlspecialchars($sp['address_detail'] ?? ''); ?>" placeholder="الحي، الشارع، رقم المبنى...">
                        </div>
                        <!-- الصف الثاني: رقم الموبايل الأساسي ورقم الهاتف الأرضي والبريد الإلكتروني -->
                        <div class="col-md-4">
                            <label class="form-label">رقم الموبايل الأساسي</label>
                            <input type="text" class="form-control mobile-input" name="phone_mobile" value="<?php echo htmlspecialchars($sp['phone_mobile'] ?? ''); ?>" dir="ltr" maxlength="11" pattern="[0-9]{11}" inputmode="numeric" placeholder="11 رقمًا">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">رقم الهاتف الأرضي الأساسي</label>
                            <input type="text" class="form-control landline-input" name="phone_home" value="<?php echo htmlspecialchars($sp['phone_home'] ?? ''); ?>" dir="ltr" pattern="[0-9]*" inputmode="numeric" placeholder="أرقام فقط">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">البريد الإلكتروني</label>
                            <input type="email" class="form-control" name="email_personal" value="<?php echo htmlspecialchars($sp['email_personal'] ?? ''); ?>" dir="ltr" placeholder="example@mail.com">
                        </div>
                        <!-- الصف الثالث: رقم الطوارئ ثم اسم شخص الطوارئ -->
                        <div class="col-md-6">
                            <label class="form-label">رقم الطوارئ</label>
                            <input type="text" class="form-control mobile-input" name="phone_emergency" value="<?php echo htmlspecialchars($sp['phone_emergency'] ?? ''); ?>" dir="ltr" maxlength="11" pattern="[0-9]{11}" inputmode="numeric" placeholder="11 رقمًا">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">اسم شخص الطوارئ</label>
                            <input type="text" class="form-control" name="emergency_contact_name" value="<?php echo htmlspecialchars($sp['emergency_contact_name'] ?? ''); ?>" placeholder="الاسم الكامل لجهة الاتصال">
                        </div>
                        <!-- الهواتف الإضافية -->
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">أرقام هواتف إضافية مع ملاحظة لكل رقم</label>
                                <button type="button" class="btn btn-outline-success btn-sm" id="addStaffMobileBtn"><i class="fas fa-plus me-1"></i>إضافة موبايل أو رقم هاتف إضافي</button>
                            </div>
                            <div id="staffMobilesContainer"></div>
                        </div>

                        <!-- إضافة بيانات أخرى -->
                        <div class="col-12">
                            <h6 class="tab-section-title red"><i class="fas fa-plus-square me-2"></i>إضافة بيانات أخرى</h6>
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">بيانات إضافية (مسمى البيانات + بيانها)</label>
                                <button type="button" class="btn btn-outline-success btn-sm" id="addAdditionalDataBtn"><i class="fas fa-plus me-1"></i>إضافة بيان</button>
                            </div>
                            <div id="additionalDataContainer"></div>
                        </div>

                        <!-- ملاحظات إدارية -->
                        <div class="col-12 mt-4">
                            <h6 class="tab-section-title purple"><i class="fas fa-note-sticky me-2"></i>ملاحظة إدارية</h6>
                        </div>
                        <div class="col-12 mb-2">
                            <textarea class="form-control" id="staff_admin_notes" name="admin_notes" rows="3"
                                maxlength="1000" placeholder="اكتب أي ملاحظات إدارية هنا..."><?php echo htmlspecialchars($sp['admin_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                </div>

                <!-- ====== تبويب 2: البيانات الوظيفية ====== -->
                <div class="tab-pane fade <?php echo $activeTab === 'employment' ? 'show active' : ''; ?>" id="pane-employment" role="tabpanel">
                    <div class="row g-2">
                        <div class="col-12">
                            <h6 class="tab-section-title blue"><i class="fas fa-file-contract me-2"></i>بيانات التعاقد والسجل الوظيفي</h6>
                        </div>

                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <span class="text-secondary small">الحركات المسجلة</span>
                                <button type="button" class="btn btn-outline-success btn-sm" id="add_status_btn">
                                    <i class="fas fa-user-clock me-1"></i>إضافة حالة أخرى
                                </button>
                            </div>
                            <input type="hidden" name="status_history" id="status_history_data" value="<?php echo htmlspecialchars($sp['status_history'] ?? '[]'); ?>">
                            <div id="status_history_container" class="mb-2"></div>
                        </div>

                        <!-- قسم الترقيات والتدرج الوظيفي -->
                        <div class="col-12 mt-1">
                            <h6 class="tab-section-title purple"><i class="fas fa-level-up-alt me-2"></i>الترقيات والتدرج الوظيفي</h6>
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <span class="text-secondary small">الحركات التي تغير المسمى أو الدرجة أو القسم أو نوع التعاقد.</span>
                                <button type="button" class="btn btn-outline-success btn-sm" id="add_promotion_btn">
                                    <i class="fas fa-plus me-1"></i>إضافة حركة وظيفية
                                </button>
                            </div>
                            <input type="hidden" name="promotions" id="promotions_data" value="<?php echo htmlspecialchars($sp['promotions'] ?? '[]'); ?>">
                            <div id="promotions_container" class="mb-3"></div>
                        </div>

                        <!-- إضافة بيانات أخرى (البيانات الوظيفية) -->
                        <div class="col-12 mt-3">
                            <h6 class="tab-section-title red"><i class="fas fa-plus-square me-2"></i>إضافة بيانات أخرى</h6>
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">بيانات إضافية (مسمى البيانات + بيانها)</label>
                                <button type="button" class="btn btn-outline-success btn-sm" id="addEmploymentExtraDataBtn"><i class="fas fa-plus me-1"></i>إضافة بيان</button>
                            </div>
                            <div id="employmentExtraDataContainer"></div>
                        </div>
                    </div>

                </div>

                <!-- ====== تبويب 3: المؤهلات والخبرات ====== -->
                <div class="tab-pane fade <?php echo $activeTab === 'qualifications' ? 'show active' : ''; ?>" id="pane-qualifications" role="tabpanel">
                    <h6 class="tab-section-title blue"><i class="fas fa-graduation-cap me-2"></i>المؤهلات العلمية</h6>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">المؤهل الدراسي</label>
                            <input type="text" class="form-control" name="qualification" value="<?php echo htmlspecialchars($sp['qualification'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">سنة التخرج</label>
                            <input type="number" class="form-control" name="qualification_year" min="1960" max="2030" value="<?php echo $sp['qualification_year'] ?? ''; ?>" dir="ltr">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">الجامعة / المعهد</label>
                            <input type="text" class="form-control" name="qualification_university" value="<?php echo htmlspecialchars($sp['qualification_university'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">التخصص</label>
                            <input type="text" class="form-control" name="specialization" value="<?php echo htmlspecialchars($sp['specialization'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- المؤهلات الإضافية الديناميكية -->
                    <div class="mt-4">
                        <h6 class="tab-section-title blue"><i class="fas fa-award me-2"></i>المؤهلات الدراسية والشهادات العلمية الأخرى</h6>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-secondary small">أضف المؤهلات العلمية الإضافية والدرجات الأكاديمية للموظف.</span>
                            <button type="button" class="btn btn-outline-success btn-sm" id="add_other_qualification_btn">
                                <i class="fas fa-plus me-1"></i>إضافة مؤهل دراسي
                            </button>
                        </div>
                        <input type="hidden" name="other_qualifications" id="other_qualifications_data" value="<?php echo htmlspecialchars($sp['other_qualifications'] ?? '[]'); ?>">
                        <div id="other_qualifications_container" class="mb-3"></div>
                    </div>

                    <h6 class="tab-section-title green"><i class="fas fa-certificate me-2"></i>الدورات التدريبية و الشهادات العلمية</h6>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-secondary small">أضف الدورات التدريبية والشهادات المهنية التي حصل عليها الموظف.</span>
                        <button type="button" class="btn btn-outline-success btn-sm" id="add_training_course_btn">
                            <i class="fas fa-plus me-1"></i>إضافة دورة تدريبية
                        </button>
                    </div>
                    <input type="hidden" name="training_courses" id="training_courses_data" value="<?php echo htmlspecialchars($sp['training_courses'] ?? '[]'); ?>">
                    <div id="training_courses_container" class="mb-3"></div>

                    <h6 class="tab-section-title purple"><i class="fas fa-building me-2"></i>الخبرات و أماكن العمل السابقة</h6>
                    <p class="text-secondary small mb-3">أضف سجل الخبرات الوظيفية وأماكن العمل السابقة للموظف.</p>

                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">عدد سنوات الخبرة</label>
                            <input type="number" class="form-control" name="years_of_experience" step="0.5" min="0" max="50" value="<?php echo $sp['years_of_experience'] ?? ''; ?>" dir="ltr">
                        </div>
                        <div class="col-md-8 d-flex justify-content-end pb-1">
                            <button type="button" class="btn btn-outline-success btn-sm" id="add_work_history">
                                <i class="fas fa-plus me-1"></i>إضافة مكان عمل سابق
                            </button>
                        </div>
                    </div>

                    <input type="hidden" name="work_history" id="work_history_data" value="<?php echo htmlspecialchars($sp['work_history'] ?? '[]'); ?>">
                    <div id="work_history_container">
                        <!-- يتم ملؤها بـ JavaScript -->
                    </div>

                </div>

                <!-- ====== تبويب: البيانات الصحية والنفسية ====== -->
                <div class="tab-pane fade <?php echo $activeTab === 'health' ? 'show active' : ''; ?>" id="pane-health" role="tabpanel">
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
                            <input type="text" class="form-control" name="insurance_number" value="<?php echo htmlspecialchars($sp['insurance_number'] ?? ''); ?>" dir="ltr">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">تاريخ بداية التأمين</label>
                            <input type="text" class="form-control flatpickr-date" name="insurance_start_date" value="<?php echo htmlspecialchars($sp['insurance_start_date'] ?? ''); ?>" placeholder="اختر التاريخ..." dir="ltr">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">تاريخ نهاية التأمين</label>
                            <input type="text" class="form-control flatpickr-date" name="insurance_end_date" value="<?php echo htmlspecialchars($sp['insurance_end_date'] ?? ''); ?>" placeholder="اختر التاريخ..." dir="ltr">
                        </div>

                        <!-- الصف الثاني -->
                        <div class="col-md-6">
                            <label class="form-label">الحالة الصحية العامة</label>
                            <textarea class="form-control" name="health_status" rows="2"><?php echo htmlspecialchars($sp['health_status'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الأمراض المزمنة</label>
                            <textarea class="form-control" name="chronic_diseases" rows="2"><?php echo htmlspecialchars($sp['chronic_diseases'] ?? ''); ?></textarea>
                        </div>

                        <!-- الصف الثالث -->
                        <div class="col-md-6">
                            <label class="form-label">الحساسية</label>
                            <textarea class="form-control" name="allergies" rows="2"><?php echo htmlspecialchars($sp['allergies'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الإعاقات (إن وجدت)</label>
                            <textarea class="form-control" name="disabilities" rows="2"><?php echo htmlspecialchars($sp['disabilities'] ?? ''); ?></textarea>
                        </div>

                        <!-- الصف الرابع -->
                        <div class="col-md-6">
                            <label class="form-label">العلاج / الأدوية</label>
                            <textarea class="form-control" name="medications" rows="2"><?php echo htmlspecialchars($sp['medications'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">خطط علاجية متبعة</label>
                            <textarea class="form-control" name="treatment_plan" rows="2"><?php echo htmlspecialchars($sp['treatment_plan'] ?? ''); ?></textarea>
                        </div>

                        <!-- الصف الخامس -->
                        <div class="col-md-6">
                            <label class="form-label">تقارير طبية سابقة</label>
                            <textarea class="form-control" name="previous_medical_reports" rows="2"><?php echo htmlspecialchars($sp['previous_medical_reports'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ملاحظات طبية طارئة</label>
                            <textarea class="form-control" name="emergency_medical_notes" rows="2"><?php echo htmlspecialchars($sp['emergency_medical_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <h6 class="tab-section-title purple"><i class="fas fa-brain me-2"></i>الحالة النفسية والسلوكية</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label">ملاحظات نفسية وسلوكية</label>
                            <textarea class="form-control" name="psychological_notes" rows="3"><?php echo htmlspecialchars($sp['psychological_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                </div>

                <!-- ====== تبويب: المرفقات ====== -->
                <div class="tab-pane fade <?php echo $activeTab === 'attachments' ? 'show active' : ''; ?>" id="pane-attachments" role="tabpanel">
                    <h6 class="tab-section-title amber"><i class="fas fa-paperclip me-2"></i>مرفقات الموظف</h6>

                    <?php if ($action === 'edit'): ?>
                        <div class="alert alert-light border shadow-sm p-4">
                            <!-- قسم رفع الصورة الشخصية والمرفقات الأخرى -->
                            <div class="row g-2 align-items-end mb-4 pb-4 border-bottom">
                                <div class="col-md-5">
                                    <label class="form-label small text-secondary"><i
                                            class="fas fa-camera me-1"></i>الصورة الشخصية</label>
                                    <input type="file" class="form-control form-control-sm" id="staff_profile_image_file" accept="image/jpeg,image/png,image/webp">
                                </div>
                                <div class="col-md-3 d-flex align-items-center gap-2 mb-1" id="current_staff_avatar_container" style="display: <?php echo !empty($sp['profile_image']) ? 'flex' : 'none'; ?> !important;">
                                    <span class="small text-secondary">الصورة الحالية:</span>
                                    <a href="<?php echo !empty($sp['profile_image']) ? '../uploads/staff/' . htmlspecialchars($sp['profile_image']) : '#'; ?>" target="_blank" id="current_staff_avatar_link">
                                        <img src="<?php echo !empty($sp['profile_image']) ? '../uploads/staff/' . htmlspecialchars($sp['profile_image']) : ''; ?>" class="rounded border shadow-sm" style="width:31px;height:31px;object-fit:cover;" alt="صورة الموظف" id="current_staff_avatar_img">
                                    </a>
                                </div>
                                <div class="col-md-4 d-flex align-items-center ms-auto justify-content-md-end mb-1">
                                    <input type="file" id="staff_attachment_file_input" multiple
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" style="display:none;">
                                    <button type="button" class="btn btn-primary shadow-sm" id="staff_upload_attachment_btn">
                                        <i class="fas fa-cloud-upload-alt me-2"></i>رفع مرفقات إضافية
                                    </button>
                                </div>
                            </div>

                            <!-- قسم رفع المرفقات الأخرى (رفع فوري) -->
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <span class="text-muted small"><i class="fas fa-info-circle me-1"></i>الأنواع المسموحة: PDF, Word, Excel, صور (JPG, PNG, WebP) - حد أقصى 10MB لكل ملف. تُرفع جميع الملفات والصورة الشخصية فوراً عند اختيارها مع عرض نسبة التقدم.</span>
                                </div>
                            </div>
                        </div>

                        <!-- جدول المرفقات الحالية -->
                        <div class="table-responsive" id="staffAttachmentsTableWrap" <?php echo empty($staffAttachments) ? 'style="display:none;"' : ''; ?>>
                            <table class="table table-hover table-bordered align-middle" id="staffAttachmentsTable">
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
                                <tbody id="staffAttachmentsTableBody">
                                    <?php if (!empty($staffAttachments)): ?>
                                        <?php foreach ($staffAttachments as $saidx => $satt): ?>
                                            <tr data-attachment-id="<?php echo (int) $satt['id']; ?>">
                                                <td class="att-index"><?php echo $saidx + 1; ?></td>
                                                <td><strong class="att-label"><?php echo htmlspecialchars($satt['label']); ?></strong></td>
                                                <td>
                                            <a href="<?php echo htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('staff', (int)$satt['id'])); ?>" target="_blank" class="text-decoration-none">
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
                                                        <?php if (($satt['label'] ?? '') !== 'الصورة الشخصية'): ?>
                                                        <button type="button" class="btn btn-action-pills btn-edit att-rename-btn" data-bs-toggle="tooltip" title="تعديل الاسم"
                                                             data-attachment-id="<?php echo (int) $satt['id']; ?>"
                                                             data-attachment-label="<?php echo htmlspecialchars($satt['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                                             <i class="fas fa-edit"></i>
                                                         </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-action-pills btn-delete att-delete-btn" data-bs-toggle="tooltip" title="حذف"
                                                             data-attachment-id="<?php echo (int) $satt['id']; ?>"
                                                             data-attachment-label="<?php echo htmlspecialchars($satt['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                                             <i class="fas fa-trash"></i>
                                                         </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="staffAttachmentsEmpty" <?php echo !empty($staffAttachments) ? 'style="display:none;"' : ''; ?>>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>لا توجد مرفقات حالياً. استخدم زر «رفع مرفق» لإضافة مرفقات.
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- وضع الإضافة: رسالة -->
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>يمكنك إضافة المرفقات بعد حفظ بيانات الموظف أولاً.
                        </div>
                    <?php endif; ?>

                </div>

            </div><!-- end tab-content -->
            </div><!-- /staff-profile-tab-scroll -->
        </div><!-- /modal-body -->
        <div class="modal-footer">
            <a href="staff.php" class="btn btn-secondary" data-modal-cancel><i class="fas fa-times me-1"></i>إلغاء</a>
            <button type="submit" name="<?php echo $action === 'add' ? 'add_staff' : 'edit_staff'; ?>"
                class="btn <?php echo $action === 'add' ? 'btn-success' : 'btn-primary'; ?>">
                <i class="fas fa-save me-1"></i><?php echo $action === 'add' ? 'إضافة الموظف' : 'حفظ جميع التغييرات'; ?>
            </button>
        </div>
    </div><!-- /modal layout -->
</form>
    </div>
</div>

        <!-- نماذج مخفية لرفع الصورة وحذف المرفقات (خارج النموذج الرئيسي) -->
        <?php if ($action === 'edit'): ?>
        <form id="uploadStaffProfileImageForm" method="POST" action="staff.php?action=edit&id=<?php echo $user->id; ?>" enctype="multipart/form-data" style="display:none;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="upload_staff_profile_image">
            <input type="hidden" name="id" value="<?php echo $user->id; ?>">
            <input type="file" name="profile_image" id="hidden_staff_profile_image_file" accept="image/jpeg,image/png,image/webp">
        </form>
        <form id="deleteStaffAttachmentForm" method="POST" action="staff.php?action=edit&id=<?php echo $user->id; ?>" style="display:none;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_staff_attachment">
            <input type="hidden" name="id" value="<?php echo $user->id; ?>">
            <input type="hidden" name="attachment_id" id="hidden_delete_staff_attachment_id">
        </form>
        <?php require dirname(__DIR__, 4) . '/includes/profile_attachment_label_modal.php'; ?>
        <?php endif; ?>

        <div class="modal fade" id="staffInlineDeleteConfirmModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-trash-alt text-danger" style="font-size:2.5rem;"></i>
                        </div>
                        <p class="text-center mb-0" id="staffInlineDeleteConfirmMessage">هل أنت متأكد من الحذف؟</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="button" class="btn btn-danger" id="staffInlineDeleteConfirmBtn">تأكيد</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="staffUnsavedChangesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-exclamation-circle me-2"></i>تنبيه قبل الخروج</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-triangle-exclamation text-warning" style="font-size:2.5rem;"></i>
                        </div>
                        <p class="text-center mb-0">لديك بيانات غير محفوظة. إذا غادرت الآن ستفقد التغييرات.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">البقاء في الصفحة</button>
                        <button type="button" class="btn btn-warning" id="staffUnsavedLeaveBtn">مغادرة بدون حفظ</button>
                    </div>
                </div>
            </div>
        </div>

<?php require __DIR__ . '/profile_form_scripts.php'; ?>

<?php endif; ?>
