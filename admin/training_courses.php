<?php
/**
 * إدارة الدورات التدريبية - Admin Training Courses Management
 */
$page_title = "إدارة الدورات التدريبية";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/Training.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/csrf.php';

// Auth validation before any processing
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

$training = new Training($db);
ActivityLog::setDb($db);

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Determine view mode
$view = $_GET['view'] ?? 'courses';
$program_id = isset($_GET['program_id']) ? intval($_GET['program_id']) : null;
$course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : null;
$unit_id = isset($_GET['unit_id']) ? intval($_GET['unit_id']) : null;

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    try {
        $action = $_POST['form_action'] ?? '';
        
        switch ($action) {
            // Course actions
            case 'add_course':
                $courseData = [
                    'program_id' => $_POST['program_id'],
                    'title' => trim($_POST['title']),
                    'title_en' => trim($_POST['title_en'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'description_en' => trim($_POST['description_en'] ?? ''),
                    'difficulty' => $_POST['difficulty'] ?? 'beginner',
                    'estimated_hours' => floatval($_POST['estimated_hours'] ?? 1),
                    'passing_score' => intval($_POST['passing_score'] ?? 70),
                    'is_mandatory' => isset($_POST['is_mandatory']) ? 1 : 0,
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'sort_order' => intval($_POST['sort_order'] ?? 0),
                    'display_language' => $_POST['display_language'] ?? 'ar',
                    'created_by' => $_SESSION['user_id'] ?? null
                ];
                $training->createCourse($courseData);
                $newCourseId = (int)$db->lastInsertId();
                ActivityLog::logCreate('training_course', $newCourseId, $courseData['title'], [
                    'program_id' => $courseData['program_id'],
                    'difficulty' => $courseData['difficulty'],
                    'estimated_hours' => $courseData['estimated_hours'],
                    'passing_score' => $courseData['passing_score'],
                    'is_mandatory' => $courseData['is_mandatory'],
                    'is_active' => $courseData['is_active'],
                    'display_language' => $courseData['display_language'],
                ]);
                $_SESSION['success_message'] = "تم إضافة الدورة التدريبية بنجاح.";
                header("Location: training_courses.php?view=courses" . ($program_id ? "&program_id=$program_id" : ""));
                exit();

            case 'edit_course':
                $courseId = (int)$_POST['id'];
                $oldCourse = $training->getCourse($courseId);
                $courseData = [
                    'program_id' => $_POST['program_id'],
                    'title' => trim($_POST['title']),
                    'title_en' => trim($_POST['title_en'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'description_en' => trim($_POST['description_en'] ?? ''),
                    'difficulty' => $_POST['difficulty'] ?? 'beginner',
                    'estimated_hours' => floatval($_POST['estimated_hours'] ?? 1),
                    'passing_score' => intval($_POST['passing_score'] ?? 70),
                    'is_mandatory' => isset($_POST['is_mandatory']) ? 1 : 0,
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'sort_order' => intval($_POST['sort_order'] ?? 0),
                    'display_language' => $_POST['display_language'] ?? 'ar'
                ];
                $training->updateCourse($courseId, $courseData);
                $changes = [];
                if ($oldCourse) {
                    foreach (['title', 'title_en', 'program_id', 'difficulty', 'estimated_hours', 'passing_score', 'is_mandatory', 'is_active', 'sort_order', 'display_language'] as $f) {
                        $old = $oldCourse[$f] ?? '';
                        $new = $courseData[$f] ?? '';
                        if ((string)$old !== (string)$new) {
                            $changes[$f] = ['from' => $old, 'to' => $new];
                        }
                    }
                }
                ActivityLog::logUpdate('training_course', $courseId, $courseData['title'], ['changes' => $changes]);
                $_SESSION['success_message'] = "تم تحديث الدورة بنجاح.";
                header("Location: training_courses.php?view=courses" . ($program_id ? "&program_id=$program_id" : ""));
                exit();

            case 'delete_course':
                $courseId = (int)$_POST['id'];
                $course = $training->getCourse($courseId);
                $training->deleteCourse($courseId);
                ActivityLog::logDelete('training_course', $courseId, $course['title'] ?? '', ['course_id' => $courseId]);
                $_SESSION['success_message'] = "تم حذف الدورة بنجاح.";
                header("Location: training_courses.php?view=courses" . ($program_id ? "&program_id=$program_id" : ""));
                exit();

            case 'toggle_course':
                $courseId = (int)$_POST['id'];
                $newStatus = (int)$_POST['new_status'];
                $training->toggleCourseStatus($courseId, $newStatus);
                $course = $training->getCourse($courseId);
                ActivityLog::logUpdate('training_course', $courseId, $course['title'] ?? '', ['is_active' => $newStatus]);
                $_SESSION['success_message'] = "تم تغيير حالة الدورة بنجاح.";
                header("Location: training_courses.php?view=courses" . ($program_id ? "&program_id=$program_id" : ""));
                exit();
                
            // Unit actions
            case 'add_unit':
                $unitData = [
                    'course_id' => $_POST['course_id'],
                    'title' => trim($_POST['title']),
                    'title_en' => trim($_POST['title_en'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'description_en' => trim($_POST['description_en'] ?? ''),
                    'unit_type' => $_POST['unit_type'] ?? 'text',
                    'content' => $_POST['content'] ?? '',
                    'content_en' => $_POST['content_en'] ?? '',
                    'video_url' => trim($_POST['video_url'] ?? ''),
                    'file_path' => trim($_POST['file_path'] ?? ''),
                    'external_link' => trim($_POST['external_link'] ?? ''),
                    'duration_minutes' => intval($_POST['duration_minutes'] ?? 30),
                    'has_assessment' => isset($_POST['has_assessment']) ? 1 : 0,
                    'sort_order' => intval($_POST['sort_order'] ?? 0)
                ];
                $training->createUnit($unitData);
                $newUnitId = (int)$db->lastInsertId();
                ActivityLog::logCreate('training_unit', $newUnitId, $unitData['title'], [
                    'course_id' => $unitData['course_id'],
                    'unit_type' => $unitData['unit_type'],
                    'duration_minutes' => $unitData['duration_minutes'],
                    'has_assessment' => $unitData['has_assessment'],
                    'is_active' => 1,
                ]);
                $_SESSION['success_message'] = "تم إضافة الوحدة التدريبية بنجاح.";
                header("Location: training_courses.php?view=units&course_id=" . $_POST['course_id']);
                exit();

            case 'edit_unit':
                $unitId = (int)$_POST['id'];
                $oldUnit = $training->getUnit($unitId);
                $unitData = [
                    'title' => trim($_POST['title']),
                    'title_en' => trim($_POST['title_en'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'description_en' => trim($_POST['description_en'] ?? ''),
                    'unit_type' => $_POST['unit_type'] ?? 'text',
                    'content' => $_POST['content'] ?? '',
                    'content_en' => $_POST['content_en'] ?? '',
                    'video_url' => trim($_POST['video_url'] ?? ''),
                    'file_path' => trim($_POST['file_path'] ?? ''),
                    'external_link' => trim($_POST['external_link'] ?? ''),
                    'duration_minutes' => intval($_POST['duration_minutes'] ?? 30),
                    'has_assessment' => isset($_POST['has_assessment']) ? 1 : 0,
                    'sort_order' => intval($_POST['sort_order'] ?? 0),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0
                ];
                $training->updateUnit($unitId, $unitData);
                $changes = [];
                if ($oldUnit) {
                    foreach (['title', 'title_en', 'unit_type', 'duration_minutes', 'has_assessment', 'sort_order', 'is_active'] as $f) {
                        $old = $oldUnit[$f] ?? '';
                        $new = $unitData[$f] ?? '';
                        if ((string)$old !== (string)$new) {
                            $changes[$f] = ['from' => $old, 'to' => $new];
                        }
                    }
                }
                ActivityLog::logUpdate('training_unit', $unitId, $unitData['title'], ['changes' => $changes]);
                $_SESSION['success_message'] = "تم تحديث الوحدة بنجاح.";
                header("Location: training_courses.php?view=units&course_id=" . $_POST['course_id']);
                exit();

            case 'delete_unit':
                $unitId = (int)$_POST['id'];
                $unit = $training->getUnit($unitId);
                $training->deleteUnit($unitId);
                ActivityLog::logDelete('training_unit', $unitId, $unit['title'] ?? '', ['unit_id' => $unitId, 'course_id' => $unit['course_id'] ?? null]);
                $_SESSION['success_message'] = "تم حذف الوحدة بنجاح.";
                header("Location: training_courses.php?view=units&course_id=" . ($_GET['course_id'] ?? 0));
                exit();
                
            // Question actions
            case 'add_question':
                $questionData = [
                    'unit_id' => $_POST['unit_id'],
                    'question_text' => trim($_POST['question_text']),
                    'question_text_en' => trim($_POST['question_text_en'] ?? ''),
                    'question_type' => $_POST['question_type'] ?? 'multiple_choice',
                    'option_a' => trim($_POST['option_a']),
                    'option_a_en' => trim($_POST['option_a_en'] ?? ''),
                    'option_b' => trim($_POST['option_b']),
                    'option_b_en' => trim($_POST['option_b_en'] ?? ''),
                    'option_c' => trim($_POST['option_c'] ?? ''),
                    'option_c_en' => trim($_POST['option_c_en'] ?? ''),
                    'option_d' => trim($_POST['option_d'] ?? ''),
                    'option_d_en' => trim($_POST['option_d_en'] ?? ''),
                    'correct_answer' => $_POST['correct_answer'],
                    'explanation' => trim($_POST['explanation'] ?? ''),
                    'explanation_en' => trim($_POST['explanation_en'] ?? ''),
                    'sort_order' => intval($_POST['sort_order'] ?? 0)
                ];
                $training->createQuestion($questionData);
                $newQuestionId = (int)$db->lastInsertId();
                ActivityLog::logCreate('training_question', $newQuestionId, mb_substr($questionData['question_text'], 0, 80), [
                    'unit_id' => $questionData['unit_id'],
                    'question_type' => $questionData['question_type'],
                    'correct_answer' => $questionData['correct_answer'],
                    'sort_order' => $questionData['sort_order'],
                ]);
                $_SESSION['success_message'] = "تم إضافة السؤال بنجاح.";
                header("Location: training_courses.php?view=questions&unit_id=" . $_POST['unit_id']);
                exit();

            case 'edit_question':
                $questionId = (int)$_POST['id'];
                $oldQuestion = $training->getQuestion($questionId);
                $questionData = [
                    'question_text' => trim($_POST['question_text']),
                    'question_text_en' => trim($_POST['question_text_en'] ?? ''),
                    'question_type' => $_POST['question_type'] ?? 'multiple_choice',
                    'option_a' => trim($_POST['option_a']),
                    'option_a_en' => trim($_POST['option_a_en'] ?? ''),
                    'option_b' => trim($_POST['option_b']),
                    'option_b_en' => trim($_POST['option_b_en'] ?? ''),
                    'option_c' => trim($_POST['option_c'] ?? ''),
                    'option_c_en' => trim($_POST['option_c_en'] ?? ''),
                    'option_d' => trim($_POST['option_d'] ?? ''),
                    'option_d_en' => trim($_POST['option_d_en'] ?? ''),
                    'correct_answer' => $_POST['correct_answer'],
                    'explanation' => trim($_POST['explanation'] ?? ''),
                    'explanation_en' => trim($_POST['explanation_en'] ?? ''),
                    'sort_order' => intval($_POST['sort_order'] ?? 0)
                ];
                $training->updateQuestion($questionId, $questionData);
                $changes = [];
                if ($oldQuestion) {
                    foreach (['question_type', 'correct_answer', 'sort_order'] as $f) {
                        $old = $oldQuestion[$f] ?? '';
                        $new = $questionData[$f] ?? '';
                        if ((string)$old !== (string)$new) {
                            $changes[$f] = ['from' => $old, 'to' => $new];
                        }
                    }
                }
                ActivityLog::logUpdate('training_question', $questionId, mb_substr($questionData['question_text'], 0, 80), ['changes' => $changes]);
                $_SESSION['success_message'] = "تم تحديث السؤال بنجاح.";
                header("Location: training_courses.php?view=questions&unit_id=" . $_POST['unit_id']);
                exit();

            case 'delete_question':
                $questionId = (int)$_POST['id'];
                $question = $training->getQuestion($questionId);
                $training->deleteQuestion($questionId);
                ActivityLog::logDelete('training_question', $questionId, mb_substr($question['question_text'] ?? '', 0, 80), ['question_id' => $questionId, 'unit_id' => $question['unit_id'] ?? null]);
                $_SESSION['success_message'] = "تم حذف السؤال بنجاح.";
                header("Location: training_courses.php?view=questions&unit_id=" . ($_GET['unit_id'] ?? 0));
                exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        // العودة لصفحة العرض المناسبة حسب السياق (حفظ PRG).
        $fallbackView = $view ?? 'courses';
        $fallbackQuery = '';
        if ($fallbackView === 'units' && !empty($_POST['course_id'])) {
            $fallbackQuery = '&course_id=' . (int)$_POST['course_id'];
        } elseif ($fallbackView === 'questions' && !empty($_POST['unit_id'])) {
            $fallbackQuery = '&unit_id=' . (int)$_POST['unit_id'];
        } elseif ($fallbackView === 'courses' && $program_id) {
            $fallbackQuery = '&program_id=' . $program_id;
        }
        header("Location: training_courses.php?view=" . $fallbackView . $fallbackQuery);
        exit();
    }
}

// Get data based on view
$programs = $training->getPrograms();
$courses = [];
$units = [];
$questions = [];
$currentCourse = null;
$currentUnit = null;

if ($view === 'units' && $course_id) {
    $currentCourse = $training->getCourse($course_id);
    $units = $training->getUnits($course_id);
} elseif ($view === 'questions' && $unit_id) {
    $currentUnit = $training->getUnit($unit_id);
    $questions = $training->getQuestions($unit_id);
    if ($currentUnit) $course_id = $currentUnit['course_id'];
} else {
    $courses = $training->getCourses($program_id);
}

include_once '../includes/admin_header.php';
?>

<style>
/* Premium Dashboard Enhancements */
.course-card-wrapper {
    transition: opacity 0.3s ease;
}
.course-card {
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s ease, border-color 0.3s ease !important;
    border: 1px solid rgba(0, 0, 0, 0.05) !important;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 16px !important;
    overflow: hidden;
}
.course-card:hover {
    transform: translateY(-6px) !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08), 0 6px 22px var(--course-accent-glow) !important;
    border-color: var(--course-accent-color) !important;
}
.search-input-group {
    background: #fff;
    border: 1px solid rgba(0, 0, 0, 0.08);
    transition: box-shadow 0.3s ease, border-color 0.3s ease;
}
.search-input-group:focus-within {
    box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.15) !important;
    border-color: rgba(37, 99, 235, 0.5);
}
.search-input-group input:focus {
    box-shadow: none !important;
    outline: none !important;
}

/* Program Filter Buttons Redesign */
.program-filter-btn {
    border: 1px solid rgba(0, 0, 0, 0.04) !important;
    background-color: var(--filter-bg-color, #f8f9fa) !important;
    color: var(--filter-text-color, #4b5563) !important;
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.85rem !important;
    padding: 0.45rem 1.1rem !important;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
    margin-bottom: 2px;
}
.program-filter-btn:hover {
    background-color: var(--filter-accent-color) !important;
    color: #fff !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 4px 12px var(--filter-accent-glow) !important;
}
.program-filter-btn.active {
    background-color: var(--filter-accent-color) !important;
    color: #fff !important;
    border-color: var(--filter-accent-color) !important;
    box-shadow: 0 4px 14px var(--filter-accent-glow) !important;
}

/* Glassmorphic Pill Badges */
.course-card .badge.bg-success {
    background-color: rgba(16, 185, 129, 0.08) !important;
    color: #10b981 !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.35rem 0.8rem !important;
}
.course-card .badge.bg-warning {
    background-color: rgba(245, 158, 11, 0.08) !important;
    color: #d97706 !important;
    border: 1px solid rgba(245, 158, 11, 0.15) !important;
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.35rem 0.8rem !important;
}
.course-card .badge.bg-danger {
    background-color: rgba(239, 68, 68, 0.08) !important;
    color: #ef4444 !important;
    border: 1px solid rgba(239, 68, 68, 0.15) !important;
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.35rem 0.8rem !important;
}
.course-card .badge.bg-info {
    background-color: rgba(14, 165, 233, 0.08) !important;
    color: #0ea5e9 !important;
    border: 1px solid rgba(14, 165, 233, 0.15) !important;
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.35rem 0.8rem !important;
}
.course-card .program-badge {
    background-color: rgba(255, 255, 255, 0.95) !important;
    color: var(--prog-badge-color) !important;
    background: var(--prog-badge-color)12 !important;
    border: 1px solid var(--prog-badge-color)26 !important;
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.35rem 0.8rem !important;
}

/* Premium Card Stats Pill Layout */
.course-stat-pill {
    flex: 1 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0.55rem 0.4rem !important;
    border-radius: 12px !important;
    font-size: 0.8rem !important;
    transition: all 0.2s ease !important;
    gap: 5px !important;
    font-weight: 500;
}
.stat-units {
    background-color: rgba(59, 130, 246, 0.06) !important;
    color: #2563eb !important;
}
.stat-enrolled {
    background-color: rgba(16, 185, 129, 0.06) !important;
    color: #10b981 !important;
}
.stat-hours {
    background-color: rgba(245, 158, 11, 0.06) !important;
    color: #d97706 !important;
}

/* Button Upgrades */
.course-card .btn-info {
    font-weight: 600 !important;
    background-color: #0ea5e9 !important;
    border-color: #0ea5e9 !important;
    transition: all 0.2s ease !important;
}
.course-card .btn-info:hover {
    background-color: #0284c7 !important;
    border-color: #0284c7 !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 10px rgba(14, 165, 233, 0.25) !important;
}
.course-card .btn {
    border-radius: 10px !important;
}

/* List View Overrides */
@media (min-width: 768px) {
    #coursesContainer.view-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    #coursesContainer.view-list > .col-lg-4 {
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
    }
    #coursesContainer.view-list .card {
        flex-direction: row !important;
        align-items: center !important;
        padding: 0.75rem 1.25rem !important;
        height: auto !important;
        border-radius: 12px !important;
    }
    #coursesContainer.view-list .card:hover {
        transform: translateY(-2px) !important;
    }
    #coursesContainer.view-list .card-body {
        padding: 0.25rem !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        flex-grow: 1 !important;
        margin-left: 1.5rem !important;
        margin-right: 0 !important;
        gap: 1rem;
    }
    #coursesContainer.view-list .card-body > div:first-child {
        min-width: 250px;
        flex-shrink: 0;
    }
    #coursesContainer.view-list .card-title {
        margin-bottom: 0 !important;
        font-size: 1.05rem !important;
        font-weight: 700 !important;
    }
    #coursesContainer.view-list .card-body p {
        margin-bottom: 0 !important;
        max-width: 320px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        padding-left: 1.5rem !important;
    }
    #coursesContainer.view-list .course-stat-pill {
        flex: 0 0 auto !important;
        padding: 0.4rem 0.8rem !important;
        border-radius: 8px !important;
        min-width: 90px;
    }
    #coursesContainer.view-list .card-body .d-flex.justify-content-between {
        margin-bottom: 0 !important;
        margin-top: 0 !important;
        display: flex !important;
        flex-direction: row !important;
        gap: 0.5rem !important;
        min-width: 300px !important;
        flex-shrink: 0 !important;
    }
    #coursesContainer.view-list .card-footer {
        padding: 0.25rem !important;
        width: auto !important;
        flex-shrink: 0 !important;
        min-width: 250px;
    }
}

