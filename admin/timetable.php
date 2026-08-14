<?php
/**
 * إدارة الجدول المدرسي - Timetable Management
 * Compatible with ASC Timetables XML import
 */
require_once '../includes/session_config.php';
$page_title = "الجدول المدرسي";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/SchemaReadinessGuard.php';
require_once '../classes/FileUploadGuard.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

(new SchemaReadinessGuard($db))->assertColumns('classes', ['timetable_image']);

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Define active tab
$active_tab = $_GET['tab'] ?? 'image';
if (!in_array($active_tab, ['image', 'asc'])) {
    $active_tab = 'image';
}

// ==================== Timetable Image Upload & Delete Handlers ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $class_id = intval($_POST['class_id'] ?? 0);
    
    if ($_POST['action'] === 'upload_image' && $class_id > 0) {
        if (isset($_FILES['timetable_image']) && $_FILES['timetable_image']['error'] === UPLOAD_ERR_OK) {
            try {
                $validatedFile = FileUploadGuard::validate($_FILES['timetable_image'], [
                    'jpg' => ['image/jpeg'],
                    'jpeg' => ['image/jpeg'],
                    'png' => ['image/png'],
                    'gif' => ['image/gif'],
                ], 5 * 1024 * 1024);
            } catch (InvalidArgumentException $e) {
                $_SESSION['error_message'] = $e->getMessage();
                $validatedFile = null;
            }

            if ($validatedFile !== null) {
                // Ensure target directory exists
                $upload_dir = '../uploads/timetables';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Fetch current image to delete it if exists
                $stmt = $db->prepare("SELECT timetable_image FROM classes WHERE id = ?");
                $stmt->execute([$class_id]);
                $old_image = $stmt->fetchColumn();
                $new_filename = FileUploadGuard::randomFileName('timetable_class_' . $class_id, $validatedFile['extension']);
                $dest_path = $upload_dir . '/' . $new_filename;
                $db_path = 'uploads/timetables/' . $new_filename;
                
                if (move_uploaded_file($validatedFile['tmp_name'], $dest_path)) {
                    try {
                        $stmt = $db->prepare("UPDATE classes SET timetable_image = ? WHERE id = ?");
                        $stmt->execute([$db_path, $class_id]);
                    } catch (Throwable $e) {
                        @unlink($dest_path);
                        throw $e;
                    }
                    if ($old_image) {
                        @unlink(dirname(__DIR__) . '/' . ltrim((string)$old_image, '/\\'));
                    }
                    
                    ActivityLog::logUpdate('classes', $class_id, 'رفع صورة الجدول المدرسي لفصل', ['timetable_image' => $db_path]);
                    $_SESSION['success_message'] = "تم رفع صورة الجدول بنجاح.";
                } else {
                    $_SESSION['error_message'] = "حدث خطأ أثناء حفظ الملف المرفوع.";
                }
            }
        } else {
            $_SESSION['error_message'] = "يرجى اختيار ملف صورة صالح للرفع.";
        }
        header("Location: timetable.php?tab=image&class_id=" . $class_id);
        exit();
    }
    
    if ($_POST['action'] === 'delete_image' && $class_id > 0) {
        $stmt = $db->prepare("SELECT timetable_image FROM classes WHERE id = ?");
        $stmt->execute([$class_id]);
        $image_path = $stmt->fetchColumn();
        
        $stmt = $db->prepare("UPDATE classes SET timetable_image = NULL WHERE id = ?");
        $stmt->execute([$class_id]);
        if ($image_path) {
            @unlink(dirname(__DIR__) . '/' . ltrim((string)$image_path, '/\\'));
        }
        
        ActivityLog::logUpdate('classes', $class_id, 'حذف صورة الجدول المدرسي لفصل', ['timetable_image' => null]);
        $_SESSION['success_message'] = "تم حذف صورة الجدول المدرسي بنجاح.";
        header("Location: timetable.php?tab=image&class_id=" . $class_id);
        exit();
    }
}

$day_names = [
    1 => 'الأحد',
    2 => 'الاثنين',
    3 => 'الثلاثاء',
    4 => 'الأربعاء',
    5 => 'الخميس'
];

