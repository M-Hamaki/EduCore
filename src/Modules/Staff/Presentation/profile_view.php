<?php
$action = $_GET['action'] ?? '';

// ===========================
// عرض الملف الشخصي المفصل
// ===========================
if ($action === 'view' && $viewProfile !== null):
    $vp = $viewProfile;
?>
<div class="card shadow mb-4 border-0">
    <div class="card-header bg-primary text-white py-3 rounded-top-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold"><i class="fas fa-id-card me-2"></i>ملف بيانات الموظف: <?php echo htmlspecialchars($viewUser->name); ?></h5>
        <a href="staff.php" class="btn btn-light btn-sm px-3"><i class="fas fa-arrow-right me-1"></i>رجوع للقائمة</a>
    </div>
    <div class="card-body bg-light-subtle p-4">
        <div class="row g-4">
            <!-- الصورة والملخص -->
            <div class="col-lg-3 col-md-4 text-center mb-2">
                <div class="card border-0 shadow-sm p-4 h-100 bg-white rounded-3">
                    <div class="position-relative d-inline-block mx-auto mb-3">
                        <?php if (!empty($vp['profile_image'])): ?>
                            <img src="../uploads/staff/<?php echo htmlspecialchars($vp['profile_image']); ?>" class="rounded-circle shadow-sm" style="width:130px;height:130px;object-fit:cover; border: 4px solid var(--bs-primary-bg-subtle);" alt="صورة">
                        <?php else: ?>
                            <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center mx-auto shadow-sm"
                                style="width:130px;height:130px; border: 4px solid var(--bs-primary-bg-subtle);">
                                <i class="fas fa-user fa-4x text-primary"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h5 class="fw-bold mb-2 text-dark"><?php echo htmlspecialchars($vp['full_name_ar'] ?: $viewUser->name); ?></h5>

                    <div class="d-flex flex-wrap justify-content-center gap-1 mt-2">
                        <span class="badge bg-<?php echo $roleBadges[$viewUser->role] ?? 'secondary'; ?> px-3 py-2 rounded-pill fs-7">
                            <?php echo $roleLabels[$viewUser->role] ?? $viewUser->role; ?>
                        </span>
                        <?php if (!empty($viewUser->is_supervisor)): ?>
                            <span class="badge px-3 py-2 rounded-pill fs-7 text-white" style="background-color:#8b5cf6">مشرف</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-9 col-md-8">
                <div class="card border-0 shadow-sm p-4 bg-white rounded-3">
                    <!-- البيانات الشخصية -->
                    <h6 class="fw-bold text-primary mb-3 d-flex align-items-center">
                        <span class="bg-primary-subtle text-primary rounded-circle p-2 d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                            <i class="fas fa-user fs-6"></i>
                        </span>
                        البيانات الشخصية
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-barcode text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">كود الموظف لدى المدرسة:</span>
                                <strong class="text-dark" dir="ltr"><?php echo htmlspecialchars($vp['employee_code'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-fingerprint text-info me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">رقم البصمة:</span>
                                <strong class="text-dark" dir="ltr"><?php echo htmlspecialchars($vp['biometric_id'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-barcode text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">كود الموظف بالوزارة:</span>
                                <strong class="text-dark" dir="ltr"><?php echo htmlspecialchars($vp['ministry_code'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-language text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">الاسم بالإنجليزية:</span>
                                <strong class="text-dark" dir="ltr"><?php echo htmlspecialchars($vp['full_name_en'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-id-card text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">الرقم القومي للمصريين:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['national_id'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-passport text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">رقم جواز السفر:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['passport_number'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-birthday-cake text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">تاريخ الميلاد:</span>
                                <strong class="text-dark"><?php echo $vp['birth_date'] ?? '-'; ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-map-marker-alt text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">محل الميلاد:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['birth_place'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-venus-mars text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">النوع:</span>
                                <strong class="text-dark"><?php echo $genderLabels[$vp['gender'] ?? ''] ?? '-'; ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-book text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">الديانة:</span>
                                <strong class="text-dark"><?php echo $religionLabels[$vp['religion'] ?? ''] ?? htmlspecialchars($vp['religion'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-globe-africa text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">الجنسية:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['nationality'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-heart text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">الحالة الاجتماعية:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($viewMaritalStatusLabel); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-child text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">عدد الأبناء:</span>
                                <strong class="text-dark"><?php echo (int)($vp['number_of_children'] ?? 0); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-tint text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">فصيلة الدم:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['blood_type'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-map-marked-alt text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">العنوان:</span>
                                <strong class="text-dark">
                                    <?php
                                    $addressParts = [];
                                    if (!empty($vp['city_area'])) {
                                        $addressParts[] = $vp['city_area'];
                                    }
                                    if (!empty($vp['address_detail'])) {
                                        $addressParts[] = $vp['address_detail'];
                                    }
                                    echo htmlspecialchars(implode(' - ', $addressParts) ?: '-');
                                    ?>
                                </strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-phone text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">الموبايل:</span>
                                <strong class="text-dark" dir="ltr"><?php echo htmlspecialchars($vp['phone_mobile'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-phone-alt text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">تلفون المنزل:</span>
                                <strong class="text-dark" dir="ltr"><?php echo htmlspecialchars($vp['phone_home'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-phone-slash text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">رقم الطوارئ:</span>
                                <strong class="text-dark" dir="ltr"><?php echo htmlspecialchars($vp['phone_emergency'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-user-shield text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">شخص الطوارئ:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['emergency_contact_name'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-envelope text-primary me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">البريد الشخصي:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['email_personal'] ?? '-'); ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- البيانات الوظيفية -->
                    <h6 class="fw-bold text-success mb-3 d-flex align-items-center border-top pt-4">
                        <span class="bg-success-subtle text-success rounded-circle p-2 d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                            <i class="fas fa-briefcase fs-6"></i>
                        </span>
                        البيانات الوظيفية
                    </h6>
                    <div class="row g-3 mb-4">

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-user-clock text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">الحالة الحالية:</span>
                                <?php $viewWorkStatus = ($vp['current_work_status'] ?? 'on_duty') === 'off_duty' ? ['ليس على رأس العمل', 'danger'] : ['على رأس العمل', 'success']; ?>
                                <span class="badge bg-<?php echo $viewWorkStatus[1]; ?>"><?php echo $viewWorkStatus[0]; ?></span>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-calendar-check text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">تاريخ سريان الحالة:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['current_status_effective_date'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-calendar-day text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">تاريخ التعيين:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['hire_date'] ?? ($vp['first_hire_date'] ?? '-')); ?></strong>
                            </div>
                        </div>


                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-briefcase text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">المسمى الوظيفي:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['job_title'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-building text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">القوة التابعة لها:</span>
                                <div>
                                    <?php
                                    if (!empty($vp['department'])) {
                                        $viewDepts = array_map('trim', explode(',', $vp['department']));
                                        foreach ($viewDepts as $vd) {
                                            echo '<span class="badge bg-secondary-subtle text-secondary me-1">' . htmlspecialchars($vd) . '</span>';
                                        }
                                    } else { echo '-'; }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-award text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">الدرجة الوظيفية:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['job_grade'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-file-contract text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">نوع التعاقد:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($contractLabels[$vp['contract_type'] ?? ''] ?? ($vp['contract_type'] ?? '-')); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-calendar-plus text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">بدء التعاقد:</span>
                                <strong class="text-dark"><?php echo $vp['contract_start'] ?? '-'; ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-calendar-minus text-success me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">نهاية التعاقد:</span>
                                <strong class="text-dark"><?php echo $vp['contract_end'] ?? '-'; ?></strong>
                            </div>
                        </div>

                        <?php if (!empty($vp['admin_notes'])): ?>
                            <div class="col-md-8">
                                <div class="d-flex align-items-start py-2 border-bottom border-light">
                                    <i class="fas fa-comment-alt text-success me-2 mt-1" style="width: 20px; text-align: center;"></i>
                                    <span class="text-secondary me-2">ملاحظات إدارية:</span>
                                    <span class="text-dark"><?php echo nl2br(htmlspecialchars($vp['admin_notes'])); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- المؤهلات والخبرات -->
                    <h6 class="fw-bold text-info mb-3 d-flex align-items-center border-top pt-4">
                        <span class="bg-info-subtle text-info rounded-circle p-2 d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                            <i class="fas fa-graduation-cap fs-6"></i>
                        </span>
                        المؤهلات والخبرات
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-graduation-cap text-info me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">المؤهل:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['qualification'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-calendar text-info me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">سنة التخرج:</span>
                                <strong class="text-dark"><?php echo $vp['qualification_year'] ?? '-'; ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-university text-info me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">الجامعة:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['qualification_university'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-microscope text-info me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">التخصص:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['specialization'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-history text-info me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">سنوات الخبرة:</span>
                                <strong class="text-dark"><?php echo !empty($vp['years_of_experience']) ? $vp['years_of_experience'] . ' سنة' : '-'; ?></strong>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                <i class="fas fa-award text-info me-2 mt-1" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">مؤهلات أخرى:</span>
                                <span class="text-dark">
                                    <?php
                                    $otherQuals = json_decode($vp['other_qualifications'] ?? '[]', true);
                                    if (is_array($otherQuals) && !empty($otherQuals)):
                                        $qualsList = [];
                                        foreach ($otherQuals as $oq) {
                                            $qStr = '<strong>' . htmlspecialchars($oq['qualification'] ?? '') . '</strong>';
                                            if (!empty($oq['school'])) $qStr .= ' (' . htmlspecialchars($oq['school']) . ')';
                                            if (!empty($oq['date'])) $qStr .= ' - ' . htmlspecialchars($oq['date']);
                                            $qualsList[] = $qStr;
                                        }
                                        echo implode('<br>', $qualsList);
                                    else:
                                        echo nl2br(htmlspecialchars($vp['other_qualifications'] ?? '-'));
                                    endif;
                                    ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($vp['training_courses'])): ?>
                            <div class="col-12">
                                <div class="d-flex align-items-start py-2 border-bottom border-light">
                                    <i class="fas fa-certificate text-info me-2 mt-1" style="width: 20px; text-align: center;"></i>
                                    <span class="text-secondary me-2">الدورات التدريبية والشهادات:</span>
                                    <span class="text-dark">
                                        <?php
                                        $courses = json_decode($vp['training_courses'] ?? '[]', true);
                                        if (is_array($courses) && !empty($courses)):
                                            $coursesList = [];
                                            foreach ($courses as $c) {
                                                $cStr = '<strong>' . htmlspecialchars($c['course'] ?? '') . '</strong>';
                                                if (!empty($c['date'])) $cStr .= ' - ' . htmlspecialchars($c['date']);
                                                $coursesList[] = $cStr;
                                            }
                                            echo implode('<br>', $coursesList);
                                        else:
                                            echo nl2br(htmlspecialchars($vp['training_courses']));
                                        endif;
                                        ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    $workHistory = json_decode($vp['work_history'] ?? '[]', true);
                    if (!empty($workHistory)):
                    ?>
                        <h6 class="fw-bold text-primary mb-3 border-top pt-4"><i class="fas fa-building me-2"></i>أماكن العمل السابقة</h6>
                        <div class="card border border-light-subtle shadow-sm rounded-3 mb-4 overflow-hidden">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-3 text-secondary">#</th>
                                            <th class="text-secondary">مكان العمل</th>
                                            <th class="text-secondary">من</th>
                                            <th class="text-secondary">إلى</th>
                                            <th class="pe-3 text-secondary">المدة</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($workHistory as $i => $wh):
                                        $whDuration = '-';
                                        if (!empty($wh['start_date']) && !empty($wh['end_date'])) {
                                            $d1 = new DateTime($wh['start_date']);
                                            $d2 = new DateTime($wh['end_date']);
                                            $diff = $d1->diff($d2);
                                            $whDuration = ($diff->y > 0 ? $diff->y . ' سنة ' : '') . ($diff->m > 0 ? $diff->m . ' شهر' : '');
                                            if (empty($whDuration)) $whDuration = $diff->d . ' يوم';
                                        }
                                    ?>
                                        <tr>
                                            <td class="ps-3 text-muted"><?php echo $i + 1; ?></td>
                                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($wh['name'] ?? ''); ?></td>
                                            <td><?php echo $wh['start_date'] ?? '-'; ?></td>
                                            <td><?php echo $wh['end_date'] ?? '-'; ?></td>
                                            <td class="pe-3"><span class="badge bg-secondary-subtle text-secondary"><?php echo $whDuration; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- معلومات إضافية -->
                    <h6 class="fw-bold text-warning mb-3 d-flex align-items-center border-top pt-4">
                        <span class="bg-warning-subtle text-warning rounded-circle p-2 d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                            <i class="fas fa-notes-medical fs-6"></i>
                        </span>
                        معلومات إضافية
                    </h6>
                    <div class="row g-3 mb-2">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-file-medical text-warning me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">رقم التأمين:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['insurance_number'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-calendar-check text-warning me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">بداية التأمين:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['insurance_start_date'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex align-items-center py-2 border-bottom border-light">
                                <i class="fas fa-calendar-times text-warning me-2" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">نهاية التأمين:</span>
                                <strong class="text-dark"><?php echo htmlspecialchars($vp['insurance_end_date'] ?? '-'); ?></strong>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                <i class="fas fa-pills text-warning me-2 mt-1" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">العلاج / الأدوية:</span>
                                <span class="text-dark"><?php echo nl2br(htmlspecialchars($vp['treatment_plan'] ?? '-')); ?></span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                <i class="fas fa-notes-medical text-warning me-2 mt-1" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">ملاحظات صحية:</span>
                                <span class="text-dark"><?php echo nl2br(htmlspecialchars($vp['health_issues'] ?? '-')); ?></span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                <i class="fas fa-brain text-warning me-2 mt-1" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">ملاحظات نفسية:</span>
                                <span class="text-dark"><?php echo nl2br(htmlspecialchars($vp['psychological_notes'] ?? '-')); ?></span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="d-flex align-items-start py-2 border-bottom border-light">
                                <i class="fas fa-sticky-note text-warning me-2 mt-1" style="width: 20px; text-align: center;"></i>
                                <span class="text-secondary me-2">ملاحظات عامة:</span>
                                <span class="text-dark"><?php echo nl2br(htmlspecialchars($vp['notes'] ?? '-')); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="text-end mt-4 border-top pt-3">
            <a href="staff.php?action=edit&id=<?php echo $viewUserId; ?>" class="btn btn-outline-primary px-4"><i class="fas fa-edit me-1"></i>تعديل البيانات</a>
        </div>
    </div>
</div>

<?php endif; ?>
