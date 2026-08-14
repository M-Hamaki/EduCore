<?php

require_once __DIR__ . '/includes/import_helpers.php';
require_once __DIR__ . '/../classes/ActivityLog.php';

$redirectUrl = 'stages.php';
$db = adminImportBootstrap();
validateImportRequest($redirectUrl);

$availableServices = ['rewards', 'reports', 'materials', 'ebooks', 'results', 'timetable', 'activities'];
$availableTeacherServices = ['rewards', 'lesson_prep', 'grade_system', 'attendance', 'timetable', 'training', 'activities', 'ai_chat'];

try {
    $rows = getImportedRows();
    if (empty($rows)) {
        $_SESSION['error_message'] = 'الملف لا يحتوي على بيانات قابلة للاستيراد.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    $existingNames = array_flip(array_map(static function ($value) {
        return normalizeImportHeader((string)$value);
    }, $db->query("SELECT stage_name FROM stages")->fetchAll(PDO::FETCH_COLUMN)));

    $stmt = $db->prepare("INSERT INTO stages (stage_name, stage_name_en, stage_code, stage_order, services, teacher_services, new_badges, teacher_new_badges, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $imported = 0;
    $errors = [];

    $db->beginTransaction();
    foreach ($rows as $row) {
        $name = importValue($row, ['stage_name', 'name', 'اسم المرحلة', 'المرحلة']);
        $nameEn = importValue($row, ['stage_name_en', 'name_en', 'english_name', 'الاسم بالإنجليزية']);
        $code = importValue($row, ['stage_code', 'code', 'الكود']);
        $order = (int)importValue($row, ['stage_order', 'order', 'الترتيب'], '0');
        $status = importValue($row, ['status', 'الحالة'], 'active');
        $studentServices = array_values(array_intersect(importList(importValue($row, ['services', 'student_services', 'خدمات الطلاب'])), $availableServices));
        $teacherServices = array_values(array_intersect(importList(importValue($row, ['teacher_services', 'خدمات المعلمين'])), $availableTeacherServices));

        if ($name === '') {
            $errors[] = 'السطر ' . $row['_row_number'] . ': اسم المرحلة مطلوب.';
            continue;
        }

        $normalizedName = normalizeImportHeader($name);
        if (isset($existingNames[$normalizedName])) {
            $errors[] = 'السطر ' . $row['_row_number'] . ': المرحلة ' . $name . ' موجودة بالفعل.';
            continue;
        }

        $stmt->execute([
            $name,
            $nameEn === '' ? null : $nameEn,
            $code === '' ? null : $code,
            $order,
            json_encode($studentServices, JSON_UNESCAPED_UNICODE),
            json_encode($teacherServices, JSON_UNESCAPED_UNICODE),
            json_encode([], JSON_UNESCAPED_UNICODE),
            json_encode([], JSON_UNESCAPED_UNICODE),
            normalizeImportHeader($status) === 'inactive' ? 'inactive' : 'active'
        ]);
        $existingNames[$normalizedName] = true;
        $imported++;
    }
    $db->commit();

    if ($imported > 0) {
        $_SESSION['success_message'] = "تم استيراد {$imported} مرحلة بنجاح.";
        if (!empty($errors)) {
            $_SESSION['success_message'] .= '<br><small>' . htmlspecialchars(implode(' | ', $errors), ENT_QUOTES, 'UTF-8') . '</small>';
        }
        ActivityLog::logImport('stage', $imported, ['errors' => count($errors)]);
    } else {
        $_SESSION['error_message'] = implode(' | ', $errors) ?: 'لم يتم استيراد أي مرحلة.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error_message'] = 'فشل استيراد المراحل: ' . $e->getMessage();
}

header('Location: ' . $redirectUrl);
exit();