// ==================== AJAX Endpoints ====================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    // Get timetable for a class
    if ($_GET['ajax'] === 'get_timetable') {
        $class_id = intval($_GET['class_id'] ?? 0);
        
        $stmt = $db->prepare("
            SELECT te.*, tp.period_name, tp.start_time, tp.end_time, tp.is_break, tp.period_number,
                   s.name as subject_name, u.name as teacher_name
            FROM timetable_entries te
            JOIN timetable_periods tp ON te.period_id = tp.id
            LEFT JOIN subjects s ON te.subject_id = s.id
            LEFT JOIN users u ON te.teacher_id = u.id
            WHERE te.class_id = ?
            ORDER BY te.day_of_week, tp.sort_order
        ");
        $stmt->execute([$class_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    // Save single entry
    if ($_GET['ajax'] === 'save_entry') {
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $stmt = $db->prepare("
                INSERT INTO timetable_entries (class_id, subject_id, teacher_id, period_id, day_of_week, room)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE subject_id = VALUES(subject_id), teacher_id = VALUES(teacher_id), room = VALUES(room)
            ");
            $stmt->execute([
                $data['class_id'], 
                $data['subject_id'] ?: null, 
                $data['teacher_id'] ?: null, 
                $data['period_id'], 
                $data['day_of_week'],
                $data['room'] ?? null
            ]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Delete entry
    if ($_GET['ajax'] === 'delete_entry') {
        $id = intval($_GET['id'] ?? 0);
        $db->prepare("DELETE FROM timetable_entries WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Save manual ASC mapping
    if ($_GET['ajax'] === 'save_mapping') {
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $stmt = $db->prepare("UPDATE timetable_asc_mapping SET local_id = ? WHERE id = ?");
            $stmt->execute([$data['local_id'] ?: null, intval($data['mapping_id'])]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Re-process ASC cards using updated mappings (all saved files)
    if ($_GET['ajax'] === 'reprocess_cards') {
        try {
            // Find all saved XML files
            $asc_dir = sys_get_temp_dir() . '/educore_asc_files';
            $xml_files = [];
            
            // Check new multi-file directory
            if (is_dir($asc_dir)) {
                $xml_files = glob($asc_dir . '/*.xml');
            }
            
            // Also check legacy single file
            $legacy_path = sys_get_temp_dir() . '/educore_asc_import.xml';
            if (empty($xml_files) && file_exists($legacy_path)) {
                $xml_files = [$legacy_path];
            }
            
            if (empty($xml_files)) {
                echo json_encode(['success' => false, 'message' => 'لم يتم العثور على ملفات ASC محفوظة. يرجى إعادة رفع الملفات.']);
                exit;
            }
            
            $db->beginTransaction();
            $entries_count = 0;
            $files_processed = 0;
            $warnings = [];
            
            $asc_day_map = [
                '10000' => 1, '01000' => 2, '00100' => 3, '00010' => 4, '00001' => 5,
                '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5
            ];
            
            // Clear existing entries before re-import
            $db->exec("DELETE FROM timetable_entries");
            
            $get_map = $db->prepare("SELECT local_id FROM timetable_asc_mapping WHERE asc_type = ? AND asc_id = ? LIMIT 1");
            
            foreach ($xml_files as $xml_path) {
                $xml = simplexml_load_file($xml_path);
                if ($xml === false) {
                    $warnings[] = 'خطأ في قراءة: ' . basename($xml_path);
                    continue;
                }
                
                $files_processed++;
                
                if (isset($xml->cards->card)) {
                    foreach ($xml->cards->card as $card) {
                        $class_asc = (string)($card['classids'] ?? $card['classid'] ?? '');
                        $subject_asc = (string)($card['subjectid'] ?? '');
                        $teacher_asc = (string)($card['teacherids'] ?? $card['teacherid'] ?? '');
                        $period_asc = (string)($card['period'] ?? '');
                        $days = (string)($card['days'] ?? $card['day'] ?? '');
                        $room = (string)($card['classroomids'] ?? $card['room'] ?? '');
                        
                        $get_map->execute(['class', $class_asc]);
                        $local_class = $get_map->fetchColumn();
                        
                        $get_map->execute(['subject', $subject_asc]);
                        $local_subject = $get_map->fetchColumn();
                        
                        $get_map->execute(['teacher', $teacher_asc]);
                        $local_teacher = $get_map->fetchColumn();
                        
                        $get_map->execute(['period', $period_asc]);
                        $local_period = $get_map->fetchColumn();
                        
                        $day = $asc_day_map[$days] ?? null;
                        if (!$day && is_numeric($days)) $day = intval($days);
                        
                        if ($local_class && $local_period && $day) {
                            $ins = $db->prepare("
                                INSERT INTO timetable_entries (class_id, subject_id, teacher_id, period_id, day_of_week, room)
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE subject_id = VALUES(subject_id), teacher_id = VALUES(teacher_id), room = VALUES(room)
                            ");
                            $ins->execute([$local_class, $local_subject ?: null, $local_teacher ?: null, $local_period, $day, $room ?: null]);
                            $entries_count++;
                        } else {
                            if (!$local_class) $warnings[] = "فصل غير متطابق: {$class_asc}";
                            if (!$local_period) $warnings[] = "حصة غير متطابقة: {$period_asc}";
                        }
                    }
                }
            }
            
            $db->commit();
            
            $unique_warnings = array_unique($warnings);
            echo json_encode([
                'success' => true, 
                'entries' => $entries_count,
                'files' => $files_processed,
                'warnings' => array_slice($unique_warnings, 0, 10)
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    exit;
}

// ==================== ASC XML Import ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_asc'])) {
    try {
        if (!isset($_FILES['asc_files']) || !is_array($_FILES['asc_files']['name'])) {
            throw new Exception('خطأ في رفع الملفات');
        }
        
        // Check at least one valid file
        $valid_files = [];
        foreach ($_FILES['asc_files']['error'] as $i => $err) {
            if ($err === UPLOAD_ERR_OK) {
                $valid_files[] = $i;
            }
        }
        if (empty($valid_files)) {
            throw new Exception('لم يتم رفع أي ملف صالح');
        }
        
        $import_mode = $_POST['import_mode'] ?? 'merge';
        $asc_dir = sys_get_temp_dir() . '/educore_asc_files';
        
        // If replace mode: clear saved files and timetable entries
        if ($import_mode === 'replace') {
            if (is_dir($asc_dir)) {
                array_map('unlink', glob($asc_dir . '/*.xml'));
            }
        }
        
        // Ensure directory exists
        if (!is_dir($asc_dir)) {
            mkdir($asc_dir, 0777, true);
        }
        
        $db->beginTransaction();
        
        // Clear entries if replace mode
        if ($import_mode === 'replace') {
            $db->exec("DELETE FROM timetable_entries");
            $db->exec("DELETE FROM timetable_asc_mapping");
        }
        
        $imported = ['classes' => 0, 'subjects' => 0, 'teachers' => 0, 'periods' => 0, 'entries' => 0, 'files' => 0];
        $warnings = [];
        
        // Process each uploaded file
        foreach ($valid_files as $file_idx) {
        
        $xml_content = file_get_contents($_FILES['asc_files']['tmp_name'][$file_idx]);
        $xml = simplexml_load_string($xml_content);
        
        if ($xml === false) {
            $fname = $_FILES['asc_files']['name'][$file_idx];
            $warnings[] = "ملف غير صالح: {$fname}";
            continue;
        }
        
        // Save XML for later re-processing
        $save_name = date('Ymd_His') . '_' . $file_idx . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/u', '_', $_FILES['asc_files']['name'][$file_idx]);
        file_put_contents($asc_dir . '/' . $save_name, $xml_content);
        $imported['files']++;
        
        // Parse ASC XML format
        // ASC exports: <timetable>, with <periods>, <subjects>, <teachers>, <classes>, <cards> (the actual timetable entries)
        
        // 1. Parse Periods - create/update with times from XML
        if (isset($xml->periods->period)) {
            $period_order = 0;
            foreach ($xml->periods->period as $period) {
                $asc_id = (string)$period['name'];
                $short = (string)($period['short'] ?? $asc_id);
                $starttime = (string)($period['starttime'] ?? '');
                $endtime = (string)($period['endtime'] ?? '');
                
                // Format times: ASC uses "7:30" or "07:30" format
                if ($starttime && !preg_match('/^\d{2}:/', $starttime)) $starttime = '0' . $starttime;
                if ($endtime && !preg_match('/^\d{2}:/', $endtime)) $endtime = '0' . $endtime;
                
                // Detect if this is a break period
                $is_break = 0;
                $name_lower = mb_strtolower($short);
                if (preg_match('/break|فسح|استراح|راحة|فطور/iu', $short)) {
                    $is_break = 1;
                }
                
                $period_order++;
                
                // Try to match with existing period by name or number
                $match = $db->prepare("SELECT id FROM timetable_periods WHERE period_name LIKE ? OR period_number = ? LIMIT 1");
                $match->execute(['%' . $short . '%', $period_order]);
                $local_id = $match->fetchColumn();
                
                if ($local_id) {
                    // Update existing period times from XML
                    if ($starttime && $endtime) {
                        $upd = $db->prepare("UPDATE timetable_periods SET start_time = ?, end_time = ?, is_break = ?, sort_order = ? WHERE id = ?");
                        $upd->execute([$starttime, $endtime, $is_break, $period_order, $local_id]);
                    }
                } else {
                    // Create new period from XML data
                    if ($starttime && $endtime) {
                        $ins = $db->prepare("INSERT INTO timetable_periods (period_number, period_name, start_time, end_time, is_break, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                        $ins->execute([$period_order, $short, $starttime, $endtime, $is_break, $period_order]);
                        $local_id = $db->lastInsertId();
                    }
                }
                
                // Save ASC mapping
                $map_stmt = $db->prepare("INSERT INTO timetable_asc_mapping (asc_type, asc_id, asc_name, local_id) VALUES ('period', ?, ?, ?) ON DUPLICATE KEY UPDATE asc_name = VALUES(asc_name), local_id = VALUES(local_id)");
                $map_stmt->execute([$asc_id, $short . ($starttime ? " ({$starttime}-{$endtime})" : ''), $local_id ?: null]);
                $imported['periods']++;
            }
        }
        
        // 2. Parse Subjects
        if (isset($xml->subjects->subject)) {
            foreach ($xml->subjects->subject as $subject) {
                $asc_id = (string)$subject['id'];
                $name = (string)($subject['name'] ?? $subject['short'] ?? $asc_id);
                
                // Try to match with existing subject
                $match = $db->prepare("SELECT id FROM subjects WHERE name LIKE ? OR code LIKE ? LIMIT 1");
                $match->execute(['%' . $name . '%', '%' . (string)($subject['short'] ?? '') . '%']);
                $local_id = $match->fetchColumn();
                
                $map_stmt = $db->prepare("INSERT INTO timetable_asc_mapping (asc_type, asc_id, asc_name, local_id) VALUES ('subject', ?, ?, ?) ON DUPLICATE KEY UPDATE asc_name = VALUES(asc_name), local_id = VALUES(local_id)");
                $map_stmt->execute([$asc_id, $name, $local_id ?: null]);
                $imported['subjects']++;
            }
        }
        
        // 3. Parse Teachers
        if (isset($xml->teachers->teacher)) {
            foreach ($xml->teachers->teacher as $teacher) {
                $asc_id = (string)$teacher['id'];
                $name = (string)($teacher['name'] ?? $teacher['short'] ?? $asc_id);
                
                // Try to match with existing teacher
                $match = $db->prepare("SELECT u.id FROM users u
                    WHERE u.name LIKE ?
                      AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active')
                    LIMIT 1");
                $match->execute(['%' . $name . '%']);
                $local_id = $match->fetchColumn();
                
                $map_stmt = $db->prepare("INSERT INTO timetable_asc_mapping (asc_type, asc_id, asc_name, local_id) VALUES ('teacher', ?, ?, ?) ON DUPLICATE KEY UPDATE asc_name = VALUES(asc_name), local_id = VALUES(local_id)");
                $map_stmt->execute([$asc_id, $name, $local_id ?: null]);
                $imported['teachers']++;
            }
        }
        
        // 4. Parse Classes
        if (isset($xml->classes->class)) {
            foreach ($xml->classes->class as $class) {
                $asc_id = (string)$class['id'];
                $name = (string)($class['name'] ?? $class['short'] ?? $asc_id);
                
                // Try to match with existing class
                $match = $db->prepare("SELECT id FROM classes WHERE name LIKE ? LIMIT 1");
                $match->execute(['%' . $name . '%']);
                $local_id = $match->fetchColumn();
                
                $map_stmt = $db->prepare("INSERT INTO timetable_asc_mapping (asc_type, asc_id, asc_name, local_id) VALUES ('class', ?, ?, ?) ON DUPLICATE KEY UPDATE asc_name = VALUES(asc_name), local_id = VALUES(local_id)");
                $map_stmt->execute([$asc_id, $name, $local_id ?: null]);
                $imported['classes']++;
            }
        }
        
        // 5. Parse Cards (timetable entries)
        if (isset($xml->cards->card)) {
            // Day mapping from ASC (typically: 1=Mon, 2=Tue... or configurable)
            $asc_day_map = [
                '10000' => 1, '01000' => 2, '00100' => 3, '00010' => 4, '00001' => 5,
                '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5
            ];
            
            foreach ($xml->cards->card as $card) {
                $class_asc = (string)($card['classids'] ?? $card['classid'] ?? '');
                $subject_asc = (string)($card['subjectid'] ?? '');
                $teacher_asc = (string)($card['teacherids'] ?? $card['teacherid'] ?? '');
                $period_asc = (string)($card['period'] ?? '');
                $days = (string)($card['days'] ?? $card['day'] ?? '');
                $room = (string)($card['classroomids'] ?? $card['room'] ?? '');
                
                // Resolve local IDs via mapping
                $get_map = $db->prepare("SELECT local_id FROM timetable_asc_mapping WHERE asc_type = ? AND asc_id = ? LIMIT 1");
                
                $get_map->execute(['class', $class_asc]);
                $local_class = $get_map->fetchColumn();
                
                $get_map->execute(['subject', $subject_asc]);
                $local_subject = $get_map->fetchColumn();
                
                $get_map->execute(['teacher', $teacher_asc]);
                $local_teacher = $get_map->fetchColumn();
                
                $get_map->execute(['period', $period_asc]);
                $local_period = $get_map->fetchColumn();
                
                // Determine day
                $day = $asc_day_map[$days] ?? null;
                if (!$day && is_numeric($days)) $day = intval($days);
                
                if ($local_class && $local_period && $day) {
                    $ins = $db->prepare("
                        INSERT INTO timetable_entries (class_id, subject_id, teacher_id, period_id, day_of_week, room)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE subject_id = VALUES(subject_id), teacher_id = VALUES(teacher_id), room = VALUES(room)
                    ");
                    $ins->execute([$local_class, $local_subject ?: null, $local_teacher ?: null, $local_period, $day, $room ?: null]);
                    $imported['entries']++;
                } else {
                    if (!$local_class) $warnings[] = "فصل غير متطابق: {$class_asc}";
                    if (!$local_period) $warnings[] = "حصة غير متطابقة: {$period_asc}";
                }
            }
        }
        
        } // end foreach valid_files
        
        $db->commit();
        
        ActivityLog::logImport('timetable', null, 'استيراد جدول ASC', $imported);
        
        $_SESSION['success_message'] = "تم الاستيراد بنجاح! (وضع: {$mode_label})<br>";
        $_SESSION['success_message'] .= "الملفات: {$imported['files']} | الفصول: {$imported['classes']} | المواد: {$imported['subjects']} | المعلمين: {$imported['teachers']} | الحصص: {$imported['periods']} | الإدخالات: {$imported['entries']}";
        
        if (!empty($warnings)) {
            $unique_warnings = array_unique($warnings);
            $_SESSION['success_message'] .= "<br><small class='text-warning'>تحذيرات: " . implode('، ', array_slice($unique_warnings, 0, 10)) . "</small>";
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error_message'] = "خطأ في الاستيراد: " . $e->getMessage();
    }
    header("Location: timetable.php" . Utilities::buildQueryString(['class_id' => $_GET['class_id'] ?? '']));
    exit();
}

// ==================== Manual Add/Edit/Delete ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_period'])) {
    try {
        if (isset($_POST['period_id']) && $_POST['period_id']) {
            $stmt = $db->prepare("UPDATE timetable_periods SET period_name=?, start_time=?, end_time=?, is_break=? WHERE id=?");
            $stmt->execute([$_POST['period_name'], $_POST['start_time'], $_POST['end_time'], isset($_POST['is_break']) ? 1 : 0, $_POST['period_id']]);
            $success_message = "تم تحديث الحصة بنجاح.";
        } else {
            $max_num = $db->query("SELECT COALESCE(MAX(period_number), 0) + 1 FROM timetable_periods")->fetchColumn();
            $max_ord = $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM timetable_periods")->fetchColumn();
            $stmt = $db->prepare("INSERT INTO timetable_periods (period_number, period_name, start_time, end_time, is_break, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$max_num, $_POST['period_name'], $_POST['start_time'], $_POST['end_time'], isset($_POST['is_break']) ? 1 : 0, $max_ord]);
            $_SESSION['success_message'] = "تم إضافة الحصة بنجاح.";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
    header("Location: timetable.php" . Utilities::buildQueryString(['class_id' => $_GET['class_id'] ?? '']));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_period'])) {
    $db->prepare("DELETE FROM timetable_periods WHERE id = ?")->execute([intval($_POST['period_id'])]);
    $_SESSION['success_message'] = "تم حذف الحصة.";
    header("Location: timetable.php" . Utilities::buildQueryString(['class_id' => $_GET['class_id'] ?? '']));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_timetable'])) {
    $db->exec("DELETE FROM timetable_entries");
    ActivityLog::logReset('timetable', null, 'مسح جميع إدخالات الجدول');
    $_SESSION['success_message'] = "تم مسح جميع إدخالات الجدول.";
    header("Location: timetable.php" . Utilities::buildQueryString(['class_id' => $_GET['class_id'] ?? '']));
    exit();
}

// ==================== Get Data ====================
$periods = $db->query("SELECT * FROM timetable_periods ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$classes_list = $db->query("SELECT c.id, c.name, c.timetable_image, g.grade_name, st.stage_name FROM classes c LEFT JOIN grades g ON c.grade_id = g.id LEFT JOIN stages st ON g.stage_id = st.id WHERE c.status = 'active' ORDER BY st.stage_order, g.grade_order, c.name")->fetchAll(PDO::FETCH_ASSOC);
$subjects_list = $db->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$teachers_list = $db->query("SELECT u.id, u.name FROM users u
    WHERE u.status = 'active'
      AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active')
    ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
$asc_mappings = $db->query("SELECT * FROM timetable_asc_mapping ORDER BY asc_type, asc_name")->fetchAll(PDO::FETCH_ASSOC);

$selected_class = $_GET['class_id'] ?? '';

require_once '../includes/admin_header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 fw-bold text-dark"><i class="far fa-calendar-alt me-3 text-primary"></i>الجدول المدرسي</h1>
            <p class="text-muted m-0">إدارة الجداول الحصصية والصور وتوزيع الحصص للفصول</p>
        </div>
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show"><?php echo $success_message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?php echo $error_message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4" id="timetableTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link fw-semibold <?php echo $active_tab === 'image' ? 'active' : ''; ?>" 
               href="?tab=image&class_id=<?php echo $selected_class; ?>">
                <i class="fas fa-image me-1"></i>جدول الصور (رفع صورة الجدول)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-semibold <?php echo $active_tab === 'asc' ? 'active' : ''; ?>" 
               href="?tab=asc&class_id=<?php echo $selected_class; ?>">
                <i class="fas fa-link me-1"></i>الربط ببيانات ASC المدرجة
            </a>
        </li>
    </ul>

    <?php if ($active_tab === 'asc'): ?>
    <!-- Class Selector (Visible only in ASC tab) -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">
                <div class="col-md-9">
                    <label class="form-label fw-bold text-dark mb-2"><i class="fas fa-school me-2 text-primary"></i>اختر الفصل لعرض أو إدارة الجدول</label>
                    <select name="class_id" class="form-select shadow-sm" onchange="this.form.submit()" style="border-radius: 8px;">
                        <option value="">-- اختر فصلاً --</option>
                        <?php foreach ($classes_list as $cls): ?>
                            <option value="<?php echo $cls['id']; ?>" <?php echo $selected_class == $cls['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($cls['grade_name'] ? $cls['grade_name'] . ' - ' : '') . $cls['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100 py-2 shadow-sm" style="border-radius: 8px;">
                        <i class="fas fa-eye me-2"></i>عرض الجدول
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($active_tab === 'image'): ?>
        <?php if (empty($classes_list)): ?>
            <div class="card shadow-sm">
                <div class="card-body py-5 text-center text-muted">
                    <i class="fas fa-image fa-3x mb-3"></i>
                    <p class="h5">لا توجد فصول نشطة مسجلة في النظام.</p>
                </div>
            </div>
        <?php else:
            // Group classes by stage
            $grouped_classes = [];
            foreach ($classes_list as $cls) {
                $stage_name = $cls['stage_name'] ?? 'عام';
                $grouped_classes[$stage_name][] = $cls;
            }
            
            foreach ($grouped_classes as $stage_name => $classes):
                $stage_id_attr = 'collapse-stage-' . md5($stage_name);
        ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#<?php echo $stage_id_attr; ?>" aria-expanded="true">
                    <h5 class="mb-0"><i class="fas fa-school me-2"></i>مرحلة: <?php echo htmlspecialchars($stage_name); ?></h5>
                    <i class="fas fa-chevron-down collapse-icon"></i>
                </div>
                <div id="<?php echo $stage_id_attr; ?>" class="collapse show">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="text-center">
                                        <th class="text-center" style="width: 25%;">الصف الدراسي</th>
                                        <th class="text-center" style="width: 20%;">الفصل</th>
                                        <th class="text-center" style="width: 20%;">معاينة الجدول</th>
                                        <th class="text-center" style="width: 35%;">إجراءات الرفع والتحكم</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($classes as $cls): 
                                        $grade_label = $cls['grade_name'] ?? 'غير محدد';
                                        $img_path = $cls['timetable_image'];
                                        $has_img = ($img_path && file_exists('../' . $img_path));
                                    ?>
                                        <tr>
                                            <td class="text-center fw-bold text-muted">
                                                <?php echo htmlspecialchars($grade_label); ?>
                                            </td>
                                            <td class="text-center fw-bold text-dark">
                                                <?php echo htmlspecialchars($cls['name']); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($has_img): ?>
                                                    <div class="position-relative d-inline-block">
                                                        <a href="../<?php echo htmlspecialchars($img_path); ?>" target="_blank" title="عرض بالحجم الكامل">
                                                            <img src="../<?php echo htmlspecialchars($img_path); ?>?v=<?php echo time(); ?>" class="img-thumbnail shadow-sm" style="max-height: 80px; max-width: 120px; object-fit: contain; border: 2px solid #0d6efd;" alt="معاينة الجدول">
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small"><i class="fas fa-image me-1 text-secondary"></i>لا يوجد جدول</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column align-items-center gap-2">
                                                    <?php if ($has_img): ?>
                                                        <!-- Replace / Delete Actions -->
                                                        <div class="d-flex gap-2 justify-content-center w-100 flex-wrap">
                                                            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#upload-form-<?php echo $cls['id']; ?>" aria-expanded="false">
                                                                <i class="fas fa-exchange-alt me-1"></i>استبدال الصورة
                                                            </button>
                                                            
                                                            <form method="POST" class="d-inline m-0" data-confirm-message="هل أنت متأكد من حذف صورة الجدول المدرسي لهذا الفصل؟" data-confirm-operation="delete">
                                                                <?php echo csrfField(); ?>
                                                                <input type="hidden" name="action" value="delete_image">
                                                                <input type="hidden" name="class_id" value="<?php echo $cls['id']; ?>">
                                                                <button type="submit" class="btn btn-danger btn-sm">
                                                                    <i class="fas fa-trash-alt me-1"></i>حذف الجدول
                                                                </button>
                                                            </form>
                                                        </div>
                                                        
                                                        <!-- Hidden replace form -->
                                                        <div class="collapse w-100 mt-2" id="upload-form-<?php echo $cls['id']; ?>">
                                                            <form method="POST" enctype="multipart/form-data" class="bg-light p-2 rounded border border-primary">
                                                                <?php echo csrfField(); ?>
                                                                <input type="hidden" name="action" value="upload_image">
                                                                <input type="hidden" name="class_id" value="<?php echo $cls['id']; ?>">
                                                                <div class="input-group input-group-sm">
                                                                    <input type="file" name="timetable_image" class="form-control" accept="image/*" required>
                                                                    <button class="btn btn-primary" type="submit">حفظ الجديد</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    <?php else: ?>
                                                        <!-- Upload Form -->
                                                        <form method="POST" enctype="multipart/form-data" class="w-100 px-3" style="max-width: 320px;">
                                                            <?php echo csrfField(); ?>
                                                            <input type="hidden" name="action" value="upload_image">
                                                            <input type="hidden" name="class_id" value="<?php echo $cls['id']; ?>">
                                                            <div class="input-group input-group-sm shadow-sm">
                                                                <input type="file" name="timetable_image" class="form-control" accept="image/*" required>
                                                                <button class="btn btn-success" type="submit">
                                                                    <i class="fas fa-upload me-1"></i>رفع
                                                                </button>
                                                            </div>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php 
            endforeach;
        endif; 
        ?>

    <?php elseif ($active_tab === 'asc'): ?>
        <!-- Tab 2: ASC Integration Toolbars & Grid -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="mb-0 text-dark fw-bold"><i class="fas fa-link me-2 text-primary"></i>الجدول التفاعلي المرتبط ببيانات ASC</h5>
            <div>
                <button class="btn btn-success btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-import me-1"></i>استيراد ASC
                </button>
                <button class="btn btn-secondary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#periodsModal">
                    <i class="fas fa-clock me-1"></i>إدارة الحصص
                </button>
                <button class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#mappingModal">
                    <i class="fas fa-link me-1"></i>تعيينات ASC
                </button>
            </div>
        </div>

        <?php if (!$selected_class): ?>
            <div class="card shadow-sm">
                <div class="card-body py-5 text-center text-muted">
                    <i class="fas fa-calendar-alt fa-3x mb-3"></i>
                    <p class="h5">يرجى اختيار الفصل الدراسي لعرض وتعديل الجدول التفاعلي</p>
                </div>
            </div>
        <?php else: ?>
            <!-- Timetable Grid -->
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-th me-2"></i>الجدول الأسبوعي التفاعلي</h5>
                    <button class="btn btn-light btn-sm fw-bold" onclick="exportTimetableToCSV()">
                        <i class="fas fa-file-csv me-1"></i>تصدير CSV
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0" id="timetableGrid">
                            <thead>
                                <tr class="table-primary text-center">
                                    <th style="width: 120px;">الحصة / الوقت</th>
                                    <?php foreach ($day_names as $dn => $name): ?>
                                        <th><?php echo $name; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Get entries for selected class
                                $entries_stmt = $db->prepare("
                                    SELECT te.*, tp.period_name, tp.start_time, tp.end_time, tp.is_break,
                                           s.name as subject_name, u.name as teacher_name
                                    FROM timetable_entries te
                                    JOIN timetable_periods tp ON te.period_id = tp.id
                                    LEFT JOIN subjects s ON te.subject_id = s.id
                                    LEFT JOIN users u ON te.teacher_id = u.id
                                    WHERE te.class_id = ?
                                    ORDER BY tp.sort_order, te.day_of_week
                                ");
                                $entries_stmt->execute([$selected_class]);
                                $entries = [];
                                while ($e = $entries_stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $entries[$e['period_id']][$e['day_of_week']] = $e;
                                }
                                
                                foreach ($periods as $period):
                                ?>
                                <tr class="<?php echo $period['is_break'] ? 'table-warning' : ''; ?>">
                                    <td class="text-center small fw-bold<?php echo $period['is_break'] ? ' text-warning' : ''; ?>">
                                        <?php echo htmlspecialchars($period['period_name']); ?>
                                        <br><small class="text-muted"><?php echo substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5); ?></small>
                                    </td>
                                    <?php foreach ($day_names as $day_num => $day_name): 
                                        $entry = $entries[$period['id']][$day_num] ?? null;
                                    ?>
                                    <td class="text-center p-1" style="min-width: 130px; cursor: pointer;" 
                                        onclick="editCell(<?php echo $selected_class; ?>, <?php echo $period['id']; ?>, <?php echo $day_num; ?>, this)"
                                        data-entry-id="<?php echo $entry ? $entry['id'] : ''; ?>"
                                        data-subject-id="<?php echo $entry ? $entry['subject_id'] : ''; ?>"
                                        data-teacher-id="<?php echo $entry ? $entry['teacher_id'] : ''; ?>">
                                        <?php if ($period['is_break']): ?>
                                            <span class="text-warning"><i class="fas fa-coffee"></i></span>
                                        <?php elseif ($entry): ?>
                                            <div class="small">
                                                <strong class="text-primary"><?php echo htmlspecialchars($entry['subject_name'] ?? '---'); ?></strong>
                                                <?php if ($entry['teacher_name']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($entry['teacher_name']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($entry['room']): ?>
                                                    <br><small class="text-info"><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($entry['room']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">+</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
<form method="POST" enctype="multipart/form-data">
    <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد من ASC Timetables</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i>
                        قم بتصدير ملفات XML من برنامج ASC Timetables ثم ارفعها هنا.
                        <br>يمكنك رفع <strong>عدة ملفات</strong> في نفس الوقت (مثلاً: ملف لكل مرحلة دراسية).
                        <br>سيتم محاولة مطابقة الفصول والمواد والمعلمين تلقائياً.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-file-code me-1"></i>ملفات XML من ASC Timetables</label>
                        <input type="file" name="asc_files[]" class="form-control" accept=".xml" multiple required>
                        <div class="form-text">يمكنك اختيار عدة ملفات بالضغط على Ctrl أثناء الاختيار</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-cog me-1"></i>طريقة الاستيراد</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="import_mode" id="modeMerge" value="merge" checked>
                            <label class="form-check-label" for="modeMerge">
                                <strong>دمج (إضافة)</strong> — يُضاف الجدول الجديد مع الإبقاء على الإدخالات الحالية
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="import_mode" id="modeReplace" value="replace">
                            <label class="form-check-label" for="modeReplace">
                                <strong>استبدال</strong> — يُمسح الجدول الحالي بالكامل ثم يُستورد الجديد
                            </label>
                        </div>
                    </div>
                    <?php
                    // Show previously imported files
                    $asc_dir = sys_get_temp_dir() . '/educore_asc_files';
                    if (is_dir($asc_dir)) {
                        $saved_files = glob($asc_dir . '/*.xml');
                        if (!empty($saved_files)): ?>
                    <div class="alert alert-secondary small mb-0">
                        <i class="fas fa-database me-1"></i>
                        <strong>الملفات المحفوظة سابقاً (<?php echo count($saved_files); ?>):</strong>
                        <ul class="mb-0 mt-1">
                            <?php foreach ($saved_files as $sf): ?>
                            <li><?php echo htmlspecialchars(basename($sf)); ?> <small class="text-muted">(<?php echo date('Y/m/d H:i', filemtime($sf)); ?>)</small></li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="mt-2">
                            <small>عند اختيار "استبدال" سيتم مسح هذه الملفات واستبدالها بالملفات الجديدة</small>
                        </div>
                    </div>
                        <?php endif;
                    } ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="import_asc" class="btn btn-success"><i class="fas fa-upload me-1"></i>استيراد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Periods Modal -->
<div class="modal fade" id="periodsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clock me-2"></i>إدارة الحصص</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm">
                    <thead>
                        <tr><th>#</th><th>الاسم</th><th>من</th><th>إلى</th><th>استراحة</th><th>إجراء</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periods as $p): ?>
                        <tr>
                            <td><?php echo $p['period_number']; ?></td>
                            <td><?php echo htmlspecialchars($p['period_name']); ?></td>
                            <td><?php echo substr($p['start_time'], 0, 5); ?></td>
                            <td><?php echo substr($p['end_time'], 0, 5); ?></td>
                            <td><?php echo $p['is_break'] ? '<span class="badge bg-warning">نعم</span>' : '-'; ?></td>
                            <td>
<form method="POST" class="d-inline" data-confirm-message="حذف هذه الحصة؟" data-confirm-operation="delete">
    <?php echo csrfField(); ?>
                                    <input type="hidden" name="period_id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" name="delete_period" class="btn btn-sm btn-danger" data-bs-toggle="tooltip" title="حذف">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <hr>
<form method="POST" class="row g-2">
    <?php echo csrfField(); ?>
                    <div class="col-md-3">
                        <input type="text" name="period_name" class="form-control form-control-sm" placeholder="اسم الحصة" required>
                    </div>
                    <div class="col-md-2">
                        <input type="time" name="start_time" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-2">
                        <input type="time" name="end_time" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check mt-1">
                            <input type="checkbox" name="is_break" class="form-check-input" id="newBreak">
                            <label class="form-check-label" for="newBreak">استراحة</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" name="save_period" class="btn btn-success btn-sm w-100"><i class="fas fa-plus me-1"></i>إضافة حصة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Mapping Modal -->
<div class="modal fade" id="mappingModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-link me-2"></i>تعيينات ASC Timetables</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($asc_mappings)): ?>
                    <p class="text-muted text-center">لا توجد تعيينات بعد. قم باستيراد ملف ASC أولاً.</p>
                <?php else: ?>
                    <div class="alert alert-info small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        إذا لم تتطابق الأسماء تلقائياً، اختر العنصر الصحيح من القائمة المنسدلة ثم اضغط <strong>"إعادة معالجة الجدول"</strong> لتحديث الإدخالات.
                    </div>

                    <?php
                    // Group mappings by type
                    $grouped_mappings = [];
                    foreach ($asc_mappings as $map) {
                        $grouped_mappings[$map['asc_type']][] = $map;
                    }
                    $type_labels = ['class' => 'الفصول', 'subject' => 'المواد', 'teacher' => 'المعلمين', 'period' => 'الحصص'];
                    $type_icons = ['class' => 'school', 'subject' => 'book', 'teacher' => 'chalkboard-teacher', 'period' => 'clock'];
                    $type_colors = ['class' => 'primary', 'subject' => 'success', 'teacher' => 'warning', 'period' => 'secondary'];
                    ?>

                    <?php foreach (['period', 'class', 'subject', 'teacher'] as $type): ?>
                    <?php if (!empty($grouped_mappings[$type])): ?>
                    <div class="mb-4">
                        <h6 class="fw-bold text-<?php echo $type_colors[$type]; ?> mb-2">
                            <i class="fas fa-<?php echo $type_icons[$type]; ?> me-1"></i>
                            <?php echo $type_labels[$type]; ?>
                            <?php
                            $unmatched = count(array_filter($grouped_mappings[$type], fn($m) => !$m['local_id']));
                            if ($unmatched > 0): ?>
                                <span class="badge bg-danger ms-2"><?php echo $unmatched; ?> غير متطابق</span>
                            <?php endif; ?>
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:30%;">اسم ASC</th>
                                        <th style="width:15%;">معرف ASC</th>
                                        <th style="width:15%;">الحالة</th>
                                        <th style="width:40%;">تعيين يدوي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grouped_mappings[$type] as $map): ?>
                                    <tr id="mapping-row-<?php echo $map['id']; ?>">
                                        <td class="fw-bold"><?php echo htmlspecialchars($map['asc_name']); ?></td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($map['asc_id']); ?></td>
                                        <td>
                                            <span id="status-<?php echo $map['id']; ?>" class="badge bg-<?php echo $map['local_id'] ? 'success' : 'danger'; ?>">
                                                <?php if ($map['local_id']): ?>
                                                    <i class="fas fa-check me-1"></i>متطابق
                                                <?php else: ?>
                                                    <i class="fas fa-times me-1"></i>غير متطابق
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm mapping-select" 
                                                    data-mapping-id="<?php echo $map['id']; ?>"
                                                    data-type="<?php echo $map['asc_type']; ?>"
                                                    onchange="saveMapping(this)">
                                                <option value="">-- اختر --</option>
                                                <?php if ($type === 'class'): ?>
                                                    <?php foreach ($classes_list as $cls): ?>
                                                        <option value="<?php echo $cls['id']; ?>" <?php echo $map['local_id'] == $cls['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars(($cls['grade_name'] ? $cls['grade_name'] . ' - ' : '') . $cls['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php elseif ($type === 'subject'): ?>
                                                    <?php foreach ($subjects_list as $sub): ?>
                                                        <option value="<?php echo $sub['id']; ?>" <?php echo $map['local_id'] == $sub['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($sub['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php elseif ($type === 'teacher'): ?>
                                                    <?php foreach ($teachers_list as $t): ?>
                                                        <option value="<?php echo $t['id']; ?>" <?php echo $map['local_id'] == $t['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($t['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php elseif ($type === 'period'): ?>
                                                    <?php foreach ($periods as $p): ?>
                                                        <option value="<?php echo $p['id']; ?>" <?php echo $map['local_id'] == $p['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($p['period_name'] . ' (' . substr($p['start_time'],0,5) . '-' . substr($p['end_time'],0,5) . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>

                <?php endif; ?>
            </div>
            <?php if (!empty($asc_mappings)): ?>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-primary" onclick="reprocessCards()">
                    <i class="fas fa-sync-alt me-1"></i>إعادة معالجة الجدول
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Cell Modal -->
<div class="modal fade" id="editCellModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل الحصة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editClassId">
                <input type="hidden" id="editPeriodId">
                <input type="hidden" id="editDayOfWeek">
                <input type="hidden" id="editEntryId">
                
                <div class="mb-3">
                    <label class="form-label">المادة</label>
                    <select id="editSubject" class="form-select">
                        <option value="">-- بدون مادة --</option>
                        <?php foreach ($subjects_list as $sub): ?>
                            <option value="<?php echo $sub['id']; ?>"><?php echo htmlspecialchars($sub['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">المعلم</label>
                    <select id="editTeacher" class="form-select">
                        <option value="">-- بدون معلم --</option>
                        <?php foreach ($teachers_list as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">القاعة</label>
                    <input type="text" id="editRoom" class="form-control" placeholder="رقم القاعة (اختياري)">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="deleteCellBtn" style="display:none;" onclick="deleteEntry()">
                    <i class="fas fa-trash me-1"></i>حذف
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary" onclick="saveEntry()"><i class="fas fa-save me-1"></i>حفظ</button>
            </div>
        </div>
    </div>
</div>

<script>
function editCell(classId, periodId, dayOfWeek, cell) {
    document.getElementById('editClassId').value = classId;
    document.getElementById('editPeriodId').value = periodId;
    document.getElementById('editDayOfWeek').value = dayOfWeek;
    
    const entryId = cell.dataset.entryId;
    document.getElementById('editEntryId').value = entryId;
    document.getElementById('editSubject').value = cell.dataset.subjectId || '';
    document.getElementById('editTeacher').value = cell.dataset.teacherId || '';
    document.getElementById('editRoom').value = '';
    
    document.getElementById('deleteCellBtn').style.display = entryId ? 'inline-block' : 'none';
    
    new bootstrap.Modal(document.getElementById('editCellModal')).show();
}

function saveEntry() {
    const data = {
        class_id: document.getElementById('editClassId').value,
        period_id: document.getElementById('editPeriodId').value,
        day_of_week: document.getElementById('editDayOfWeek').value,
        subject_id: document.getElementById('editSubject').value,
        teacher_id: document.getElementById('editTeacher').value,
        room: document.getElementById('editRoom').value
    };
    
    fetch('timetable.php?ajax=save_entry', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            location.reload();
        } else {
            alert(d.message || 'حدث خطأ');
        }
    });
}

async function deleteEntry() {
    const entryId = document.getElementById('editEntryId').value;
    if (!entryId) return;
    const approved = await window.adminConfirm('حذف هذه الحصة؟', { operation: 'delete' });
    if (!approved) return;
    
    fetch('timetable.php?ajax=delete_entry&id=' + entryId)
    .then(r => r.json())
    .then(d => {
        if (d.success) location.reload();
    });
}

// Save manual ASC mapping
function saveMapping(selectEl) {
    const mappingId = selectEl.dataset.mappingId;
    const localId = selectEl.value;
    const statusBadge = document.getElementById('status-' + mappingId);
    
    fetch('timetable.php?ajax=save_mapping', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ mapping_id: mappingId, local_id: localId })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            if (localId) {
                statusBadge.className = 'badge bg-success';
                statusBadge.innerHTML = '<i class="fas fa-check me-1"></i>متطابق';
                selectEl.closest('tr').style.background = 'rgba(25,135,84,0.05)';
            } else {
                statusBadge.className = 'badge bg-danger';
                statusBadge.innerHTML = '<i class="fas fa-times me-1"></i>غير متطابق';
                selectEl.closest('tr').style.background = '';
            }
        } else {
            alert(d.message || 'حدث خطأ في الحفظ');
        }
    })
    .catch(err => alert('خطأ في الاتصال'));
}

// Re-process ASC timetable cards with updated mappings
async function reprocessCards() {
    const approved = await window.adminConfirm('سيتم إعادة معالجة جميع إدخالات الجدول بناءً على التعيينات الحالية. سيتم حذف الإدخالات القديمة واستبدالها. هل تريد المتابعة؟');
    if (!approved) return;
    
    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>جاري المعالجة...';
    
    fetch('timetable.php?ajax=reprocess_cards')
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            let msg = 'تمت إعادة المعالجة بنجاح!\nالملفات: ' + (d.files || 1) + ' | الإدخالات: ' + d.entries;
            if (d.warnings && d.warnings.length > 0) {
                msg += '\n\nتحذيرات:\n' + d.warnings.join('\n');
            }
            alert(msg);
            location.reload();
        } else {
            alert(d.message || 'حدث خطأ في المعالجة');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>إعادة معالجة الجدول';
        }
    })
    .catch(err => {
        alert('خطأ في الاتصال بالخادم');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>إعادة معالجة الجدول';
    });
}
</script>

<style>
.collapse-icon {
    transition: transform 0.3s ease;
}
.card-header[aria-expanded="false"] .collapse-icon {
    transform: rotate(180deg);
}
/* Admin timetable mobile responsive */
@media (max-width: 768px) {
    .container-fluid h1.h2 { font-size: 1.2rem; }
    .container-fluid .d-flex.justify-content-between { flex-direction: column; gap: 10px; }
    .container-fluid .d-flex.justify-content-between > div:last-child { display: flex; flex-wrap: wrap; gap: 5px; }
    #timetableGrid th { font-size: 0.75rem; padding: 6px 2px; }
    #timetableGrid td { font-size: 0.7rem; padding: 3px 2px; min-width: 85px !important; }
}
</style>

<?php require_once '../includes/admin_footer.php'; ?>