/* Table Redesign in Units View */
.table-responsive {
    border-radius: 12px !important;
    overflow: hidden;
}
.table.table-hover tbody tr {
    transition: all 0.2s ease !important;
    border-right: 3px solid transparent !important;
}
.table.table-hover tbody tr:hover {
    background-color: rgba(37, 99, 235, 0.018) !important;
    border-right: 3px solid #2563eb !important;
}
.table thead {
    background-color: #f8f9fa !important;
    border-bottom: 2px solid rgba(0, 0, 0, 0.04) !important;
}
.table thead th {
    font-weight: 700 !important;
    color: #4b5563 !important;
    font-size: 0.85rem !important;
    padding: 1rem 0.75rem !important;
}

/* Glassmorphic Column Badges */
.badge-glass {
    border-radius: 30px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.35rem 0.8rem !important;
    display: inline-flex !important;
    align-items: center !important;
    border: 1px solid rgba(0, 0, 0, 0.04) !important;
    gap: 4px;
}
.badge-glass-primary {
    background-color: rgba(99, 102, 241, 0.08) !important;
    color: #4f46e5 !important;
}
.badge-glass-success {
    background-color: rgba(16, 185, 129, 0.08) !important;
    color: #10b981 !important;
}
.badge-glass-warning {
    background-color: rgba(245, 158, 11, 0.08) !important;
    color: #d97706 !important;
}
.badge-glass-danger {
    background-color: rgba(239, 68, 68, 0.08) !important;
    color: #ef4444 !important;
}
.badge-glass-info {
    background-color: rgba(14, 165, 233, 0.08) !important;
    color: #0ea5e9 !important;
}
.badge-glass-secondary {
    background-color: rgba(107, 114, 128, 0.08) !important;
    color: #4b5563 !important;
}

