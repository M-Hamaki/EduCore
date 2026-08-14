<?php
$canArchiveStudents = $canArchiveStudents ?? true;
$canCreateStudents = $canCreateStudents ?? true;
?>
<?php if ($page_action !== 'view' && ($studentDataScope === 'current' || ($page_action !== 'add' && $page_action !== 'edit'))): // ========================= عرض القائمة =========================  ?>

    <div id="tab-students">
        <form method="GET" action="<?php echo htmlspecialchars($studentsBasePage); ?>" class="admin-filter-bar" id="filterForm">
            <input type="hidden" name="student_scope" value="<?php echo htmlspecialchars($studentDataScope); ?>">
            <div class="admin-filter-controls">
                <!-- الفلاتر من جهة اليمين -->
                    <!-- Stages Dropdown -->
                    <div class="dropdown d-inline-block me-2">
                        <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                            <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
                        </button>
                        <div class="dropdown-menu p-3" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                            <?php foreach ($stages as $stage_id => $stage_name): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input stage-checkbox" type="checkbox" name="stage_ids[]" value="<?php echo $stage_id; ?>" id="stage_<?php echo $stage_id; ?>" <?php echo in_array((int)$stage_id, $filter_stage_ids) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="stage_<?php echo $stage_id; ?>"><?php echo htmlspecialchars($stage_name); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Grades Dropdown -->
                    <div class="dropdown d-inline-block me-2">
                        <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                            <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
                        </button>
                        <div class="dropdown-menu p-3" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                            <?php foreach ($grades as $grade_id => $grade_info): ?>
                                <div class="form-check mb-1 grade-item" data-stage="<?php echo $grade_info['stage_id']; ?>">
                                    <input class="form-check-input grade-checkbox" type="checkbox" name="grade_ids[]" value="<?php echo $grade_id; ?>" id="grade_<?php echo $grade_id; ?>" <?php echo in_array((int)$grade_id, $filter_grade_ids) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="grade_<?php echo $grade_id; ?>"><?php echo htmlspecialchars($grade_info['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php if (!empty($classes)): ?>
                    <!-- Classes Dropdown -->
                    <div class="dropdown d-inline-block">
                        <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                            <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
                        </button>
                        <div class="dropdown-menu p-3" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                            <?php foreach ($classes as $class_item): ?>
                                <div class="form-check mb-1 class-item" data-grade="<?php echo $class_item['grade_id']; ?>">
                                    <input class="form-check-input class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo $class_item['id']; ?>" id="class_<?php echo $class_item['id']; ?>" <?php echo in_array((int)$class_item['id'], $filter_class_ids) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="class_<?php echo $class_item['id']; ?>"><?php echo htmlspecialchars($class_item['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- الأزرار من جهة اليسار -->
            <div class="admin-filter-actions">

                <!-- Reset Filters Button -->
                <a href="<?php echo $studentsBasePage; ?>" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
                    <i class="fas fa-undo me-1"></i>إعادة تعيين
                </a>

                    <!-- Table Settings Button -->
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal"
                        data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
                        <i class="fas fa-cog me-1"></i>إعدادات الجدول
                    </button>
            </div>
        </form>
        <div class="admin-list-surface">
            <div class="table-responsive admin-table-wrap">
                <table
                    class="table table-hover table-striped admin-data-table<?php echo $students_use_datatables ? ' datatable' : ''; ?>"
                    id="studentsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th class="col-student-code">الكود</th>
                            <th class="col-national-id">الرقم القومي</th>
                            <th>الاسم</th>
                            <th class="col-class">الفصل</th>
                            <th class="col-birth-date d-none">تاريخ الميلاد</th>
                            <th class="col-current-age d-none">العمر الحالي</th>
                            <th class="col-gender d-none">النوع</th>
                            <th class="col-religion d-none">الديانة</th>
                            <th class="col-city-area d-none">المدينة/المنطقة</th>
                            <th class="col-phone-emergency d-none">تليفون الطوارئ</th>
                            <th class="col-enrollment-date d-none">تاريخ القيد</th>
                            <!-- أعمدة بيانات أساسية إضافية -->
                            <th class="col-passport d-none">جواز السفر</th>
                            <th class="col-nationality d-none">الجنسية</th>
                            <th class="col-birth-place d-none">محل الميلاد</th>
                            <th class="col-ministry-code d-none">كود الوزارة</th>
                            <th class="col-previous-school d-none">المدرسة السابقة</th>
                            <th class="col-name-en d-none">الاسم بالإنجليزية</th>
                            <th class="col-age-october d-none">العمر في 1 أكتوبر</th>
                            <th class="col-guardianship d-none">الوصاية التعليمية</th>
                            <th class="col-notes d-none">ملاحظات عامة</th>
                            <th class="col-phone-mobile d-none">موبايل الطالب</th>
                            <th class="col-phone-home d-none">الهاتف الأرضي</th>
                            <th class="col-address d-none">العنوان التفصيلي</th>
                            <!-- أعمدة بيانات صحية -->
                            <th class="col-blood-type d-none">فصيلة الدم</th>
                            <th class="col-insurance-number d-none">رقم التأمين</th>
                            <th class="col-insurance-start d-none">بداية التأمين</th>
                            <th class="col-insurance-end d-none">نهاية التأمين</th>
                            <th class="col-health-status d-none">الحالة الصحية</th>
                            <th class="col-chronic d-none">أمراض مزمنة</th>
                            <th class="col-allergies d-none">الحساسية</th>
                            <th class="col-disabilities d-none">الإعاقات</th>
                            <th class="col-medications d-none">الأدوية</th>
                            <th class="col-treatment d-none">خطط علاجية</th>
                            <th class="col-medical-reports d-none">تقارير طبية</th>
                            <th class="col-emergency-notes d-none">ملاحظات طارئة</th>
                            <th class="col-psychological d-none">ملاحظات نفسية</th>
                            <!-- أعمدة الأب -->
                            <th class="col-father-name d-none">اسم الأب</th>
                            <th class="col-father-mobile d-none">موبايل الأب</th>
                            <th class="col-father-landline d-none">أرضي الأب</th>
                            <th class="col-father-email d-none">بريد الأب</th>
                            <th class="col-father-address d-none">عنوان الأب</th>
                            <th class="col-father-national-id d-none">رقم الأب القومي</th>
                            <th class="col-father-qualification d-none">مؤهل الأب</th>
                            <th class="col-father-job d-none">وظيفة الأب</th>
                            <th class="col-father-employer d-none">جهة عمل الأب</th>
                            <th class="col-father-work-phone d-none">هاتف عمل الأب</th>
                            <th class="col-father-birth-date d-none">ميلاد الأب</th>
                            <th class="col-father-religion d-none">ديانة الأب</th>
                            <th class="col-father-nationality d-none">جنسية الأب</th>
                            <th class="col-father-passport d-none">جواز الأب</th>
                            <!-- أعمدة الأم -->
                            <th class="col-mother-name d-none">اسم الأم</th>
                            <th class="col-mother-mobile d-none">موبايل الأم</th>
                            <th class="col-mother-landline d-none">أرضي الأم</th>
                            <th class="col-mother-email d-none">بريد الأم</th>
                            <th class="col-mother-address d-none">عنوان الأم</th>
                            <th class="col-mother-national-id d-none">رقم الأم القومي</th>
                            <th class="col-mother-qualification d-none">مؤهل الأم</th>
                            <th class="col-mother-job d-none">وظيفة الأم</th>
                            <th class="col-mother-employer d-none">جهة عمل الأم</th>
                            <th class="col-mother-work-phone d-none">هاتف عمل الأم</th>
                            <th class="col-mother-birth-date d-none">ميلاد الأم</th>
                            <th class="col-mother-religion d-none">ديانة الأم</th>
                            <th class="col-mother-nationality d-none">جنسية الأم</th>
                            <th class="col-mother-passport d-none">جواز الأم</th>
                            <?php foreach (\EduCore\Modules\Students\Presentation\StudentListColumnCatalog::additionalColumns() as $additionalColumn): ?>
                                <th class="<?php echo htmlspecialchars((string) $additionalColumn['class']); ?> d-none"><?php echo htmlspecialchars((string) $additionalColumn['label']); ?></th>
                            <?php endforeach; ?>
                            <?php if ($studentDataScope === 'transferred'): ?>
                                <th>الجهة المنقول إليها</th>
                                <th>تاريخ النقل</th>
                            <?php endif; ?>
                            <th class="col-status">الحالة السنوية</th>
                            <th class="col-siblings d-none text-center">الإخوة والأشقاء</th>
                            <th class="col-profile-image d-none text-center">الصورة الشخصية</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Check if $students is not empty
                        if (isset($students) && !empty($students)):
                            $i = ($current_user_role === 'admin') ? ($students_offset + 1) : 1;
                            foreach ($students as $student):
                                ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td class="col-student-code" dir="ltr">
                                        <?php echo htmlspecialchars($student['student_code'] ?? '-'); ?>
                                    </td>
                                    <td class="col-national-id">
                                        <?php echo htmlspecialchars($student['national_id'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo $studentsBasePage; ?>?action=view&id=<?php echo $student['id']; ?><?php echo $backQueryAmp; ?>"
                                            class="text-decoration-none fw-bold" title="عرض الملف الشخصي">
                                            <?php echo htmlspecialchars($student['name']); ?>
                                        </a>
                                    </td>
                                    <td class="col-class">
                                        <?php
                                        $className = isset($student['class_name']) ? $student['class_name'] : null;
                                        if (!empty($className)) {
                                            echo htmlspecialchars($className);
                                        } else {
                                            echo '<span class="text-muted">غير مسند لفصل</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="col-birth-date d-none">
                                        <?php echo htmlspecialchars($student['birth_date'] ?? '-'); ?>
                                    </td>
                                    <?php $studentCurrentAge = User::calculateCurrentAge($student['birth_date'] ?? null); ?>
                                    <td class="col-current-age d-none" data-order="<?php echo ($studentCurrentAge && empty($studentCurrentAge['is_future'])) ? (int) $studentCurrentAge['years'] : -1; ?>">
                                        <?php echo ($studentCurrentAge && empty($studentCurrentAge['is_future'])) ? (int) $studentCurrentAge['years'] . ' سنة' : '-'; ?>
                                    </td>
                                    <td class="col-gender d-none">
                                        <?php
                                        $g = $student['gender'] ?? '';
                                        echo ($g === 'male' ? 'ذكر' : ($g === 'female' ? 'أنثى' : '-'));
                                        ?>
                                    </td>
                                    <td class="col-religion d-none">
                                        <?php
                                        $r = $student['religion'] ?? '';
                                        echo ($r === 'muslim' ? 'مسلم' : ($r === 'christian' ? 'مسيحي' : ($r === 'other' ? 'أخرى' : '-')));
                                        ?>
                                    </td>
                                    <td class="col-city-area d-none">
                                        <?php echo htmlspecialchars($student['city_area'] ?? '-'); ?>
                                    </td>
                                    <td class="col-phone-emergency d-none" dir="ltr">
                                        <?php echo htmlspecialchars($student['phone_emergency'] ?? '-'); ?>
                                    </td>
                                    <td class="col-enrollment-date d-none">
                                        <?php echo htmlspecialchars($student['enrollment_date'] ?? '-'); ?>
                                    </td>
                                    <!-- خلايا بيانات أساسية إضافية -->
                                    <td class="col-passport d-none" dir="ltr">
                                        <?php echo htmlspecialchars($student['passport_number'] ?? '-'); ?>
                                    </td>
                                    <td class="col-nationality d-none">
                                        <?php echo htmlspecialchars($student['nationality'] ?? '-'); ?>
                                    </td>
                                    <td class="col-birth-place d-none">
                                        <?php echo htmlspecialchars($student['birth_place'] ?? '-'); ?>
                                    </td>
                                    <td class="col-ministry-code d-none" dir="ltr">
                                        <?php echo htmlspecialchars($student['ministry_code'] ?? '-'); ?>
                                    </td>
                                    <td class="col-previous-school d-none">
                                        <?php echo htmlspecialchars($student['previous_school'] ?? '-'); ?>
                                    </td>
                                     <td class="col-name-en d-none">
                                         <?php 
                                         $fullNameEn = trim(($student['first_name_en'] ?? '') . ' ' . ($student['second_name_en'] ?? '') . ' ' . ($student['third_name_en'] ?? '') . ' ' . ($student['fourth_name_en'] ?? '') . ' ' . ($student['family_name_en'] ?? ''));
                                         echo htmlspecialchars($fullNameEn ?: '-'); 
                                         ?>
                                     </td>
                                     <td class="col-age-october d-none">
                                         <?php 
                                         $ageOct = '';
                                         if (!empty($student['age_years'])) {
                                             $ageOct = (int)$student['age_years'] . ' سنة';
                                             if (isset($student['age_months'])) {
                                                 $ageOct .= ' و ' . (int)$student['age_months'] . ' شهر';
                                             }
                                         }
                                         echo htmlspecialchars($ageOct ?: '-');
                                         ?>
                                     </td>
                                     <td class="col-guardianship d-none">
                                         <?php 
                                         $extraData = json_decode($student['extra_data'] ?? '', true) ?: [];
                                         $guard = '';
                                         foreach ($extraData as $item) {
                                             if (($item['label'] ?? '') === '__educational_guardianship' || ($item['label'] ?? '') === 'الوصاية التعليمية') {
                                                 $guard = $item['value'] ?? '';
                                                 break;
                                             }
                                         }
                                         echo htmlspecialchars($guard !== '' ? ($relationshipLabels[$guard] ?? $guard) : '-');
                                         ?>
                                     </td>
                                     <td class="col-notes d-none text-center">
                                         <?php 
                                         $notesVal = trim((string)($student['notes'] ?? ''));
                                         if ($notesVal !== '') {
                                             echo '<button type="button" class="btn btn-sm btn-link p-0 view-cell-content" data-title="ملاحظات عامة" data-content="' . htmlspecialchars($notesVal, ENT_QUOTES) . '" data-bs-toggle="tooltip" title="عرض الملاحظات"><i class="fas fa-sticky-note text-warning"></i></button>';
                                         } else {
                                             echo '<span class="text-muted">-</span>';
                                         }
                                         ?>
                                     </td>
                                    <td class="col-phone-mobile d-none" dir="ltr">
                                        <?php echo htmlspecialchars($student['phone_mobile'] ?? '-'); ?>
                                    </td>
                                    <td class="col-phone-home d-none" dir="ltr">
                                        <?php echo htmlspecialchars($student['phone_home'] ?? '-'); ?>
                                    </td>
                                    <td class="col-address d-none text-center">
                                        <?php
                                        $addrVal = trim((string) ($student['address_current'] ?? ''));
                                        if ($addrVal !== '') {
                                            echo '<button type="button" class="btn btn-sm btn-link p-0 view-cell-content" data-title="العنوان التفصيلي" data-content="' . htmlspecialchars($addrVal, ENT_QUOTES) . '" data-bs-toggle="tooltip" title="عرض العنوان"><i class="fas fa-map-marker-alt text-primary"></i></button>';
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <!-- خلايا بيانات صحية -->
                                    <td class="col-blood-type d-none" dir="ltr">
                                        <?php echo htmlspecialchars($student['blood_type'] ?? '-'); ?>
                                    </td>
                                    <td class="col-insurance-number d-none" dir="ltr">
                                        <?php echo htmlspecialchars($student['insurance_number'] ?? '-'); ?>
                                    </td>
                                    <td class="col-insurance-start d-none">
                                        <?php echo htmlspecialchars($student['insurance_start_date'] ?? '-'); ?>
                                    </td>
                                    <td class="col-insurance-end d-none">
                                        <?php echo htmlspecialchars($student['insurance_end_date'] ?? '-'); ?>
                                    </td>
                                    <?php
                                    // خلايا النصوص الطويلة/المنطقية الصحية: أيقونة منبثقة أو تحذير حسب وجود المحتوى
                                    $healthTextFields = [
                                        'col-health-status' => ['key' => 'health_status', 'label' => 'الحالة الصحية العامة', 'icon' => 'fa-notes-medical', 'warn' => false],
                                        'col-chronic' => ['key' => 'chronic_diseases', 'label' => 'الأمراض المزمنة', 'icon' => 'fa-heart-pulse', 'warn' => true],
                                        'col-allergies' => ['key' => 'allergies', 'label' => 'الحساسية', 'icon' => 'fa-allergies', 'warn' => true],
                                        'col-disabilities' => ['key' => 'disabilities', 'label' => 'الإعاقات', 'icon' => 'fa-wheelchair', 'warn' => true],
                                        'col-medications' => ['key' => 'medications', 'label' => 'العلاج / الأدوية', 'icon' => 'fa-pills', 'warn' => false],
                                        'col-treatment' => ['key' => 'treatment_plan', 'label' => 'خطط علاجية متبعة', 'icon' => 'fa-clipboard-list', 'warn' => false],
                                        'col-medical-reports' => ['key' => 'previous_medical_reports', 'label' => 'تقارير طبية سابقة', 'icon' => 'fa-file-medical', 'warn' => false],
                                        'col-emergency-notes' => ['key' => 'emergency_medical_notes', 'label' => 'ملاحظات طبية طارئة', 'icon' => 'fa-triangle-exclamation', 'warn' => true],
                                        'col-psychological' => ['key' => 'psychological_notes', 'label' => 'ملاحظات نفسية وسلوكية', 'icon' => 'fa-brain', 'warn' => false],
                                    ];
                                    foreach ($healthTextFields as $colClass => $cfg):
                                        $cellVal = trim((string) ($student[$cfg['key']] ?? ''));
                                        ?>
                                        <td class="<?php echo $colClass; ?> d-none text-center">
                                            <?php
                                            if ($cellVal !== '') {
                                                $btnClass = $cfg['warn'] ? 'text-danger' : 'text-info';
                                                echo '<button type="button" class="btn btn-sm btn-link p-0 view-cell-content ' . $btnClass . '" data-title="' . htmlspecialchars($cfg['label']) . '" data-content="' . htmlspecialchars($cellVal, ENT_QUOTES) . '" data-bs-toggle="tooltip" title="' . htmlspecialchars($cfg['label']) . '"><i class="fas ' . $cfg['icon'] . '"></i></button>';
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <!-- خلايا الأب والأم (منفصلة) -->
                                    <?php
                                    // تعريف حقول الأب والأم بنفس البنية: colClass, key, label, type
                                    // type: text=نص عادي، ltr=رقمي/إنجليزي، religion=ديانة، address=نص طويل (أيقونة منبثقة)
                                    $parentFields = [
                                        ['name', 'الاسم', 'text'],
                                        ['mobile', 'الموبايل', 'ltr'],
                                        ['landline', 'الهاتف الأرضي', 'ltr'],
                                        ['email', 'البريد الإلكتروني', 'ltr'],
                                        ['address', 'العنوان', 'address'],
                                        ['national_id', 'الرقم القومي', 'ltr'],
                                        ['qualification', 'المؤهل الدراسي', 'text'],
                                        ['job', 'المسمى الوظيفي', 'text'],
                                        ['employer', 'جهة العمل', 'text'],
                                        ['work_phone', 'هاتف العمل', 'ltr'],
                                        ['birth_date', 'تاريخ الميلاد', 'text'],
                                        ['religion', 'الديانة', 'religion'],
                                        ['nationality', 'الجنسية', 'text'],
                                        ['passport', 'جواز السفر', 'ltr'],
                                    ];
                                    foreach (['father' => 'الأب', 'mother' => 'الأم'] as $parentKey => $parentLabel):
                                        foreach ($parentFields as $pf):
                                            $colClass = 'col-' . $parentKey . '-' . $pf[0];
                                            $dataKey = $parentKey . '_' . $pf[0];
                                            $cellVal = trim((string) ($student[$dataKey] ?? ''));
                                            ?>
                                            <td
                                                class="<?php echo $colClass; ?> d-none<?php echo $pf[2] === 'address' ? ' text-center' : ''; ?>">
                                                <?php
                                                if ($pf[2] === 'religion') {
                                                    echo ($cellVal === 'muslim' ? 'مسلم' : ($cellVal === 'christian' ? 'مسيحي' : ($cellVal === 'other' ? 'أخرى' : ($cellVal !== '' ? htmlspecialchars($cellVal) : '<span class="text-muted">-</span>'))));
                                                } elseif ($pf[2] === 'address') {
                                                    if ($cellVal !== '') {
                                                        echo '<button type="button" class="btn btn-sm btn-link p-0 view-cell-content" data-title="عنوان ' . $parentLabel . '" data-content="' . htmlspecialchars($cellVal, ENT_QUOTES) . '" data-bs-toggle="tooltip" title="عرض العنوان"><i class="fas fa-map-marker-alt text-primary"></i></button>';
                                                    } else {
                                                        echo '<span class="text-muted">-</span>';
                                                    }
                                                } elseif ($cellVal !== '') {
                                                    echo htmlspecialchars($cellVal);
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <?php
                                        endforeach;
                                    endforeach;
                                    ?>
                                    <?php
                                    $additionalGuardians = ['father' => [], 'mother' => [], 'others' => []];
                                    $additionalGuardianMap = ['name' => 'guardian_name', 'relationship' => 'relationship', 'birth_date' => 'birth_date', 'birth_place' => 'birth_place', 'religion' => 'religion', 'nationality' => 'nationality', 'national_id' => 'national_id', 'passport' => 'passport_number', 'mobile' => 'phone_primary', 'landline' => 'phone_landline', 'email' => 'email', 'address' => 'address', 'extra_phones' => 'extra_phones', 'qualification' => 'qualification', 'job' => 'job_title', 'employer' => 'employer', 'work_phone' => 'work_phone', 'extra_data' => 'extra_data'];
                                    foreach (['father', 'mother'] as $additionalParent) {
                                        foreach ($additionalGuardianMap as $suffix => $target) {
                                            $sourceField = $additionalParent . '_' . $suffix;
                                            if (array_key_exists($sourceField, $student)) {
                                                $additionalGuardians[$additionalParent][$target] = $student[$sourceField];
                                            }
                                        }
                                    }
                                    foreach (\EduCore\Modules\Students\Presentation\StudentListColumnCatalog::additionalColumns() as $additionalColumn):
                                        $additionalField = (string) $additionalColumn['field'];
                                        $additionalValue = \EduCore\Modules\Students\Presentation\StudentExportValueFormatter::format($additionalField, $student, $additionalGuardians, $student['age_reference_date'] ?? null);
                                        $additionalClass = htmlspecialchars((string) $additionalColumn['class']);
                                        ?>
                                        <td class="<?php echo $additionalClass; ?> d-none">
                                            <?php if (\EduCore\Modules\Students\Presentation\StudentListColumnCatalog::isDetail($additionalField)): ?>
                                                <?php if ($additionalValue !== '-'): ?>
                                                    <button type="button" class="btn btn-sm btn-link p-0 view-cell-content" data-title="<?php echo htmlspecialchars((string) $additionalColumn['label'], ENT_QUOTES, 'UTF-8'); ?>" data-content="<?php echo htmlspecialchars($additionalValue, ENT_QUOTES, 'UTF-8'); ?>" data-bs-toggle="tooltip" title="<?php echo htmlspecialchars((string) $additionalColumn['label'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-circle-info text-primary"></i></button>
                                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                            <?php else: ?>
                                                <span<?php echo \EduCore\Modules\Students\Presentation\StudentListColumnCatalog::direction($additionalField) ? ' dir="' . htmlspecialchars((string) \EduCore\Modules\Students\Presentation\StudentListColumnCatalog::direction($additionalField)) . '"' : ''; ?>><?php echo htmlspecialchars($additionalValue); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <?php if ($studentDataScope === 'transferred'): ?>
                                        <td><?php echo htmlspecialchars($student['transfer_destination'] ?? '-'); ?></td>
                                        <td class="text-nowrap">
                                            <?php echo htmlspecialchars($student['external_transfer_date'] ?? '-'); ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="col-status">
                                        <?php
                                        $enrollmentStatus = $student['enrollment_status'] ?? 'enrolled';
                                        $academicStatus = $student['academic_status'] ?? (($student['status'] ?? '') === 'graduated' ? 'graduated' : 'new');
                                        $enrollmentLabels = ['enrolled' => 'مقيد', 'transferred' => 'منقول', 'discontinued' => 'منقطع', 'withdrawn' => 'منقطع'];
                                        $enrollmentClasses = ['enrolled' => 'success', 'transferred' => 'warning text-dark', 'discontinued' => 'secondary', 'withdrawn' => 'secondary'];
                                        $academicLabels = ['new' => 'مستجد', 'promoted' => 'ناجح ومنقول', 'retained' => 'راسب', 'graduated' => 'خريج'];
                                        $academicClasses = ['new' => 'info', 'promoted' => 'success', 'retained' => 'warning text-dark', 'graduated' => 'primary'];
                                        echo '<div class="d-flex flex-wrap gap-1">';
                                        echo '<span class="badge bg-' . ($enrollmentClasses[$enrollmentStatus] ?? 'secondary') . '">' . htmlspecialchars($enrollmentLabels[$enrollmentStatus] ?? $enrollmentStatus) . '</span>';
                                        echo '<span class="badge bg-' . ($academicClasses[$academicStatus] ?? 'secondary') . '">' . htmlspecialchars($academicLabels[$academicStatus] ?? $academicStatus) . '</span>';
                                        echo '</div>';
                                        ?>
                                    </td>
                                    <td class="col-siblings d-none text-center">
                                        <?php
                                        $siblingsCount = (int)($student['siblings_count'] ?? 0);
                                        $siblingsInfo  = $student['siblings_info'] ?? '';
                                        if ($siblingsCount > 0 && $siblingsInfo !== ''):
                                            // بناء محتوى الـ popover
                                            $rows = explode(';;', $siblingsInfo);
                                            $popoverHtml = '<ul class="mb-0 ps-3 text-start" style="min-width:160px;">';
                                            foreach ($rows as $row) {
                                                [$sibName, $sibClass] = array_pad(explode('||', $row, 2), 2, '—');
                                                $popoverHtml .= '<li><strong>' . htmlspecialchars($sibName, ENT_QUOTES, 'UTF-8') . '</strong>';
                                                $popoverHtml .= ' — ' . htmlspecialchars($sibClass, ENT_QUOTES, 'UTF-8') . '</li>';
                                            }
                                            $popoverHtml .= '</ul>';
                                        ?>
                                            <span class="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle fw-semibold"
                                                  style="cursor:pointer; font-size:0.8rem;"
                                                  data-bs-toggle="popover"
                                                  data-bs-trigger="hover focus"
                                                  data-bs-placement="top"
                                                  data-bs-html="true"
                                                  data-bs-title="الإخوة والأشقاء"
                                                  data-bs-content="<?php echo htmlspecialchars($popoverHtml, ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-users me-1"></i><?php echo $siblingsCount; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-profile-image d-none text-center">
                                        <?php if (!empty($student['profile_image_id'])): ?>
                                            <img src="<?php echo htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', (int)$student['profile_image_id'])); ?>" 
                                                 class="rounded-circle shadow-sm" 
                                                 style="width:36px; height:36px; object-fit:cover; border: 2px solid var(--bs-primary-bg-subtle);" 
                                                 alt="صورة">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center mx-auto shadow-sm" 
                                                 style="width:36px; height:36px;">
                                                <i class="fas fa-user-graduate text-primary" style="font-size: 14px;"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions-column admin-table-actions">
                                        <a href="<?php echo $studentsBasePage; ?>?action=edit&id=<?php echo $student['id']; ?><?php echo $backQueryAmp; ?>"
                                            class="btn btn-action-pills btn-edit has-tooltip me-1"
                                            data-student-id="<?php echo (int) $student['id']; ?>" title="تعديل البيانات">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($canArchiveStudents): ?>
                                        <button type="button" class="btn btn-action-pills btn-deactivate archive-student has-tooltip"
                                            data-id="<?php echo $student['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($student['name']); ?>" data-bs-toggle="modal"
                                            data-bs-target="#archiveStudentModal" title="أرشفة">
                                            <i class="fas fa-box-archive"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr>
                                <td colspan="<?php echo (($studentDataScope === 'transferred') ? 71 : 69) + count(\EduCore\Modules\Students\Presentation\StudentListColumnCatalog::additionalColumns()); ?>" class="text-center p-0">
                                    <div class="admin-list-loading" role="status" aria-live="polite"><i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل الطلاب…</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <script src="../assets/js/admin-server-side-table.js"></script>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                const filters = <?php echo json_encode(['student_scope'=>$studentDataScope,'stage_ids'=>$filter_stage_ids ?? [],'grade_ids'=>$filter_grade_ids ?? [],'class_ids'=>$filter_class_ids ?? []], JSON_UNESCAPED_UNICODE); ?>;
                if (!window.AdminServerSideTable) return;
                function selectedOptionalColumns() {
                    let preferences = {};
                    try {
                        preferences = JSON.parse(localStorage.getItem('students_table_columns_v2') || '{}');
                    } catch (error) {
                        preferences = {};
                    }
                    return Array.from(document.querySelectorAll('.col-toggle-checkbox')).filter(function (checkbox) {
                        const column = checkbox.getAttribute('data-column');
                        return column && (Object.prototype.hasOwnProperty.call(preferences, column) ? preferences[column] : checkbox.checked);
                    }).map(function (checkbox) {
                        return checkbox.getAttribute('data-column');
                    });
                }
                const studentsTable = window.AdminServerSideTable.init({
                    selector: '#studentsTable',
                    url: 'ajax_students_datatable.php',
                    order: [[3, 'asc']],
                    dtOptions: { pageLength: 50 },
                    requestData: function () { return Object.assign({}, filters, { visible_columns: selectedOptionalColumns() }); },
                    language: { processing: '<div class="admin-list-loading"><i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل الطلاب…</div>' },
                    decorateRow: function (row) { row.lastElementChild.classList.add('actions-column', 'admin-table-actions'); },
                    onDraw: function () { document.querySelectorAll('.col-toggle-checkbox').forEach(function(cb){ applyColumnVisibility(cb.getAttribute('data-column'),cb.checked); }); }
                });
                const settingsModal = document.getElementById('tableSettingsModal');
                if (settingsModal && studentsTable) {
                    settingsModal.addEventListener('hidden.bs.modal', function () {
                        studentsTable.ajax.reload(null, false);
                    });
                }
            });
            </script>
        </div>
    </div>
    <!-- /tab-students -->

    </div><!-- /py-2 -->

    <?php if ($studentDataScope === 'current'): ?>
        <?php
        $bulkOldInput = $_SESSION['student_bulk_old_input'] ?? [];
        unset($_SESSION['student_bulk_old_input']);
        $bulkDefaultClassValue = (int) ($bulkOldInput['default_class_id'] ?? 0);
        $bulkRows = array_values(array_filter($bulkOldInput['students'] ?? [], 'is_array'));
        if (count($bulkRows) < 2) {
            $bulkRows = array_pad($bulkRows, 2, []);
        }
        $bulkRows = array_slice($bulkRows, 0, 20);
        ?>
        <div class="modal fade" id="bulkAddStudentsModal" tabindex="-1"
            aria-labelledby="bulkAddStudentsModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
                <form method="POST" action="<?php echo $studentsBasePage . $backQuery; ?>"
                    class="modal-content admin-modal admin-modal-premium admin-modal-create" id="bulkAddStudentsForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="student_scope" value="current">
                    <div class="modal-header">
                        <h5 class="modal-title" id="bulkAddStudentsModalTitle">
                            <i class="fas fa-users me-2"></i>إضافة طلاب جماعياً
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                    </div>
                    <!-- لوحة التحكم العلوية المثبتة (Control Panel Header) -->
                    <div class="px-4 py-3 border-bottom" style="background: #f8fafc; z-index: 2; border-bottom: 1px solid #e2e8f0 !important; padding: 1.25rem 2rem !important;">
                        <?php if (($_GET['bulk_add'] ?? '') === '1' && $error_message): ?>
                            <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center gap-2 mb-3" role="alert" style="background: #fee2e2; color: #b91c1c; border-radius: 8px; padding: 0.75rem 1rem;">
                                <i class="fas fa-exclamation-circle fs-5"></i>
                                <div style="font-size: 0.85rem; font-weight: 600;">
                                    <?php echo htmlspecialchars($error_message); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-info border-0 shadow-sm d-flex align-items-center gap-2 mb-3" style="background: rgba(14, 165, 233, 0.08); color: #0369a1; border-radius: 8px; padding: 0.75rem 1rem;">
                            <i class="fas fa-info-circle text-primary fs-5"></i>
                            <div style="font-size: 0.85rem; font-weight: 500;">
                                أضف من طالبين إلى 20 طالباً. للأعداد الأكبر، يُنصح باستخدام «استيراد Excel» لتوفير الوقت.
                            </div>
                        </div>

                        <div class="row g-3 align-items-center">
                            <div class="col-md-7 col-lg-6">
                                <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-2">
                                    <label class="form-label mb-0 fw-bold text-secondary text-nowrap" style="font-size: 0.85rem;" for="bulkDefaultClass">
                                        <i class="fas fa-school me-1 text-primary"></i> الفصل الافتراضي لجميع الصفوف:
                                    </label>
                                    <select class="form-select form-select-sm" id="bulkDefaultClass" name="bulk_default_class_id" style="border-radius: 8px; max-width: 320px;">
                                        <option value="">اختر فصلاً افتراضياً</option>
                                        <?php foreach ($classes as $classItem): ?>
                                            <option value="<?php echo (int) $classItem['id']; ?>"
                                                <?php echo $bulkDefaultClassValue === (int) $classItem['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(($classItem['stage_name'] ?? '') . ' — ' . ($classItem['grade_name'] ?? '') . ' — ' . $classItem['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-5 col-lg-6 text-md-end">
                                <button type="button" class="btn btn-outline-primary btn-sm px-3 py-2 shadow-sm" id="addBulkStudentRow" style="border-radius: 8px; font-weight: 600; min-height: 38px;">
                                    <i class="fas fa-user-plus me-1"></i> إضافة طالب آخر
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="modal-body" style="padding: 1.5rem 2rem !important;">
                        <div class="table-responsive admin-table-wrap">
                            <table class="table table-hover table-striped align-middle admin-data-table" id="bulkStudentsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>اسم الطالب <span class="text-danger">*</span></th>
                                        <th>الفصل</th>
                                        <th>الرقم القومي</th>
                                        <th>النوع</th>
                                        <th>الموبايل</th>
                                        <th>تاريخ القيد</th>
                                        <th>إجراء</th>
                                    </tr>
                                </thead>
                                <tbody id="bulkStudentsRows">
                                    <?php foreach ($bulkRows as $bulkIndex => $bulkRow): ?>
                                        <tr class="bulk-student-row" data-index="<?php echo $bulkIndex; ?>">
                                            <td class="bulk-row-number"><?php echo $bulkIndex + 1; ?></td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm"
                                                    name="bulk_students[<?php echo $bulkIndex; ?>][name]"
                                                    value="<?php echo htmlspecialchars($bulkRow['name'] ?? ''); ?>"
                                                    placeholder="الاسم الكامل">
                                            </td>
                                            <td>
                                                <select class="form-select form-select-sm"
                                                    name="bulk_students[<?php echo $bulkIndex; ?>][class_id]">
                                                    <option value="">الفصل الافتراضي</option>
                                                    <?php foreach ($classes as $classItem): ?>
                                                        <option value="<?php echo (int) $classItem['id']; ?>"
                                                            <?php echo (int) ($bulkRow['class_id'] ?? 0) === (int) $classItem['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars(($classItem['grade_name'] ?? '') . ' — ' . $classItem['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><input type="text" class="form-control form-control-sm" inputmode="numeric"
                                                pattern="[0-9]{14}" maxlength="14"
                                                name="bulk_students[<?php echo $bulkIndex; ?>][national_id]"
                                                value="<?php echo htmlspecialchars($bulkRow['national_id'] ?? ''); ?>"></td>
                                            <td>
                                                <select class="form-select form-select-sm" name="bulk_students[<?php echo $bulkIndex; ?>][gender]">
                                                    <option value="">-- اختر --</option>
                                                    <option value="male" <?php echo ($bulkRow['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>ذكر</option>
                                                    <option value="female" <?php echo ($bulkRow['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>أنثى</option>
                                                </select>
                                            </td>
                                            <td><input type="text" class="form-control form-control-sm" inputmode="numeric"
                                                pattern="[0-9]{11}" maxlength="11"
                                                name="bulk_students[<?php echo $bulkIndex; ?>][phone_mobile]"
                                                value="<?php echo htmlspecialchars($bulkRow['phone_mobile'] ?? ''); ?>"></td>
                                            <td><input type="text" class="form-control form-control-sm flatpickr-date"
                                                name="bulk_students[<?php echo $bulkIndex; ?>][enrollment_date]"
                                                placeholder="اختر التاريخ..."
                                                value="<?php echo htmlspecialchars($bulkRow['enrollment_date'] ?? date('Y-m-d')); ?>"></td>
                                            <td>
                                                <button type="button" class="btn btn-action-pills btn-delete remove-bulk-student"
                                                    data-bs-toggle="tooltip" title="حذف الصف">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>إلغاء
                        </button>
                        <button type="submit" name="add_students_bulk" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>إضافة الطلاب
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Import Students Modal -->
    <div class="modal fade" id="importStudentsModal" tabindex="-1" aria-labelledby="importStudentsModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                <div class="modal-header">
                    <h5 class="modal-title" id="importStudentsModalLabel">
                        <i class="fas fa-file-import me-2"></i>استيراد طلاب من Excel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <div class="modal-body">
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-shield-alt me-2"></i>
                            <strong>استيراد تفصيلي وآمن:</strong> حمّل النموذج الفارغ أولاً؛ يحتوي على أوراق الطلاب وأولياء الأمور والهواتف الإضافية.
                            لا يشمل النموذج الحسابات أو كلمات المرور أو المرفقات.
                        </div>

                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 border rounded p-3 bg-light mb-3">
                            <div>
                                <div class="fw-semibold"><i class="fas fa-file-download text-success me-1"></i>نموذج استيراد الطلاب</div>
                                <small class="text-muted">استخدم رموز الطلاب الفريدة لربط أولياء الأمور والهواتف.</small>
                            </div>
                            <a class="btn btn-outline-success btn-sm" href="<?php echo $studentsBasePage; ?>?download_profile_template=student&amp;student_scope=<?php echo urlencode($studentDataScope); ?>">
                                <i class="fas fa-download me-1"></i>تحميل النموذج الفارغ
                            </a>
                        </div>
                        <ul class="small text-muted mb-3 ps-3">
                            <li>صيغة التاريخ في جميع الأوراق: <code>YYYY-MM-DD</code>.</li>
                            <li>يجب أن يطابق <code>class_name</code> اسم الفصل في النظام تماماً.</li>
                            <li>يفحص النظام الملف كاملاً قبل الحفظ؛ عند وجود خطأ أو تكرار لن تُضاف أي بيانات.</li>
                        </ul>

                        <div class="row g-3">
                            <div class="col-12">
                                <label for="excel_file" class="form-label">
                                    <i class="fas fa-file-excel me-1"></i>ملف Excel
                                </label>
                                <input type="file" class="form-control" id="excel_file" name="excel_file"
                                    accept=".xlsx,.xls" required>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    الملفات المقبولة: .xlsx و .xls، والحد الأقصى 10 ميجابايت.
                                </small>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>إلغاء
                        </button>
                        <button type="submit" name="import_students" class="btn btn-header-premium btn-import-soft">
                            <i class="fas fa-file-import me-1"></i>استيراد Excel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($canArchiveStudents): ?>
    <!-- Archive Student Modal -->
    <div class="modal fade" id="archiveStudentModal" tabindex="-1" aria-labelledby="archiveStudentModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
                <form method="post" action="" data-no-form-safety="true">
                    <div class="modal-header">
                        <h5 class="modal-title" id="archiveStudentModalLabel"><i class="fas fa-box-archive me-2"></i>أرشفة طالب</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-box-archive text-warning admin-modal-icon-lg"></i>
                        </div>
                        <p class="text-center">هل تريد أرشفة الطالب <span class="fw-bold text-primary"
                                id="archive_student_name"></span>؟</p>
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            سيختفي الطالب من القوائم التشغيلية ويُعطّل حسابه، مع الاحتفاظ بالدرجات والحضور والبيانات المالية والمرفقات.
                        </div>
                        <label for="archive_reason" class="form-label">سبب الأرشفة <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="archive_reason" name="archive_reason" rows="3"
                            minlength="5" maxlength="500" required></textarea>
                        <div class="form-text">يمكن استرجاع الطالب لاحقًا من صفحة أرشيف الطلاب.</div>
                    </div>
                    <div class="modal-footer">
                        <?php echo csrfField(); ?>
                        <input type="hidden" id="archive_user_id" name="user_id">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>إلغاء
                        </button>
                        <button type="submit" name="archive_student" class="btn btn-warning">
                            <i class="fas fa-box-archive me-1"></i>أرشفة
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Table Settings Modal -->
    <div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-labelledby="tableSettingsModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
                <div class="modal-header">
                    <h5 class="modal-title" id="tableSettingsModalLabel">
                        <i class="fas fa-cog me-2"></i>تخصيص أعمدة الجدول
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- الملحوظة والأزرار العامة في نفس السطر -->
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom no-print">
                        <p class="text-muted small mb-0">اختر الأعمدة التي ترغب في عرضها في جدول الطلاب. التغييرات تُحفظ تلقائياً.</p>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-success btn-sm px-3" id="selectAllColumns" style="border-radius: 6px;">
                                <i class="fas fa-check-double me-1"></i>تحديد الكل
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm px-3" id="deselectAllColumns" style="border-radius: 6px;">
                                <i class="fas fa-times me-1"></i>إلغاء الكل
                            </button>
                        </div>
                    </div>

                    <?php
                    // تعريف الأعمدة منظّمة حسب تبويبات النموذج بأسمائها تماماً
                    // العناصر العادية: [colClass, id, label, defaultChecked]
                    // العناوين الفرعية: ['__header__', 'العنوان']
                    $columnSections = [
                        'البيانات الأساسية' => [
                            ['__header__', 'البيانات الشخصية'],
                            ['col-national-id', 'chk_national_id', 'الرقم القومي', true],
                            ['col-birth-date', 'chk_birth_date', 'تاريخ الميلاد', false],
                            ['col-current-age', 'chk_current_age', 'العمر الحالي', false],
                            ['col-age-october', 'chk_age_october', 'العمر في 1 أكتوبر', false],
                            ['col-gender', 'chk_gender', 'النوع', false],
                            ['col-religion', 'chk_religion', 'الديانة', false],
                            ['col-nationality', 'chk_nationality', 'الجنسية', false],
                            ['col-birth-place', 'chk_birth_place', 'محل الميلاد', false],
                            ['col-passport', 'chk_passport', 'جواز السفر', false],
                            ['col-name-en', 'chk_name_en', 'الاسم بالإنجليزية', false],
                            ['col-guardianship', 'chk_guardianship', 'الوصاية التعليمية', false],

                            ['__header__', 'كود الطالب وحالة القيد الدراسي'],
                            ['col-student-code', 'chk_student_code', 'كود الطالب', true],
                            ['col-ministry-code', 'chk_ministry_code', 'كود الوزارة', false],
                            ['col-class', 'chk_class', 'الفصل', true],
                            ['col-status', 'chk_status', 'حالة القيد', true],
                            ['col-enrollment-date', 'chk_enrollment_date', 'تاريخ القيد', false],
                            ['col-previous-school', 'chk_previous_school', 'المدرسة السابقة', false],

                            ['__header__', 'العناوين وبيانات التواصل'],
                            ['col-city-area', 'chk_city_area', 'المدينة/المنطقة', false],
                            ['col-address', 'chk_address', 'العنوان التفصيلي', false],
                            ['col-phone-mobile', 'chk_phone_mobile', 'موبايل الطالب', false],
                            ['col-phone-home', 'chk_phone_home', 'الهاتف الأرضي', false],
                            ['col-phone-emergency', 'chk_phone_emergency', 'تليفون الطوارئ', false],
                            ['col-notes', 'chk_notes', 'ملاحظات عامة', false],
                        ],
                        'بيانات الأب' => [
                            ['col-father-name', 'chk_father_name', 'اسم الأب', false],
                            ['col-father-mobile', 'chk_father_mobile', 'موبايل الأب', false],
                            ['col-father-landline', 'chk_father_landline', 'الهاتف الأرضي للأب', false],
                            ['col-father-email', 'chk_father_email', 'البريد الإلكتروني للأب', false],
                            ['col-father-address', 'chk_father_address', 'عنوان الأب', false],
                            ['col-father-national-id', 'chk_father_national_id', 'الرقم القومي للأب', false],
                            ['col-father-qualification', 'chk_father_qualification', 'المؤهل الدراسي للأب', false],
                            ['col-father-job', 'chk_father_job', 'المسمى الوظيفي للأب', false],
                            ['col-father-employer', 'chk_father_employer', 'جهة عمل الأب', false],
                            ['col-father-work-phone', 'chk_father_work_phone', 'هاتف عمل الأب', false],
                            ['col-father-birth-date', 'chk_father_birth_date', 'تاريخ ميلاد الأب', false],
                            ['col-father-religion', 'chk_father_religion', 'ديانة الأب', false],
                            ['col-father-nationality', 'chk_father_nationality', 'جنسية الأب', false],
                            ['col-father-passport', 'chk_father_passport', 'جواز سفر الأب', false],
                        ],
                        'بيانات الأم' => [
                            ['col-mother-name', 'chk_mother_name', 'اسم الأم', false],
                            ['col-mother-mobile', 'chk_mother_mobile', 'موبايل الأم', false],
                            ['col-mother-landline', 'chk_mother_landline', 'الهاتف الأرضي للأم', false],
                            ['col-mother-email', 'chk_mother_email', 'البريد الإلكتروني للأم', false],
                            ['col-mother-address', 'chk_mother_address', 'عنوان الأم', false],
                            ['col-mother-national-id', 'chk_mother_national_id', 'الرقم القومي للأم', false],
                            ['col-mother-qualification', 'chk_mother_qualification', 'المؤهل الدراسي للأم', false],
                            ['col-mother-job', 'chk_mother_job', 'المسمى الوظيفي للأم', false],
                            ['col-mother-employer', 'chk_mother_employer', 'جهة عمل الأم', false],
                            ['col-mother-work-phone', 'chk_mother_work_phone', 'هاتف عمل الأم', false],
                            ['col-mother-birth-date', 'chk_mother_birth_date', 'تاريخ ميلاد الأم', false],
                            ['col-mother-religion', 'chk_mother_religion', 'ديانة الأم', false],
                            ['col-mother-nationality', 'chk_mother_nationality', 'جنسية الأم', false],
                            ['col-mother-passport', 'chk_mother_passport', 'جواز سفر الأم', false],
                        ],
                        'البيانات الصحية والنفسية' => [
                            ['__header__', 'الحالة الصحية'],
                            ['col-blood-type', 'chk_blood_type', 'فصيلة الدم', false],
                            ['col-insurance-number', 'chk_insurance_number', 'رقم التأمين', false],
                            ['col-insurance-start', 'chk_insurance_start', 'بداية التأمين', false],
                            ['col-insurance-end', 'chk_insurance_end', 'نهاية التأمين', false],
                            ['col-health-status', 'chk_health_status', 'الحالة الصحية', false],
                            ['col-chronic', 'chk_chronic', 'الأمراض المزمنة', false],
                            ['col-allergies', 'chk_allergies', 'الحساسية', false],
                            ['col-disabilities', 'chk_disabilities', 'الإعاقات', false],
                            ['col-medications', 'chk_medications', 'الأدوية', false],
                            ['col-treatment', 'chk_treatment', 'خطط علاجية', false],
                            ['col-medical-reports', 'chk_medical_reports', 'تقارير طبية', false],
                            ['col-emergency-notes', 'chk_emergency_notes', 'ملاحظات طارئة', false],

                            ['__header__', 'الحالة النفسية والسلوكية'],
                            ['col-psychological', 'chk_psychological', 'ملاحظات نفسية', false],
                        ],
                        'الإخوة والأشقاء' => [
                            ['col-siblings', 'chk_siblings', 'الإخوة والأشقاء', false],
                        ],
                        'الصورة الشخصية' => [
                            ['col-profile-image', 'chk_profile_image', 'عرض الصورة الشخصية', false],
                        ],
                    ];
                    foreach (\EduCore\Modules\Students\Presentation\StudentListColumnCatalog::additionalColumns() as $additionalColumn) {
                        $sectionTitle = (string) ($additionalColumn['section'] ?? 'بيانات إضافية');
                        if (!isset($columnSections[$sectionTitle])) {
                            $columnSections[$sectionTitle] = [];
                        }
                        $columnSections[$sectionTitle][] = [
                            (string) $additionalColumn['class'],
                            (string) $additionalColumn['id'],
                            (string) $additionalColumn['label'],
                            false,
                        ];
                    }
                    // أيقونات مطابقة للأقسام الستة
                    $sectionIcons = [
                        'البيانات الأساسية'       => 'fas fa-id-card',
                        'بيانات الأب'             => 'fas fa-male',
                        'بيانات الأم'             => 'fas fa-female',
                        'البيانات الصحية والنفسية' => 'fas fa-heartbeat',
                        'الإخوة والأشقاء'         => 'fas fa-users',
                        'الصورة الشخصية'          => 'fas fa-camera',
                        'أولياء الأمور الآخرون'    => 'fas fa-people-roof',
                        'الأسرة وصلات القرابة'     => 'fas fa-people-arrows',
                        'المسار الدراسي'           => 'fas fa-graduation-cap',
                        'الصورة الشخصية والمرفقات' => 'fas fa-paperclip',
                    ];
                    foreach ($columnSections as $sectionTitle => $cols):
                        $sectionIcon = $sectionIcons[$sectionTitle] ?? 'fas fa-folder-open';
                        // تحقق: هل يحتوي القسم على حقول قابلة للتحديد (غير __header__ و __note__ فقط)
                        $hasToggles = false;
                        foreach ($cols as $_c) { if ($_c[0] !== '__header__' && $_c[0] !== '__note__') { $hasToggles = true; break; } }
                        ?>
                        <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px; background: #ffffff; border: 1px solid #e2e8f0 !important;">
                            <div class="card-header border-0 d-flex justify-content-between align-items-center py-3" style="background: rgba(37, 99, 235, 0.05); border-top-left-radius: 12px; border-top-right-radius: 12px;">
                                <h6 class="mb-0 fw-bold text-primary d-flex align-items-center" style="font-size: 0.95rem;">
                                    <span class="d-inline-flex align-items-center justify-content-center bg-white text-primary rounded-circle shadow-sm me-2" style="width: 32px; height: 32px;">
                                        <i class="<?php echo htmlspecialchars($sectionIcon); ?>"></i>
                                    </span>
                                    <span><?php echo htmlspecialchars($sectionTitle); ?></span>
                                </h6>
                                <?php if ($hasToggles): ?>
                                <div class="d-flex gap-1" role="group">
                                    <button type="button" class="btn btn-outline-success btn-sm select-section px-2 py-1"
                                        data-target-section="<?php echo htmlspecialchars($sectionTitle); ?>" title="تحديد القسم" style="border-radius: 6px; font-size: 0.75rem;">
                                        <i class="fas fa-check"></i> تحديد
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm deselect-section px-2 py-1"
                                        data-target-section="<?php echo htmlspecialchars($sectionTitle); ?>" title="إلغاء القسم" style="border-radius: 6px; font-size: 0.75rem;">
                                        <i class="fas fa-times"></i> إلغاء
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body py-3">
                                <div class="row g-3" data-section="<?php echo htmlspecialchars($sectionTitle); ?>">
                                    <?php foreach ($cols as $c): ?>
                                        <?php if ($c[0] === '__header__'): ?>
                                            <div class="col-12">
                                                <div class="d-flex align-items-center mb-1 mt-2">
                                                    <span class="badge rounded-pill text-bg-light border me-2" style="font-size:0.75rem; color:#374151 !important;">
                                                        <i class="fas fa-layer-group me-1 text-primary"></i>
                                                        <?php echo htmlspecialchars($c[1]); ?>
                                                    </span>
                                                    <hr class="flex-grow-1 my-0" style="border-color:#dee2e6;">
                                                </div>
                                            </div>
                                        <?php elseif ($c[0] === '__note__'): ?>
                                            <div class="col-12">
                                                <div class="alert alert-light border d-flex align-items-center py-2 px-3 mb-0" style="border-radius:8px; font-size:0.85rem; color:#6b7280;">
                                                    <i class="fas fa-info-circle me-2 text-primary"></i>
                                                    <?php echo htmlspecialchars($c[1]); ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="col-lg-3 col-md-4 col-sm-6 col-6">
                                                <div class="form-check form-switch custom-switch-premium">
                                                    <input class="form-check-input col-toggle-checkbox" type="checkbox" role="switch"
                                                        id="<?php echo $c[1]; ?>" data-column="<?php echo $c[0]; ?>" <?php echo $c[3] ? 'checked' : ''; ?> style="cursor: pointer;">
                                                    <label class="form-check-label text-secondary fw-medium"
                                                        for="<?php echo $c[1]; ?>" style="cursor: pointer; font-size: 0.85rem;"><?php echo htmlspecialchars($c[2]); ?></label>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i
                            class="fas fa-times me-1"></i>إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة عرض محتوى الخلية (للنصوص الطويلة) -->
    <div class="modal fade" id="cellContentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
                <div class="modal-header">
                    <h5 class="modal-title" id="cellContentTitle"><i class="fas fa-info-circle me-2"></i></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="cellContentBody" class="text-dark" style="white-space: pre-wrap; word-wrap: break-word;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i
                            class="fas fa-times me-1"></i>إغلاق</button>
                </div>
            </div>
        </div>
    </div>


    <!-- End of content area -->

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize Bootstrap tooltips
            if (window.bootstrap) {
                document.querySelectorAll('.has-tooltip').forEach(el => { new bootstrap.Tooltip(el); });
                // Initialize Bootstrap popovers (e.g. siblings column)
                document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
                    new bootstrap.Popover(el, { sanitize: false });
                });
            }

            document.addEventListener('click', function (e) {
                const archiveBtn = e.target.closest('.archive-student');
                if (archiveBtn) {
                    document.getElementById('archive_user_id').value = archiveBtn.getAttribute('data-id');
                    document.getElementById('archive_student_name').textContent = archiveBtn.getAttribute('data-name');
                    document.getElementById('archive_reason').value = '';
                }
            });

            // Standardized Tab Persistence
            const tabLinks = document.querySelectorAll('#studentTabs button[data-bs-toggle="tab"]');
            const activeTabInput = document.getElementById('active_tab_input');
            const urlParams = new URLSearchParams(window.location.search);
            const tabTargetToValue = target => target.replace('#pane-', '').replace(/-/g, '_');
            const tabValueToTarget = value => '#pane-' + String(value || '').replace(/_/g, '-');

            if (urlParams.has('tab')) {
                const urlTab = urlParams.get('tab');
                const targetId = tabValueToTarget(urlTab);
                const tabEl = document.querySelector(`button[data-bs-target="${targetId}"]`);
                if (tabEl && !tabEl.classList.contains('active')) {
                    new bootstrap.Tab(tabEl).show();
                }
                if (activeTabInput) { activeTabInput.value = urlTab; }
            } else if (activeTabInput) {
                // فتح إضافة/تعديل جديد يبدأ دائماً من البيانات الأساسية؛ لا نعيد آخر تبويب محفوظ.
                activeTabInput.value = 'basic';
            }

            tabLinks.forEach(link => {
                link.addEventListener('shown.bs.tab', function (e) {
                    const target = e.target.getAttribute('data-bs-target');
                    if (activeTabInput) { activeTabInput.value = tabTargetToValue(target); }

                    const newUrl = new URL(window.location);
                    newUrl.searchParams.set('tab', tabTargetToValue(target));
                    window.history.replaceState({}, '', newUrl);
                });
            });

        });

        function handleAjaxError(error, message) {
            console.error('Error:', error);
            showAlert('danger', message || 'حدث خطأ في الاتصال بالخادم');
        }

        // Function to update student points in the table without reloading (Points column is removed)
        function updateStudentPoints(studentId, newPoints) {
            // Points column removed from students listing table
        }

        // Function to show alert messages
        function showAlert(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.role = 'alert';
            alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;

            const container = document.querySelector('.container-fluid');
            if (container) {
                container.insertBefore(alertDiv, container.firstChild);

                setTimeout(() => {
                    alertDiv.classList.remove('show');
                    setTimeout(() => alertDiv.remove(), 150);
                }, 5000);
            } else {
                alert(message);
            }
        }

        // ===== Multiple Selection Filter Cascading =====
        function updateDropdownLabels() {
            // 1. Stages
            var checkedStages = document.querySelectorAll('.stage-checkbox:checked');
            var stageLabel = document.getElementById('selectedStagesLabel');
            var stageBtn = document.getElementById('stageDropdown');
            if (stageLabel) {
                if (checkedStages.length === 0) {
                    stageLabel.textContent = 'الكل';
                } else if (checkedStages.length === document.querySelectorAll('.stage-checkbox').length) {
                    stageLabel.textContent = 'الكل';
                } else if (checkedStages.length <= 2) {
                    var names = [];
                    checkedStages.forEach(function(cb) {
                        names.push(cb.nextElementSibling.textContent.trim());
                    });
                    stageLabel.textContent = names.join('، ');
                } else {
                    stageLabel.textContent = checkedStages.length + ' محددة';
                }
            }
            if (stageBtn) {
                stageBtn.classList.toggle('active-filter', checkedStages.length > 0);
            }

            // 2. Grades
            var checkedGrades = document.querySelectorAll('.grade-checkbox:checked');
            var gradeLabel = document.getElementById('selectedGradesLabel');
            var gradeBtn = document.getElementById('gradeDropdown');
            if (gradeLabel) {
                var visibleGradesCount = document.querySelectorAll('.grade-item:not([style*="display: none"])').length || document.querySelectorAll('.grade-checkbox').length;
                if (checkedGrades.length === 0) {
                    gradeLabel.textContent = 'الكل';
                } else if (checkedGrades.length === visibleGradesCount) {
                    gradeLabel.textContent = 'الكل';
                } else if (checkedGrades.length <= 2) {
                    var names = [];
                    checkedGrades.forEach(function(cb) {
                        names.push(cb.nextElementSibling.textContent.trim());
                    });
                    gradeLabel.textContent = names.join('، ');
                } else {
                    gradeLabel.textContent = checkedGrades.length + ' محددة';
                }
            }
            if (gradeBtn) {
                gradeBtn.classList.toggle('active-filter', checkedGrades.length > 0);
            }

            // 3. Classes
            var checkedClasses = document.querySelectorAll('.class-checkbox:checked');
            var classLabel = document.getElementById('selectedClassesLabel');
            var classBtn = document.getElementById('classDropdown');
            if (classLabel) {
                var visibleClassesCount = document.querySelectorAll('.class-item:not([style*="display: none"])').length || document.querySelectorAll('.class-checkbox').length;
                if (checkedClasses.length === 0) {
                    classLabel.textContent = 'الكل';
                } else if (checkedClasses.length === visibleClassesCount) {
                    classLabel.textContent = 'الكل';
                } else if (checkedClasses.length <= 2) {
                    var names = [];
                    checkedClasses.forEach(function(cb) {
                        names.push(cb.nextElementSibling.textContent.trim());
                    });
                    classLabel.textContent = names.join('، ');
                } else {
                    classLabel.textContent = checkedClasses.length + ' محددة';
                }
            }
            if (classBtn) {
                classBtn.classList.toggle('active-filter', checkedClasses.length > 0);
            }
        }

        function applyCascadingFilters() {
            // Get checked stage IDs
            var checkedStages = Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(function(cb) {
                return cb.value;
            });

            // Update grades visibility
            var gradeItems = document.querySelectorAll('.grade-item');
            gradeItems.forEach(function(item) {
                var stageId = item.getAttribute('data-stage');
                if (checkedStages.length === 0 || checkedStages.includes(stageId)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                    var cb = item.querySelector('.grade-checkbox');
                    if (cb && cb.checked) {
                        cb.checked = false;
                    }
                }
            });

            // Get checked grade IDs
            var checkedGrades = Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(function(cb) {
                return cb.value;
            });

            // Update classes visibility
            var classItems = document.querySelectorAll('.class-item');
            classItems.forEach(function(item) {
                var gradeId = item.getAttribute('data-grade');
                var cb = item.querySelector('.class-checkbox');

                // Check if this class's grade belongs to any visible grades/stages
                var gradeItem = document.querySelector('.grade-checkbox[value="' + gradeId + '"]');
                var isGradeVisible = gradeItem && gradeItem.closest('.grade-item').style.display !== 'none';

                if (isGradeVisible && (checkedGrades.length === 0 || checkedGrades.includes(gradeId))) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                    if (cb && cb.checked) {
                        cb.checked = false;
                    }
                }
            });

            updateDropdownLabels();
        }

        // Initialize multiple selection filters and table settings column toggling
        document.addEventListener('DOMContentLoaded', function () {
            // Bind change listeners to checkboxes
            document.querySelectorAll('.stage-checkbox').forEach(function(cb) {
                cb.addEventListener('change', applyCascadingFilters);
            });
            document.querySelectorAll('.grade-checkbox').forEach(function(cb) {
                cb.addEventListener('change', applyCascadingFilters);
            });
            document.querySelectorAll('.class-checkbox').forEach(function(cb) {
                cb.addEventListener('change', updateDropdownLabels);
            });

            // Auto-submit form when any filter dropdown collapses
            document.querySelectorAll('#filterForm .dropdown').forEach(function(dropdown) {
                dropdown.addEventListener('hide.bs.dropdown', function () {
                    const filterForm = document.getElementById('filterForm');
                    if (filterForm) {
                        filterForm.submit();
                    }
                });
            });

            // Initial trigger
            applyCascadingFilters();

            // Table settings column toggling
            const checkboxes = document.querySelectorAll('.col-toggle-checkbox');
            const storageKey = 'students_table_columns_v2';

            // Load preferences
            let prefs = {};
            try {
                const saved = localStorage.getItem(storageKey);
                if (saved) {
                    prefs = JSON.parse(saved);
                }
            } catch (e) {
                console.error('Error parsing table columns preferences:', e);
            }

            // Set initial checkbox states and apply column visibility
            checkboxes.forEach(cb => {
                const colClass = cb.getAttribute('data-column');
                if (!colClass) return;

                // Determine if column should be visible
                let isVisible;
                if (prefs.hasOwnProperty(colClass)) {
                    isVisible = prefs[colClass];
                    cb.checked = isVisible;
                } else {
                    // Use current state from HTML
                    isVisible = cb.checked;
                }

                // Apply visibility
                applyColumnVisibility(colClass, isVisible);

                // Listen for changes
                cb.addEventListener('change', function () {
                    const show = this.checked;
                    applyColumnVisibility(colClass, show);
                    prefs[colClass] = show;
                    localStorage.setItem(storageKey, JSON.stringify(prefs));
                });
            });
        });

        function applyColumnVisibility(colClass, isVisible) {
            const elements = document.querySelectorAll('.' + colClass);
            elements.forEach(el => {
                if (isVisible) {
                    el.classList.remove('d-none');
                } else {
                    el.classList.add('d-none');
                }
            });
        }

        // ===== أزرار تحديد الكل / إلغاء الكل / تحديد القسم في مودال إعدادات الجدول =====
        // نُطلق حدث 'change' على كل checkbox ليُطبّق الإخفاء/الإظهار ويُحفظ في localStorage تلقائياً.
        (function () {
            function toggleAll(checked) {
                document.querySelectorAll('#tableSettingsModal .col-toggle-checkbox').forEach(function (cb) {
                    if (cb.checked !== checked) {
                        cb.checked = checked;
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            }
            const btnAll = document.getElementById('selectAllColumns');
            const btnNone = document.getElementById('deselectAllColumns');
            if (btnAll) btnAll.addEventListener('click', function () { toggleAll(true); });
            if (btnNone) btnNone.addEventListener('click', function () { toggleAll(false); });

            document.querySelectorAll('#tableSettingsModal .select-section').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const section = this.getAttribute('data-target-section');
                    document.querySelectorAll('#tableSettingsModal .row[data-section="' + section + '"] .col-toggle-checkbox').forEach(function (cb) {
                        if (!cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true })); }
                    });
                });
            });
            document.querySelectorAll('#tableSettingsModal .deselect-section').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const section = this.getAttribute('data-target-section');
                    document.querySelectorAll('#tableSettingsModal .row[data-section="' + section + '"] .col-toggle-checkbox').forEach(function (cb) {
                        if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); }
                    });
                });
            });
        })();

        // ===== معالج النقر على أيقونات النصوص الطويلة لفتح النافذة المنبثقة =====
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.view-cell-content');
            if (!btn) return;
            e.preventDefault();
            const title = btn.getAttribute('data-title') || 'التفاصيل';
            const content = btn.getAttribute('data-content') || '';
            const titleEl = document.getElementById('cellContentTitle');
            const bodyEl = document.getElementById('cellContentBody');
            if (titleEl) titleEl.innerHTML = '<i class="fas fa-info-circle me-2"></i>' + title;
            if (bodyEl) bodyEl.textContent = content;
            const modalEl = document.getElementById('cellContentModal');
            if (modalEl) {
                const inst = bootstrap.Modal.getOrCreateInstance(modalEl);
                inst.show();
            }
        });


    </script>

    <script>
        // Add print date when printing
        window.addEventListener('beforeprint', function () {
            const now = new Date();
            const printDate = now.toLocaleDateString('ar-SA') + ' ' + now.toLocaleTimeString('ar-SA');
            document.body.setAttribute('data-print-date', printDate);
        });

        // Export evaluations to Excel
        document.addEventListener('click', function (e) {
            if (e.target && e.target.id === 'exportEvaluationsBtn') {
                const studentId = currentEvaluationStudentId;
                if (studentId) {
                    window.open(`../includes/ajax_handlers.php?action=export_student_evaluations&student_id=${studentId}`, '_blank');
                }
            }
        });

        // Delete all evaluations for student
        document.addEventListener('click', function (e) {
            if (e.target && e.target.id === 'deleteAllEvaluationsBtn') {
                const studentId = currentEvaluationStudentId;
                const studentName = document.getElementById('student_evaluations_name').textContent;

                if (studentId) {
                    // Set modal data
                    document.getElementById('delete_all_student_id').value = studentId;
                    document.getElementById('delete_all_student_name').textContent = studentName;

                    // Show modal using Bootstrap JavaScript
                    const modal = new bootstrap.Modal(document.getElementById('deleteAllEvaluationsModal'));
                    modal.show();
                }
            }
        });

        // Handle delete all evaluations confirmation
        document.addEventListener('click', function (e) {
            if (e.target && e.target.id === 'confirmDeleteAllEvaluations') {
                const studentId = document.getElementById('delete_all_student_id').value;
                deleteAllStudentEvaluations(studentId);

                // Hide modal using Bootstrap JavaScript
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteAllEvaluationsModal'));
                if (modal) {
                    modal.hide();
                }
            }
        });

        // Handle delete single evaluation
        document.addEventListener('click', function (e) {
            // Check if click is on the button or its children (like icon)
            const deleteButton = e.target.closest('.delete-evaluation');

            if (deleteButton) {
                const evaluationId = deleteButton.getAttribute('data-id');
                const studentId = currentEvaluationStudentId;

                if (evaluationId && studentId) {
                    // Set modal data
                    document.getElementById('delete_single_evaluation_id').value = evaluationId;
                    document.getElementById('delete_single_student_id').value = studentId;

                    // Show modal using Bootstrap JavaScript
                    const modal = new bootstrap.Modal(document.getElementById('deleteSingleEvaluationModal'));
                    modal.show();
                }
            }
        });

        // Handle confirm delete single evaluation
        document.addEventListener('click', function (e) {
            if (e.target && e.target.id === 'confirmDeleteSingleEvaluation') {
                const evaluationId = document.getElementById('delete_single_evaluation_id').value;
                const studentId = document.getElementById('delete_single_student_id').value;

                deleteSingleEvaluation(evaluationId, studentId);

                // Hide modal using Bootstrap JavaScript
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteSingleEvaluationModal'));
                if (modal) {
                    modal.hide();
                }
            }
        });

        // Function to delete all evaluations for a student
        function deleteAllStudentEvaluations(studentId) {
            fetch('../includes/ajax_handlers.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_all_student_evaluations&student_id=${studentId}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message with Bootstrap alert
                        const alertHtml = `
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    تم حذف جميع التقييمات بنجاح.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
                        document.querySelector('.container-fluid').insertAdjacentHTML('afterbegin', alertHtml);

                        // Refresh evaluations list
                        loadStudentEvaluations(studentId);
                        // Update points in main table
                        updateStudentPoints(studentId, 0);
                    } else {
                        // Show error message
                        const alertHtml = `
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    ${data.message || 'فشل في حذف التقييمات.'}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
                        document.querySelector('.container-fluid').insertAdjacentHTML('afterbegin', alertHtml);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    const alertHtml = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                حدث خطأ أثناء حذف التقييمات.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
                    document.querySelector('.container-fluid').insertAdjacentHTML('afterbegin', alertHtml);
                });
        }

        // Function to delete single evaluation
        function deleteSingleEvaluation(evaluationId, studentId) {
            fetch('../includes/ajax_handlers.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_evaluation&evaluation_id=${evaluationId}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message with Bootstrap alert
                        const alertHtml = `
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    تم حذف التقييم بنجاح.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
                        document.querySelector('.container-fluid').insertAdjacentHTML('afterbegin', alertHtml);

                        // Refresh evaluations list
                        loadStudentEvaluations(studentId);

                        // Update points in main table if new total is provided
                        if (data.new_total !== undefined) {
                            updateStudentPoints(studentId, data.new_total);
                        }
                    } else {
                        // Show error message
                        const alertHtml = `
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    ${data.message || 'فشل في حذف التقييم.'}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
                        document.querySelector('.container-fluid').insertAdjacentHTML('afterbegin', alertHtml);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    const alertHtml = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                حدث خطأ أثناء حذف التقييم.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
                    document.querySelector('.container-fluid').insertAdjacentHTML('afterbegin', alertHtml);
                });
        }

    </script>

<?php endif; // End of list view (page_action !== add/edit) ?>
