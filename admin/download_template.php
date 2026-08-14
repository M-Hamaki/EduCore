<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/excel_handler.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$type = $_GET['type'] ?? '';

$headers = [];
$filename = '';

switch ($type) {
    case 'stages':
        $headers = ['اسم المرحلة (إجباري)', 'الاسم بالإنجليزية', 'الكود', 'الترتيب', 'الحالة', 'خدمات الطلاب', 'خدمات المعلمين'];
        $filename = 'نموذج_استيراد_المراحل';
        break;
    case 'grades':
        $headers = ['اسم الصف (إجباري)', 'الكود', 'المرحلة', 'الترتيب', 'الوصف'];
        $filename = 'نموذج_استيراد_الصفوف';
        break;
    case 'classes':
        $headers = ['اسم الفصل (إجباري)', 'الصف الدراسي', 'مقر الفصل', 'الترتيب', 'الحالة'];
        $filename = 'نموذج_استيراد_الفصول';
        break;
    case 'subjects':
        $headers = ['اسم المادة (إجباري)', 'الكود (إجباري)', 'الترتيب', 'مادة أساسية (نعم/لا)'];
        $filename = 'نموذج_استيراد_المواد';
        break;
    default:
        header('Location: index.php');
        exit;
}

$data = [$headers];

try {
    $excel_handler = new ExcelHandler();
    $filepath = $excel_handler->exportToExcel($data, $filename);

    if ($filepath && file_exists($filepath)) {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: must-revalidate');
        readfile($filepath);
        unlink($filepath);
        exit;
    }
} catch (Exception $e) {
    error_log("Download template error: " . $e->getMessage());
    header('Location: index.php?error=template_failed');
    exit;
}
