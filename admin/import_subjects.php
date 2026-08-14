<?php

require_once __DIR__ . '/includes/import_helpers.php';
require_once __DIR__ . '/../classes/ActivityLog.php';

$redirectUrl = 'subjects.php';
$db = adminImportBootstrap();
validateImportRequest($redirectUrl);

try {
    $rows = getImportedRows();
    if (empty($rows)) {
        $_SESSION['error_message'] = 'الملف لا يحتوي على بيانات قابلة للاستيراد.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    $existingNames = array_map('mb_strtolower', $db->query("SELECT name FROM subjects")->fetchAll(PDO::FETCH_COLUMN));
    $existingCodes = array_map('mb_strtolower', array_filter($db->query("SELECT code FROM subjects WHERE code IS NOT NULL AND code <> ''")->fetchAll(PDO::FETCH_COLUMN)));
    $nameLookup = array_flip($existingNames);
    $codeLookup = array_flip($existingCodes);

    $stmt = $db->prepare("INSERT INTO subjects (name, code, sort_order, is_core) VALUES (?, ?, ?, ?)");
    $imported = 0;
    $errors = [];

    $db->beginTransaction();
    foreach ($rows as $row) {
        $name = importValue($row, ['name', 'subject_name', 'subject', 'اسم المادة', 'المادة', 'namear']);
        $code = importValue($row, ['code', 'subject_code', 'الرمز', 'الكود']);
        $sortOrder = (int)importValue($row, ['sort_order', 'order', 'الترتيب'], '0');
        $isCore = importBool(importValue($row, ['is_core', 'core', 'النوع', 'أساسية', 'اساسية']), false);

        if ($name === '') {
            $errors[] = 'السطر ' . $row['_row_number'] . ': اسم المادة مطلوب.';
            continue;
        }

        $lowerName = mb_strtolower($name);
        if (isset($nameLookup[$lowerName])) {
            $errors[] = 'السطر ' . $row['_row_number'] . ': المادة ' . $name . ' موجودة بالفعل.';
            continue;
        }

        if ($code !== '') {
            $lowerCode = mb_strtolower($code);
            if (isset($codeLookup[$lowerCode])) {
                $errors[] = 'السطر ' . $row['_row_number'] . ': الكود ' . $code . ' مستخدم بالفعل.';
                continue;
            }
            $codeLookup[$lowerCode] = true;
        } else {
            $code = null;
        }

        $stmt->execute([$name, $code, $sortOrder, $isCore]);
        $nameLookup[$lowerName] = true;
        $imported++;
    }
    $db->commit();

    if ($imported > 0) {
        $_SESSION['success_message'] = "تم استيراد {$imported} مادة بنجاح.";
        if (!empty($errors)) {
            $_SESSION['success_message'] .= '<br><small>' . htmlspecialchars(implode(' | ', $errors), ENT_QUOTES, 'UTF-8') . '</small>';
        }
        ActivityLog::logImport('subject', $imported, ['errors' => count($errors)]);
    } else {
        $_SESSION['error_message'] = implode(' | ', $errors) ?: 'لم يتم استيراد أي مادة.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error_message'] = 'فشل استيراد المواد: ' . $e->getMessage();
}

header('Location: ' . $redirectUrl);
exit();
