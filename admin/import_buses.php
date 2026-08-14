<?php

require_once __DIR__ . '/includes/import_helpers.php';
require_once __DIR__ . '/../classes/ActivityLog.php';

$redirectUrl = 'buses.php';
$db = adminImportBootstrap();
validateImportRequest($redirectUrl);

try {
    $rows = getImportedRows();
    if (empty($rows)) {
        $_SESSION['error_message'] = 'الملف لا يحتوي على بيانات قابلة للاستيراد.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    $existingBuses = array_flip(array_map(static function ($value) {
        return normalizeImportHeader((string)$value);
    }, $db->query("SELECT bus_number FROM buses")->fetchAll(PDO::FETCH_COLUMN)));

    $stmt = $db->prepare("INSERT INTO buses (bus_number, capacity, status, notes) VALUES (?, ?, ?, ?)");

    $imported = 0;
    $errors = [];

    $db->beginTransaction();
    foreach ($rows as $row) {
        $busNumber = importValue($row, ['bus_number', 'bus', 'رقم الحافلة', 'الحافلة', 'رقم الباص', 'الباص']);
        $capacity = importValue($row, ['capacity', 'السعة', 'سعة', 'السعة المقعدية'], '0');
        $status = importValue($row, ['status', 'الحالة'], 'active');
        $notes = importValue($row, ['notes', 'note', 'الملاحظات', 'ملاحظات']);

        if ($busNumber === '') {
            $errors[] = 'السطر ' . $row['_row_number'] . ': رقم الحافلة مطلوب.';
            continue;
        }

        $normalizedBusNumber = normalizeImportHeader($busNumber);
        if (isset($existingBuses[$normalizedBusNumber])) {
            $errors[] = 'السطر ' . $row['_row_number'] . ': الحافلة "' . $busNumber . '" موجودة بالفعل.';
            continue;
        }

        // Normalize status
        $normalizedStatus = normalizeImportHeader($status);
        if (strpos($normalizedStatus, 'توقف') !== false || strpos($normalizedStatus, 'inactive') !== false || strpos($normalizedStatus, 'معطل') !== false) {
            $finalStatus = 'inactive';
        } else {
            $finalStatus = 'active'; // default
        }

        // Capacity
        $capacityInt = (int)$capacity;
        if ($capacityInt <= 0) $capacityInt = null;

        $stmt->execute([$busNumber, $capacityInt, $finalStatus, $notes ?: null]);
        
        $existingBuses[$normalizedBusNumber] = true;
        $imported++;
    }
    $db->commit();

    if ($imported > 0) {
        $_SESSION['success_message'] = "تم استيراد {$imported} حافلة بنجاح.";
        if (!empty($errors)) {
            $_SESSION['success_message'] .= '<br><small>' . htmlspecialchars(implode(' | ', $errors), ENT_QUOTES, 'UTF-8') . '</small>';
        }
        ActivityLog::logImport('buses', $imported, ['errors' => count($errors)]);
    } else {
        $_SESSION['error_message'] = implode(' | ', $errors) ?: 'لم يتم استيراد أي بيانات.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error_message'] = 'فشل استيراد الحافلات: ' . $e->getMessage();
}

header('Location: ' . $redirectUrl);
exit();