/* Glowing Indicator Dot */
.glow-dot {
    width: 6px;
    height: 6px;
    background-color: currentColor;
    border-radius: 50%;
    display: inline-block;
    margin-left: 5px;
    margin-right: 2px;
    box-shadow: 0 0 8px currentColor;
    animation: blink-animation 1.5s infinite ease-in-out;
}
@keyframes blink-animation {
    0%, 100% { opacity: 0.3; }
    50% { opacity: 1; }
}

/* Questions Card Redesign */
.question-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border: 1px solid rgba(0, 0, 0, 0.05) !important;
    border-radius: 16px !important;
    background: #fff;
}
.question-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05) !important;
}
.question-number-badge {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.9rem;
    background-color: rgba(37, 99, 235, 0.1) !important;
    color: #2563eb !important;
    border-radius: 50%;
}
.option-box {
    border: 1px solid rgba(0, 0, 0, 0.08) !important;
    border-radius: 12px !important;
    padding: 0.85rem 1.1rem !important;
    transition: all 0.2s ease !important;
    background-color: #f8f9fa;
    display: flex;
    align-items: center;
}
.option-box:hover {
    border-color: rgba(37, 99, 235, 0.25) !important;
    background-color: rgba(37, 99, 235, 0.02) !important;
}
.option-box.correct-option {
    border-color: #10b981 !important;
    background-color: rgba(16, 185, 129, 0.06) !important;
    color: #059669 !important;
}
.option-prefix {
    font-weight: 700;
    color: #4f46e5;
    background-color: rgba(99, 102, 241, 0.1);
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    margin-left: 0.75rem;
    font-size: 0.85rem;
}
.option-box.correct-option .option-prefix {
    background-color: #10b981;
    color: #fff;
}
</style>



<div id="trainingCoursesPage" class="training-courses-page admin-unified-page">

