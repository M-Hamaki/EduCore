<?php

declare(strict_types=1);

if (!defined('EDUCORE_NOTIFICATIONS_PAGE')) {
    http_response_code(404);
    exit;
}

?><div class="notifications-page">
    <div class="admin-page-heading">
        <div>
            <h1 class="h2"><i class="fas fa-bell me-2 text-primary"></i>إدارة التنبيهات</h1>
            <p class="text-muted mb-0">إدارة وإرسال التنبيهات العادية وتنبيهات المناسبات للمستخدمين.</p>
        </div>
        <div class="admin-top-actions no-print gap-2">
            <button type="button" class="btn btn-header-premium btn-success shadow-sm <?php echo $activeTab === 'occasions' ? 'd-none' : ''; ?>" id="addNotifTopBtn" data-bs-toggle="modal" data-bs-target="#addNotificationModal">
                <i class="fas fa-plus-circle me-1"></i>إنشاء تنبيه
            </button>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm <?php echo $activeTab === 'occasions' ? '' : 'd-none'; ?>" id="addOccasionTopBtn" data-bs-toggle="modal" data-bs-target="#addOccasionModal">
                <i class="fas fa-plus-circle me-1"></i>إضافة مناسبة
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

<?php
$action = isset($_GET['action']) ? $_GET['action'] : '';
$isForm = ($action === 'add' || ($action === 'edit' && $edit_notification));

if ($isForm):
    $n = $edit_notification ?: $duplicate_notification;
    $isEdit = ($action === 'edit');
    $isDuplicate = ($action === 'add' && $duplicate_notification !== null);
    $currentType = ($isEdit || $isDuplicate) ? $n['type'] : 'student';
    $currentDays = [];
    if (($isEdit || $isDuplicate) && !empty($n['show_days'])) {
        $currentDays = json_decode($n['show_days'], true) ?: [];
    }
?>

