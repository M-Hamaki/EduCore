<?php if ($action !== 'view'): ?>

<!-- ============================= -->
<!-- قائمة الموظفين الرئيسية -->
<!-- ============================= -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?php echo $mainTab === 'staff' ? 'active' : ''; ?>" href="staff.php"><i class="fas fa-id-card-alt me-2"></i>قائمة العاملين <span class="badge bg-primary ms-1"><?php echo (int) $staffTotal; ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?php echo $mainTab === 'activity_log' ? 'active' : ''; ?>" href="staff.php?main_tab=activity_log"><i class="fas fa-history me-2"></i>سجل العمليات <span class="badge bg-secondary ms-1"><?php echo $staffLogTotal; ?></span></a></li>
</ul>

<?php if ($mainTab === 'activity_log'): ?>
<form method="get" class="admin-filter-bar">
    <input type="hidden" name="main_tab" value="activity_log">
    <div class="admin-filter-controls">
        <input class="form-control form-control-sm" style="min-width:160px;" name="log_search" placeholder="بحث بالموظف أو المنفذ" value="<?php echo htmlspecialchars($_GET['log_search'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <select class="form-select form-select-sm" style="width:auto;" name="log_action"><option value="">كل العمليات</option><?php foreach (['create'=>'إنشاء','update'=>'تعديل','delete'=>'حذف','status_change'=>'تغيير حالة','import'=>'استيراد'] as $v=>$l): ?><option value="<?php echo $v; ?>" <?php echo ($_GET['log_action'] ?? '') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select>
        <input class="form-control form-control-sm flatpickr-date" style="width:auto;" type="text" name="log_from" value="<?php echo htmlspecialchars($_GET['log_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="اختر التاريخ..." title="من تاريخ">
        <input class="form-control form-control-sm flatpickr-date" style="width:auto;" type="text" name="log_to" value="<?php echo htmlspecialchars($_GET['log_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="اختر التاريخ..." title="إلى تاريخ">
    </div>
    <div class="admin-filter-actions">
        <button class="btn btn-light btn-sm" type="submit"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="staff.php?main_tab=activity_log" class="btn btn-light btn-sm"><i class="fas fa-undo me-1"></i>إعادة تعيين</a>
    </div>