<!-- Page Header -->
<?php if ($view === 'courses'): ?>
    <div class="admin-page-heading">
        <h1 class="h2"><i class="fas fa-book me-2 text-primary"></i>إدارة الدورات التدريبية</h1>
        <div class="admin-top-actions no-print">
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#courseModal" onclick="resetCourseForm()">
                <i class="fas fa-plus-circle me-1"></i>إضافة دورة جديدة
            </button>
        </div>
    </div>

    <!-- Statistics Cards (ui_preview.php pattern) -->
    <?php
    $totalCourses = count($courses);
    $activeCourses = count(array_filter($courses, fn($c) => !empty($c['is_active'])));
    $totalUnits = array_sum(array_column($courses, 'unit_count'));
    $totalEnrolled = array_sum(array_column($courses, 'enrollment_count'));
    ?>
    <div class="dashboard-canvas sortable-dashboard mb-4">
        <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 sortable-dashboard" id="widget-training-stats">
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <div class="stat-card-icon"><i class="fas fa-book"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $totalCourses; ?>">0</div>
                        <div class="stat-card-label">إجمالي الدورات</div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $activeCourses; ?>">0</div>
                        <div class="stat-card-label">الدورات النشطة</div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <div class="stat-card-icon"><i class="fas fa-list-ol"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $totalUnits; ?>">0</div>
                        <div class="stat-card-label">إجمالي الوحدات</div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                    <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-card-info">
                        <div class="stat-card-number counter" data-target="<?php echo $totalEnrolled; ?>">0</div>
                        <div class="stat-card-label">إجمالي المسجلين</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Unified Admin Filter Bar -->
    <form id="coursesFilterForm" class="admin-filter-bar mb-4" novalidate>
        <div class="admin-filter-controls">
            <input type="text" id="searchCourses" class="form-control form-control-sm admin-inline-select-sm" placeholder="ابحث عن دورة بالاسم أو الوصف..." style="min-width: 220px;">
            <select id="filterProgram" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة البرنامج" onchange="location.href='training_courses.php' + (this.value ? '?program_id=' + this.value : '');">
                <option value="">جميع البرامج التدريبية</option>
                <?php foreach ($programs as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $program_id == $p['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select id="filterDifficulty" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة المستوى">
                <option value="">جميع المستويات</option>
                <option value="beginner">مبتدئ</option>
                <option value="intermediate">متوسط</option>
                <option value="advanced">متقدم</option>
            </select>
            <select id="filterStatus" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة الحالة">
                <option value="">جميع الحالات</option>
                <option value="1">نشط</option>
                <option value="0">غير نشط</option>
            </select>
        </div>
        <div class="admin-filter-actions">
            <button type="button" class="btn btn-light btn-sm" id="btnResetFilters" onclick="resetCourseFilters()"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</button>
            <div class="btn-group shadow-sm rounded-3" role="group" aria-label="Layout view options">
                <button type="button" id="btnGridView" class="btn btn-layout-toggle" data-bs-toggle="tooltip" title="عرض شبكي">
                    <i class="fas fa-th-large"></i>
                    <span>شبكي</span>
                </button>
                <button type="button" id="btnListView" class="btn btn-layout-toggle" data-bs-toggle="tooltip" title="عرض طولي">
                    <i class="fas fa-list"></i>
                    <span>طولي</span>
                </button>
            </div>
        </div>
    </form>
    
<?php elseif ($view === 'units' && $currentCourse): ?>
    <div class="admin-page-heading">
        <h1 class="h2"><i class="fas fa-list me-2 text-primary"></i>وحدات: <?php echo htmlspecialchars($currentCourse['title']); ?></h1>
        <div class="admin-top-actions no-print">
            <button type="button" class="btn btn-header-premium btn-success shadow-sm me-2" data-bs-toggle="modal" data-bs-target="#unitModal" onclick="resetUnitForm()">
                <i class="fas fa-plus-circle me-1"></i>إضافة وحدة جديدة
            </button>
            <a href="training_courses.php" class="btn btn-header-premium btn-print-soft shadow-sm">
                <i class="fas fa-arrow-right me-1"></i>عودة للدورات
            </a>
        </div>
    </div>
    
<?php elseif ($view === 'questions' && $currentUnit): ?>
    <div class="admin-page-heading">
        <h1 class="h2"><i class="fas fa-question-circle me-2 text-primary"></i>أسئلة: <?php echo htmlspecialchars($currentUnit['title']); ?></h1>
        <div class="admin-top-actions no-print">
            <button type="button" class="btn btn-header-premium btn-success shadow-sm me-2" data-bs-toggle="modal" data-bs-target="#questionModal" onclick="resetQuestionForm()">
                <i class="fas fa-plus-circle me-1"></i>إضافة سؤال جديد
            </button>
            <a href="training_courses.php?view=units&course_id=<?php echo $currentUnit['course_id']; ?>" class="btn btn-header-premium btn-print-soft shadow-sm">
                <i class="fas fa-arrow-right me-1"></i>عودة للوحدات
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Alerts -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($view === 'courses'): ?>
<!-- ==================== COURSES VIEW ==================== -->
<div class="row g-4" id="coursesContainer">
    <?php if (empty($courses)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-book fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">لا توجد دورات تدريبية</h5>
                    <button class="btn btn-success mt-2" data-bs-toggle="modal" data-bs-target="#courseModal" onclick="resetCourseForm()">
                        <i class="fas fa-plus me-1"></i> إضافة دورة
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($courses as $course): ?>
            <div class="col-lg-4 col-md-6 course-card-wrapper" 
                 data-title="<?php echo htmlspecialchars($course['title'], ENT_QUOTES); ?>" 
                 data-description="<?php echo htmlspecialchars($course['description'] ?? '', ENT_QUOTES); ?>"
                 data-program="<?php echo htmlspecialchars($course['program_name'], ENT_QUOTES); ?>"
                 data-program-id="<?php echo $course['program_id']; ?>"
                 data-difficulty="<?php echo htmlspecialchars($course['difficulty'] ?? '', ENT_QUOTES); ?>"
                 data-active="<?php echo (string)$course['is_active']; ?>"
                 style="--course-accent-color: <?php echo htmlspecialchars($course['program_color']); ?>; --course-accent-glow: <?php echo htmlspecialchars($course['program_color']); ?>26;">
                <div class="card border-0 shadow-sm h-100 course-card <?php echo !$course['is_active'] ? 'opacity-50' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-start mb-2">
                            <span class="badge program-badge me-2" style="--prog-badge-color: <?php echo $course['program_color']; ?>;">
                                <i class="fas <?php echo $course['program_icon']; ?> me-1"></i>
                                <?php echo htmlspecialchars($course['program_name']); ?>
                            </span>
                            <span class="badge <?php echo Training::getDifficultyBadge($course['difficulty']); ?>">
                                <?php echo Training::getDifficultyLabel($course['difficulty']); ?>
                            </span>
                            <?php echo Training::getLanguageBadge($course['display_language'] ?? 'ar'); ?>
                            <?php if ($course['is_mandatory']): ?>
                                <span class="badge bg-danger ms-1">إلزامي</span>
                            <?php endif; ?>
                        </div>
                        <h5 class="card-title"><?php echo htmlspecialchars($course['title']); ?></h5>
                        <p class="text-muted small mb-3"><?php echo htmlspecialchars(mb_substr($course['description'] ?? '', 0, 100)); ?></p>
                        <div class="d-flex justify-content-between mb-4 mt-2 gap-2">
                            <div class="course-stat-pill stat-units">
                                <i class="fas fa-list me-1"></i>
                                <span><strong><?php echo $course['unit_count']; ?></strong> وحدة</span>
                            </div>
                            <div class="course-stat-pill stat-enrolled">
                                <i class="fas fa-users me-1"></i>
                                <span><strong><?php echo $course['enrollment_count']; ?></strong> مسجل</span>
                            </div>
                            <div class="course-stat-pill stat-hours">
                                <i class="fas fa-clock me-1"></i>
                                <span><strong><?php echo $course['estimated_hours']; ?></strong> ساعة</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-0 pb-3 pt-0">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <a href="training_courses.php?view=units&course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-info text-white flex-grow-1 py-2 rounded-3 shadow-sm" data-bs-toggle="tooltip" title="عرض وحدات الدورة">
                                <i class="fas fa-list me-1"></i> عرض الوحدات
                            </a>
                            <div class="d-flex gap-1 admin-actions">
                                <button type="button" class="btn btn-action-pills btn-edit me-1" onclick='editCourse(<?php echo json_encode($course, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' data-bs-toggle="tooltip" title="تعديل الدورة">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="form_action" value="toggle_course">
                                    <input type="hidden" name="id" value="<?php echo $course['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $course['is_active'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn btn-action-pills <?php echo $course['is_active'] ? 'btn-deactivate' : 'btn-activate'; ?> me-1" data-bs-toggle="tooltip" title="<?php echo $course['is_active'] ? 'تعطيل الدورة' : 'تفعيل الدورة'; ?>">
                                        <i class="fas <?php echo $course['is_active'] ? 'fa-ban' : 'fa-check'; ?>"></i>
                                    </button>
                                </form>
                                <?php if ($course['enrollment_count'] == 0): ?>
                                    <button type="button" class="btn btn-action-pills btn-delete" onclick="deleteItem('course', <?php echo $course['id']; ?>, '<?php echo htmlspecialchars($course['title'], ENT_QUOTES); ?>')" data-bs-toggle="tooltip" title="حذف الدورة">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Course Modal -->
<div class="modal fade" id="courseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create" id="courseModalContent">
            <form method="POST" id="courseForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="form_action" id="courseFormAction" value="add_course">
                <input type="hidden" name="id" id="courseId">
                <div class="modal-header">
                    <h5 class="modal-title" id="courseModalTitle"><i class="fas fa-plus-circle me-2"></i>إضافة دورة تدريبية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">عنوان الدورة (عربي) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" id="courseTitle" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">البرنامج <span class="text-danger">*</span></label>
                            <select class="form-select" name="program_id" id="courseProgramId" required>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" <?php echo $program_id == $p['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">عنوان الدورة (إنجليزي)</label>
                            <input type="text" class="form-control" name="title_en" id="courseTitleEn" dir="ltr" placeholder="Course title in English">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الوصف (عربي)</label>
                            <textarea class="form-control" name="description" id="courseDesc" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الوصف (إنجليزي)</label>
                            <textarea class="form-control" name="description_en" id="courseDescEn" rows="3" dir="ltr" placeholder="Course description in English"></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">المستوى</label>
                            <select class="form-select" name="difficulty" id="courseDifficulty">
                                <option value="beginner">مبتدئ</option>
                                <option value="intermediate">متوسط</option>
                                <option value="advanced">متقدم</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">لغة العرض للمعلمين</label>
                            <select class="form-select" name="display_language" id="courseDisplayLang">
                                <option value="ar">🌍 العربية</option>
                                <option value="en">🌐 English</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">الساعات المتوقعة</label>
                            <input type="number" class="form-control" name="estimated_hours" id="courseHours" value="1" min="0.5" step="0.5">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">درجة النجاح (%)</label>
                            <input type="number" class="form-control" name="passing_score" id="coursePassScore" value="70" min="0" max="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">الترتيب</label>
                            <input type="number" class="form-control" name="sort_order" id="courseSortOrder" value="0" min="0">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="is_mandatory" id="courseMandatory">
                                <label class="form-check-label" for="courseMandatory">إلزامي</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="is_active" id="courseActive" checked>
                                <label class="form-check-label" for="courseActive">مفعّل</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetCourseForm() {
    document.getElementById('courseFormAction').value = 'add_course';
    document.getElementById('courseId').value = '';
    document.getElementById('courseTitle').value = '';
    document.getElementById('courseTitleEn').value = '';
    document.getElementById('courseDesc').value = '';
    document.getElementById('courseDescEn').value = '';
    document.getElementById('courseDifficulty').value = 'beginner';
    document.getElementById('courseDisplayLang').value = 'ar';
    document.getElementById('courseHours').value = '1';
    document.getElementById('coursePassScore').value = '70';
    document.getElementById('courseSortOrder').value = '0';
    document.getElementById('courseMandatory').checked = false;
    document.getElementById('courseActive').checked = true;
    document.getElementById('courseModalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة دورة تدريبية';
    
    var modalContent = document.getElementById('courseModalContent');
    modalContent.classList.remove('admin-modal-edit');
    modalContent.classList.add('admin-modal-create');
    var submitBtn = document.querySelector('#courseForm button[type="submit"]');
    submitBtn.className = 'btn btn-success';
}

function editCourse(course) {
    document.getElementById('courseFormAction').value = 'edit_course';
    document.getElementById('courseId').value = course.id;
    document.getElementById('courseTitle').value = course.title;
    document.getElementById('courseTitleEn').value = course.title_en || '';
    document.getElementById('courseProgramId').value = course.program_id;
    document.getElementById('courseDesc').value = course.description || '';
    document.getElementById('courseDescEn').value = course.description_en || '';
    document.getElementById('courseDifficulty').value = course.difficulty;
    document.getElementById('courseDisplayLang').value = course.display_language || 'ar';
    document.getElementById('courseHours').value = course.estimated_hours;
    document.getElementById('coursePassScore').value = course.passing_score;
    document.getElementById('courseSortOrder').value = course.sort_order;
    document.getElementById('courseMandatory').checked = course.is_mandatory == 1;
    document.getElementById('courseActive').checked = course.is_active == 1;
    document.getElementById('courseModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل الدورة';
    
    var modalContent = document.getElementById('courseModalContent');
    modalContent.classList.remove('admin-modal-create');
    modalContent.classList.add('admin-modal-edit');
    var submitBtn = document.querySelector('#courseForm button[type="submit"]');
    submitBtn.className = 'btn btn-primary';
    
    new bootstrap.Modal(document.getElementById('courseModal')).show();
}
</script>

<?php elseif ($view === 'units' && $currentCourse): ?>
<!-- ==================== UNITS VIEW ==================== -->
<div class="admin-list-surface mb-4">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped datatable admin-data-table">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>الوحدة</th>
                    <th>النوع</th>
                    <th>المدة</th>
                    <th>اختبار</th>
                    <th>الأسئلة</th>
                    <th>الحالة</th>
                    <th class="text-center" width="180">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($units)): ?>
                    <tr><td colspan="8" class="text-center py-4 text-muted">لا توجد وحدات تدريبية بعد</td></tr>
                <?php else: ?>
                    <?php foreach ($units as $i => $unit): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <strong class="text-primary"><?php echo htmlspecialchars($unit['title']); ?></strong>
                                <?php if ($unit['description']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars(mb_substr($unit['description'], 0, 60)); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $unitTypeClasses = [
                                    'video' => 'bg-danger-subtle text-danger border-danger-subtle',
                                    'text' => 'bg-success-subtle text-success border-success-subtle',
                                    'task' => 'bg-primary-subtle text-primary border-primary-subtle',
                                    'file' => 'bg-warning-subtle text-warning border-warning-subtle',
                                    'link' => 'bg-info-subtle text-info border-info-subtle'
                                ];
                                $unitClass = $unitTypeClasses[$unit['unit_type']] ?? 'bg-secondary-subtle text-secondary';
                                ?>
                                <span class="badge <?php echo $unitClass; ?>">
                                    <i class="fas <?php echo Training::getUnitTypeIcon($unit['unit_type']); ?> me-1"></i>
                                    <?php echo Training::getUnitTypeLabel($unit['unit_type']); ?>
                                </span>
                            </td>
                            <td><span class="fw-bold text-dark"><?php echo $unit['duration_minutes']; ?></span> دقيقة</td>
                            <td>
                                <?php if ($unit['has_assessment']): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> نعم</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">لا</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($unit['has_assessment']): ?>
                                    <a href="training_courses.php?view=questions&unit_id=<?php echo $unit['id']; ?>" class="badge bg-info text-decoration-none">
                                        <i class="fas fa-question-circle me-1"></i> <?php echo $unit['question_count']; ?> سؤال
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($unit['is_active']): ?>
                                    <span class="badge bg-success">نشط</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">معطل</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-view me-1" onclick='previewUnit(<?php echo json_encode($unit, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)' data-bs-toggle="tooltip" title="معاينة كمعلم">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($unit['has_assessment']): ?>
                                    <a href="training_courses.php?view=questions&unit_id=<?php echo $unit['id']; ?>" class="btn btn-action-pills btn-services me-1" data-bs-toggle="tooltip" title="الأسئلة">
                                        <i class="fas fa-question-circle"></i>
                                    </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-action-pills btn-edit me-1" onclick='editUnit(<?php echo json_encode($unit, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' data-bs-toggle="tooltip" title="تعديل">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-delete" onclick="deleteItem('unit', <?php echo $unit['id']; ?>, '<?php echo htmlspecialchars($unit['title'], ENT_QUOTES); ?>')" data-bs-toggle="tooltip" title="حذف">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- Unit Modal -->
<div class="modal fade" id="unitModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create" id="unitModalContent">
            <form method="POST" id="unitForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="form_action" id="unitFormAction" value="add_unit">
                <input type="hidden" name="id" id="unitId">
                <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="unitModalTitle"><i class="fas fa-plus-circle me-2"></i>إضافة وحدة تدريبية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">عنوان الوحدة (عربي) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" id="unitTitle" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">نوع المحتوى</label>
                            <select class="form-select" name="unit_type" id="unitType" onchange="toggleUnitFields()">
                                <option value="text">محتوى نصي</option>
                                <option value="video">فيديو</option>
                                <option value="task">مهمة عملية</option>
                                <option value="file">ملف مرفق</option>
                                <option value="link">رابط خارجي</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">المدة (دقائق)</label>
                            <input type="number" class="form-control" name="duration_minutes" id="unitDuration" value="30" min="1">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">عنوان الوحدة (إنجليزي)</label>
                            <input type="text" class="form-control" name="title_en" id="unitTitleEn" dir="ltr" placeholder="Unit title in English">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الوصف (عربي)</label>
                            <textarea class="form-control" name="description" id="unitDesc" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الوصف (إنجليزي)</label>
                            <textarea class="form-control" name="description_en" id="unitDescEn" rows="2" dir="ltr" placeholder="Unit description in English"></textarea>
                        </div>
                        <div class="col-12" id="contentField">
                            <label class="form-label fw-bold">المحتوى العربي (HTML مدعوم)</label>
                            <textarea class="form-control" name="content" id="unitContent" rows="8"></textarea>
                        </div>
                        <div class="col-12" id="contentEnField">
                            <label class="form-label fw-bold">المحتوى الإنجليزي (HTML supported)</label>
                            <textarea class="form-control" name="content_en" id="unitContentEn" rows="8" dir="ltr" placeholder="English content (HTML supported)"></textarea>
                        </div>
                        <div class="col-md-6" id="videoField">
                            <label class="form-label fw-bold"><i class="fas fa-video me-1"></i>رابط الفيديو</label>
                            <input type="url" class="form-control" name="video_url" id="unitVideoUrl" placeholder="https://youtube.com/watch?v=...">
                            <small class="text-muted">يدعم YouTube و Vimeo - يظهر الفيديو داخل الوحدة للمعلمين</small>
                        </div>
                        <div class="col-md-6" id="fileField" style="display:none;">
                            <label class="form-label fw-bold">مسار الملف</label>
                            <input type="text" class="form-control" name="file_path" id="unitFilePath" placeholder="uploads/training/file.pdf">
                        </div>
                        <div class="col-md-6" id="linkField" style="display:none;">
                            <label class="form-label fw-bold">الرابط الخارجي</label>
                            <input type="url" class="form-control" name="external_link" id="unitExternalLink" placeholder="https://example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">الترتيب</label>
                            <input type="number" class="form-control" name="sort_order" id="unitSortOrder" value="0" min="0">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="has_assessment" id="unitHasAssessment">
                                <label class="form-check-label" for="unitHasAssessment">يتضمن اختبار</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="is_active" id="unitActive" checked>
                                <label class="form-check-label" for="unitActive">مفعّل</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetUnitForm() {
    document.getElementById('unitFormAction').value = 'add_unit';
    document.getElementById('unitId').value = '';
    document.getElementById('unitTitle').value = '';
    document.getElementById('unitTitleEn').value = '';
    document.getElementById('unitDesc').value = '';
    document.getElementById('unitDescEn').value = '';
    document.getElementById('unitType').value = 'text';
    document.getElementById('unitContent').value = '';
    document.getElementById('unitContentEn').value = '';
    document.getElementById('unitVideoUrl').value = '';
    document.getElementById('unitFilePath').value = '';
    document.getElementById('unitExternalLink').value = '';
    document.getElementById('unitDuration').value = '30';
    document.getElementById('unitSortOrder').value = '0';
    document.getElementById('unitHasAssessment').checked = false;
    document.getElementById('unitActive').checked = true;
    document.getElementById('unitModalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة وحدة تدريبية';
    toggleUnitFields();
    
    var modalContent = document.getElementById('unitModalContent');
    modalContent.classList.remove('admin-modal-edit');
    modalContent.classList.add('admin-modal-create');
    var submitBtn = document.querySelector('#unitForm button[type="submit"]');
    submitBtn.className = 'btn btn-success';
}

function editUnit(unit) {
    document.getElementById('unitFormAction').value = 'edit_unit';
    document.getElementById('unitId').value = unit.id;
    document.getElementById('unitTitle').value = unit.title;
    document.getElementById('unitTitleEn').value = unit.title_en || '';
    document.getElementById('unitDesc').value = unit.description || '';
    document.getElementById('unitDescEn').value = unit.description_en || '';
    document.getElementById('unitType').value = unit.unit_type;
    document.getElementById('unitContent').value = unit.content || '';
    document.getElementById('unitContentEn').value = unit.content_en || '';
    document.getElementById('unitVideoUrl').value = unit.video_url || '';
    document.getElementById('unitFilePath').value = unit.file_path || '';
    document.getElementById('unitExternalLink').value = unit.external_link || '';
    document.getElementById('unitDuration').value = unit.duration_minutes;
    document.getElementById('unitSortOrder').value = unit.sort_order;
    document.getElementById('unitHasAssessment').checked = unit.has_assessment == 1;
    document.getElementById('unitActive').checked = unit.is_active == 1;
    document.getElementById('unitModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل الوحدة';
    toggleUnitFields();
    
    var modalContent = document.getElementById('unitModalContent');
    modalContent.classList.remove('admin-modal-create');
    modalContent.classList.add('admin-modal-edit');
    var submitBtn = document.querySelector('#unitForm button[type="submit"]');
    submitBtn.className = 'btn btn-primary';
    
    new bootstrap.Modal(document.getElementById('unitModal')).show();
}

function toggleUnitFields() {
    var type = document.getElementById('unitType').value;
    // عرض حقل الفيديو لجميع الأنواع (فيديو مرفق اختياري)
    document.getElementById('videoField').style.display = '';
    document.getElementById('fileField').style.display = (type === 'file') ? '' : 'none';
    document.getElementById('linkField').style.display = (type === 'link') ? '' : 'none';
    document.getElementById('contentField').style.display = (type !== 'link') ? '' : 'none';
    document.getElementById('contentEnField').style.display = (type !== 'link') ? '' : 'none';
    // تحديث تسمية حقل الفيديو حسب النوع
    var videoLabel = document.querySelector('#videoField label');
    if (videoLabel) {
        videoLabel.innerHTML = (type === 'video') ? '<i class="fas fa-video me-1"></i>رابط الفيديو' : '<i class="fas fa-video me-1"></i>فيديو مرفق <small class="text-muted">(اختياري - YouTube)</small>';
    }
}
</script>

<?php elseif ($view === 'questions' && $currentUnit): ?>
<!-- ==================== QUESTIONS VIEW ==================== -->
<div class="row g-4">
    <?php if (empty($questions)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-question-circle fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">لا توجد أسئلة بعد</h5>
                    <button class="btn btn-success mt-2" data-bs-toggle="modal" data-bs-target="#questionModal" onclick="resetQuestionForm()">
                        <i class="fas fa-plus me-1"></i> إضافة سؤال
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($questions as $i => $q): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm question-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div class="flex-grow-1">
                                <h6 class="mb-3 d-flex align-items-center gap-2 flex-wrap text-start">
                                    <span class="question-number-badge"><?php echo $i + 1; ?></span>
                                    <span class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($q['question_text']); ?></span>
                                    <span class="badge-glass <?php echo $q['question_type'] == 'true_false' ? 'badge-glass-warning' : 'badge-glass-primary'; ?>">
                                        <i class="fas <?php echo $q['question_type'] == 'true_false' ? 'fa-toggle-on' : 'fa-list-ol'; ?> me-1"></i>
                                        <?php echo $q['question_type'] == 'true_false' ? 'صح/خطأ' : 'اختيار متعدد'; ?>
                                    </span>
                                </h6>
                                <div class="row g-3 mb-2 mt-2">
                                    <?php foreach (['a', 'b', 'c', 'd'] as $opt): 
                                        $optKey = 'option_' . $opt;
                                        if (empty($q[$optKey])) continue;
                                        $isCorrect = ($q['correct_answer'] === $opt);
                                    ?>
                                        <div class="col-md-6">
                                            <div class="option-box <?php echo $isCorrect ? 'correct-option' : ''; ?>">
                                                <span class="option-prefix"><?php echo strtoupper($opt); ?></span>
                                                <span><?php echo htmlspecialchars($q[$optKey]); ?></span>
                                                <?php if ($isCorrect): ?>
                                                    <i class="fas fa-check-circle text-success ms-auto fs-5"></i>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($q['explanation']): ?>
                                    <div class="alert alert-info py-2 mb-0 mt-3 border-0 rounded-3" style="background-color: rgba(14, 165, 233, 0.05); color: #0284c7; text-align: right;">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <strong>التوضيح:</strong> <?php echo htmlspecialchars($q['explanation']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-1 align-items-center actions-column">
                                <button class="btn btn-action-pills btn-edit" onclick='editQuestion(<?php echo json_encode($q, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' data-bs-toggle="tooltip" title="تعديل">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-action-pills btn-delete" onclick="deleteItem('question', <?php echo $q['id']; ?>, 'السؤال رقم <?php echo $i+1; ?>')" data-bs-toggle="tooltip" title="حذف">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Question Modal -->
<div class="modal fade" id="questionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create" id="questionModalContent">
            <form method="POST" id="questionForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="form_action" id="qFormAction" value="add_question">
                <input type="hidden" name="id" id="qId">
                <input type="hidden" name="unit_id" value="<?php echo $unit_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="qModalTitle"><i class="fas fa-plus-circle me-2"></i>إضافة سؤال</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">نص السؤال (عربي) <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="question_text" id="qText" rows="2" required></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">نوع السؤال</label>
                            <select class="form-select" name="question_type" id="qType" onchange="toggleQuestionType()">
                                <option value="multiple_choice">اختيار متعدد</option>
                                <option value="true_false">صح / خطأ</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">نص السؤال (إنجليزي)</label>
                            <textarea class="form-control" name="question_text_en" id="qTextEn" rows="2" dir="ltr" placeholder="Question text in English"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الخيار أ (عربي) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="option_a" id="qOptA" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Option A (English)</label>
                            <input type="text" class="form-control" name="option_a_en" id="qOptAEn" dir="ltr">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الخيار ب (عربي) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="option_b" id="qOptB" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Option B (English)</label>
                            <input type="text" class="form-control" name="option_b_en" id="qOptBEn" dir="ltr">
                        </div>
                        <div class="col-md-6" id="optCField">
                            <label class="form-label fw-bold">الخيار ج (عربي)</label>
                            <input type="text" class="form-control" name="option_c" id="qOptC">
                        </div>
                        <div class="col-md-6" id="optCEnField">
                            <label class="form-label fw-bold">Option C (English)</label>
                            <input type="text" class="form-control" name="option_c_en" id="qOptCEn" dir="ltr">
                        </div>
                        <div class="col-md-6" id="optDField">
                            <label class="form-label fw-bold">الخيار د (عربي)</label>
                            <input type="text" class="form-control" name="option_d" id="qOptD">
                        </div>
                        <div class="col-md-6" id="optDEnField">
                            <label class="form-label fw-bold">Option D (English)</label>
                            <input type="text" class="form-control" name="option_d_en" id="qOptDEn" dir="ltr">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">الإجابة الصحيحة <span class="text-danger">*</span></label>
                            <select class="form-select" name="correct_answer" id="qCorrect" required>
                                <option value="a">أ</option>
                                <option value="b">ب</option>
                                <option value="c">ج</option>
                                <option value="d">د</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">الترتيب</label>
                            <input type="number" class="form-control" name="sort_order" id="qSort" value="0" min="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">التوضيح (عربي - اختياري)</label>
                            <textarea class="form-control" name="explanation" id="qExplanation" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Explanation (English - optional)</label>
                            <textarea class="form-control" name="explanation_en" id="qExplanationEn" rows="2" dir="ltr" placeholder="Explanation in English"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetQuestionForm() {
    document.getElementById('qFormAction').value = 'add_question';
    document.getElementById('qId').value = '';
    document.getElementById('qText').value = '';
    document.getElementById('qTextEn').value = '';
    document.getElementById('qType').value = 'multiple_choice';
    document.getElementById('qOptA').value = '';
    document.getElementById('qOptAEn').value = '';
    document.getElementById('qOptB').value = '';
    document.getElementById('qOptBEn').value = '';
    document.getElementById('qOptC').value = '';
    document.getElementById('qOptCEn').value = '';
    document.getElementById('qOptD').value = '';
    document.getElementById('qOptDEn').value = '';
    document.getElementById('qCorrect').value = 'a';
    document.getElementById('qSort').value = '0';
    document.getElementById('qExplanation').value = '';
    document.getElementById('qExplanationEn').value = '';
    document.getElementById('qModalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة سؤال';
    toggleQuestionType();
    
    var modalContent = document.getElementById('questionModalContent');
    modalContent.classList.remove('admin-modal-edit');
    modalContent.classList.add('admin-modal-create');
    var submitBtn = document.querySelector('#questionForm button[type="submit"]');
    submitBtn.className = 'btn btn-success';
}

function editQuestion(q) {
    document.getElementById('qFormAction').value = 'edit_question';
    document.getElementById('qId').value = q.id;
    document.getElementById('qText').value = q.question_text;
    document.getElementById('qTextEn').value = q.question_text_en || '';
    document.getElementById('qType').value = q.question_type;
    document.getElementById('qOptA').value = q.option_a;
    document.getElementById('qOptAEn').value = q.option_a_en || '';
    document.getElementById('qOptB').value = q.option_b;
    document.getElementById('qOptBEn').value = q.option_b_en || '';
    document.getElementById('qOptC').value = q.option_c || '';
    document.getElementById('qOptCEn').value = q.option_c_en || '';
    document.getElementById('qOptD').value = q.option_d || '';
    document.getElementById('qOptDEn').value = q.option_d_en || '';
    document.getElementById('qCorrect').value = q.correct_answer;
    document.getElementById('qSort').value = q.sort_order;
    document.getElementById('qExplanation').value = q.explanation || '';
    document.getElementById('qExplanationEn').value = q.explanation_en || '';
    document.getElementById('qModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل السؤال';
    toggleQuestionType();
    
    var modalContent = document.getElementById('questionModalContent');
    modalContent.classList.remove('admin-modal-create');
    modalContent.classList.add('admin-modal-edit');
    var submitBtn = document.querySelector('#questionForm button[type="submit"]');
    submitBtn.className = 'btn btn-primary';
    
    new bootstrap.Modal(document.getElementById('questionModal')).show();
}

function toggleQuestionType() {
    var isTF = document.getElementById('qType').value === 'true_false';
    document.getElementById('optCField').style.display = isTF ? 'none' : '';
    document.getElementById('optCEnField').style.display = isTF ? 'none' : '';
    document.getElementById('optDField').style.display = isTF ? 'none' : '';
    document.getElementById('optDEnField').style.display = isTF ? 'none' : '';
    if (isTF) {
        document.getElementById('qOptA').value = 'صح';
        document.getElementById('qOptB').value = 'خطأ';
        // Limit correct answer options
        var sel = document.getElementById('qCorrect');
        sel.querySelectorAll('option').forEach(function(opt, i) {
            opt.style.display = (i < 2) ? '' : 'none';
        });
    } else {
        var sel = document.getElementById('qCorrect');
        sel.querySelectorAll('option').forEach(function(opt) {
            opt.style.display = '';
        });
    }
}
</script>
<?php endif; ?>

<!-- Unit Preview Modal (Teacher View) -->
<div class="modal fade" id="unitPreviewModal" tabindex="-1" aria-labelledby="unitPreviewModalLabel">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="unitPreviewModalLabel">
                    <i class="fas fa-eye me-2 text-secondary"></i>
                    <span id="previewUnitTitle">معاينة الوحدة</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Preview Header -->
                <div class="p-3 border-bottom" style="background: linear-gradient(135deg, #f8f9fa, #e9ecef);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-light text-dark me-2" id="previewUnitTypeBadge"></span>
                            <span class="badge bg-light text-muted" id="previewUnitDuration"></span>
                        </div>
                        <span class="badge bg-info" id="previewUnitAssessment" style="display:none;"><i class="fas fa-question-circle me-1"></i>يتضمن اختبار</span>
                    </div>
                    <p class="text-muted mt-2 mb-0 small" id="previewUnitDesc"></p>
                </div>
                <!-- Preview Video -->
                <div id="previewVideoContainer" class="p-3" style="display:none;">
                    <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 12px; background: #000;">
                        <iframe id="previewVideoIframe" src="" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
                    </div>
                </div>
                <!-- Preview Content -->
                <div class="p-4" id="previewContentArea" dir="rtl" style="font-family: 'Cairo', sans-serif; line-height: 1.9;">
                </div>
            </div>
            <div class="modal-footer">
                <small class="text-muted me-auto"><i class="fas fa-info-circle me-1"></i>هذه المعاينة تعرض المحتوى كما يظهر للمعلم</small>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Shared Delete Form -->
<form method="POST" id="deleteForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="form_action" id="deleteAction">
    <input type="hidden" name="id" id="deleteId">
</form>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i><span id="deleteModalTitle">حذف</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من حذف <span class="fw-bold text-primary" id="deleteItemName"></span>؟</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    سيتم حذف جميع البيانات المرتبطة بشكل نهائي.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash me-1"></i>حذف
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function previewUnit(unit) {
    // Title
    document.getElementById('previewUnitTitle').textContent = unit.title || 'معاينة الوحدة';
    
    // Type badge
    var typeIcons = {video: 'fa-play-circle', text: 'fa-file-alt', task: 'fa-tasks', file: 'fa-file-download', link: 'fa-external-link-alt'};
    var typeLabels = {video: 'فيديو', text: 'نص تعليمي', task: 'مهمة', file: 'ملف', link: 'رابط خارجي'};
    var icon = typeIcons[unit.unit_type] || 'fa-file';
    var label = typeLabels[unit.unit_type] || unit.unit_type;
    document.getElementById('previewUnitTypeBadge').innerHTML = '<i class="fas ' + icon + ' me-1"></i>' + label;
    
    // Duration
    document.getElementById('previewUnitDuration').innerHTML = '<i class="fas fa-clock me-1"></i>' + (unit.duration_minutes || 0) + ' دقيقة';
    
    // Description
    var descEl = document.getElementById('previewUnitDesc');
    if (unit.description) {
        descEl.textContent = unit.description;
        descEl.style.display = '';
    } else {
        descEl.style.display = 'none';
    }
    
    // Assessment badge
    document.getElementById('previewUnitAssessment').style.display = unit.has_assessment ? '' : 'none';
    
    // Video
    var videoContainer = document.getElementById('previewVideoContainer');
    var videoIframe = document.getElementById('previewVideoIframe');
    if (unit.video_url) {
        var embedUrl = '';
        var ytMatch = unit.video_url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/);
        var vimeoMatch = unit.video_url.match(/vimeo\.com\/(\d+)/);
        if (ytMatch) {
            embedUrl = 'https://www.youtube.com/embed/' + ytMatch[1] + '?rel=0&modestbranding=1';
        } else if (vimeoMatch) {
            embedUrl = 'https://player.vimeo.com/video/' + vimeoMatch[1];
        } else {
            embedUrl = unit.video_url;
        }
        videoIframe.src = embedUrl;
        videoContainer.style.display = '';
    } else {
        videoIframe.src = '';
        videoContainer.style.display = 'none';
    }
    
    // Content
    var contentArea = document.getElementById('previewContentArea');
    contentArea.innerHTML = unit.content || '<p class="text-muted text-center py-5"><i class="fas fa-file-alt fa-3x mb-3 d-block"></i>لا يوجد محتوى نصي لهذه الوحدة</p>';
    
    // External link alert
    if (unit.unit_type === 'link' && unit.external_link) {
        contentArea.innerHTML = '<div class="alert alert-info"><i class="fas fa-external-link-alt me-2"></i><a href="' + unit.external_link + '" target="_blank" class="alert-link">انقر هنا لفتح الرابط الخارجي</a></div>' + contentArea.innerHTML;
    }
    
    // File download alert
    if (unit.unit_type === 'file' && unit.file_path) {
        contentArea.innerHTML = '<div class="alert alert-info"><i class="fas fa-file-download me-2"></i><a href="' + unit.file_path + '" target="_blank" class="alert-link">تحميل الملف المرفق</a></div>' + contentArea.innerHTML;
    }
    
    new bootstrap.Modal(document.getElementById('unitPreviewModal')).show();
}

// Stop video when modal closes
document.getElementById('unitPreviewModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('previewVideoIframe').src = '';
});

function deleteItem(type, id, name) {
    var labels = { course: 'الدورة', unit: 'الوحدة', question: 'السؤال' };
    document.getElementById('deleteModalTitle').textContent = 'حذف ' + (labels[type] || type);
    document.getElementById('deleteItemName').textContent = '"' + name + '"';
    document.getElementById('confirmDeleteBtn').onclick = function() {
        document.getElementById('deleteAction').value = 'delete_' + type;
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    };
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}

// Search, Filter & Layout Switcher Logic
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchCourses');
    var difficultySelect = document.getElementById('filterDifficulty');
    var statusSelect = document.getElementById('filterStatus');
    var container = document.getElementById('coursesContainer');
    
    if (container) {
        var cards = container.querySelectorAll('.course-card-wrapper');
        
        function applyFilters() {
            var query = searchInput ? searchInput.value.toLowerCase().trim() : '';
            var difficulty = difficultySelect ? difficultySelect.value : '';
            var status = statusSelect ? statusSelect.value : '';
            
            cards.forEach(function(card) {
                var title = (card.getAttribute('data-title') || '').toLowerCase();
                var desc = (card.getAttribute('data-description') || '').toLowerCase();
                var prog = (card.getAttribute('data-program') || '').toLowerCase();
                var cardDiff = card.getAttribute('data-difficulty') || '';
                var cardActive = card.getAttribute('data-active') || '';
                
                var matchesSearch = !query || title.includes(query) || desc.includes(query) || prog.includes(query);
                var matchesDiff = !difficulty || cardDiff === difficulty;
                var matchesStatus = !status || cardActive === status;
                
                if (matchesSearch && matchesDiff && matchesStatus) {
                    card.style.display = '';
                    card.style.opacity = '1';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        if (searchInput) searchInput.addEventListener('input', applyFilters);
        if (difficultySelect) difficultySelect.addEventListener('change', applyFilters);
        if (statusSelect) statusSelect.addEventListener('change', applyFilters);
        
        // Grid/List View Toggler
        var btnGrid = document.getElementById('btnGridView');
        var btnList = document.getElementById('btnListView');
        
        if (btnGrid && btnList) {
            // Load preference
            var savedView = localStorage.getItem('courses_layout_view') || 'grid';
            setView(savedView);
            
            btnGrid.addEventListener('click', function() {
                setView('grid');
            });
            
            btnList.addEventListener('click', function() {
                setView('list');
            });
            
            function setView(view) {
                if (view === 'list') {
                    container.classList.add('view-list');
                    btnList.classList.add('active');
                    btnGrid.classList.remove('active');
                    localStorage.setItem('courses_layout_view', 'list');
                } else {
                    container.classList.remove('view-list');
                    btnGrid.classList.add('active');
                    btnList.classList.remove('active');
                    localStorage.setItem('courses_layout_view', 'grid');
                }
            }
        }
    }
});

function resetCourseFilters() {
    var searchInput = document.getElementById('searchCourses');
    var difficultySelect = document.getElementById('filterDifficulty');
    var statusSelect = document.getElementById('filterStatus');
    var filterProg = document.getElementById('filterProgram');

    if (searchInput) searchInput.value = '';
    if (difficultySelect) difficultySelect.value = '';
    if (statusSelect) statusSelect.value = '';

    if (filterProg && filterProg.value !== '') {
        location.href = 'training_courses.php';
        return;
    }
    
    if (typeof window.applyFilters === 'function') {
        window.applyFilters();
    }
}
</script>

</div><!-- /#trainingCoursesPage -->

<?php include_once '../includes/admin_footer.php'; ?>