<!-- ========== ADD/EDIT FORM ========== -->
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <?php if ($isEdit): ?>
                <i class="fas fa-edit me-2"></i>تعديل التنبيه
            <?php elseif ($isDuplicate): ?>
                <i class="fas fa-copy me-2"></i>تكرار التنبيه
            <?php else: ?>
                <i class="fas fa-plus-circle me-2"></i>إنشاء تنبيه جديد
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
<form method="POST" action="notifications.php" id="notificationForm">
    <?php echo csrfField(); ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
            <?php endif; ?>
            <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
            
            <!-- Notification Type -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold"><i class="fas fa-tag me-1"></i> نوع التنبيه <span class="text-danger">*</span></label>
                    <select class="form-select" name="notification_type" id="notificationType" required>
                        <option value="student" <?php echo $currentType === 'student' ? 'selected' : ''; ?>>تنبيه للطلاب</option>
                        <option value="teacher" <?php echo $currentType === 'teacher' ? 'selected' : ''; ?>>تنبيه للمعلمين</option>
                        <option value="specialist" <?php echo $currentType === 'specialist' ? 'selected' : ''; ?>>تنبيه للأخصائيين</option>
                        <option value="public" <?php echo $currentType === 'public' ? 'selected' : ''; ?>>تنبيه عام (البوابة الرئيسية)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold"><i class="fas fa-exclamation-triangle me-1"></i> الأهمية</label>
                    <select class="form-select" name="priority">
                        <option value="normal" <?php echo (($isEdit || $isDuplicate) && $n['priority'] === 'normal') ? 'selected' : ''; ?>>عادي</option>
                        <option value="important" <?php echo (($isEdit || $isDuplicate) && $n['priority'] === 'important') ? 'selected' : ''; ?>>مهم</option>
                        <option value="urgent" <?php echo (($isEdit || $isDuplicate) && $n['priority'] === 'urgent') ? 'selected' : ''; ?>>عاجل</option>
                    </select>
                </div>
            </div>
            
            <!-- Title & Message -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="fas fa-heading me-1"></i> العنوان <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" value="<?php echo ($isEdit || $isDuplicate) ? htmlspecialchars($isDuplicate ? '(نسخة) ' . $n['title'] : $n['title']) : ''; ?>" required placeholder="عنوان التنبيه">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="fas fa-comment me-1"></i> الرسالة <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="message" rows="3" required placeholder="نص الرسالة..."><?php echo ($isEdit || $isDuplicate) ? htmlspecialchars($n['message']) : ''; ?></textarea>
                </div>
            </div>
            
            <!-- Scheduling -->
            <div class="card mb-3 border-info">
                <div class="card-header bg-info bg-opacity-10">
                    <h6 class="mb-0"><i class="fas fa-clock me-2"></i>جدولة الظهور (اختياري)</h6>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">من تاريخ</label>
                            <input type="text" class="form-control flatpickr-date" name="start_date" value="<?php echo ($isEdit || $isDuplicate) ? ($n['start_date'] ?? '') : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">إلى تاريخ</label>
                            <input type="text" class="form-control flatpickr-date" name="end_date" value="<?php echo ($isEdit || $isDuplicate) ? ($n['end_date'] ?? '') : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">من وقت</label>
                            <input type="time" class="form-control" name="start_time" value="<?php echo ($isEdit || $isDuplicate) ? ($n['start_time'] ?? '') : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">إلى وقت</label>
                            <input type="time" class="form-control" name="end_time" value="<?php echo ($isEdit || $isDuplicate) ? ($n['end_time'] ?? '') : ''; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <label class="form-label">أيام الظهور</label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php 
                                $dayNames = [6=>'السبت', 0=>'الأحد', 1=>'الإثنين', 2=>'الثلاثاء', 3=>'الأربعاء', 4=>'الخميس', 5=>'الجمعة'];
                                foreach ($dayNames as $i => $dayName): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="show_days[]" value="<?php echo $i; ?>" 
                                               id="day_<?php echo $i; ?>" <?php echo in_array($i, $currentDays) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="day_<?php echo $i; ?>"><?php echo $dayName; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">اتركها فارغة ليظهر التنبيه كل يوم</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ===== Student Targets ===== -->
            <div id="studentTargets" class="card mb-3 border-success" style="display: <?php echo $currentType === 'student' ? 'block' : 'none'; ?>;">
                <div class="card-header bg-success bg-opacity-10">
                    <h6 class="mb-0"><i class="fas fa-users me-2"></i>اختيار المستهدفين (الطلاب)</h6>
                </div>
                <div class="card-body">
                    <!-- Stages -->
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-layer-group me-1"></i> المراحل</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($stages as $stage): ?>
                                <div class="form-check">
                                    <input class="form-check-input target-stage" type="checkbox" name="target_stages[]" 
                                           value="<?php echo $stage['id']; ?>" id="stage_<?php echo $stage['id']; ?>"
                                           <?php echo isset($edit_targets['stage']) && in_array($stage['id'], $edit_targets['stage']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="stage_<?php echo $stage['id']; ?>"><?php echo htmlspecialchars($stage['stage_name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- Grades -->
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-graduation-cap me-1"></i> الصفوف</label>
                        <select class="form-select" name="target_grades[]" id="targetGrades" multiple size="4">
                            <?php foreach ($grades as $grade): ?>
                                <option value="<?php echo $grade['id']; ?>" data-stage="<?php echo $grade['stage_id']; ?>"
                                    <?php echo isset($edit_targets['grade']) && in_array($grade['id'], $edit_targets['grade']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($grade['grade_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">اضغط Ctrl للتحديد المتعدد</small>
                    </div>
                    <!-- Classes -->
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-door-open me-1"></i> الفصول</label>
                        <select class="form-select" name="target_classes[]" id="targetClasses" multiple size="4">
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" data-grade="<?php echo $class['grade_id']; ?>"
                                    <?php echo isset($edit_targets['class']) && in_array($class['id'], $edit_targets['class']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">اضغط Ctrl للتحديد المتعدد</small>
                    </div>
                    <!-- Individual Students -->
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-user-graduate me-1"></i> طلاب محددين</label>
                        <div class="input-group mb-2">
                            <select class="form-select" id="studentClassFilter">
                                <option value="">-- اختر فصل لتحميل طلابه --</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-primary" type="button" id="loadStudentsBtn">
                                <i class="fas fa-search"></i> تحميل
                            </button>
                        </div>
                        <select class="form-select" name="target_students[]" id="targetStudents" multiple size="5">
                            <?php if (isset($edit_targets['student'])): 
                                // Load existing student targets
                                $studentIdArr = array_map('intval', $edit_targets['student']);
                                $placeholders = implode(',', array_fill(0, count($studentIdArr), '?'));
                                $stStmt = $db->prepare("SELECT id, name FROM users WHERE id IN ($placeholders) ORDER BY name");
                                $stStmt->execute($studentIdArr);
                                $existingStudents = $stStmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($existingStudents as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" selected><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                        <small class="text-muted">اضغط Ctrl للتحديد المتعدد</small>
                    </div>
                    <div class="alert alert-info py-2 mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        يمكنك اختيار مرحلة أو صف أو فصل أو طلاب محددين أو مزيج منها. إذا لم تختر أي مستهدف سيظهر لجميع الطلاب.
                    </div>
                </div>
            </div>
            
            <!-- ===== Teacher Targets ===== -->
            <div id="teacherTargets" class="card mb-3 border-warning" style="display: <?php echo $currentType === 'teacher' ? 'block' : 'none'; ?>;">
                <div class="card-header bg-warning bg-opacity-10">
                    <h6 class="mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>اختيار المستهدفين (المعلمين)</h6>
                </div>
                <div class="card-body">
                    <!-- Individual Teachers -->
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-user me-1"></i> المعلمين</label>
                        <select class="form-select" name="target_teachers[]" id="targetTeachers" multiple size="5">
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>"
                                    <?php echo isset($edit_targets['teacher']) && in_array($teacher['id'], $edit_targets['teacher']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($teacher['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">اضغط Ctrl للتحديد المتعدد</small>
                    </div>
                    <!-- Subjects -->
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-book me-1"></i> المواد الدراسية</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($subjects as $subject): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="target_subjects[]" 
                                           value="<?php echo $subject['id']; ?>" id="subj_<?php echo $subject['id']; ?>"
                                           <?php echo isset($edit_targets['subject']) && in_array($subject['id'], $edit_targets['subject']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="subj_<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- Teacher Stages -->
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-layer-group me-1"></i> المراحل</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($stages as $stage): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="target_teacher_stages[]" 
                                           value="<?php echo $stage['id']; ?>" id="tstage_<?php echo $stage['id']; ?>"
                                           <?php echo isset($edit_targets['stage']) && in_array($stage['id'], $edit_targets['stage']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="tstage_<?php echo $stage['id']; ?>"><?php echo htmlspecialchars($stage['stage_name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="alert alert-info py-2 mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        يمكنك اختيار معلمين أو مواد أو مراحل أو مزيج منها. إذا لم تختر أي مستهدف سيظهر لجميع المعلمين.
                    </div>
                </div>
            </div>
            
            <!-- ===== Specialist Targets ===== -->
            <div id="specialistTargets" class="card mb-3 border-info" style="display: <?php echo $currentType === 'specialist' ? 'block' : 'none'; ?>;">
                <div class="card-header bg-info bg-opacity-10">
                    <h6 class="mb-0"><i class="fas fa-user-cog me-2"></i>اختيار المستهدفين (الأخصائيين)</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-user me-1"></i> الأخصائيين</label>
                        <select class="form-select" name="target_specialists[]" id="targetSpecialists" multiple size="5">
                            <?php foreach ($specialists as $spec): ?>
                                <option value="<?php echo $spec['id']; ?>"
                                    <?php echo isset($edit_targets['specialist']) && in_array($spec['id'], $edit_targets['specialist']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($spec['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">اضغط Ctrl للتحديد المتعدد</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-layer-group me-1"></i> المراحل</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($stages as $stage): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="target_specialist_stages[]" 
                                           value="<?php echo $stage['id']; ?>" id="sstage_<?php echo $stage['id']; ?>"
                                           <?php echo isset($edit_targets['stage']) && in_array($stage['id'], $edit_targets['stage']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="sstage_<?php echo $stage['id']; ?>"><?php echo htmlspecialchars($stage['stage_name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="alert alert-info py-2 mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        يمكنك اختيار أخصائيين محددين أو مراحل أو مزيج منها. إذا لم تختر أي مستهدف سيظهر لجميع الأخصائيين.
                    </div>
                </div>
            </div>
            
            <!-- Public notice -->
            <div id="publicNotice" class="alert alert-primary mb-3" style="display: <?php echo $currentType === 'public' ? 'block' : 'none'; ?>;">
                <i class="fas fa-globe me-2"></i>
                <strong>تنبيه عام:</strong> سيظهر هذا التنبيه في الصفحة الرئيسية (صفحة اختيار المرحلة) لجميع الزوار قبل تسجيل الدخول.
            </div>
            
            <!-- Push Notification Option -->
            <div class="card mb-3 border-success" id="pushNotifCard" style="display: <?php echo $currentType !== 'public' ? 'block' : 'none'; ?>;">
                <div class="card-body py-2">
                    <div class="form-check form-switch d-flex align-items-center gap-2">
                        <input class="form-check-input" type="checkbox" name="send_push" id="sendPushCheck" value="1"
                               <?php echo (($isEdit || $isDuplicate) && !empty($n['send_push'])) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="sendPushCheck">
                            <i class="fas fa-mobile-alt me-1 text-success"></i>
                            إرسال إشعار فوري (Push Notification)
                        </label>
                        <span class="badge bg-success bg-opacity-10 text-success" id="pushSubCount"></span>
                    </div>
                    <small class="text-muted">سيتم إرسال إشعار فوري لأجهزة المستخدمين المستهدفين الذين فعّلوا الإشعارات</small>
                </div>
            </div>
            
            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <a href="notifications.php" class="btn btn-secondary"><i class="fas fa-times me-1"></i> إلغاء</a>
                <button type="submit" name="<?php echo $isEdit ? 'edit_notification' : 'add_notification'; ?>" class="btn <?php echo $isEdit ? 'btn-primary' : 'btn-success'; ?>">
                    <i class="fas fa-save me-1"></i> <?php echo $isEdit ? 'حفظ التغييرات' : 'إنشاء التنبيه'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>

<!-- Stat Cards Container (ABOVE Tabs Navigation) -->
<div id="top-stat-cards-container" class="mb-4">
    <!-- Stat Cards for Tab 1: Regular Notifications -->
    <div id="stats-tab-notifications" class="tab-stats-pane <?php echo $activeTab === 'notifications' ? '' : 'd-none'; ?>">
        <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3" aria-label="إحصائيات التنبيهات العادية">
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <div class="stat-card-icon"><i class="fas fa-bell"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $totalNotifsCount; ?>">0</div>
                        <div class="stat-card-label">إجمالي التنبيهات</div>
                        <div class="stat-card-sub"><i class="fas fa-list"></i> جميع التنبيهات العادية</div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $activeNotifsCount; ?>">0</div>
                        <div class="stat-card-label">تنبيهات نشطة</div>
                        <div class="stat-card-sub"><i class="fas fa-eye"></i> معروضة حالياً</div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $studentNotifsCount; ?>">0</div>
                        <div class="stat-card-label">تنبيهات الطلاب</div>
                        <div class="stat-card-sub"><i class="fas fa-users"></i> الموجهة للطلاب</div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                    <div class="stat-card-icon"><i class="fas fa-mobile-alt"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $pushNotifsCount; ?>">0</div>
                        <div class="stat-card-label">إشعارات فورية (Push)</div>
                        <div class="stat-card-sub"><i class="fas fa-paper-plane"></i> مفضّلة للإرسال</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat Cards for Tab 2: Occasions -->
    <div id="stats-tab-occasions" class="tab-stats-pane <?php echo $activeTab === 'occasions' ? '' : 'd-none'; ?>">
        <div class="row row-cols-1 row-cols-sm-3 g-3" aria-label="إحصائيات المناسبات">
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <div class="stat-card-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $totalOccasionsCount; ?>">0</div>
                        <div class="stat-card-label">إجمالي المناسبات</div>
                        <div class="stat-card-sub"><i class="fas fa-calendar-check"></i> المناسبات والأعياد</div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $activeOccasionsCount; ?>">0</div>
                        <div class="stat-card-label">مناسبات مفعّلة</div>
                        <div class="stat-card-sub"><i class="fas fa-eye"></i> تظهر للمستخدمين</div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                    <div class="stat-card-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $disabledOccasionsCount; ?>">0</div>
                        <div class="stat-card-label">مناسبات معطّلة</div>
                        <div class="stat-card-sub"><i class="fas fa-eye-slash"></i> غير معروضة حالياً</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-tabs mb-3 border-bottom" id="notifTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>" id="tab-notifications-link" 
                data-bs-toggle="tab" data-bs-target="#tab-notifications" type="button" role="tab">
            <i class="fas fa-bell me-1"></i> التنبيهات العادية
            <span class="badge bg-primary ms-1"><?php echo $totalNotifsCount; ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'occasions' ? 'active' : ''; ?>" id="tab-occasions-link" 
                data-bs-toggle="tab" data-bs-target="#tab-occasions" type="button" role="tab">
            <i class="fas fa-star me-1"></i> تنبيهات المناسبات
            <span class="badge bg-secondary ms-1"><?php echo $totalOccasionsCount; ?></span>
        </button>
    </li>
</ul>

<div class="tab-content" id="notifTabContent">
<div class="tab-pane fade <?php echo $activeTab === 'notifications' ? 'show active' : ''; ?>" id="tab-notifications" role="tabpanel">

<!-- ========== NOTIFICATIONS LIST ========== -->
<div class="admin-filter-bar mb-3" aria-label="فلترة التنبيهات">
    <div class="admin-filter-controls">
        <select class="form-select form-select-sm admin-inline-select-sm" id="typeFilter" aria-label="فلترة النوع">
            <option value="all">جميع الأنواع</option>
            <option value="student">تنبيهات الطلاب</option>
            <option value="teacher">تنبيهات المعلمين</option>
            <option value="specialist">تنبيهات الأخصائيين</option>
            <option value="public">تنبيهات عامة</option>
        </select>
        <select class="form-select form-select-sm admin-inline-select-sm" id="priorityFilter" aria-label="فلترة الأهمية">
            <option value="all">جميع مستويات الأهمية</option>
            <option value="normal">عادي</option>
            <option value="important">مهم</option>
            <option value="urgent">عاجل</option>
        </select>
    </div>
    <div class="admin-filter-actions">
        <button type="button" class="btn btn-light btn-sm" id="resetNotifFilters">
            <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
        </button>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#notifTableSettingsModal">
            <i class="fas fa-cog me-1"></i>إعدادات الجدول
        </button>
    </div>
</div>

<?php
$notificationPagination = paginationState((int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn(), 50, 'notifications_page');
$notificationsQuery = "SELECT n.*, u.name as creator_name,
                       (SELECT COUNT(*) FROM notification_targets WHERE notification_id = n.id) as target_count,
                       (SELECT COUNT(*) FROM notification_reads WHERE notification_id = n.id) as read_count
                       FROM notifications n
                       LEFT JOIN users u ON n.created_by = u.id
                       ORDER BY n.created_at DESC LIMIT {$notificationPagination['limit']} OFFSET {$notificationPagination['offset']}";
$notifications = $db->query($notificationsQuery)->fetchAll(PDO::FETCH_ASSOC);

if (count($notifications) > 0):
?>
<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped datatable admin-data-table" id="notificationsTable">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>العنوان</th>
                    <th width="100">النوع</th>
                    <th width="80">الأهمية</th>
                    <th width="120">المستهدفون</th>
                    <th width="100">الفترة</th>
                    <th width="110">المنشئ</th>
                    <th width="100">تاريخ الإنشاء</th>
                    <th width="80">القراءات</th>
                    <th width="80">الحالة</th>
                    <th width="180" class="text-center">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php $counter = 1; foreach ($notifications as $notif):
                    $isActive = (int)$notif['is_active'];
                    $typeBadge = ['student' => 'bg-success', 'teacher' => 'bg-warning', 'specialist' => 'bg-purple', 'public' => 'bg-info'];
                    $typeLabel = ['student' => 'طلاب', 'teacher' => 'معلمين', 'specialist' => 'أخصائيين', 'public' => 'عام'];
                    $priorityBadge = ['normal' => 'bg-secondary', 'important' => 'bg-warning', 'urgent' => 'bg-danger'];
                    $priorityLabel = ['normal' => 'عادي', 'important' => 'مهم', 'urgent' => 'عاجل'];
                    
                    // Build period text
                    $periodText = '';
                    if ($notif['start_date'] || $notif['end_date']) {
                        $periodText = ($notif['start_date'] ?? '...') . ' → ' . ($notif['end_date'] ?? '...');
                    } else {
                        $periodText = 'دائم';
                    }
                ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($notif['title']); ?></strong>
                        <br><small class="text-muted"><?php echo mb_substr(htmlspecialchars($notif['message']), 0, 50) . '...'; ?></small>
                    </td>
                    <td><span class="badge <?php echo $typeBadge[$notif['type']] ?? 'bg-secondary'; ?>"><?php echo $typeLabel[$notif['type']] ?? $notif['type']; ?></span></td>
                    <td><span class="badge <?php echo $priorityBadge[$notif['priority']]; ?>"><?php echo $priorityLabel[$notif['priority']]; ?></span></td>
                    <td>
                        <?php if ($notif['type'] === 'public'): ?>
                            <span class="badge bg-info"><i class="fas fa-globe me-1"></i>الجميع</span>
                        <?php elseif ($notif['target_count'] > 0): ?>
                            <span class="badge bg-primary"><?php echo $notif['target_count']; ?> هدف</span>
                        <?php else: ?>
                            <span class="badge bg-dark"><i class="fas fa-users me-1"></i>الجميع</span>
                        <?php endif; ?>
                    </td>
                    <td><small><?php echo $periodText; ?></small></td>
                    <td><small><?php echo htmlspecialchars($notif['creator_name'] ?? 'غير معروف'); ?></small></td>
                    <td><small><?php echo date('Y/m/d', strtotime($notif['created_at'])); ?></small></td>
                    <td><span class="badge <?php echo $notif['read_count'] > 0 ? 'bg-info' : 'bg-secondary'; ?>"><?php echo $notif['read_count']; ?></span></td>
                    <td>
                        <?php if ($isActive): ?>
                            <span class="badge bg-success">نشط</span>
                        <?php else: ?>
                            <span class="badge bg-danger">معطل</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center actions-column admin-table-actions">
                        <button type="button" class="btn btn-action-pills btn-edit me-1 edit-notif-btn" data-id="<?php echo $notif['id']; ?>" data-bs-toggle="tooltip" title="تعديل" aria-label="تعديل">
                            <i class="fas fa-edit"></i>
                        </button>
                        <a href="notifications.php?action=add&duplicate=<?php echo $notif['id']; ?>" class="btn btn-action-pills me-1" style="background:#e0e7ff; color:#4338ca;" data-bs-toggle="tooltip" title="تكرار" aria-label="تكرار">
                            <i class="fas fa-copy"></i>
                        </a>
                        <?php if ($isActive): ?>
                            <button type="button" class="btn btn-action-pills me-1 send-push-btn" style="background:#dcfce7; color:#15803d;" data-id="<?php echo $notif['id']; ?>" data-title="<?php echo htmlspecialchars($notif['title']); ?>" data-bs-toggle="tooltip" title="إرسال إشعار فوري" aria-label="إرسال إشعار فوري">
                                <i class="fas fa-mobile-alt"></i>
                            </button>
                            <button type="button" class="btn btn-action-pills btn-deactivate me-1 toggle-status" data-id="<?php echo $notif['id']; ?>" data-new-status="0" data-bs-toggle="tooltip" title="تعطيل" aria-label="تعطيل">
                                <i class="fas fa-ban"></i>
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-action-pills btn-activate me-1 toggle-status" data-id="<?php echo $notif['id']; ?>" data-new-status="1" data-bs-toggle="tooltip" title="تفعيل" aria-label="تفعيل">
                                <i class="fas fa-check"></i>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-action-pills btn-delete delete-notif" data-id="<?php echo $notif['id']; ?>" data-title="<?php echo htmlspecialchars($notif['title']); ?>" data-bs-toggle="tooltip" title="حذف" aria-label="حذف">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php renderPagination($notificationPagination); ?>
<?php else: ?>
<div class="admin-list-surface text-center py-5">
    <i class="fas fa-bell-slash text-muted" style="font-size: 4rem;"></i>
    <h4 class="mt-3 text-muted">لا توجد تنبيهات</h4>
    <p class="text-muted">ابدأ بإنشاء تنبيه جديد</p>
    <a href="notifications.php?action=add" class="btn btn-success">
        <i class="fas fa-plus-circle me-1"></i> إنشاء تنبيه جديد
    </a>
</div>
<?php endif; ?>
</div><!-- end tab-notifications -->

<!-- ========== TAB 2: OCCASIONS ========== -->
<div class="tab-pane fade <?php echo $activeTab === 'occasions' ? 'show active' : ''; ?>" id="tab-occasions" role="tabpanel">

<div class="admin-filter-bar mb-3" aria-label="شريط أدوات الفلترة للمناسبات">
    <div class="admin-filter-controls">
        <select class="form-select form-select-sm admin-inline-select-sm" id="occTargetFilter" aria-label="فلترة المستهدفين">
            <option value="all">جميع الفئات المستهدفة</option>
            <option value="student">الطلاب فقط</option>
            <option value="teacher">المعلمين فقط</option>
        </select>
        <select class="form-select form-select-sm admin-inline-select-sm" id="occStatusFilter" aria-label="فلترة الحالة">
            <option value="all">جميع الحالات</option>
            <option value="active">مفعّلة فقط</option>
            <option value="disabled">معطّلة فقط</option>
        </select>
    </div>
    <div class="admin-filter-actions">
        <button type="button" class="btn btn-light btn-sm" id="resetOccasionFilters">
            <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
        </button>
    </div>
</div>

<!-- Info Alert -->
<div class="alert alert-info small mb-3 shadow-sm">
    <i class="fas fa-info-circle me-2"></i>
    قم بتفعيل أو تعطيل تنبيهات المناسبات التي تظهر في بوابات الطلاب والمعلمين. المناسبات ذات التواريخ المحددة تظهر تلقائياً في موعدها كل عام.
</div>

<!-- Occasions Grid -->
<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-4 g-3">
    <?php 
    $targetLabels = ['all'=>'الجميع','student'=>'الطلاب','teacher'=>'المعلمين','both'=>'الطلاب والمعلمين'];
    $targetBg = ['all'=>'primary','student'=>'success','teacher'=>'warning','both'=>'info'];
    $themeLabels = ['ramadan'=>'رمضاني','eid'=>'عيد','national'=>'وطني','islamic'=>'إسلامي','celebration'=>'احتفال','spring'=>'ربيعي','default'=>'عام'];
    foreach ($occasions as $occ): 
        $occActive = (int)$occ['is_active'];
    ?>
    <div class="col occasion-card-col" data-target-type="<?php echo htmlspecialchars($occ['target_type']); ?>" data-status="<?php echo $occActive; ?>">
        <div class="card h-100 occasion-card-v2 <?php echo !$occActive ? 'occasion-card-v2-disabled' : ''; ?> d-flex flex-column justify-content-between" id="occ-card-<?php echo $occ['id']; ?>">
            <!-- Card Header with Theme Gradient -->
            <div class="occasion-header-v2" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($occ['gradient_start']); ?> 0%, <?php echo htmlspecialchars($occ['gradient_end']); ?> 100%); color: <?php echo htmlspecialchars($occ['text_color']); ?>;">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2 overflow-hidden me-1">
                        <div class="occasion-icon-v2">
                            <i class="<?php echo htmlspecialchars($occ['icon']); ?>"></i>
                        </div>
                        <div class="overflow-hidden">
                            <h6 class="mb-0.5 fw-bold text-truncate" style="font-size: 0.95rem; color: inherit; line-height: 1.3;">
                                <?php 
                                $dispTitle = $occ['title'];
                                $emojiStr = trim($occ['emoji'] ?? '');
                                if (!empty($emojiStr) && mb_strpos($dispTitle, $emojiStr) === false) {
                                    $dispTitle = $emojiStr . ' ' . $dispTitle;
                                }
                                echo htmlspecialchars($dispTitle);
                                ?>
                            </h6>
                            <span class="occasion-theme-tag">
                                <?php echo $themeLabels[$occ['theme']] ?? $occ['theme']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="form-check form-switch mb-0 flex-shrink-0">
                        <input class="form-check-input occasion-toggle" type="checkbox" 
                               data-id="<?php echo $occ['id']; ?>" 
                               data-title="<?php echo htmlspecialchars($occ['title']); ?>"
                               data-status="<?php echo $occActive; ?>"
                               <?php echo $occActive ? 'checked' : ''; ?>
                               title="تفعيل / تعطيل المناسبة">
                    </div>
                </div>
            </div>

            <!-- Card Body -->
            <div class="card-body p-3 d-flex flex-column justify-content-between bg-white">
                <p class="occasion-msg-v2">
                    <?php echo htmlspecialchars(mb_substr($occ['message'], 0, 92)); ?><?php echo mb_strlen($occ['message']) > 92 ? '...' : ''; ?>
                </p>
                <div class="d-flex flex-wrap gap-2 align-items-center mt-auto">
                    <span class="badge occasion-pill-target">
                        <i class="fas fa-users me-1"></i><?php echo $targetLabels[$occ['target_type']] ?? $occ['target_type']; ?>
                    </span>
                    <?php if ($occ['start_date'] && $occ['end_date']): ?>
                        <span class="badge occasion-pill-date">
                            <i class="fas fa-calendar-alt me-1 text-primary"></i><?php echo $occ['start_date']; ?> &#8592; <?php echo $occ['end_date']; ?>
                        </span>
                    <?php else: ?>
                        <span class="badge occasion-pill-manual">
                            <i class="fas fa-hand-pointer me-1"></i>تفعيل يدوي
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card Footer -->
            <div class="card-footer occasion-footer-v2 d-flex justify-content-between align-items-center">
                <span id="occ-status-<?php echo $occ['id']; ?>" class="badge rounded-pill px-2.5 py-1.5 fw-bold <?php echo $occActive ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle'; ?>" style="font-size: 0.76rem;">
                    <i class="fas <?php echo $occActive ? 'fa-check-circle' : 'fa-times-circle'; ?> me-1"></i>
                    <?php echo $occActive ? 'مفعّل' : 'معطّل'; ?>
                </span>
                <div class="d-flex gap-1 admin-table-actions">
                    <?php if ($occActive): ?>
                    <button type="button" class="btn btn-action-pills send-push-occasion-btn" style="background:#e0f2fe; color:#0369a1;" data-id="<?php echo $occ['id']; ?>" data-title="<?php echo htmlspecialchars($occ['title']); ?>" data-bs-toggle="tooltip" title="إرسال إشعار فوري" aria-label="إرسال إشعار فوري">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-action-pills preview-occasion-btn" style="background: #e0f7fa; color: #00838f;" data-id="<?php echo $occ['id']; ?>" data-bs-toggle="tooltip" title="معاينة العرض في البوابة" aria-label="معاينة العرض في البوابة">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button type="button" class="btn btn-action-pills btn-edit edit-occasion-btn" data-id="<?php echo $occ['id']; ?>" data-bs-toggle="tooltip" title="تعديل" aria-label="تعديل">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button" class="btn btn-action-pills btn-delete delete-occasion-btn" data-id="<?php echo $occ['id']; ?>" data-title="<?php echo htmlspecialchars($occ['title']); ?>" data-bs-toggle="tooltip" title="حذف" aria-label="حذف">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

</div><!-- end tab-occasions -->
</div><!-- end tab-content -->

<!-- Add Occasion Modal -->
<div class="modal fade" id="addOccasionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="post" action="notifications.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="create_occasion">
                <input type="hidden" name="theme" id="addOccThemeHidden" value="default">
                <input type="hidden" name="gradient_start" id="addOccGradStartHidden" value="#0d6efd">
                <input type="hidden" name="gradient_end" id="addOccGradEndHidden" value="#0a58ca">
                <input type="hidden" name="text_color" id="addOccTextColorHidden" value="#ffffff">
                <input type="hidden" name="show_confetti" id="addOccConfettiHidden" value="0">
                <input type="hidden" name="is_active" id="addOccActiveHidden" value="1">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إضافة مناسبة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Live Banner Preview -->
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary"><i class="fas fa-eye me-1"></i> معاينة المظهر الحقيقي للافتة</label>
                        <div id="addOccPreview" class="occasion-banner rounded-4 shadow-sm p-3.5 position-relative overflow-hidden" style="background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); color: #ffffff; transition: all 0.3s ease;">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px; font-size: 1.3rem; background: rgba(255,255,255,0.22); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.3);">
                                    <i id="addOccPreviewIcon" class="fas fa-star"></i>
                                </div>
                                <div class="overflow-hidden text-white">
                                    <h6 class="fw-bold mb-1" id="addOccPreviewTitle" style="font-size: 1.05rem; color: inherit; line-height: 1.3;">عنوان المناسبة</h6>
                                    <p class="mb-0 opacity-90 small" id="addOccPreviewMsg" style="color: inherit; line-height: 1.5;">رسالة المناسبة ستظهر هنا بكامل الوضوح والألوان المحددة...</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-heading me-1"></i> العنوان <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" id="addOccTitle" placeholder="مثال: يوم المعلم العالمي 👨‍🏫" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-face-smile me-1"></i> إيموجي</label>
                            <input type="text" class="form-control" name="emoji" id="addOccEmoji" placeholder="🎉" maxlength="10">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-comment me-1"></i> الرسالة <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="message" id="addOccMessage" rows="2" placeholder="رسالة التهنئة أو المناسبة..." required></textarea>
                    </div>

                    <!-- Theme Selection -->
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-palette me-1"></i> الشكل / الثيم</label>
                        <div class="row g-2" id="themeSelector">
                            <div class="col-6 col-sm-3"><button type="button" class="btn w-100 py-2 px-1 theme-btn active" data-theme="default" data-gs="#0d6efd" data-ge="#0a58ca" data-tc="#ffffff" style="background:linear-gradient(135deg,#0d6efd,#0a58ca);color:#fff;border:2px solid #0d6efd;font-size:0.82rem;"><i class="fas fa-star me-1"></i>عام</button></div>
                            <div class="col-6 col-sm-3"><button type="button" class="btn w-100 py-2 px-1 theme-btn" data-theme="ramadan" data-gs="#1a1a4e" data-ge="#2d1b69" data-tc="#f0e6d3" style="background:linear-gradient(135deg,#1a1a4e,#2d1b69);color:#f0e6d3;border:2px solid transparent;font-size:0.82rem;"><i class="fas fa-moon me-1"></i>رمضاني</button></div>
                            <div class="col-6 col-sm-3"><button type="button" class="btn w-100 py-2 px-1 theme-btn" data-theme="eid" data-gs="#059669" data-ge="#047857" data-tc="#ffffff" style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border:2px solid transparent;font-size:0.82rem;"><i class="fas fa-gift me-1"></i>عيد</button></div>
                            <div class="col-6 col-sm-3"><button type="button" class="btn w-100 py-2 px-1 theme-btn" data-theme="national" data-gs="#dc2626" data-ge="#991b1b" data-tc="#ffffff" style="background:linear-gradient(135deg,#dc2626,#991b1b);color:#fff;border:2px solid transparent;font-size:0.82rem;"><i class="fas fa-flag me-1"></i>وطني</button></div>
                            <div class="col-6 col-sm-3"><button type="button" class="btn w-100 py-2 px-1 theme-btn" data-theme="islamic" data-gs="#0f766e" data-ge="#115e59" data-tc="#ccfbf1" style="background:linear-gradient(135deg,#0f766e,#115e59);color:#ccfbf1;border:2px solid transparent;font-size:0.82rem;"><i class="fas fa-mosque me-1"></i>إسلامي</button></div>
                            <div class="col-6 col-sm-3"><button type="button" class="btn w-100 py-2 px-1 theme-btn" data-theme="celebration" data-gs="#7c3aed" data-ge="#6d28d9" data-tc="#ede9fe" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#ede9fe;border:2px solid transparent;font-size:0.82rem;"><i class="fas fa-cake-candles me-1"></i>احتفال</button></div>
                            <div class="col-6 col-sm-3"><button type="button" class="btn w-100 py-2 px-1 theme-btn" data-theme="spring" data-gs="#16a34a" data-ge="#15803d" data-tc="#dcfce7" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#dcfce7;border:2px solid transparent;font-size:0.82rem;"><i class="fas fa-seedling me-1"></i>ربيعي</button></div>
                            <div class="col-6 col-sm-3"><button type="button" class="btn w-100 py-2 px-1 theme-btn" data-theme="custom" data-gs="" data-ge="" data-tc="" style="background:#f8f9fa;color:#333;border:2px solid #cbd5e1;font-size:0.82rem;"><i class="fas fa-sliders me-1"></i>مخصص</button></div>
                        </div>
                    </div>

                    <!-- Custom Colors (hidden by default) -->
                    <div class="mb-3" id="customColorsSection" style="display:none;">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">لون البداية</label>
                                <input type="color" class="form-control form-control-color w-100" id="addOccGradStart" value="#0d6efd">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">لون النهاية</label>
                                <input type="color" class="form-control form-control-color w-100" id="addOccGradEnd" value="#0a58ca">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">لون النص</label>
                                <input type="color" class="form-control form-control-color w-100" id="addOccTextColor" value="#ffffff">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-icons me-1"></i> الأيقونة</label>
                            <select class="form-select" name="icon" id="addOccIcon">
                                <option value="fas fa-star">⭐ نجمة</option>
                                <option value="fas fa-moon">🌙 هلال</option>
                                <option value="fas fa-gift">🎁 هدية</option>
                                <option value="fas fa-flag">🚩 علم</option>
                                <option value="fas fa-mosque">🕌 مسجد</option>
                                <option value="fas fa-heart">❤ قلب</option>
                                <option value="fas fa-cake-candles">🎂 كيك</option>
                                <option value="fas fa-seedling">🌱 نبتة</option>
                                <option value="fas fa-school">🏫 مدرسة</option>
                                <option value="fas fa-sun">☀ شمس</option>
                                <option value="fas fa-hands-praying">🤲 دعاء</option>
                                <option value="fas fa-champagne-glasses">🥂 احتفال</option>
                                <option value="fas fa-star-and-crescent">☪ إسلامي</option>
                                <option value="fas fa-chalkboard-teacher">👨‍🏫 معلم</option>
                                <option value="fas fa-trophy">🏆 كأس</option>
                                <option value="fas fa-graduation-cap">🎓 تخرج</option>
                                <option value="fas fa-bell">🔔 جرس</option>
                                <option value="fas fa-mountain-sun">🏔 طبيعة</option>
                                <option value="fas fa-landmark">🏛 معلم تاريخي</option>
                                <option value="fas fa-shield-halved">🛡 درع</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-users me-1"></i> يظهر لـ</label>
                            <select class="form-select" name="target_type" id="addOccTarget">
                                <option value="all">الجميع (طلاب ومعلمين)</option>
                                <option value="student">الطلاب فقط</option>
                                <option value="teacher">المعلمين فقط</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i> من تاريخ (شهر-يوم)</label>
                            <input type="text" class="form-control" name="start_date" id="addOccStartDate" placeholder="مثال: 01-25" maxlength="5" dir="ltr">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i> إلى تاريخ (شهر-يوم)</label>
                            <input type="text" class="form-control" name="end_date" id="addOccEndDate" placeholder="مثال: 01-27" maxlength="5" dir="ltr">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-wand-magic-sparkles me-1"></i> الرسوم المتحركة</label>
                            <select class="form-select" name="animation_type" id="addOccAnimation">
                                <option value="fadeIn">ظهور تدريجي</option>
                                <option value="confetti">قصاصات ملونة</option>
                                <option value="stars">نجوم</option>
                                <option value="fireworks">ألعاب نارية</option>
                                <option value="hearts">قلوب</option>
                                <option value="flowers">زهور</option>
                                <option value="glow">توهج</option>
                                <option value="flag">علم</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="addOccConfetti" onchange="document.getElementById('addOccConfettiHidden').value = this.checked ? 1 : 0;">
                        <label class="form-check-label" for="addOccConfetti">عرض قصاصات ملونة (Confetti) عند الظهور</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="addOccActive" checked onchange="document.getElementById('addOccActiveHidden').value = this.checked ? 1 : 0;">
                        <label class="form-check-label" for="addOccActive">تفعيل المناسبة فوراً</label>
                    </div>

                    <div class="alert alert-info small py-2 mt-3 mb-0">
                        <i class="fas fa-lightbulb me-1"></i>
                        اترك حقول التاريخ فارغة لجعل المناسبة بتفعيل يدوي. المناسبات ذات التاريخ تظهر تلقائياً كل عام.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus-circle me-1"></i>إنشاء المناسبة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Occasion Modal -->
<div class="modal fade" id="editOccasionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="post" action="notifications.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="update_occasion">
                <input type="hidden" name="id" id="editOccId">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل تنبيه المناسبة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-heading me-1"></i> العنوان</label>
                        <input type="text" class="form-control" name="title" id="editOccTitle" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-comment me-1"></i> الرسالة</label>
                        <textarea class="form-control" name="message" id="editOccMessage" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-users me-1"></i> يظهر لـ</label>
                        <select class="form-select" name="target_type" id="editOccTarget">
                            <option value="all">الجميع (طلاب ومعلمين)</option>
                            <option value="student">الطلاب فقط</option>
                            <option value="teacher">المعلمين فقط</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i> من تاريخ (شهر-يوم)</label>
                            <input type="text" class="form-control" name="start_date" id="editOccStartDate" placeholder="مثال: 01-25" maxlength="5" dir="ltr">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i> إلى تاريخ (شهر-يوم)</label>
                            <input type="text" class="form-control" name="end_date" id="editOccEndDate" placeholder="مثال: 01-27" maxlength="5" dir="ltr">
                        </div>
                    </div>
                    <div class="alert alert-info small py-2 mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        المناسبات ذات التاريخ المحدد تظهر تلقائياً كل عام في موعدها.
                        <br>
                        <i class="fas fa-lightbulb me-1 mt-1"></i>
                        اترك حقول التاريخ فارغة لجعل المناسبة بتفعيل يدوي (مثل المناسبات الإسلامية المعتمدة على التقويم الهجري).
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Occasion Modal -->
<div class="modal fade" id="deleteOccasionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="post" action="notifications.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="delete_occasion">
                <input type="hidden" name="id" id="deleteOccasionId">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>حذف مناسبة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل أنت متأكد من حذف المناسبة <span class="fw-bold text-primary" id="deleteOccasionName"></span>؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم حذف المناسبة وتنبيهها بشكل نهائي.
                    </div>
                    <p class="text-danger text-center mb-0">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        هذا الإجراء لا يمكن التراجع عنه.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>حذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Occasion Status Modal -->
<div class="modal fade" id="toggleOccasionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleOccasionModalContent">
            <form method="post" action="notifications.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="toggle_occasion">
                <input type="hidden" name="id" id="toggleOccasionId">
                <input type="hidden" name="new_status" id="toggleOccasionStatus">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="toggleOccasionTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i id="toggleOccasionIcon" class="fas fa-star text-primary" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center" id="toggleOccasionText"></p>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="toggleOccasionDesc"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn" id="toggleOccasionBtn">تأكيد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف تنبيه</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <i class="fas fa-exclamation-triangle text-warning" style="font-size:3rem;"></i>
                <p class="mt-3">هل أنت متأكد من حذف التنبيه: <strong id="deleteTitle"></strong>؟</p>
            </div>
            <div class="modal-footer">
                <form method="post" action="notifications.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                    <input type="hidden" id="deleteId" name="id">
                    <input type="hidden" name="action" value="delete">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">حذف</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toggle Notification Status Modal -->
<div class="modal fade" id="toggleNotifModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleNotifModalContent">
            <form method="post" action="notifications.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" id="toggleNotifId">
                <input type="hidden" name="new_status" id="toggleNotifStatus">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="toggleNotifTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i id="toggleNotifIcon" class="fas fa-bell text-primary" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center" id="toggleNotifText"></p>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="toggleNotifDesc"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn" id="toggleNotifBtn">تأكيد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Send Push for Occasion Modal -->
<div class="modal fade" id="sendPushOccasionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <form method="post" action="notifications.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="send_push_occasion">
                <input type="hidden" name="id" id="sendPushOccasionId">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>إرسال إشعار فوري</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-bell text-info" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل تريد إرسال إشعار فوري للمناسبة:<br><span class="fw-bold text-primary" id="sendPushOccasionName"></span>؟</p>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم إرسال إشعار فوري لجميع الأجهزة المسجلة للمستخدمين المستهدفين.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-paper-plane me-1"></i>إرسال
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Send Push for Notification Modal -->
<div class="modal fade" id="sendPushNotifModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <form method="post" action="notifications.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="send_push">
                <input type="hidden" name="id" id="sendPushNotifId">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-mobile-alt me-2"></i>إرسال إشعار فوري</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-bell text-success" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل تريد إرسال إشعار فوري (Push) للمستخدمين المستهدفين لـ:<br><span class="fw-bold text-primary" id="sendPushNotifName"></span>؟</p>
                    <div class="alert alert-success">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم إرسال إشعار فوري لجميع الأجهزة المسجلة للمستخدمين المستهدفين.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-mobile-alt me-1"></i>إرسال
                    </button>
                </div>
            </form>
        </div>
    </div>
<!-- Create Notification Modal -->
<div class="modal fade" id="addNotificationModal" tabindex="-1" aria-labelledby="addNotificationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST" action="notifications.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="active_tab" class="active-tab-input" value="notifications">
                <input type="hidden" name="add_notification" value="1">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="addNotificationModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>إنشاء تنبيه جديد
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                
                <div class="modal-body">
                    <!-- Notification Type & Priority -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><i class="fas fa-tag me-1 text-success"></i> نوع التنبيه <span class="text-danger">*</span></label>
                            <select class="form-select" name="notification_type" id="notificationTypeModal" required>
                                <option value="student" selected>تنبيه للطلاب</option>
                                <option value="teacher">تنبيه للمعلمين</option>
                                <option value="specialist">تنبيه للأخصائيين</option>
                                <option value="public">تنبيه عام (البوابة الرئيسية)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><i class="fas fa-exclamation-triangle me-1 text-warning"></i> الأهمية</label>
                            <select class="form-select" name="priority">
                                <option value="normal" selected>عادي</option>
                                <option value="important">مهم</option>
                                <option value="urgent">عاجل</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Title & Message -->
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-bold"><i class="fas fa-heading me-1 text-primary"></i> العنوان <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" required placeholder="مثال: تنبيه بخصوص الاختبارات الشهرية">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold"><i class="fas fa-comment me-1 text-info"></i> الرسالة <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="message" rows="3" required placeholder="اكتب نص الرسالة والتفاصيل هنا..."></textarea>
                        </div>
                    </div>
                    
                    <!-- Scheduling -->
                    <div class="card mb-3 border">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 text-dark fw-bold small"><i class="fas fa-clock me-2 text-info"></i>جدولة الظهور (اختياري)</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2 mb-2">
                                <div class="col-6 col-md-3">
                                    <label class="form-label small">من تاريخ</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" name="start_date">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small">إلى تاريخ</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" name="end_date">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small">من وقت</label>
                                    <input type="time" class="form-control form-control-sm" name="start_time">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small">إلى وقت</label>
                                    <input type="time" class="form-control form-control-sm" name="end_time">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <label class="form-label small fw-bold">أيام الظهور</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php 
                                        $dayNames = [6=>'السبت', 0=>'الأحد', 1=>'الإثنين', 2=>'الثلاثاء', 3=>'الأربعاء', 4=>'الخميس', 5=>'الجمعة'];
                                        foreach ($dayNames as $i => $dayName): ?>
                                            <div class="form-check form-check-inline me-2">
                                                <input class="form-check-input" type="checkbox" name="show_days[]" value="<?php echo $i; ?>" id="mday_<?php echo $i; ?>">
                                                <label class="form-check-label small" for="mday_<?php echo $i; ?>"><?php echo $dayName; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <small class="text-muted d-block mt-1">اتركها فارغة ليظهر التنبيه كل يوم</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ===== Student Targets ===== -->
                    <div id="studentTargetsModal" class="card mb-3 border" style="display: block;">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 text-dark fw-bold small"><i class="fas fa-users me-2 text-success"></i>اختيار المستهدفين (الطلاب)</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="mb-2">
                                <label class="form-label small fw-bold"><i class="fas fa-layer-group me-1"></i> المراحل</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($stages as $stage): ?>
                                        <div class="form-check form-check-inline me-2">
                                            <input class="form-check-input" type="checkbox" name="target_stages[]" value="<?php echo $stage['id']; ?>" id="mstage_<?php echo $stage['id']; ?>">
                                            <label class="form-check-label small" for="mstage_<?php echo $stage['id']; ?>"><?php echo htmlspecialchars($stage['stage_name']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold"><i class="fas fa-graduation-cap me-1"></i> الصفوف</label>
                                    <select class="form-select form-select-sm" name="target_grades[]" multiple size="5" style="min-height: 130px;">
                                        <?php foreach ($grades as $grade): ?>
                                            <option value="<?php echo $grade['id']; ?>"><?php echo htmlspecialchars($grade['grade_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold"><i class="fas fa-door-open me-1"></i> الفصول</label>
                                    <select class="form-select form-select-sm" name="target_classes[]" multiple size="5" style="min-height: 130px;">
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="alert alert-info py-1 px-2 mb-0 small">
                                <i class="fas fa-info-circle me-1"></i>
                                يمكن التحديد المتعدد بـ Ctrl. في حال تركها فارغة، يصل التنبيه لجميع الطلاب.
                            </div>
                        </div>
                    </div>
                    
                    <!-- ===== Teacher Targets ===== -->
                    <div id="teacherTargetsModal" class="card mb-3 border" style="display: none;">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 text-dark fw-bold small"><i class="fas fa-chalkboard-teacher me-2 text-warning"></i>اختيار المستهدفين (المعلمين)</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">المعلمين</label>
                                <select class="form-select form-select-sm" name="target_teachers[]" multiple size="5" style="min-height: 130px;">
                                    <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="alert alert-info py-1 px-2 mb-0 small">
                                <i class="fas fa-info-circle me-1"></i>
                                إذا لم تختر معلمين محددين، سيظهر التنبيه لجميع المعلمين.
                            </div>
                        </div>
                    </div>

                    <!-- ===== Specialist Targets ===== -->
                    <div id="specialistTargetsModal" class="card mb-3 border" style="display: none;">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 text-dark fw-bold small"><i class="fas fa-user-cog me-2 text-info"></i>اختيار المستهدفين (الأخصائيين)</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">الأخصائيين</label>
                                <select class="form-select form-select-sm" name="target_specialists[]" multiple size="5" style="min-height: 130px;">
                                    <?php foreach ($specialists as $spec): ?>
                                        <option value="<?php echo $spec['id']; ?>"><?php echo htmlspecialchars($spec['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Public Notice -->
                    <div id="publicNoticeModal" class="alert alert-primary mb-3 small" style="display: none;">
                        <i class="fas fa-globe me-2"></i>
                        <strong>تنبيه عام:</strong> سيظهر هذا التنبيه في البوابة الرئيسية لجميع زوار النظام.
                    </div>

                    <!-- Push Notification Option -->
                    <div class="card mb-0 border" id="pushNotifCardModal">
                        <div class="card-body py-2">
                            <div class="form-check form-switch d-flex align-items-center gap-2 mb-0">
                                <input class="form-check-input" type="checkbox" name="send_push" id="sendPushCheckModal" value="1">
                                <label class="form-check-label fw-bold small mb-0" for="sendPushCheckModal">
                                    <i class="fas fa-mobile-alt me-1 text-success"></i>
                                    إرسال إشعار فوري (Push Notification)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" name="add_notification" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>حفظ وتفعيل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Notification Modal -->
<div class="modal fade" id="editNotificationModal" tabindex="-1" aria-labelledby="editNotificationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST" action="notifications.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="active_tab" class="active-tab-input" value="notifications">
                <input type="hidden" name="edit_notification" value="1">
                <input type="hidden" name="id" id="editNotifId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="editNotificationModalLabel">
                        <i class="fas fa-edit me-2"></i>تعديل التنبيه
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                
                <div class="modal-body">
                    <!-- Notification Type & Priority -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><i class="fas fa-tag me-1 text-primary"></i> نوع التنبيه <span class="text-danger">*</span></label>
                            <select class="form-select" name="notification_type" id="editNotificationType" required>
                                <option value="student">تنبيه للطلاب</option>
                                <option value="teacher">تنبيه للمعلمين</option>
                                <option value="specialist">تنبيه للأخصائيين</option>
                                <option value="public">تنبيه عام (البوابة الرئيسية)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><i class="fas fa-exclamation-triangle me-1 text-warning"></i> الأهمية</label>
                            <select class="form-select" name="priority" id="editPriority">
                                <option value="normal">عادي</option>
                                <option value="important">مهم</option>
                                <option value="urgent">عاجل</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Title & Message -->
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-bold"><i class="fas fa-heading me-1 text-primary"></i> العنوان <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" id="editTitle" required placeholder="عنوان التنبيه">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold"><i class="fas fa-comment me-1 text-info"></i> الرسالة <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="message" id="editMessage" rows="3" required placeholder="نص الرسالة..."></textarea>
                        </div>
                    </div>
                    
                    <!-- Scheduling -->
                    <div class="card mb-3 border">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 text-dark fw-bold small"><i class="fas fa-clock me-2 text-info"></i>جدولة الظهور (اختياري)</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2 mb-2">
                                <div class="col-6 col-md-3">
                                    <label class="form-label small">من تاريخ</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" name="start_date" id="editStartDate">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small">إلى تاريخ</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" name="end_date" id="editEndDate">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small">من وقت</label>
                                    <input type="time" class="form-control form-control-sm" name="start_time" id="editStartTime">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small">إلى وقت</label>
                                    <input type="time" class="form-control form-control-sm" name="end_time" id="editEndTime">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <label class="form-label small fw-bold">أيام الظهور</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php 
                                        $dayNames = [6=>'السبت', 0=>'الأحد', 1=>'الإثنين', 2=>'الثلاثاء', 3=>'الأربعاء', 4=>'الخميس', 5=>'الجمعة'];
                                        foreach ($dayNames as $i => $dayName): ?>
                                            <div class="form-check form-check-inline me-2">
                                                <input class="form-check-input edit-day-checkbox" type="checkbox" name="show_days[]" value="<?php echo $i; ?>" id="edit_day_<?php echo $i; ?>">
                                                <label class="form-check-label small" for="edit_day_<?php echo $i; ?>"><?php echo $dayName; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <small class="text-muted d-block mt-1">اتركها فارغة ليظهر التنبيه كل يوم</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ===== Student Targets ===== -->
                    <div id="editStudentTargets" class="card mb-3 border" style="display: block;">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 text-dark fw-bold small"><i class="fas fa-users me-2 text-success"></i>اختيار المستهدفين (الطلاب)</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="mb-2">
                                <label class="form-label small fw-bold"><i class="fas fa-layer-group me-1"></i> المراحل</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($stages as $stage): ?>
                                        <div class="form-check form-check-inline me-2">
                                            <input class="form-check-input edit-stage-checkbox" type="checkbox" name="target_stages[]" value="<?php echo $stage['id']; ?>" id="edit_stage_<?php echo $stage['id']; ?>">
                                            <label class="form-check-label small" for="edit_stage_<?php echo $stage['id']; ?>"><?php echo htmlspecialchars($stage['stage_name']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold"><i class="fas fa-graduation-cap me-1"></i> الصفوف</label>
                                    <select class="form-select form-select-sm" name="target_grades[]" id="editTargetGrades" multiple size="5" style="min-height: 130px;">
                                        <?php foreach ($grades as $grade): ?>
                                            <option value="<?php echo $grade['id']; ?>"><?php echo htmlspecialchars($grade['grade_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold"><i class="fas fa-door-open me-1"></i> الفصول</label>
                                    <select class="form-select form-select-sm" name="target_classes[]" id="editTargetClasses" multiple size="5" style="min-height: 130px;">
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="alert alert-info py-1 px-2 mb-0 small">
                                <i class="fas fa-info-circle me-1"></i>
                                يمكن التحديد المتعدد بـ Ctrl. في حال تركها فارغة، يصل التنبيه لجميع الطلاب.
                            </div>
                        </div>
                    </div>
                    
                    <!-- ===== Teacher Targets ===== -->
                    <div id="editTeacherTargets" class="card mb-3 border" style="display: none;">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 text-dark fw-bold small"><i class="fas fa-chalkboard-teacher me-2 text-warning"></i>اختيار المستهدفين (المعلمين)</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">المعلمين</label>
                                <select class="form-select form-select-sm" name="target_teachers[]" id="editTargetTeachers" multiple size="5" style="min-height: 130px;">
                                    <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="alert alert-info py-1 px-2 mb-0 small">
                                <i class="fas fa-info-circle me-1"></i>
                                إذا لم تختر معلمين محددين، سيظهر التنبيه لجميع المعلمين.
                            </div>
                        </div>
                    </div>

                    <!-- ===== Specialist Targets ===== -->
                    <div id="editSpecialistTargets" class="card mb-3 border" style="display: none;">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 text-dark fw-bold small"><i class="fas fa-user-cog me-2 text-info"></i>اختيار المستهدفين (الأخصائيين)</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">الأخصائيين</label>
                                <select class="form-select form-select-sm" name="target_specialists[]" id="editTargetSpecialists" multiple size="5" style="min-height: 130px;">
                                    <?php foreach ($specialists as $spec): ?>
                                        <option value="<?php echo $spec['id']; ?>"><?php echo htmlspecialchars($spec['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Public Notice -->
                    <div id="editPublicNotice" class="alert alert-primary mb-3 small" style="display: none;">
                        <i class="fas fa-globe me-2"></i>
                        <strong>تنبيه عام:</strong> سيظهر هذا التنبيه في البوابة الرئيسية لجميع زوار النظام.
                    </div>

                    <!-- Push Notification Option -->
                    <div class="card mb-0 border" id="editPushNotifCard">
                        <div class="card-body py-2">
                            <div class="form-check form-switch d-flex align-items-center gap-2 mb-0">
                                <input class="form-check-input" type="checkbox" name="send_push" id="editSendPushCheck" value="1">
                                <label class="form-check-label fw-bold small mb-0" for="editSendPushCheck">
                                    <i class="fas fa-mobile-alt me-1 text-success"></i>
                                    إرسال إشعار فوري (Push Notification)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" name="edit_notification" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Notification Table Settings Modal -->
<div class="modal fade" id="notifTableSettingsModal" tabindex="-1" aria-labelledby="notifTableSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="notifTableSettingsModalLabel">
                    <i class="fas fa-cog me-2"></i>إعدادات إظهار أعمدة الجدول
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">اختر الأعمدة التي تريد إظهارها في جدول التنبيهات العادية:</p>
                <div class="row g-3" id="notifColumnToggleContainer">
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="0" id="col_0" checked>
                            <label class="form-check-label small fw-bold" for="col_0">#</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="1" id="col_1" checked>
                            <label class="form-check-label small fw-bold" for="col_1">العنوان والرسالة</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="2" id="col_2" checked>
                            <label class="form-check-label small fw-bold" for="col_2">النوع</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="3" id="col_3" checked>
                            <label class="form-check-label small fw-bold" for="col_3">الأهمية</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="4" id="col_4" checked>
                            <label class="form-check-label small fw-bold" for="col_4">المستهدفون</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="5" id="col_5" checked>
                            <label class="form-check-label small fw-bold" for="col_5">فترة الظهور</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="6" id="col_6" checked>
                            <label class="form-check-label small fw-bold" for="col_6">المنشئ</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="7" id="col_7" checked>
                            <label class="form-check-label small fw-bold" for="col_7">تاريخ الإنشاء</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="8" id="col_8" checked>
                            <label class="form-check-label small fw-bold" for="col_8">القراءات</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input col-toggle-check" type="checkbox" data-column="9" id="col_9" checked>
                            <label class="form-check-label small fw-bold" for="col_9">الحالة</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إغلاق
                </button>
<!-- Preview Occasion Portal Banner Modal -->
<div class="modal fade" id="previewOccasionModal" tabindex="-1" aria-labelledby="previewOccasionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="previewOccasionModalLabel">
                    <i class="fas fa-eye me-2 text-primary"></i>معاينة ظهور المناسبة في بوابة المستخدمين
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-info small mb-3 border-0 bg-info-subtle text-info-emphasis rounded-3">
                    <i class="fas fa-info-circle me-1"></i>
                    توضح هذه المعاينة التفاعلية كيف تظهر اللافتة الترحيبية للطلاب والمعلمين أعلى بواباتهم بنفس الخطوط، الألوان، الزخارف والأنميشن.
                </div>

                <!-- Simulated Browser Window Mockup -->
                <div class="border rounded-4 overflow-hidden shadow-sm bg-white">
                    <!-- Browser Bar -->
                    <div class="px-3 py-2 bg-light border-bottom d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle bg-danger opacity-75 d-inline-block" style="width: 10px; height: 10px;"></span>
                            <span class="rounded-circle bg-warning opacity-75 d-inline-block" style="width: 10px; height: 10px;"></span>
                            <span class="rounded-circle bg-success opacity-75 d-inline-block" style="width: 10px; height: 10px;"></span>
                            <span class="ms-2 small text-muted font-monospace d-none d-sm-inline" style="font-size: 0.76rem;">
                                <i class="fas fa-lock text-success me-1"></i>https://educore.school/portal
                            </span>
                        </div>
                        <span id="previewOccasionRoleBadge" class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 rounded-pill" style="font-size: 0.76rem;">
                            معاينة العرض للطلاب والمعلمين
                        </span>
                    </div>
                    
                    <!-- Simulated Portal Body Output -->
                    <div class="p-3 p-md-4 bg-slate-50" id="previewOccasionPortalContainer" style="background-color: #f8fafc; min-height: 140px;">
                        <!-- Rendered Banner HTML injected via JS -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<?php echo getPortalNotificationsAssets(); ?>

<?php endif; ?>
