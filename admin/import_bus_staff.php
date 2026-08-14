<?php

require_once __DIR__ . '/includes/import_helpers.php';
require_once __DIR__ . '/../classes/ActivityLog.php';

$redirectUrl = 'bus_staff.php';
$db = adminImportBootstrap();
validateImportRequest($redirectUrl);

try {
    $rows = getImportedRows();
    if (empty($rows)) {
        $_SESSION['error_message'] = 'الملف لا يحتوي على بيانات قابلة للاستيراد.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    $existingNames = array_flip(array_map(static function ($value) {
        return normalizeImportHeader((string)$value);
    }, $db->query("SELECT name FROM bus_staff")->fetchAll(PDO::FETCH_COLUMN)));

    $stmt = $db->prepare("INSERT INTO bus_staff (name, role, phones, notes, bus_id) VALUES (?, ?, ?, ?, NULL)");

    $imported = 0;
    $errors = [];

    $db->beginTransaction();
    foreach ($rows as $row) {
        $name = importValue($row, ['name', 'الاسم']);
        $role = importValue($row, ['role', 'الوظيفة', 'المسمى الوظيفي'], 'سائق');
        $phones = importValue($row, ['phones', 'phone', 'الهواتف', 'رقم الهاتف', 'الهاتف']);
        $notes = importValue($row, ['notes', 'note', 'الملاحظات', 'ملاحظات']);

        if ($name === '') {
            $errors[] = 'السطر ' . $row['_row_number'] . ': الاسم مطلوب.';
            continue;
        }

        $normalizedName = normalizeImportHeader($name);
        if (isset($existingNames[$normalizedName])) {
            $errors[] = 'السطر ' . $row['_row_number'] . ': الطاقم "' . $name . '" موجود بالفعل.';
            continue;
        }

        // Normalize role — يجب أن تتطابق قيم الدور مع المستخدمة في bus_staff.php
        // (driver / supervisor) وإلا ستظهر المشرفون ك سائقين في صفحة الإدارة
        $normalizedRole = normalizeImportHeader($role);
        if (strpos($normalizedRole, 'مشرف') !== false || strpos($normalizedRole, 'supervisor') !== false) {
            $finalRole = 'supervisor';
        } else {
            $finalRole = 'driver'; // default
        }

        $stmt->execute([$name, $finalRole, $phones ?: null, $notes ?: null]);
        
        $existingNames[$normalizedName] = true;
        $imported++;
    }
    $db->commit();

    if ($imported > 0) {
        $_SESSION['success_message'] = "تم استيراد {$imported} عضو طاقم بنجاح.";
        if (!empty($errors)) {
            $_SESSION['success_message'] .= '<br><small>' . htmlspecialchars(implode(' | ', $errors), ENT_QUOTES, 'UTF-8') . '</small>';
        }
        ActivityLog::logImport('bus_staff', $imported, ['errors' => count($errors)]);
    } else {
        $_SESSION['error_message'] = implode(' | ', $errors) ?: 'لم يتم استيراد أي بيانات.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // تسجيل التفاصيل الداخلية للسجل فقط — عدم إفشائها للمستخدم
    error_log('bus_staff import failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $_SESSION['error_message'] = 'فشل استيراد الطاقم بسبب خطأ غير متوقع. يرجى التأكد من صحة الملف والمحاولة مرة أخرى.';
}

header('Location: ' . $redirectUrl);
exit();
