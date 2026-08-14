<?php
require_once '../includes/pagination.php';
$page_title = "إدارة التنبيهات";
$custom_page_title = true;

require_once '../includes/session_config.php';
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications_helper.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");
$currentAcademicYearId = AcademicYear::currentId($db);

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Determine active tab for redirects and UI
$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'notifications');
$validTabs = ['notifications', 'occasions'];
if (!in_array($activeTab, $validTabs)) { $activeTab = 'notifications'; }

// ==================== AJAX Endpoints ====================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Get classes by grade
    if ($_GET['ajax'] === 'classes_by_grade') {
        $grade_id = intval($_GET['grade_id'] ?? 0);
        $stmt = $db->prepare("SELECT id, name FROM classes WHERE grade_id = ? AND status = 'active' ORDER BY name");
        $stmt->execute([$grade_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Get grades by stage
    if ($_GET['ajax'] === 'grades_by_stage') {
        $stage_id = intval($_GET['stage_id'] ?? 0);
        $stmt = $db->prepare("SELECT id, grade_name as name FROM grades WHERE stage_id = ? AND status = 'active' ORDER BY grade_order");
        $stmt->execute([$stage_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Get students by class (مرتبطة بالعام الحالي)
    if ($_GET['ajax'] === 'students_by_class') {
        $class_id = intval($_GET['class_id'] ?? 0);
        if ($currentAcademicYearId > 0) {
            $stmt = $db->prepare("SELECT u.id, u.name FROM users u
                JOIN student_enrollments se ON se.student_id = u.id
                    AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                WHERE se.class_id = ? AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
                ORDER BY u.name");
            $stmt->execute([$currentAcademicYearId, $class_id]);
        } else {
            $stmt = $db->prepare("SELECT u.id, u.name FROM users u WHERE u.class_id = ? AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=u.id AND sp.enrollment_status <> 'enrolled') ORDER BY u.name");
            $stmt->execute([$class_id]);
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Get teachers
    if ($_GET['ajax'] === 'teachers') {
        $stmt = $db->prepare("SELECT u.id, u.name FROM users u
            WHERE u.status = 'active'
              AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active')
            ORDER BY u.name");
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Get occasion data (for edit modal)
    if ($_GET['ajax'] === 'get_occasion') {
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM occasion_notifications WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($data ?: ['error' => 'not found']);
        exit;
    }

    // Get notification data (for edit modal)
    if ($_GET['ajax'] === 'get_notification') {
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM notifications WHERE id = ?");
        $stmt->execute([$id]);
        $notif = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($notif) {
            $tStmt = $db->prepare("SELECT target_type, target_id FROM notification_targets WHERE notification_id = ?");
            $tStmt->execute([$id]);
            $targets = [];
            while ($row = $tStmt->fetch(PDO::FETCH_ASSOC)) {
                $targets[$row['target_type']][] = (int)$row['target_id'];
            }
            $notif['targets'] = $targets;
            $notif['show_days_arr'] = json_decode($notif['show_days'] ?? '[]', true) ?: [];
            echo json_encode($notif);
        } else {
            echo json_encode(['error' => 'not_found']);
        }
        exit;
    }

    exit;
}

// ==================== Form Processing ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Add notification
    if (isset($_POST['add_notification'])) {
        try {
            $db->beginTransaction();

            $type = $_POST['notification_type'];
            $title = trim($_POST['title']);
            $message = trim($_POST['message']);
            $priority = $_POST['priority'] ?? 'normal';
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
            $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
            $show_days = isset($_POST['show_days']) ? json_encode($_POST['show_days']) : null;

            if (empty($title) || empty($message)) {
                throw new Exception("العنوان والرسالة مطلوبان.");
            }

            $stmt = $db->prepare("INSERT INTO notifications (title, message, type, priority, start_date, end_date, start_time, end_time, show_days, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $message, $type, $priority, $start_date, $end_date, $start_time, $end_time, $show_days, $_SESSION['user_id']]);
            $notification_id = $db->lastInsertId();

            // Add targets (not needed for public type)
            if ($type === 'student') {
                // Student targets: individual students, classes, grades, stages
                $targetStmt = $db->prepare("INSERT INTO notification_targets (notification_id, target_type, target_id) VALUES (?, ?, ?)");

                if (!empty($_POST['target_students'])) {
                    foreach ($_POST['target_students'] as $sid) {
                        $targetStmt->execute([$notification_id, 'student', intval($sid)]);
                    }
                }
                if (!empty($_POST['target_classes'])) {
                    foreach ($_POST['target_classes'] as $cid) {
                        $targetStmt->execute([$notification_id, 'class', intval($cid)]);
                    }
                }
                if (!empty($_POST['target_grades'])) {
                    foreach ($_POST['target_grades'] as $gid) {
                        $targetStmt->execute([$notification_id, 'grade', intval($gid)]);
                    }
                }
                if (!empty($_POST['target_stages'])) {
                    foreach ($_POST['target_stages'] as $stid) {
                        $targetStmt->execute([$notification_id, 'stage', intval($stid)]);
                    }
                }
            } elseif ($type === 'teacher') {
                $targetStmt = $db->prepare("INSERT INTO notification_targets (notification_id, target_type, target_id) VALUES (?, ?, ?)");

                if (!empty($_POST['target_teachers'])) {
                    foreach ($_POST['target_teachers'] as $tid) {
                        $targetStmt->execute([$notification_id, 'teacher', intval($tid)]);
                    }
                }
                if (!empty($_POST['target_subjects'])) {
                    foreach ($_POST['target_subjects'] as $subid) {
                        $targetStmt->execute([$notification_id, 'subject', intval($subid)]);
                    }
                }
                if (!empty($_POST['target_teacher_stages'])) {
                    foreach ($_POST['target_teacher_stages'] as $stid) {
                        $targetStmt->execute([$notification_id, 'stage', intval($stid)]);
                    }
                }
            } elseif ($type === 'specialist') {
                $targetStmt = $db->prepare("INSERT INTO notification_targets (notification_id, target_type, target_id) VALUES (?, ?, ?)");

                if (!empty($_POST['target_specialists'])) {
                    foreach ($_POST['target_specialists'] as $sid) {
                        $targetStmt->execute([$notification_id, 'specialist', intval($sid)]);
                    }
                }
                if (!empty($_POST['target_specialist_stages'])) {
                    foreach ($_POST['target_specialist_stages'] as $stid) {
                        $targetStmt->execute([$notification_id, 'stage', intval($stid)]);
                    }
                }
            }
            // public type = no targets needed (shows to all)

            $db->commit();

            // إرسال Push Notification إذا تم تفعيل الخيار
            $pushResult = null;
            if (!empty($_POST['send_push'])) {
                try {
                    $db->prepare("UPDATE notifications SET send_push = 1 WHERE id = ?")->execute([$notification_id]);
                    require_once '../classes/PushNotification.php';
                    $push = new PushNotification($db);
                    $pushResult = $push->sendForNotification($notification_id);
                } catch (Exception $pe) {
                    // لا نوقف العملية إذا فشل Push
                    $pushResult = ['sent' => 0, 'failed' => 0, 'errors' => [$pe->getMessage()]];
                }
            }

            $_SESSION['success_message'] = "تم إنشاء التنبيه بنجاح.";
            if ($pushResult) {
                $_SESSION['success_message'] .= " (تم إرسال إشعار فوري إلى {$pushResult['sent']} جهاز)";
            }
            ActivityLog::logCreate('notification', $notification_id, $title);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => $activeTab ?? 'notifications']));
        exit();
    }

    // Edit notification
    elseif (isset($_POST['edit_notification'])) {
        try {
            $db->beginTransaction();

            $id = intval($_POST['id']);
            $type = $_POST['notification_type'];
            $title = trim($_POST['title']);
            $message = trim($_POST['message']);
            $priority = $_POST['priority'] ?? 'normal';
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
            $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
            $show_days = isset($_POST['show_days']) ? json_encode($_POST['show_days']) : null;

            $stmt = $db->prepare("UPDATE notifications SET title=?, message=?, type=?, priority=?, start_date=?, end_date=?, start_time=?, end_time=?, show_days=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$title, $message, $type, $priority, $start_date, $end_date, $start_time, $end_time, $show_days, $id]);

            // Remove old targets
            $db->prepare("DELETE FROM notification_targets WHERE notification_id = ?")->execute([$id]);

            // Re-add targets
            if ($type === 'student') {
                $targetStmt = $db->prepare("INSERT INTO notification_targets (notification_id, target_type, target_id) VALUES (?, ?, ?)");
                if (!empty($_POST['target_students'])) {
                    foreach ($_POST['target_students'] as $sid) $targetStmt->execute([$id, 'student', intval($sid)]);
                }
                if (!empty($_POST['target_classes'])) {
                    foreach ($_POST['target_classes'] as $cid) $targetStmt->execute([$id, 'class', intval($cid)]);
                }
                if (!empty($_POST['target_grades'])) {
                    foreach ($_POST['target_grades'] as $gid) $targetStmt->execute([$id, 'grade', intval($gid)]);
                }
                if (!empty($_POST['target_stages'])) {
                    foreach ($_POST['target_stages'] as $stid) $targetStmt->execute([$id, 'stage', intval($stid)]);
                }
            } elseif ($type === 'teacher') {
                $targetStmt = $db->prepare("INSERT INTO notification_targets (notification_id, target_type, target_id) VALUES (?, ?, ?)");
                if (!empty($_POST['target_teachers'])) {
                    foreach ($_POST['target_teachers'] as $tid) $targetStmt->execute([$id, 'teacher', intval($tid)]);
                }
                if (!empty($_POST['target_subjects'])) {
                    foreach ($_POST['target_subjects'] as $subid) $targetStmt->execute([$id, 'subject', intval($subid)]);
                }
                if (!empty($_POST['target_teacher_stages'])) {
                    foreach ($_POST['target_teacher_stages'] as $stid) $targetStmt->execute([$id, 'stage', intval($stid)]);
                }
            } elseif ($type === 'specialist') {
                $targetStmt = $db->prepare("INSERT INTO notification_targets (notification_id, target_type, target_id) VALUES (?, ?, ?)");
                if (!empty($_POST['target_specialists'])) {
                    foreach ($_POST['target_specialists'] as $sid) $targetStmt->execute([$id, 'specialist', intval($sid)]);
                }
                if (!empty($_POST['target_specialist_stages'])) {
                    foreach ($_POST['target_specialist_stages'] as $stid) $targetStmt->execute([$id, 'stage', intval($stid)]);
                }
            }

            $db->commit();
            $_SESSION['success_message'] = "تم تحديث التنبيه بنجاح.";
            ActivityLog::logUpdate('notification', $id, $title);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => $activeTab ?? 'notifications']));
        exit();
    }

    // Delete notification
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM notifications WHERE id = ?");
            $stmt->execute([intval($_POST['id'])]);
            $_SESSION['success_message'] = "تم حذف التنبيه بنجاح.";
            ActivityLog::logDelete('notification', $_POST['id'], $_POST['id']);
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => $activeTab ?? 'notifications']));
        exit();
    }

    // Toggle status
    elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
        try {
            $new_active = intval($_POST['new_status']);
            $stmt = $db->prepare("UPDATE notifications SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_active, intval($_POST['id'])]);
            $status_text = $new_active ? 'تفعيل' : 'تعطيل';
            $_SESSION['success_message'] = "تم $status_text التنبيه بنجاح.";
            ActivityLog::logStatusChange('notification', $_POST['id'], $_POST['id'], ['status' => $new_active ? 'active' : 'inactive']);
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => $activeTab ?? 'notifications']));
        exit();
    }

    // إرسال إشعار فوري لتنبيه موجود
    elseif (isset($_POST['action']) && $_POST['action'] === 'send_push') {
        try {
            $notification_id = intval($_POST['id']);
            require_once '../classes/PushNotification.php';
            $push = new PushNotification($db);
            $result = $push->sendForNotification($notification_id);
            $db->prepare("UPDATE notifications SET send_push = 1 WHERE id = ?")->execute([$notification_id]);
            $_SESSION['success_message'] = "تم إرسال الإشعار الفوري إلى {$result['sent']} جهاز";
            if ($result['failed'] > 0) {
                $_SESSION['success_message'] .= " (فشل: {$result['failed']})";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ في إرسال الإشعار الفوري: " . $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => $activeTab ?? 'notifications']));
        exit();
    }

    // ==================== Occasion Form Processing ====================

    // Toggle occasion status
    elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_occasion') {
        try {
            $new_active = intval($_POST['new_status']);
            $stmt = $db->prepare("UPDATE occasion_notifications SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_active, intval($_POST['id'])]);
            $status_text = $new_active ? 'تفعيل' : 'تعطيل';
            $_SESSION['success_message'] = "تم $status_text المناسبة بنجاح.";
            // إرسال push عند التفعيل
            if ($new_active) {
                try {
                    require_once '../classes/PushNotification.php';
                    $push = new PushNotification($db);
                    $result = $push->sendForOccasion(intval($_POST['id']));
                    if ($result['sent'] > 0) {
                        $_SESSION['success_message'] .= " تم إرسال إشعار فوري إلى {$result['sent']} جهاز.";
                    }
                } catch (Exception $pushEx) { /* تجاهل أخطاء Push */ }
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => 'occasions']));
        exit();
    }

    // Update occasion
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_occasion') {
        try {
            $id = intval($_POST['id']);
            $title = trim($_POST['title'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $target_type = $_POST['target_type'] ?? 'all';
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

            if (empty($title) || empty($message)) {
                throw new Exception('العنوان والرسالة مطلوبان.');
            }

            $stmt = $db->prepare("UPDATE occasion_notifications SET title=?, message=?, target_type=?, start_date=?, end_date=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$title, $message, $target_type, $start_date, $end_date, $id]);
            $_SESSION['success_message'] = "تم تحديث تنبيه المناسبة بنجاح.";
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => 'occasions']));
        exit();
    }

    // Create new occasion
    elseif (isset($_POST['action']) && $_POST['action'] === 'create_occasion') {
        try {
            $title = trim($_POST['title'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $icon = trim($_POST['icon'] ?? 'fas fa-star');
            $emoji = trim($_POST['emoji'] ?? '');
            $theme = $_POST['theme'] ?? 'default';
            $gradient_start = $_POST['gradient_start'] ?? '#0d6efd';
            $gradient_end = $_POST['gradient_end'] ?? '#0a58ca';
            $text_color = $_POST['text_color'] ?? '#ffffff';
            $animation_type = $_POST['animation_type'] ?? 'fadeIn';
            $show_confetti = intval($_POST['show_confetti'] ?? 0);
            $target_type = $_POST['target_type'] ?? 'all';
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $is_active = intval($_POST['is_active'] ?? 1);

            if (empty($title) || empty($message)) {
                throw new Exception('العنوان والرسالة مطلوبان.');
            }

            $occasion_key = 'custom_' . time() . '_' . rand(100, 999);
            $maxOrder = $db->query("SELECT COALESCE(MAX(sort_order), 0) FROM occasion_notifications")->fetchColumn();

            $stmt = $db->prepare("INSERT INTO occasion_notifications
                (occasion_key, title, message, icon, emoji, theme, gradient_start, gradient_end, text_color, animation_type, show_confetti, target_type, start_date, end_date, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $occasion_key, $title, $message, $icon, $emoji,
                $theme, $gradient_start, $gradient_end, $text_color,
                $animation_type, $show_confetti, $target_type,
                $start_date, $end_date, $is_active, $maxOrder + 1
            ]);
            $occasionId = $db->lastInsertId();
            $_SESSION['success_message'] = "تم إنشاء تنبيه المناسبة بنجاح.";
            // إرسال push إذا كانت المناسبة مفعلة
            if ($is_active) {
                try {
                    require_once '../classes/PushNotification.php';
                    $push = new PushNotification($db);
                    $result = $push->sendForOccasion($occasionId);
                    if ($result['sent'] > 0) {
                        $_SESSION['success_message'] .= " تم إرسال إشعار فوري إلى {$result['sent']} جهاز.";
                    }
                } catch (Exception $pushEx) { /* تجاهل أخطاء Push */ }
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => 'occasions']));
        exit();
    }

    // إرسال إشعار فوري لمناسبة موجودة
    elseif (isset($_POST['action']) && $_POST['action'] === 'send_push_occasion') {
        try {
            $occasion_id = intval($_POST['id']);
            require_once '../classes/PushNotification.php';
            $push = new PushNotification($db);
            $result = $push->sendForOccasion($occasion_id);
            $_SESSION['success_message'] = "تم إرسال الإشعار الفوري إلى {$result['sent']} جهاز";
            if ($result['failed'] > 0) {
                $_SESSION['success_message'] .= " (فشل: {$result['failed']})";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ في إرسال الإشعار الفوري: " . $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => 'occasions']));
        exit();
    }

    // Delete occasion
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_occasion') {
        try {
            $stmt = $db->prepare("DELETE FROM occasion_notifications WHERE id = ?");
            $stmt->execute([intval($_POST['id'])]);
            $_SESSION['success_message'] = "تم حذف المناسبة بنجاح.";
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: notifications.php" . Utilities::buildQueryString(['tab' => 'occasions']));
        exit();
    }
}

// ==================== Get data for forms ====================
// Load notification for editing
$edit_notification = null;
$duplicate_notification = null;
$edit_targets = [];
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM notifications WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $edit_notification = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($edit_notification) {
        $stmt = $db->prepare("SELECT target_type, target_id FROM notification_targets WHERE notification_id = ?");
        $stmt->execute([$edit_notification['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $edit_targets[$row['target_type']][] = $row['target_id'];
        }
    }
}
// Load notification for duplication (pre-fill form with existing data)
if (isset($_GET['action']) && $_GET['action'] === 'add' && isset($_GET['duplicate'])) {
    $stmt = $db->prepare("SELECT * FROM notifications WHERE id = ?");
    $stmt->execute([intval($_GET['duplicate'])]);
    $duplicate_notification = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($duplicate_notification) {
        $stmt = $db->prepare("SELECT target_type, target_id FROM notification_targets WHERE notification_id = ?");
        $stmt->execute([$duplicate_notification['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $edit_targets[$row['target_type']][] = $row['target_id'];
        }
    }
}

// Load stages, grades, classes for dropdown
$stages = $db->query("SELECT id, stage_name FROM stages WHERE status='active' ORDER BY stage_order")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades WHERE status='active' ORDER BY grade_order")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, name, grade_id FROM classes WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$teachers = $db->query("SELECT u.id, u.name FROM users u
    WHERE u.status = 'active'
      AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active')
    ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
$specialists = $db->query("SELECT u.id, u.name FROM users u
    WHERE u.status = 'active'
      AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'specialist' AND ura.status = 'active')
    ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $db->query("SELECT id, name FROM subjects WHERE is_active=1 ORDER BY default_order, name")->fetchAll(PDO::FETCH_ASSOC);

// Load occasion notifications (reload after form processing)
$occasions = $db->query("SELECT * FROM occasion_notifications ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

// Metrics for Regular Notifications (Tab 1)
$totalNotifsCount = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$activeNotifsCount = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE is_active = 1")->fetchColumn();
$studentNotifsCount = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE type = 'student'")->fetchColumn();
$pushNotifsCount = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE send_push = 1")->fetchColumn();

// Metrics for Occasions (Tab 2)
$totalOccasionsCount = count($occasions);
$activeOccasionsCount = count(array_filter($occasions, fn($o) => (int)$o['is_active'] === 1));
$disabledOccasionsCount = count(array_filter($occasions, fn($o) => (int)$o['is_active'] === 0));

include_once '../includes/admin_header.php';
define('EDUCORE_NOTIFICATIONS_PAGE', true);
require_once dirname(__DIR__) . '/includes/admin_notifications_content.php';
require_once dirname(__DIR__) . '/includes/admin_notifications_scripts.php';
include_once dirname(__DIR__) . '/includes/admin_footer.php';
