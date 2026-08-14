<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);
$studentId = (int)($_GET['student_id'] ?? 0);

// 1. Fetch Stages, Grades, Classes for Filter dropdowns
$stagesList = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC);
$gradesList = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY grade_order, id")->fetchAll(PDO::FETCH_ASSOC);
$classesList = $db->query("SELECT id, name, grade_id FROM classes WHERE status = 'active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch all student records for the cascading select dropdown
$studentsStmt = $db->prepare("SELECT u.id, u.name, sp.student_code, c.name AS class_name,
       se.stage_id, se.grade_id, se.class_id
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status IN ('enrolled','graduated')
    LEFT JOIN classes c ON c.id = se.class_id
    WHERE u.role = 'student' AND u.deleted_at IS NULL
    ORDER BY c.name, u.name");
$studentsStmt->execute([$currentAcademicYearId]);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch detailed student record if selected
$student = null;
if ($studentId > 0) {
    $stmt = $db->prepare("SELECT u.id, u.name, u.username, sp.*, ay.name AS academic_year_name,
            s.stage_name, g.grade_name, c.name AS class_name, se.enrollment_status, se.graduation_year,
            (SELECT id FROM student_attachments WHERE user_id = u.id AND label = 'الصورة الشخصية' ORDER BY id DESC LIMIT 1) AS profile_image_id
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ?
        LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
        LEFT JOIN stages s ON s.id = se.stage_id
        LEFT JOIN grades g ON g.id = se.grade_id
        LEFT JOIN classes c ON c.id = se.class_id
        WHERE u.id = ? AND u.role = 'student'");
    $stmt->execute([$currentAcademicYearId, $studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Arabic labels
$genderLabels = ['male' => 'ذكر', 'female' => 'أنثى'];

require_once '../includes/admin_header.php';
?>

<!-- Premium Document Preview Styles -->
<style>
.official-doc-paper {
    background: #ffffff;
    color: #000000;
    direction: rtl;
    text-align: right;
    position: relative;
    transition: all 0.25s ease-in-out;
}
.official-doc-paper.has-border {
    border: 3.5px double #000000 !important;
    padding: 3rem !important;
}

/* Live customization styling rules */
.doc-header-box { display: none; }
.official-doc-paper.show-header .doc-header-box { display: flex; }

.doc-logo-box { display: none; }
.official-doc-paper.show-logo .doc-logo-box { display: block; }

.doc-student-photo { display: none; }
.official-doc-paper.show-photo .doc-student-photo { display: block; }

.doc-signatures-box { display: none; }
.official-doc-paper.show-signatures .doc-signatures-box { display: flex; }

.doc-details-table { display: none; }
.official-doc-paper.show-details-table .doc-details-table { display: block; }
.official-doc-paper.show-details-table .doc-details-inline { display: none; }

@media print {
    body {
        background: #ffffff !important;
        color: #000000 !important;
    }
    .no-print, .admin-sidebar, .admin-header, .admin-page-heading {
        display: none !important;
    }
    main {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    .official-doc-paper {
        box-shadow: none !important;
        border: none !important;
        margin: 0 !important;
        padding: 0 !important;
        max-width: 100% !important;
        min-height: auto !important;
    }
    .official-doc-paper.has-border {
        border: 3px double #000000 !important;
        padding: 2.5rem !important;
    }
}
</style>

<!-- Page Heading matching Enrolled Students layout -->
<div class="admin-page-heading no-print mb-4">
    <h1 class="h2"><i class="<?php echo htmlspecialchars($documentIcon); ?> me-2 text-primary"></i><?php echo htmlspecialchars($documentTitle); ?></h1>
    <div class="btn-toolbar admin-top-actions">
        <!-- Print Settings Modal Trigger -->
        <button type="button" class="btn btn-header-premium btn-light border" data-bs-toggle="modal" data-bs-target="#printSettingsModal" style="background: white; border-color: #dee2e6; height: 31px; display: inline-flex; align-items: center; gap: 5px;">
            <i class="fas fa-cog text-muted"></i>إعدادات الطباعة
        </button>
        <!-- Print Button -->
        <button onclick="window.print()" class="btn btn-header-premium btn-print-soft" style="height: 31px; display: inline-flex; align-items: center; gap: 5px;" <?php echo !$student ? 'disabled' : ''; ?>>
            <i class="fas fa-print"></i>طباعة المستند
        </button>
    </div>
</div>

<!-- Tabs matching Enrolled Students -->
<ul class="nav nav-tabs mb-3 border-bottom no-print" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link fw-semibold active" href="#">
            <i class="fas fa-file-invoice me-2"></i>معاينة المستند
        </a>
    </li>
</ul>

<!-- Filter bar matching Enrolled Students -->
<form method="GET" id="documentFilterForm" class="admin-filter-bar mb-4 no-print">
    <div class="admin-filter-controls">
        <!-- Stage Dropdown with checkbox style -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px; font-weight: bold;">
                <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3 text-end" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($stagesList as $st): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input stage-checkbox" type="checkbox" id="stage_<?php echo $st['id']; ?>" value="<?php echo $st['id']; ?>">
                        <label class="form-check-label ms-2" for="stage_<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['stage_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Grade Dropdown with checkbox style -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px; font-weight: bold;">
                <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3 text-end" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($gradesList as $gr): ?>
                    <div class="form-check mb-1 grade-item" data-stage="<?php echo $gr['stage_id']; ?>">
                        <input class="form-check-input grade-checkbox" type="checkbox" id="grade_<?php echo $gr['id']; ?>" value="<?php echo $gr['id']; ?>">
                        <label class="form-check-label ms-2" for="grade_<?php echo $gr['id']; ?>"><?php echo htmlspecialchars($gr['grade_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Class Dropdown with checkbox style -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px; font-weight: bold;">
                <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3 text-end" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($classesList as $cl): ?>
                    <div class="form-check mb-1 class-item" data-grade="<?php echo $cl['grade_id']; ?>">
                        <input class="form-check-input class-checkbox" type="checkbox" id="class_<?php echo $cl['id']; ?>" value="<?php echo $cl['id']; ?>">
                        <label class="form-check-label ms-2" for="class_<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Student Dropdown (Single Radio select with Search field) -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="studentDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 200px; font-weight: bold;">
                <span>الطالب: <span id="selectedStudentLabel" class="fw-bold">اختر الطالب</span></span>
            </button>
            <div class="dropdown-menu p-3 text-end" aria-labelledby="studentDropdown" style="max-height: 300px; overflow-y: auto; min-width: 280px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <div class="mb-2">
                    <input type="text" id="studentSearchInput" class="form-control form-control-sm" placeholder="ابحث بالاسم أو الكود...">
                </div>
                <div id="studentListContainer">
                    <?php foreach ($students as $row): ?>
                        <div class="form-check mb-1 student-item" 
                             data-stage="<?php echo (int)$row['stage_id']; ?>" 
                             data-grade="<?php echo (int)$row['grade_id']; ?>" 
                             data-class="<?php echo (int)$row['class_id']; ?>"
                             data-code="<?php echo htmlspecialchars($row['student_code'] ?? ''); ?>"
                             data-name-lower="<?php echo htmlspecialchars(mb_strtolower($row['name'])); ?>">
                            <input class="form-check-input student-radio" type="radio" name="student_id" value="<?php echo $row['id']; ?>" id="student_<?php echo $row['id']; ?>" <?php echo $studentId === (int)$row['id'] ? 'checked' : ''; ?>>
                            <label class="form-check-label ms-2" for="student_<?php echo $row['id']; ?>">
                                <?php echo htmlspecialchars($row['name']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="admin-filter-actions">
        <!-- Reset Filters Button -->
        <button type="button" id="resetFiltersBtn" class="btn btn-light btn-sm" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important;">
            <i class="fas fa-undo me-1"></i>إعادة تعيين
        </button>
        
        <!-- View Document Action Button -->
        <button class="btn btn-primary btn-sm" type="submit" style="height: 31px; display: inline-flex; align-items: center; justify-content: center;">
            <i class="fas fa-eye me-1"></i>عرض المستند
        </button>
    </div>
</form>

<!-- Modal for Document Customize Print Settings -->
<div class="modal fade" id="printSettingsModal" tabindex="-1" aria-labelledby="printSettingsModalLabel" aria-hidden="true" style="text-align: right;">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium">
            <div class="modal-header d-flex justify-content-between align-items-center">
                <h5 class="modal-title" id="printSettingsModalLabel"><i class="fas fa-cog me-2"></i>إعدادات وتخصيص مستند الطباعة</h5>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-4">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="toggleSchoolHeader" checked>
                    <label class="form-check-label fw-bold ms-2" for="toggleSchoolHeader">عرض ترويسة المدرسة الرسمية</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="toggleLogo" checked>
                    <label class="form-check-label fw-bold ms-2" for="toggleLogo">عرض شعار المدرسة</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="togglePhoto" checked>
                    <label class="form-check-label fw-bold ms-2" for="togglePhoto">عرض الصورة الشخصية للطالب (إن وجدت)</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="toggleBorder" checked>
                    <label class="form-check-label fw-bold ms-2" for="toggleBorder">عرض إطار مزخرف للمستند</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="toggleSignatures" checked>
                    <label class="form-check-label fw-bold ms-2" for="toggleSignatures">عرض حقول التوقيع والختم بالأسفل</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="toggleDetailsTable">
                    <label class="form-check-label fw-bold ms-2" for="toggleDetailsTable">عرض جدول البيانات التفصيلي</label>
                </div>
                <hr>
                <div class="mb-3">
                    <label class="form-label fw-bold text-secondary">تاريخ المستند</label>
                    <input type="text" id="documentDateInput" class="form-control flatpickr-date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold text-secondary">ملاحظات إضافية (تظهر أسفل بيان القيد)</label>
                    <textarea id="additionalNotesInput" class="form-control" rows="2" placeholder="أدخل أي بنود أو ملاحظات إضافية تود ظهورها..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>تطبيق التغييرات</button>
            </div>
        </div>
    </div>
</div>

<?php if ($student): ?>
    <!-- Premium Document Preview Container displayed directly on page -->
    <div class="d-flex justify-content-center mb-5">
        <div id="officialDocPaper" class="official-doc-paper p-5 shadow has-border show-header show-logo show-photo show-signatures" style="max-width: 800px; width: 100%; border-radius: 8px; background: #ffffff;">
            
            <!-- Standard School Official Header -->
            <div class="doc-header-box row align-items-center border-bottom pb-3 mb-4">
                <!-- Right Side -->
                <div class="col-5" style="font-size: 13px; line-height: 1.6; font-weight: bold;">
                    <div>وزارة التربية والتعليم والتعليم الفني</div>
                    <div>مديرية التربية والتعليم</div>
                    <div>إدارة شؤون الطلاب والامتحانات</div>
                    <div>مدرسة: <span style="text-decoration: underline;"><?php echo htmlspecialchars($_schoolName); ?></span></div>
                </div>
                
                <!-- Center: Logo -->
                <div class="col-2 text-center doc-logo-box">
                    <?php if (!empty($_schoolLogo)): ?>
                        <img src="<?php echo htmlspecialchars($_schoolLogo); ?>" alt="شعار المدرسة" style="max-height: 85px; max-width: 85px; object-fit: contain;">
                    <?php else: ?>
                        <svg width="70" height="70" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="50" cy="50" r="45" stroke="#1e3a8a" stroke-width="4" fill="#f8fafc"/>
                            <path d="M50 20L25 40H75L50 20Z" fill="#1e3a8a"/>
                            <rect x="30" y="40" width="40" height="35" fill="#1e3a8a"/>
                            <rect x="42" y="55" width="16" height="20" fill="#f8fafc"/>
                        </svg>
                    <?php endif; ?>
                </div>
                
                <!-- Left Side: Date / Ref details -->
                <div class="col-5 text-start" style="font-size: 13px; line-height: 1.6; font-weight: bold;">
                    <div>التاريخ: <span class="doc-date-display">...</span></div>
                    <div>الرقم الصادر: .................</div>
                    <div>المرفقات: .................</div>
                </div>
            </div>
            
            <!-- Header title -->
            <div class="text-center mb-4">
                <h3 class="fw-bold px-3 py-1 d-inline-block" style="border-bottom: 2px solid #000; font-family: 'Amiri', serif; letter-spacing: 1px;">
                    <?php echo htmlspecialchars($documentTitle); ?>
                </h3>
            </div>
            
            <!-- Student Photo and Details Block -->
            <div class="row align-items-start mb-4">
                <!-- Detailed Table Option (CSS class controlled) -->
                <div class="col-12 doc-details-table">
                    <table class="table table-bordered border-dark text-center align-middle mb-0" style="font-size: 14px;">
                        <tbody>
                            <tr>
                                <td class="bg-light fw-bold" style="width: 20%;">اسم الطالب بالكامل</td>
                                <td colspan="3" class="fw-bold fs-5 text-start px-3"><?php echo htmlspecialchars($student['name']); ?></td>
                            </tr>
                            <tr>
                                <td class="bg-light fw-bold" style="width: 20%;">كود الطالب</td>
                                <td style="width: 30%;"><?php echo htmlspecialchars($student['student_code'] ?? '-'); ?></td>
                                <td class="bg-light fw-bold" style="width: 20%;">الرقم القومي</td>
                                <td style="width: 30%;"><?php echo htmlspecialchars($student['national_id'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="bg-light fw-bold">المرحلة الدراسية</td>
                                <td><?php echo htmlspecialchars($student['stage_name'] ?? '-'); ?></td>
                                <td class="bg-light fw-bold">الصف الدراسي</td>
                                <td><?php echo htmlspecialchars($student['grade_name'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="bg-light fw-bold">الفصل الدراسي</td>
                                <td><?php echo htmlspecialchars($student['class_name'] ?? '-'); ?></td>
                                <td class="bg-light fw-bold">العام الدراسي</td>
                                <td><?php echo htmlspecialchars($student['academic_year_name'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="bg-light fw-bold">تاريخ الميلاد</td>
                                <td><?php echo htmlspecialchars($student['birth_date'] ?? '-'); ?></td>
                                <td class="bg-light fw-bold">الجنسية / النوع</td>
                                <td><?php echo htmlspecialchars(($student['nationality'] ?? '-') . ' / ' . ($genderLabels[$student['gender'] ?? ''] ?? '-')); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Inline Standard Details Box -->
                <div class="col doc-details-inline fs-5" style="line-height: 1.8;">
                    <div class="mb-2"><strong>اسم الطالب:</strong> <?php echo htmlspecialchars($student['name']); ?></div>
                    <?php if (!empty($student['student_code'])): ?>
                        <div class="mb-2"><strong>كود الطالب:</strong> <?php echo htmlspecialchars($student['student_code']); ?></div>
                    <?php endif; ?>
                    <div class="mb-2"><strong>الصف الدراسي:</strong> <?php echo htmlspecialchars($student['grade_name'] ?? '-'); ?></div>
                    <div class="mb-2"><strong>الفصل:</strong> <?php echo htmlspecialchars($student['class_name'] ?? '-'); ?></div>
                    <div class="mb-2"><strong>المرحلة:</strong> <?php echo htmlspecialchars($student['stage_name'] ?? '-'); ?></div>
                </div>
                
                <!-- Student Photo Column -->
                <div class="col-auto text-start doc-student-photo ps-3">
                    <?php if (!empty($student['profile_image_id'])): ?>
                        <img src="<?php echo htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', (int)$student['profile_image_id']), ENT_QUOTES, 'UTF-8'); ?>" alt="صورة الطالب" style="width: 105px; height: 130px; object-fit: cover; border: 1.5px solid #000; padding: 2px; background: #fff;">
                    <?php else: ?>
                        <div style="width: 105px; height: 130px; border: 1.5px dashed #000; display: inline-flex; flex-direction: column; align-items: center; justify-content: center; background: #fafafa; color: #555;">
                            <i class="fas fa-user-tie fa-2x mb-1"></i>
                            <span style="font-size: 9px; font-weight: bold;">صورة الطالب</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Document Body Content Box -->
            <div class="border rounded p-4 mb-4 fs-5 bg-light text-justify align-middle shadow-xs" style="line-height: 2.2; font-family: 'Amiri', serif; border-color: #cbd5e1 !important; text-align: justify; text-justify: inter-word;">
                <?php echo $documentBodyRenderer($student); ?>
            </div>
            
            <!-- Optional Additional Notes Box -->
            <div id="docAdditionalNotesBox" class="border border-warning rounded p-3 mb-4 bg-light" style="display: none; border-style: dashed !important; font-size: 14px; line-height: 1.6;">
                <div class="fw-bold text-dark mb-1"><i class="fas fa-exclamation-circle me-1 text-warning"></i>ملاحظات إضافية:</div>
                <div id="docAdditionalNotesContent" class="text-muted"></div>
            </div>
            
            <!-- Official Signature Fields Block -->
            <div class="doc-signatures-box row text-center mt-5 pt-3" style="font-size: 14px; font-weight: bold; line-height: 1.8;">
                <div class="col">
                    <div>مسؤول شؤون الطلاب</div>
                    <div class="mt-4" style="letter-spacing: 2px;">........................</div>
                </div>
                <div class="col">
                    <div>مدير المدرسة</div>
                    <div class="mt-4" style="letter-spacing: 2px;">........................</div>
                </div>
                <div class="col">
                    <div>خاتم المدرسة الشعبي</div>
                    <div class="mt-4" style="letter-spacing: 2px;">........................</div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Notice if no student selected -->
    <div class="alert alert-info py-4 text-center shadow-xs border-0 no-print" style="border-radius: 12px;">
        <i class="fas fa-info-circle fa-2x mb-2 text-primary"></i>
        <h5 class="fw-bold text-dark">برجاء اختيار وتحديد الطالب أولاً</h5>
        <p class="text-muted mb-0">استخدم شريط الفلاتر السريع بالاعلى لتصفية واختيار الطالب المستهدف، ثم اضغط على "عرض المستند".</p>
    </div>
<?php endif; ?>

<!-- JavaScript Cascading Filters & Settings Binding -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ----------------------------------------------------
    // § 1 — Cascading Filter & Dynamic Search Logic
    // ----------------------------------------------------
    const stageCheckboxes = document.querySelectorAll('.stage-checkbox');
    const gradeCheckboxes = document.querySelectorAll('.grade-checkbox');
    const classCheckboxes = document.querySelectorAll('.class-checkbox');
    const studentRadios = document.querySelectorAll('.student-radio');
    const studentSearchInput = document.getElementById('studentSearchInput');

    const selectedStagesLabel = document.getElementById('selectedStagesLabel');
    const selectedGradesLabel = document.getElementById('selectedGradesLabel');
    const selectedClassesLabel = document.getElementById('selectedClassesLabel');
    const selectedStudentLabel = document.getElementById('selectedStudentLabel');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');

    function applyFilters() {
        // Get active filters
        const activeStages = Array.from(stageCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        const activeGrades = Array.from(gradeCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        const activeClasses = Array.from(classCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        const searchVal = studentSearchInput ? studentSearchInput.value.toLowerCase().trim() : '';

        // 1. Update Stages Label
        if (selectedStagesLabel) {
            selectedStagesLabel.innerText = activeStages.length === 0 ? 'الكل' : (activeStages.length === 1 ? document.querySelector('label[for="stage_' + activeStages[0] + '"]').innerText.trim() : activeStages.length);
        }

        // 2. Cascade Grades visibility based on Stage
        document.querySelectorAll('.grade-item').forEach(item => {
            const stageId = item.getAttribute('data-stage');
            const cb = item.querySelector('.grade-checkbox');
            if (activeStages.length === 0 || activeStages.includes(stageId)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                cb.checked = false;
            }
        });

        const updatedActiveGrades = Array.from(gradeCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        if (selectedGradesLabel) {
            selectedGradesLabel.innerText = updatedActiveGrades.length === 0 ? 'الكل' : (updatedActiveGrades.length === 1 ? document.querySelector('label[for="grade_' + updatedActiveGrades[0] + '"]').innerText.trim() : updatedActiveGrades.length);
        }

        // 3. Cascade Classes visibility based on Grade
        document.querySelectorAll('.class-item').forEach(item => {
            const gradeId = item.getAttribute('data-grade');
            const cb = item.querySelector('.class-checkbox');
            if (updatedActiveGrades.length === 0 || updatedActiveGrades.includes(gradeId)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                cb.checked = false;
            }
        });

        const updatedActiveClasses = Array.from(classCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        if (selectedClassesLabel) {
            selectedClassesLabel.innerText = updatedActiveClasses.length === 0 ? 'الكل' : (updatedActiveClasses.length === 1 ? document.querySelector('label[for="class_' + updatedActiveClasses[0] + '"]').innerText.trim() : updatedActiveClasses.length);
        }

        // 4. Filter Student options inside dropdown list
        let visibleStudents = 0;
        document.querySelectorAll('.student-item').forEach(item => {
            const sStage = item.getAttribute('data-stage');
            const sGrade = item.getAttribute('data-grade');
            const sClass = item.getAttribute('data-class');
            const sCode = item.getAttribute('data-code').toLowerCase();
            const sNameLower = item.getAttribute('data-name-lower');
            const radio = item.querySelector('.student-radio');

            let show = true;
            if (activeStages.length > 0 && !activeStages.includes(sStage)) show = false;
            if (updatedActiveGrades.length > 0 && !updatedActiveGrades.includes(sGrade)) show = false;
            if (updatedActiveClasses.length > 0 && !updatedActiveClasses.includes(sClass)) show = false;

            if (searchVal !== '') {
                const matchesSearch = sNameLower.includes(searchVal) || sCode.includes(searchVal);
                if (!matchesSearch) show = false;
            }

            if (show) {
                item.style.display = '';
                visibleStudents++;
            } else {
                item.style.display = 'none';
                // if selected student was hidden, deselect it
                if (radio.checked) {
                    radio.checked = false;
                    if (selectedStudentLabel) selectedStudentLabel.innerText = 'اختر الطالب';
                    if (studentSelect) studentSelect.value = '';
                }
            }
        });
    }

    // Bind checkbox listeners
    stageCheckboxes.forEach(cb => cb.addEventListener('change', function() {
        // Clear children
        gradeCheckboxes.forEach(gcb => gcb.checked = false);
        classCheckboxes.forEach(ccb => ccb.checked = false);
        applyFilters();
    }));
    gradeCheckboxes.forEach(cb => cb.addEventListener('change', function() {
        classCheckboxes.forEach(ccb => ccb.checked = false);
        applyFilters();
    }));
    classCheckboxes.forEach(cb => cb.addEventListener('change', applyFilters));
    
    if (studentSearchInput) {
        studentSearchInput.addEventListener('input', applyFilters);
    }

    // Radio select listener to change Student label
    studentRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                const labelText = this.nextElementSibling.innerText.trim();
                if (selectedStudentLabel) selectedStudentLabel.innerText = labelText;
            }
        });
    });

    if (resetFiltersBtn) {
        resetFiltersBtn.addEventListener('click', function() {
            stageCheckboxes.forEach(cb => cb.checked = false);
            gradeCheckboxes.forEach(cb => cb.checked = false);
            classCheckboxes.forEach(cb => cb.checked = false);
            studentRadios.forEach(r => r.checked = false);
            if (studentSearchInput) studentSearchInput.value = '';
            if (selectedStudentLabel) selectedStudentLabel.innerText = 'اختر الطالب';
            applyFilters();
        });
    }

    // Sync selected student radio state with label on page load
    const checkedRadio = Array.from(studentRadios).find(r => r.checked);
    if (checkedRadio && selectedStudentLabel) {
        selectedStudentLabel.innerText = checkedRadio.nextElementSibling.innerText.trim();
    }

    // Run initially
    applyFilters();

    // ----------------------------------------------------
    // § 2 — Live Update Print Settings Controls
    // ----------------------------------------------------
    const docPaper = document.getElementById('officialDocPaper');
    if (!docPaper) return;

    const toggleSchoolHeader = document.getElementById('toggleSchoolHeader');
    const toggleLogo = document.getElementById('toggleLogo');
    const togglePhoto = document.getElementById('togglePhoto');
    const toggleBorder = document.getElementById('toggleBorder');
    const toggleSignatures = document.getElementById('toggleSignatures');
    const toggleDetailsTable = document.getElementById('toggleDetailsTable');
    const documentDateInput = document.getElementById('documentDateInput');
    const additionalNotesInput = document.getElementById('additionalNotesInput');

    const dateDisplay = document.querySelector('.doc-date-display');
    const notesBox = document.getElementById('docAdditionalNotesBox');
    const notesContent = document.getElementById('docAdditionalNotesContent');

    function syncPreviewClasses() {
        if (toggleSchoolHeader && toggleSchoolHeader.checked) docPaper.classList.add('show-header');
        else if (toggleSchoolHeader) docPaper.classList.remove('show-header');

        if (toggleLogo && toggleLogo.checked) docPaper.classList.add('show-logo');
        else if (toggleLogo) docPaper.classList.remove('show-logo');

        if (togglePhoto && togglePhoto.checked) docPaper.classList.add('show-photo');
        else if (togglePhoto) docPaper.classList.remove('show-photo');

        if (toggleBorder && toggleBorder.checked) docPaper.classList.add('has-border');
        else if (toggleBorder) docPaper.classList.remove('has-border');

        if (toggleSignatures && toggleSignatures.checked) docPaper.classList.add('show-signatures');
        else if (toggleSignatures) docPaper.classList.remove('show-signatures');

        if (toggleDetailsTable && toggleDetailsTable.checked) docPaper.classList.add('show-details-table');
        else if (toggleDetailsTable) docPaper.classList.remove('show-details-table');
    }

    [toggleSchoolHeader, toggleLogo, togglePhoto, toggleBorder, toggleSignatures, toggleDetailsTable].forEach(el => {
        if (el) el.addEventListener('change', syncPreviewClasses);
    });

    if (documentDateInput) {
        documentDateInput.addEventListener('change', function() {
            if (dateDisplay) {
                const dateObj = new Date(this.value);
                if (!isNaN(dateObj.getTime())) {
                    dateDisplay.innerText = dateObj.toLocaleDateString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });
                } else {
                    dateDisplay.innerText = this.value;
                }
            }
        });
        documentDateInput.dispatchEvent(new Event('change'));
    }

    if (additionalNotesInput) {
        additionalNotesInput.addEventListener('input', function() {
            if (notesBox && notesContent) {
                if (this.value.trim() !== '') {
                    notesContent.innerText = this.value;
                    notesBox.style.display = 'block';
                } else {
                    notesBox.style.display = 'none';
                }
            }
        });
    }

    // Run initial preview styling sync
    syncPreviewClasses();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