</form>
<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped admin-data-table mb-0"><thead class="table-light"><tr><th>التاريخ والوقت</th><th>المستخدم</th><th>العملية</th><th>الموظف</th><th style="min-width:360px">التفاصيل</th></tr></thead><tbody>
        <?php if (!$staffLogs): ?><tr><td colspan="5" class="text-center text-muted py-5"><i class="fas fa-inbox d-block mb-2" style="font-size:2rem;"></i>لا توجد عمليات مطابقة</td></tr><?php endif; ?>
        <?php foreach ($staffLogs as $log): ?><tr>
            <td class="text-nowrap"><strong><?php echo date('Y/m/d', strtotime($log['created_at'])); ?></strong><br><small class="text-muted"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></small></td>
            <td><strong><?php echo htmlspecialchars($log['user_name']); ?></strong><br><small class="text-muted"><?php echo ActivityLog::getTargetLabel($log['user_role']); ?></small></td>
            <td><span class="badge <?php echo ActivityLog::getActionBadgeClass($log['action']); ?>"><i class="fas <?php echo ActivityLog::getActionIcon($log['action']); ?> me-1"></i><?php echo ActivityLog::getActionLabel($log['action']); ?></span></td>
            <td><strong><?php echo htmlspecialchars($log['target_name'] ?: '-'); ?></strong><?php echo $log['target_id'] ? '<br><small class="text-muted">#' . (int)$log['target_id'] . '</small>' : ''; ?></td>
            <td class="small"><?php $d = $log['details'] ? json_decode($log['details'], true) : null; echo $d ? ActivityLog::formatDetailsHtml($d, 'diff_table') : ActivityLog::getLegacyDetailsHtml($log); ?></td>
        </tr><?php endforeach; ?>
        </tbody></table>
    </div>
    <?php if ($staffLogTotalPages > 1): ?>
    <div class="mt-3 pt-3 border-top">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">
                <?php
                $logFrom = $staffLogOffset + 1;
                $logTo = min($staffLogOffset + $staffLogPerPage, $staffLogTotal);
                echo 'عرض ' . $logFrom . ' إلى ' . $logTo . ' من إجمالي ' . $staffLogTotal . ' عملية';
                ?>
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    // بناء رابط الصفحة محافظاً على الفلاتر
                    $logQueryBase = ['main_tab' => 'activity_log'];
                    if (!empty($_GET['log_action'])) $logQueryBase['log_action'] = $_GET['log_action'];
                    if (!empty($_GET['log_search'])) $logQueryBase['log_search'] = $_GET['log_search'];
                    if (!empty($_GET['log_from'])) $logQueryBase['log_from'] = $_GET['log_from'];
                    if (!empty($_GET['log_to'])) $logQueryBase['log_to'] = $_GET['log_to'];
                    $buildLogUrl = function($page) use ($logQueryBase) {
                        $q = array_merge($logQueryBase, ['log_page' => $page]);
                        return 'staff.php?' . http_build_query($q);
                    };
                    ?>
                    <li class="page-item <?php echo $staffLogPage <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $buildLogUrl($staffLogPage - 1); ?>" aria-label="السابق"><i class="fas fa-angle-right"></i></a>
                    </li>
                    <?php
                    // عرض نطاق الصفحات (3 قبل وبعد الصفحة الحالية)
                    $start = max(1, $staffLogPage - 3);
                    $end = min($staffLogTotalPages, $staffLogPage + 3);
                    if ($start > 1) {
                        echo '<li class="page-item"><a class="page-link" href="' . $buildLogUrl(1) . '">1</a></li>';
                        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?php echo $i === $staffLogPage ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $buildLogUrl($i); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php
                    endfor;
                    if ($end < $staffLogTotalPages) {
                        if ($end < $staffLogTotalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="' . $buildLogUrl($staffLogTotalPages) . '">' . $staffLogTotalPages . '</a></li>';
                    }
                    ?>
                    <li class="page-item <?php echo $staffLogPage >= $staffLogTotalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $buildLogUrl($staffLogPage + 1); ?>" aria-label="التالي"><i class="fas fa-angle-left"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="admin-filter-bar">
    <div class="admin-filter-controls">
        <select class="form-select form-select-sm" id="staffJobTitleFilter" style="width:auto; min-width:160px;">
            <option value="all">كل المسميات الوظيفية</option>
            <?php foreach ($staffFilterJobTitles as $jobTitleOption): ?>
                <option value="<?php echo htmlspecialchars($jobTitleOption, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($jobTitleOption, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" id="staffForceFilter" style="width:auto; min-width:160px;">
            <option value="all">كل القوى التابعة لها</option>
            <?php foreach ($staffFilterForces as $forceOption): ?>
                <option value="<?php echo htmlspecialchars($forceOption, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($forceOption, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" id="staffWorkStatusFilter" style="width:auto; min-width:150px;">
            <option value="all">كل الحالات الوظيفية</option>
            <option value="on_duty">على رأس العمل</option>
            <option value="off_duty">ليس على رأس العمل</option>
        </select>
    </div>
    <div class="admin-filter-actions">
        <button type="button" class="btn btn-light btn-sm" id="resetStaffFilters">
            <i class="fas fa-undo me-1"></i>إعادة تعيين
        </button>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
            <i class="fas fa-cog me-1"></i>إعدادات الجدول
        </button>
    </div>
</div>

<div class="admin-list-surface">
    <?php
    if ($staffServerSide || count($allStaff) > 0):
    ?>
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped admin-data-table" id="staffTable">
                    <thead>
                        <tr>
                            <th width="50">#</th>
                            <th class="col-biometric d-none">رقم البصمة</th>
                            <th class="col-code">كود الموظف</th>
                            <th class="col-name">الاسم</th>
                            <th class="col-job-title">المسمى الوظيفي</th>
                            <th class="col-mobile">الموبايل</th>
                            <th class="col-national-id d-none">الرقم القومي</th>
                            <!-- أعمدة بيانات أساسية -->
                            <th class="col-passport d-none">جواز السفر</th>
                            <th class="col-birth-date d-none">تاريخ الميلاد</th>
                            <th class="col-birth-place d-none">محل الميلاد</th>
                            <th class="col-gender d-none">النوع</th>
                            <th class="col-religion d-none">الديانة</th>
                            <th class="col-nationality d-none">الجنسية</th>
                            <th class="col-ministry-code d-none">كود الوزارة</th>
                            <th class="col-military d-none">موقف التجنيد</th>
                            <th class="col-marital d-none">الحالة الاجتماعية</th>
                            <th class="col-children d-none">عدد الأبناء</th>
                            <th class="col-city-area d-none">المدينة/المنطقة</th>
                            <th class="col-address d-none">العنوان التفصيلي</th>
                            <th class="col-phone-home d-none">الهاتف الأرضي</th>
                            <th class="col-phone-emergency d-none">رقم الطوارئ</th>
                            <th class="col-email d-none">البريد الإلكتروني</th>
                            <th class="col-emergency-contact d-none">اسم شخص الطوارئ</th>
                            <!-- أعمدة مؤهلات وخبرات -->
                            <th class="col-qualification d-none">المؤهل العلمي</th>
                            <th class="col-qual-year d-none">سنة التخرج</th>
                            <th class="col-qual-uni d-none">الجامعة/المعهد</th>
                            <th class="col-specialization d-none">التخصص</th>
                            <th class="col-experience d-none">سنوات الخبرة</th>
                            <!-- أعمدة وظيفية -->
                            <th class="col-contract-type d-none">نوع العقد</th>
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
                            <th class="col-name-en d-none">الاسم بالإنجليزية</th>
                            <th class="col-current-age d-none">العمر الحالي</th>
                            <th class="col-public-service d-none">الخدمة العامة</th>
                            <th class="col-social-notes d-none">ملاحظات اجتماعية</th>
                            <th class="col-extra-phones d-none">أرقام إضافية</th>
                            <th class="col-extra-data d-none">بيانات أساسية إضافية</th>
                            <th class="col-admin-notes d-none">ملاحظة إدارية</th>
                            <th class="col-department d-none">القسم / القوة التابعة</th>
                            <th class="col-job-grade d-none">الدرجة الوظيفية</th>
                            <th class="col-hire-date d-none">تاريخ التعيين</th>
                            <th class="col-contract-start d-none">بداية التعاقد</th>
                            <th class="col-contract-end d-none">نهاية التعاقد</th>
                            <th class="col-status-reason d-none">سبب الحالة الوظيفية</th>
                            <th class="col-status-effective d-none">تاريخ سريان الحالة</th>
                            <th class="col-first-hire d-none">أول تاريخ تعيين</th>
                            <th class="col-latest-hire d-none">آخر تاريخ تعيين</th>
                            <th class="col-last-working-day d-none">آخر يوم عمل</th>
                            <th class="col-can-rehire d-none">إمكانية إعادة التعيين</th>
                            <th class="col-last-job-movement d-none">آخر حركة وظيفية</th>
                            <th class="col-status-history d-none">سجل الحالات الوظيفية</th>
                            <th class="col-job-movements d-none">الترقيات والتدرج الوظيفي</th>
                            <th class="col-extra-employment d-none">بيانات وظيفية إضافية</th>
                            <th class="col-other-qualifications d-none">مؤهلات أخرى</th>
                            <th class="col-training-courses d-none">الدورات والشهادات</th>
                            <th class="col-work-history d-none">أماكن العمل السابقة</th>
                            <th class="col-profile-image d-none">الصورة الشخصية</th>
                            <th class="col-attachments d-none">المرفقات</th>
                            <th class="col-status" width="130">الحالة الوظيفية</th>
                            <th width="100">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$allStaff): ?>
                            <tr><td colspan="71"><div class="admin-list-loading" role="status" aria-live="polite"><i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل العاملين…</div></td></tr>
                        <?php else: ?>
                        <?php
                        $renderStaffDetailCell = static function ($value, string $title, string $icon): void {
                            $value = trim((string) $value);
                            if ($value === '') {
                                echo '<span class="text-muted">-</span>';
                                return;
                            }
                            echo '<button type="button" class="btn btn-sm btn-link p-0 view-cell-content" data-title="'
                                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" data-content="'
                                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" data-bs-toggle="tooltip" title="'
                                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"><i class="fas '
                                . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></button>';
                        };
                        $counter = 1;
                        foreach ($allStaff as $row):
                        ?>
                            <tr data-force="<?php echo htmlspecialchars((string)($row['department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <td><?php echo $counter++; ?></td>
                                <td class="col-biometric d-none"><small class="text-muted" dir="ltr"><?php echo htmlspecialchars($row['biometric_id'] ?? '-'); ?></small></td>
                                <td class="col-code"><small class="text-muted" dir="ltr"><?php echo htmlspecialchars($row['employee_code'] ?? '-'); ?></small></td>
                                <td class="col-name">
                                    <a href="staff.php?action=view&id=<?php echo $row['id']; ?>" class="text-decoration-none fw-bold" title="عرض الملف الشخصي">
                                        <?php echo htmlspecialchars($row['full_name_ar'] ?: $row['name']); ?>
                                    </a>
                                </td>
                                <td class="col-job-title"><?php echo htmlspecialchars(StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null) ?? '-'); ?></td>
                                <td class="col-mobile"><small dir="ltr"><?php echo htmlspecialchars($row['phone_mobile'] ?? '-'); ?></small></td>
                                <td class="col-national-id d-none"><small dir="ltr"><?php echo htmlspecialchars($row['national_id'] ?? '-'); ?></small></td>
                                <!-- خلايا بيانات أساسية إضافية -->
                                <td class="col-passport d-none"><small dir="ltr"><?php echo htmlspecialchars($row['passport_number'] ?? '-'); ?></small></td>
                                <td class="col-birth-date d-none"><?php echo htmlspecialchars($row['birth_date'] ?? '-'); ?></td>
                                <td class="col-birth-place d-none"><?php echo htmlspecialchars($row['birth_place'] ?? '-'); ?></td>
                                <td class="col-gender d-none">
                                    <?php
                                    $g = $row['gender'] ?? '';
                                    echo ($g === 'male' ? 'ذكر' : ($g === 'female' ? 'أنثى' : '-'));
                                    ?>
                                </td>
                                <td class="col-religion d-none">
                                    <?php
                                    $r = $row['religion'] ?? '';
                                    echo ($r === 'muslim' ? 'مسلم' : ($r === 'christian' ? 'مسيحي' : ($r === 'other' ? 'أخرى' : '-')));
                                    ?>
                                </td>
                                <td class="col-nationality d-none"><?php echo htmlspecialchars($row['nationality'] ?? '-'); ?></td>
                                <td class="col-ministry-code d-none"><small dir="ltr"><?php echo htmlspecialchars($row['ministry_code'] ?? '-'); ?></small></td>
                                <td class="col-military d-none">
                                    <?php
                                    $milVal = trim((string)($row['military_status'] ?? ''));
                                    echo htmlspecialchars($milVal !== '' ? $milVal : '-');
                                    ?>
                                </td>
                                <td class="col-marital d-none"><?php echo htmlspecialchars($row['marital_status'] ?? '-'); ?></td>
                                <td class="col-children d-none"><?php echo htmlspecialchars($row['number_of_children'] ?? '-'); ?></td>
                                <td class="col-city-area d-none"><?php echo htmlspecialchars($row['city_area'] ?? '-'); ?></td>
                                <td class="col-address d-none text-center">
                                    <?php
                                    $addrVal = trim((string)($row['address_detail'] ?? ''));
                                    if ($addrVal !== '') {
                                        echo '<button type="button" class="btn btn-sm btn-link p-0 view-cell-content" data-title="العنوان التفصيلي" data-content="' . htmlspecialchars($addrVal, ENT_QUOTES) . '" data-bs-toggle="tooltip" title="عرض العنوان"><i class="fas fa-map-marker-alt text-primary"></i></button>';
                                    } else {
                                        echo '<span class="text-muted">-</span>';
                                    }
                                    ?>
                                </td>
                                <td class="col-phone-home d-none"><small dir="ltr"><?php echo htmlspecialchars($row['phone_home'] ?? '-'); ?></small></td>
                                <td class="col-phone-emergency d-none"><small dir="ltr"><?php echo htmlspecialchars($row['phone_emergency'] ?? '-'); ?></small></td>
                                <td class="col-email d-none"><small dir="ltr"><?php echo htmlspecialchars($row['email_personal'] ?? '-'); ?></small></td>
                                <td class="col-emergency-contact d-none"><?php echo htmlspecialchars($row['emergency_contact_name'] ?? '-'); ?></td>
                                <!-- خلايا مؤهلات وخبرات -->
                                <td class="col-qualification d-none"><?php echo htmlspecialchars($row['qualification'] ?? '-'); ?></td>
                                <td class="col-qual-year d-none"><?php echo htmlspecialchars($row['qualification_year'] ?? '-'); ?></td>
                                <td class="col-qual-uni d-none"><?php echo htmlspecialchars($row['qualification_university'] ?? '-'); ?></td>
                                <td class="col-specialization d-none"><?php echo htmlspecialchars($row['specialization'] ?? '-'); ?></td>
                                <td class="col-experience d-none"><?php echo htmlspecialchars($row['years_of_experience'] ?? '-'); ?></td>
                                <!-- خلايا وظيفية -->
                                <td class="col-contract-type d-none">
                                    <?php
                                    $ct_raw = $row['contract_type'] ?? '';
                                    $ct_label = $contractLabels[$ct_raw] ?? ($ct_raw !== '' ? $ct_raw : '-');
                                    $ct_class = $ct_raw === 'permanent' ? 'success' : ($ct_raw === 'temporary' ? 'warning text-dark' : 'info');
                                    ?>
                                    <span class="badge bg-<?php echo htmlspecialchars($ct_class, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ct_label, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <!-- خلايا بيانات صحية -->
                                <?php
                                // خلايا النصوص الطويلة/المنطقية الصحية: أيقونة منبثقة أو تحذير حسب وجود المحتوى
                                $staffHealthTextFields = [
                                    'col-blood-type'      => ['key' => 'blood_type', 'label' => 'فصيلة الدم', 'icon' => 'fa-droplet', 'warn' => false, 'plain' => true],
                                    'col-insurance-number'=> ['key' => 'insurance_number', 'label' => 'رقم التأمين', 'icon' => 'fa-file-medical', 'warn' => false, 'plain' => true],
                                    'col-insurance-start' => ['key' => 'insurance_start_date', 'label' => 'بداية التأمين', 'icon' => 'fa-calendar', 'warn' => false, 'plain' => true],
                                    'col-insurance-end'   => ['key' => 'insurance_end_date', 'label' => 'نهاية التأمين', 'icon' => 'fa-calendar', 'warn' => false, 'plain' => true],
                                    'col-health-status'   => ['key' => 'health_status', 'label' => 'الحالة الصحية العامة', 'icon' => 'fa-notes-medical', 'warn' => false, 'plain' => false],
                                    'col-chronic'         => ['key' => 'chronic_diseases', 'label' => 'الأمراض المزمنة', 'icon' => 'fa-heart-pulse', 'warn' => true, 'plain' => false],
                                    'col-allergies'       => ['key' => 'allergies', 'label' => 'الحساسية', 'icon' => 'fa-allergies', 'warn' => true, 'plain' => false],
                                    'col-disabilities'    => ['key' => 'disabilities', 'label' => 'الإعاقات', 'icon' => 'fa-wheelchair', 'warn' => true, 'plain' => false],
                                    'col-medications'     => ['key' => 'medications', 'label' => 'العلاج / الأدوية', 'icon' => 'fa-pills', 'warn' => false, 'plain' => false],
                                    'col-treatment'       => ['key' => 'treatment_plan', 'label' => 'خطط علاجية متبعة', 'icon' => 'fa-clipboard-list', 'warn' => false, 'plain' => false],
                                    'col-medical-reports' => ['key' => 'previous_medical_reports', 'label' => 'تقارير طبية سابقة', 'icon' => 'fa-file-medical', 'warn' => false, 'plain' => false],
                                    'col-emergency-notes' => ['key' => 'emergency_medical_notes', 'label' => 'ملاحظات طبية طارئة', 'icon' => 'fa-triangle-exclamation', 'warn' => true, 'plain' => false],
                                    'col-psychological'   => ['key' => 'psychological_notes', 'label' => 'ملاحظات نفسية وسلوكية', 'icon' => 'fa-brain', 'warn' => false, 'plain' => false],
                                ];
                                foreach ($staffHealthTextFields as $colClass => $cfg):
                                    $cellVal = trim((string)($row[$cfg['key']] ?? ''));
                                    ?>
                                    <td class="<?php echo $colClass; ?> d-none <?php echo $cfg['plain'] ? '' : 'text-center'; ?>">
                                        <?php
                                        if ($cellVal !== '') {
                                            if ($cfg['plain']) {
                                                echo '<small dir="ltr">' . htmlspecialchars($cellVal) . '</small>';
                                            } else {
                                                $btnClass = $cfg['warn'] ? 'text-danger' : 'text-info';
                                                echo '<button type="button" class="btn btn-sm btn-link p-0 view-cell-content ' . $btnClass . '" data-title="' . htmlspecialchars($cfg['label']) . '" data-content="' . htmlspecialchars($cellVal, ENT_QUOTES) . '" data-bs-toggle="tooltip" title="' . htmlspecialchars($cfg['label']) . '"><i class="fas ' . $cfg['icon'] . '"></i></button>';
                                            }
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="col-name-en d-none"><small dir="ltr"><?php echo htmlspecialchars($row['full_name_en'] ?? '-'); ?></small></td>
                                <td class="col-current-age d-none"><?php echo !empty($row['birth_date']) ? (new DateTimeImmutable((string) $row['birth_date']))->diff(new DateTimeImmutable('today'))->y . ' سنة' : '-'; ?></td>
                                <td class="col-public-service d-none"><?php echo htmlspecialchars($row['public_service_status'] ?? '-'); ?></td>
                                <td class="col-social-notes d-none text-center"><?php $renderStaffDetailCell($row['notes'] ?? '', 'ملاحظات اجتماعية', 'fa-note-sticky text-secondary'); ?></td>
                                <td class="col-extra-phones d-none text-center"><?php $renderStaffDetailCell($row['extra_phones'] ?? '', 'أرقام إضافية', 'fa-phone text-primary'); ?></td>
                                <td class="col-extra-data d-none text-center"><?php $renderStaffDetailCell($row['extra_data'] ?? '', 'بيانات أساسية إضافية', 'fa-list text-primary'); ?></td>
                                <td class="col-admin-notes d-none text-center"><?php $renderStaffDetailCell($row['admin_notes'] ?? '', 'ملاحظة إدارية', 'fa-note-sticky text-warning'); ?></td>
                                <td class="col-department d-none"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                                <td class="col-job-grade d-none"><?php echo htmlspecialchars($row['job_grade'] ?? '-'); ?></td>
                                <td class="col-hire-date d-none"><?php echo htmlspecialchars($row['hire_date'] ?? '-'); ?></td>
                                <td class="col-contract-start d-none"><?php echo htmlspecialchars($row['contract_start'] ?? '-'); ?></td>
                                <td class="col-contract-end d-none"><?php echo htmlspecialchars($row['contract_end'] ?? '-'); ?></td>
                                <td class="col-status-reason d-none text-center"><?php $renderStaffDetailCell($row['current_status_reason'] ?? '', 'سبب الحالة الوظيفية', 'fa-circle-info text-primary'); ?></td>
                                <td class="col-status-effective d-none"><?php echo htmlspecialchars($row['current_status_effective_date'] ?? '-'); ?></td>
                                <td class="col-first-hire d-none"><?php echo htmlspecialchars($row['first_hire_date'] ?? '-'); ?></td>
                                <td class="col-latest-hire d-none"><?php echo htmlspecialchars($row['latest_hire_date'] ?? '-'); ?></td>
                                <td class="col-last-working-day d-none"><?php echo htmlspecialchars($row['last_working_day'] ?? '-'); ?></td>
                                <td class="col-can-rehire d-none"><?php echo !isset($row['can_rehire']) ? '-' : ((int) $row['can_rehire'] === 1 ? 'نعم' : 'لا'); ?></td>
                                <td class="col-last-job-movement d-none"><?php echo htmlspecialchars($row['last_job_movement_date'] ?? '-'); ?></td>
                                <td class="col-status-history d-none"><span class="badge bg-light text-dark border"><?php echo (int) ($row['status_history_count'] ?? 0); ?></span></td>
                                <td class="col-job-movements d-none"><span class="badge bg-light text-dark border"><?php echo (int) ($row['job_movements_count'] ?? 0); ?></span></td>
                                <td class="col-extra-employment d-none text-center"><?php $renderStaffDetailCell($row['extra_employment_data'] ?? '', 'بيانات وظيفية إضافية', 'fa-briefcase text-primary'); ?></td>
                                <td class="col-other-qualifications d-none text-center"><?php $renderStaffDetailCell($row['other_qualifications'] ?? '', 'المؤهلات الأخرى', 'fa-graduation-cap text-primary'); ?></td>
                                <td class="col-training-courses d-none text-center"><?php $renderStaffDetailCell($row['training_courses'] ?? '', 'الدورات والشهادات', 'fa-certificate text-primary'); ?></td>
                                <td class="col-work-history d-none text-center"><?php $renderStaffDetailCell($row['work_history'] ?? '', 'أماكن العمل السابقة', 'fa-building text-primary'); ?></td>
                                <td class="col-profile-image d-none"><span class="badge <?php echo empty($row['profile_image']) ? 'bg-light text-dark border' : 'bg-success'; ?>"><?php echo empty($row['profile_image']) ? 'غير مرفقة' : 'مرفقة'; ?></span></td>
                                <td class="col-attachments d-none"><span class="badge bg-light text-dark border"><?php echo (int) ($row['attachment_count'] ?? 0); ?></span></td>
                                <td class="col-status">
                                    <?php $currentWorkStatus = ($row['current_work_status'] ?? 'on_duty') === 'off_duty' ? 'off_duty' : 'on_duty'; ?>
                                    <span class="d-none"><?php echo $currentWorkStatus; ?></span>
                                    <?php if ($currentWorkStatus === 'on_duty'): ?>
                                        <span class="badge bg-success">على رأس العمل</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">ليس على رأس العمل</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="staff.php?action=edit&id=<?php echo $row['id']; ?>" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" class="btn btn-action-pills btn-delete delete-staff"
                                            data-id="<?php echo $row['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                            data-bs-toggle="tooltip" title="حذف"
                                            onclick="openDeleteStaffModal(this)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">لا يوجد موظفين. <a href="staff.php?action=add">أضف موظفاً جديداً</a>.</div>
        <?php endif; ?>
    </div>
</div>

<?php endif; // main staff list tab ?>

<!-- Delete Modal -->
<div class="modal fade" id="deleteStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>حذف موظف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3"><i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i></div>
                <p class="text-center">هل أنت متأكد من حذف <span class="fw-bold text-primary" id="delete_staff_name"></span>؟</p>
                <p class="text-danger text-center mb-0"><i class="fas fa-exclamation-circle me-1"></i>هذا الإجراء لا يمكن التراجع عنه.</p>
            </div>
            <div class="modal-footer">
                <form method="post" action="staff.php" class="admin-modal-actions">
                    <?php echo csrfField(); ?>
                    <input type="hidden" id="delete_staff_id" name="id">
                    <input type="hidden" name="action" value="delete">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">حذف</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-excel me-2"></i>استيراد الموظفين من ملف Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="staff.php">
                <?php echo csrfField(); ?>
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <h6 class="alert-heading"><i class="fas fa-shield-alt me-2"></i>استيراد تفصيلي وآمن</h6>
                        <p class="mb-0">يتضمن النموذج بيانات العاملين والهواتف والبيانات الإضافية وسجل الحالات والحركات الوظيفية. لا يتضمن الحسابات أو الرواتب أو التكليفات أو المرفقات.</p>
                    </div>
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 border rounded p-3 bg-light mb-3">
                        <div>
                            <div class="fw-semibold"><i class="fas fa-file-download text-success me-1"></i>نموذج استيراد العاملين</div>
                            <small class="text-muted">يُستخدم كود الموظف الفريد لربط البيانات الموجودة في الأوراق الأخرى.</small>
                        </div>
                        <a class="btn btn-outline-success btn-sm" href="staff.php?download_profile_template=staff">
                            <i class="fas fa-download me-1"></i>تحميل النموذج الفارغ
                        </a>
                    </div>
                    <ul class="small text-muted mb-3 ps-3">
                        <li>صيغة التاريخ: <code>YYYY-MM-DD</code>، وحالة الموظف: <code>on_duty</code> أو <code>off_duty</code>.</li>
                        <li>يفحص النظام جميع الأوراق والعلاقات والتكرارات قبل الحفظ؛ إذا وُجد خطأ فلن تُضاف أي بيانات.</li>
                    </ul>
                    <div class="mb-3">
                        <label for="excel_file" class="form-label">اختر ملف Excel</label>
                        <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
                        <small class="text-muted">الملفات المقبولة: .xlsx و .xls، والحد الأقصى 10 ميجابايت.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="import_staff" class="btn btn-success"><i class="fas fa-upload me-1"></i>استيراد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>
