<?php

require_once __DIR__ . '/includes/import_helpers.php';
require_once __DIR__ . '/../classes/ActivityLog.php';

$redirectUrl = 'classes.php';
$db = adminImportBootstrap();
validateImportRequest($redirectUrl);

try {
    $rows = getImportedRows();
    if (empty($rows)) {
        $_SESSION['error_message'] = 'الملف لا يحتوي على بيانات قابلة للاستيراد.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    $grades = $db->query("SELECT id, grade_name FROM grades")->fetchAll(PDO::FETCH_ASSOC);
    $gradeLookup = [];
    foreach ($grades as $grade) {
        $gradeLookup[normalizeImportHeader((string)$grade['id'])] = (int)$grade['id'];
        $gradeLookup[normalizeImportHeader($grade['grade_name'])] = (int)$grade['id'];
    }

    require_once __DIR__ . '/../classes/AcademicYear.php';
    $currentAcademicYearId = null;
    try {
        $currentAcademicYearId = AcademicYear::currentId($db);
    } catch (Throwable $e) {
        // fallback
    }

    $existingNames = array_flip(array_map(static function ($value) {
        return normalizeImportHeader((string)$value);
    }, $db->query("SELECT name FROM classes")->fetchAll(PDO::FETCH_COLUMN)));

    $stmt = $db->prepare("INSERT INTO classes (name, grade_id, room_location, status, display_order, academic_year_id) VALUES (?, ?, ?, ?, ?, ?)");
    $imported = 0;
    $errors = [];

    $db->beginTransaction();
    foreach ($rows as $row) {
        $name = importValue($row, ['class_name', 'name', 'اسم الفصل', 'الفصل']);
        $gradeRef = importValue($row, ['grade', 'grade_name', 'الصف']);
        $room = importValue($row, ['room_location', 'room', 'location', 'مقر الفصل', 'المقر']);
        $status = importValue($row, ['status', 'الحالة'], 'active');
        $displayOrder = (int)importValue($row, ['display_order', 'order', 'الترتيب'], '0');

        if ($name === '') {
            $errors[] = 'السطر ' . $row['_row_number'] . ': اسم الفصل مطلوب.';
            continue;
        }

        $normalizedName = normalizeImportHeader($name);
        if (isset($existingNames[$normalizedName])) {
            $errors[] = 'السطر ' . $row['_row_number'] . ': الفصل ' . $name . ' موجود بالفعل.';
            continue;
        }

        $gradeId = null;
        if ($gradeRef !== '') {
            $key = normalizeImportHeader($gradeRef);
            if (!isset($gradeLookup[$key])) {
                $errors[] = 'السطر ' . $row['_row_number'] . ': الصف ' . $gradeRef . ' غير موجود.';
                continue;
            }
            $gradeId = $gradeLookup[$key];
        }

        $stmt->execute([
            $name,
            $gradeId,
            $room === '' ? null : $room,
            normalizeImportHeader($status) === 'inactive' ? 'inactive' : 'active',
            $displayOrder,
            $currentAcademicYearId ?: null
        ]);
        $existingNames[$normalizedName] = true;
        $imported++;
    }
    $db->commit();

    if ($imported > 0) {
        $_SESSION['success_message'] = "تم استيراد {$imported} فصل بنجاح.";
        if (!empty($errors)) {
            $_SESSION['success_message'] .= '<br><small>' . htmlspecialchars(implode(' | ', $errors), ENT_QUOTES, 'UTF-8') . '</small>';
        }
        ActivityLog::logImport('class', $imported, ['errors' => count($errors)]);
    } else {
        $_SESSION['error_message'] = implode(' | ', $errors) ?: 'لم يتم استيراد أي فصل.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error_message'] = 'فشل استيراد الفصول: ' . $e->getMessage();
}

header('Location: ' . $redirectUrl);
exit();
