<?php

require_once __DIR__ . '/includes/import_helpers.php';
require_once __DIR__ . '/../classes/ActivityLog.php';

$redirectUrl = 'grades.php';
$db = adminImportBootstrap();
validateImportRequest($redirectUrl);

try {
    $rows = getImportedRows();
    if (empty($rows)) {
        $_SESSION['error_message'] = 'الملف لا يحتوي على بيانات قابلة للاستيراد.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    $stages = $db->query("SELECT id, stage_name, stage_code FROM stages")->fetchAll(PDO::FETCH_ASSOC);
    $stageLookup = [];
    foreach ($stages as $stage) {
        $stageLookup[normalizeImportHeader((string)$stage['id'])] = (int)$stage['id'];
        $stageLookup[normalizeImportHeader($stage['stage_name'])] = (int)$stage['id'];
        if (!empty($stage['stage_code'])) {
            $stageLookup[normalizeImportHeader($stage['stage_code'])] = (int)$stage['id'];
        }
    }

    $existingNames = array_flip(array_map(static function ($value) {
        return normalizeImportHeader((string)$value);
    }, $db->query("SELECT grade_name FROM grades")->fetchAll(PDO::FETCH_COLUMN)));

    $stmt = $db->prepare("INSERT INTO grades (grade_name, grade_code, grade_order, stage_id, description) VALUES (?, ?, ?, ?, ?)");
    $imported = 0;
    $errors = [];

    $db->beginTransaction();
    foreach ($rows as $row) {
        $name = importValue($row, ['grade_name', 'name', 'اسم الصف', 'الصف']);
        $code = importValue($row, ['grade_code', 'code', 'الكود']);
        $order = (int)importValue($row, ['grade_order', 'order', 'الترتيب'], '0');
        $description = importValue($row, ['description', 'الوصف']);
        $stageRef = importValue($row, ['stage', 'stage_name', 'stage_code', 'المرحلة']);

        if ($name === '') {
            $errors[] = 'السطر ' . $row['_row_number'] . ': اسم الصف مطلوب.';
            continue;
        }

        $normalizedName = normalizeImportHeader($name);
        if (isset($existingNames[$normalizedName])) {
            $errors[] = 'السطر ' . $row['_row_number'] . ': الصف ' . $name . ' موجود بالفعل.';
            continue;
        }

        $stageId = null;
        if ($stageRef !== '') {
            $key = normalizeImportHeader($stageRef);
            if (!isset($stageLookup[$key])) {
                $errors[] = 'السطر ' . $row['_row_number'] . ': المرحلة ' . $stageRef . ' غير موجودة.';
                continue;
            }
            $stageId = $stageLookup[$key];
        }

        $stmt->execute([
            $name,
            $code === '' ? null : $code,
            $order,
            $stageId,
            $description === '' ? null : $description
        ]);
        $existingNames[$normalizedName] = true;
        $imported++;
    }
    $db->commit();

    if ($imported > 0) {
        $_SESSION['success_message'] = "تم استيراد {$imported} صف بنجاح.";
        if (!empty($errors)) {
            $_SESSION['success_message'] .= '<br><small>' . htmlspecialchars(implode(' | ', $errors), ENT_QUOTES, 'UTF-8') . '</small>';
        }
        ActivityLog::logImport('grade', $imported, ['errors' => count($errors)]);
    } else {
        $_SESSION['error_message'] = implode(' | ', $errors) ?: 'لم يتم استيراد أي صف.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error_message'] = 'فشل استيراد الصفوف: ' . $e->getMessage();
}

header('Location: ' . $redirectUrl);
exit();
